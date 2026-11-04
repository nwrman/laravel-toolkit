<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Preflight;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Nwrman\LaravelToolkit\Config\ToolkitConfig;
use Nwrman\LaravelToolkit\Pest\Plugin;
use Nwrman\LaravelToolkit\Support\Notifier;
use Nwrman\LaravelToolkit\Tia\TiaBaselines;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;

/**
 * The ship gate: every configured gate in parallel, stopping at the first failure.
 *
 * Never boots Laravel. It reads config/toolkit.php as plain arrays, and gates inherit
 * this process's environment, so nothing from `.env` reaches a test run. A gate with
 * `after` waits for that gate to pass. When a gate fails, every running gate is
 * stopped with everything it started, and the gates still waiting do not start. The
 * summary table shows every configured gate: one this run did not run shows its
 * result from the last run that did.
 */
final readonly class Preflight
{
    public const string PASS = 'pass';

    public const string FAIL = 'fail';

    public const string STOPPED = 'stopped';

    public const string NOT_RUN = 'not_run';

    private const array SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Pest's partial-selection flags (`Pest\Plugins\Tia::PARTIAL_SELECTION_FLAGS`).
     * Any one of them makes Pest switch TIA off for the run.
     */
    private const array PARTIAL_SELECTION_FLAGS = [
        '--filter', '--exclude-filter', '--group', '--exclude-group',
        '--covers', '--uses', '--testsuite', '--exclude-testsuite', '--test-suffix',
        '--dirty', '--todo', '--todos', '--flaky', '--notes',
        '--assignee', '--issue', '--ticket', '--pr', '--pull-request',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private string $root,
        private array $config,
        private OutputInterface $output,
        private Factory $process,
        private Filesystem $files,
        private ProcessTree $tree,
        private TiaBaselines $tia,
        private bool $isAgent,
    ) {}

    /**
     * @param  array<int, string>  $only
     */
    public function run(bool $retry, array $only, bool $forceBuild, bool $notify): int
    {
        $gates = $this->gates();
        $state = new GateState($this->files, ToolkitConfig::path($this->config, 'preflight_state', 'storage/logs/preflight-result.json'));
        $previous = $state->read();

        $selected = $this->select($gates, $previous, $retry, $only);

        if ($selected === null) {
            return 1;
        }

        if ($selected === []) {
            $this->output->writeln('<info>Every gate passed on the last run: nothing to retry.</info>');
            $this->renderTable($gates, [], $previous);

            return 0;
        }

        if ($this->rejectsTiaDefeatingGates($gates, $selected)) {
            return 1;
        }

        $count = count($selected);
        $this->output->writeln(sprintf('<info>Running %d gate%s...</info>', $count, $count === 1 ? '' : 's'));
        $this->output->writeln('');

        $replaysCoverage = $this->tiaEnabled() && array_any($selected, fn (string $key): bool => $this->pestCoverageRuns($gates[$key]['command']) !== []);
        $aligned = $replaysCoverage ? $this->tia->alignCoverage($this->root) : null;

        if ($aligned !== null) {
            $this->output->writeln([$aligned, '']);
        }

        $coverageRun = $replaysCoverage ? $this->tia->startCoverageRun($this->root) : null;
        $results = $this->runGates($gates, $selected, $forceBuild);

        if ($coverageRun !== null) {
            $this->tia->finishCoverageRun($coverageRun);
        }

        $state->write($results);
        $this->renderFailures($gates, $results);
        $this->renderTable($gates, $results, $previous);

        $passed = array_all($results, fn (array $result): bool => $result['status'] === self::PASS);
        $notifier = new Notifier($this->process, $notify && ! $this->isAgent && ToolkitConfig::notificationsEnabled($this->config));

        if (! $passed) {
            $this->output->writeln(['', 'Retry the gates that did not pass: <info>vendor/bin/preflight --retry</info>']);

            if (is_file($this->root.DIRECTORY_SEPARATOR.ToolkitConfig::path($this->config, 'report_file', 'storage/logs/test-failures.md'))) {
                $this->output->writeln(sprintf('Failed tests, with a command for each: <info>%s</info>', ToolkitConfig::path($this->config, 'report_file', 'storage/logs/test-failures.md')));
            }

            $notifier->notify('Preflight', 'A gate failed ✗', 'Basso');

            return 1;
        }

        // A run of some gates passes when they do, but the others' last results stand.
        $notPassed = array_values(array_filter(
            array_keys($gates),
            fn (string $key): bool => ($results[$key]['status'] ?? $previous[$key]['status'] ?? null) !== self::PASS,
        ));

        if ($notPassed !== []) {
            $this->output->writeln(['', sprintf('<info>✓ %s passed.</info> <comment>Not passed yet: %s.</comment>', $this->labels($gates, array_keys($results)), $this->labels($gates, $notPassed))]);
            $notifier->notify('Preflight', 'The selected gates passed ✓', 'Glass');

            return 0;
        }

        $this->output->writeln(['', '<info>✓ All gates passed!</info>']);
        $notifier->notify('Preflight', 'All gates passed! ✓', 'Glass');

        return 0;
    }

    /**
     * @return array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>
     */
    private function gates(): array
    {
        $gates = [];
        $configured = is_array($this->config['gates'] ?? null) ? $this->config['gates'] : [];

        foreach ($configured as $key => $gate) {
            if (! is_array($gate) || ! is_string($gate['command'] ?? null)) {
                continue;
            }

            /** @var array<string, string> $env */
            $env = is_array($gate['env'] ?? null) ? $gate['env'] : [];

            $gates[(string) $key] = [
                'label' => is_string($gate['label'] ?? null) ? $gate['label'] : (string) $key,
                'command' => $gate['command'],
                'env' => $env,
                'after' => is_string($gate['after'] ?? null) && isset($configured[$gate['after']]) ? $gate['after'] : null,
            ];
        }

        return $gates;
    }

    /**
     * The gates to run, in configured order; null when the selection is invalid.
     *
     * @param  array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>  $gates
     * @param  array<string, array{status: string, duration: float}>  $previous
     * @param  array<int, string>  $only
     * @return array<int, string>|null
     */
    private function select(array $gates, array $previous, bool $retry, array $only): ?array
    {
        if ($only !== []) {
            $invalid = array_diff($only, array_keys($gates));

            if ($invalid !== []) {
                $this->output->writeln(sprintf('<error>Unknown gate(s): %s</error>', implode(', ', $invalid)));
                $this->output->writeln(sprintf('<comment>Gates: %s</comment>', implode(', ', array_keys($gates))));

                return null;
            }

            return array_values(array_intersect(array_keys($gates), $only));
        }

        if (! $retry) {
            return array_keys($gates);
        }

        if ($previous === []) {
            $this->output->writeln('<comment>No previous preflight result: running every gate.</comment>');

            return array_keys($gates);
        }

        $failed = array_values(array_filter(
            array_keys($gates),
            fn (string $key): bool => ($previous[$key]['status'] ?? null) !== self::PASS,
        ));

        if ($failed !== []) {
            $this->output->writeln(sprintf('<info>Retrying the gates that did not pass: %s</info>', implode(', ', $failed)));
        }

        return $failed;
    }

    /**
     * Runs the gates, each as soon as the gate it waits for has passed, and stops them
     * all at the first failure.
     *
     * @param  array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>  $gates
     * @param  array<int, string>  $selected
     * @return array<string, array{status: string, duration: float, output: string}>
     */
    private function runGates(array $gates, array $selected, bool $forceBuild): array
    {
        $baseEnv = [
            'PATH' => $this->root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.PATH_SEPARATOR.(getenv('PATH') ?: ''),
            Plugin::PREFLIGHT_ENV => '1',
            ...($forceBuild ? [Plugin::FORCE_BUILD_ENV => '1'] : []),
        ];

        /** @var array<string, string> $outputs */
        $outputs = array_fill_keys($selected, '');

        /** @var array<string, InvokedProcess> $running */
        $running = [];

        /** @var array<string, float> $startedAt */
        $startedAt = [];

        /** @var array<string, array{status: string, duration: float, output: string}> $results */
        $results = [];

        $pending = $selected;
        $failed = false;
        $wallStart = microtime(true);
        $animate = ! $this->isAgent && $this->output->isDecorated();
        $frame = 0;

        if ($animate) {
            $this->renderProgress($gates, $selected, $running, $results, $frame, false); // @codeCoverageIgnore
        }

        while ($pending !== [] || $running !== []) {
            foreach ($pending as $index => $key) {
                $after = $gates[$key]['after'];
                $waitsFor = $after !== null && in_array($after, $selected, true) ? $after : null;

                if ($failed || ($waitsFor !== null && isset($results[$waitsFor]) && $results[$waitsFor]['status'] !== self::PASS)) {
                    $results[$key] = ['status' => self::NOT_RUN, 'duration' => 0.0, 'output' => ''];
                    unset($pending[$index]);

                    continue;
                }

                if ($waitsFor !== null && ! isset($results[$waitsFor])) {
                    continue;
                }

                $startedAt[$key] = microtime(true);
                $running[$key] = $this->process->newPendingProcess()->path($this->root)
                    ->env([...$baseEnv, ...$gates[$key]['env']])
                    ->forever()
                    ->start($gates[$key]['command'], function (string $type, string $buffer) use ($key, &$outputs): void {
                        $outputs[$key] .= $buffer;
                    });
                unset($pending[$index]);
            }

            // Gates that wait on each other in a cycle can never start.
            if ($running === [] && $pending !== []) {
                $this->output->writeln(sprintf('<error>These gates wait for each other through `after`, so none can start: %s.</error>', implode(', ', $pending)));

                foreach ($pending as $key) {
                    $results[$key] = ['status' => self::NOT_RUN, 'duration' => 0.0, 'output' => ''];
                }

                $pending = [];
            }

            foreach ($running as $key => $process) {
                // Stopped by an earlier gate's failure in this same pass.
                if (isset($results[$key]) || $process->running()) {
                    continue;
                }

                $passed = $this->settle($process);
                $results[$key] = [
                    'status' => $passed ? self::PASS : self::FAIL,
                    'duration' => round(microtime(true) - $startedAt[$key], 1),
                    'output' => $outputs[$key],
                ];
                unset($running[$key]);

                if ($this->isAgent) {
                    $this->output->writeln(sprintf('  %s %s (%ss)', $passed ? '✓' : '✗', $gates[$key]['label'], number_format($results[$key]['duration'], 1)));
                }

                if (! $passed && ! $failed) {
                    $failed = true;
                    $this->stopAll($running, $startedAt, $outputs, $results, array_map(fn (array $gate): string => $gate['label'], $gates));
                    $running = [];
                }
            }

            if ($animate) {
                $this->renderProgress($gates, $selected, $running, $results, ++$frame, true); // @codeCoverageIgnore
            }

            if ($pending !== [] || $running !== []) {
                // Native usleep(), never Illuminate\Support\Sleep, which a test may fake.
                usleep(150_000);
            }
        }

        $this->output->writeln(['', sprintf('<comment>Wall clock: %ss</comment>', number_format(microtime(true) - $wallStart, 1))]);

        $ordered = [];

        foreach ($selected as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * @param  array<string, InvokedProcess>  $running
     * @param  array<string, float>  $startedAt
     * @param  array<string, string>  $outputs
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     * @param  array<string, string>  $labels
     */
    private function stopAll(array $running, array $startedAt, array $outputs, array &$results, array $labels): void
    {
        $this->tree->terminate(...array_values(array_filter(array_map(fn (InvokedProcess $process): mixed => $process->id(), $running), is_int(...))));

        foreach ($running as $key => $process) {
            $label = $labels[$key] ?? $key;
            $this->settle($process);
            $results[$key] = [
                'status' => self::STOPPED,
                'duration' => round(microtime(true) - $startedAt[$key], 1),
                'output' => $outputs[$key],
            ];

            if ($this->isAgent) {
                $this->output->writeln(sprintf('  ■ %s stopped', $label));
            }
        }
    }

    /**
     * Waits for a finished gate and says whether it passed. A gate killed by a signal
     * (stopped here, or by the system) failed; Symfony reports that as an exception.
     */
    private function settle(InvokedProcess $process): bool
    {
        try {
            return $process->wait()->successful();
        } catch (ProcessSignaledException) {
            return false;
        }
    }

    /**
     * @codeCoverageIgnore
     *
     * @param  array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>  $gates
     * @param  array<int, string>  $selected
     * @param  array<string, InvokedProcess>  $running
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     */
    private function renderProgress(array $gates, array $selected, array $running, array $results, int $frame, bool $redraw): void
    {
        if ($redraw) {
            $this->output->write(sprintf("\033[%dA", count($selected)));
        }

        foreach ($selected as $key) {
            $label = mb_str_pad($gates[$key]['label'], 20);
            $line = match (true) {
                isset($results[$key]) => sprintf('%s %s (%ss)', $this->icon($results[$key]['status']), $label, number_format($results[$key]['duration'], 1)),
                isset($running[$key]) => sprintf('<fg=cyan>%s</> %s', self::SPINNER_FRAMES[$frame % count(self::SPINNER_FRAMES)], $label),
                default => sprintf('<fg=gray>·</> %s waiting', $label),
            };

            $this->output->write("\033[2K  ".$line."\n");
        }
    }

    /**
     * @param  array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>  $gates
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     */
    private function renderFailures(array $gates, array $results): void
    {
        foreach ($results as $key => $result) {
            if ($result['status'] !== self::FAIL) {
                continue;
            }

            $output = mb_trim((string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $result['output']));
            $tail = implode("\n", array_slice(explode("\n", $output), $this->isAgent ? -40 : -80));

            $this->output->writeln(['', sprintf('<fg=red>--- %s failed after %ss ---</>', $gates[$key]['label'], number_format($result['duration'], 1))]);
            $this->output->writeln($output === '' ? '<comment>No output captured.</comment>' : $tail, OutputInterface::OUTPUT_RAW);
        }

        $stopped = array_keys(array_filter($results, fn (array $result): bool => $result['status'] === self::STOPPED));

        if ($stopped !== []) {
            $this->output->writeln(['', sprintf('<comment>Stopped after the first failure: %s.</comment>', implode(', ', array_map(fn (string $key): string => $gates[$key]['label'], $stopped)))]);
        }
    }

    /**
     * @param  array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>  $gates
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     * @param  array<string, array{status: string, duration: float}>  $previous
     */
    private function renderTable(array $gates, array $results, array $previous): void
    {
        $rows = [];

        foreach ($gates as $key => $gate) {
            if (isset($results[$key])) {
                $rows[] = [$gate['label'], $this->statusLabel($results[$key]['status'], false), $this->time($results[$key])];

                continue;
            }

            if (isset($previous[$key])) {
                $rows[] = [$gate['label'], $this->statusLabel($previous[$key]['status'], true), $this->time($previous[$key])];

                continue;
            }

            $rows[] = [$gate['label'], '<fg=yellow>– never run</>', '–'];
        }

        $this->output->writeln('');
        (new Table($this->output))->setHeaders(['Gate', 'Status', 'Time'])->setRows($rows)->render();
    }

    /**
     * @param  array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>  $gates
     * @param  array<int, string>  $keys
     */
    private function labels(array $gates, array $keys): string
    {
        return implode(', ', array_map(fn (string $key): string => $gates[$key]['label'], $keys));
    }

    private function statusLabel(string $status, bool $previousRun): string
    {
        $label = match ($status) {
            self::PASS => '<fg=green>✓ passed</>',
            self::FAIL => '<fg=red>✗ FAILED</>',
            self::STOPPED => '<fg=yellow>■ stopped</>',
            default => '<fg=yellow>– not run</>',
        };

        return $previousRun ? $label.', previous run' : $label;
    }

    private function icon(string $status): string
    {
        return match ($status) {
            self::PASS => '<fg=green>✓</>',
            self::FAIL => '<fg=red>✗</>',
            default => '<fg=yellow>■</>',
        };
    }

    /**
     * @param  array{status: string, duration: float}  $result
     */
    private function time(array $result): string
    {
        return $result['status'] === self::NOT_RUN ? '–' : number_format($result['duration'], 1).'s';
    }

    /**
     * A coverage gate that selects part of the suite runs without TIA: every preflight
     * re-runs everything, and it leaves no baseline for the next branch to inherit.
     *
     * @param  array<string, array{label: string, command: string, env: array<string, string>, after: string|null}>  $gates
     * @param  array<int, string>  $selected
     */
    private function rejectsTiaDefeatingGates(array $gates, array $selected): bool
    {
        if (! $this->tiaEnabled()) {
            return false;
        }

        $rejected = false;

        foreach ($selected as $key) {
            $flag = $this->partialSelectionFlag($gates[$key]['command']);

            if ($flag === null) {
                continue;
            }

            $rejected = true;
            $this->output->writeln(sprintf('<error>The %s gate passes %s to a Pest coverage run.</error>', $gates[$key]['label'], $flag));
            $this->output->writeln('Pest treats that as a partial run and switches TIA off: every preflight re-runs the whole suite and');
            $this->output->writeln('leaves no baseline for the next branch. Remove it from config/toolkit.php. To leave Browser specs');
            $this->output->writeln(sprintf('out of a coverage run, pass %s: the run stays a full one to TIA.', Plugin::NO_BROWSER_OPTION));
        }

        return $rejected;
    }

    /**
     * The tokens of each Pest coverage run in a gate command.
     *
     * @return array<int, array<int, string>>
     */
    private function pestCoverageRuns(string $command): array
    {
        $runs = [];

        foreach (preg_split('/&&|\|\||;/', $command) ?: [] as $segment) {
            $tokens = preg_split('/\s+/', mb_trim($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $runsPest = array_any($tokens, fn (string $token): bool => str_ends_with($token, 'pest'));
            $collectsCoverage = array_any($tokens, fn (string $token): bool => $token === '--coverage' || str_starts_with($token, '--coverage='));

            if ($runsPest && $collectsCoverage) {
                $runs[] = $tokens;
            }
        }

        return $runs;
    }

    private function partialSelectionFlag(string $command): ?string
    {
        foreach ($this->pestCoverageRuns($command) as $tokens) {
            foreach ($tokens as $token) {
                foreach (self::PARTIAL_SELECTION_FLAGS as $flag) {
                    if ($token === $flag || str_starts_with($token, $flag.'=')) {
                        return $flag;
                    }
                }
            }
        }

        return null;
    }

    private function tiaEnabled(): bool
    {
        $tia = $this->config['tia'] ?? null;

        return is_array($tia) && (bool) ($tia['enabled'] ?? false);
    }
}
