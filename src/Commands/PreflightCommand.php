<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Commands;

use AgentDetector\AgentDetector;
use Illuminate\Console\Command;
use Illuminate\Console\Prohibitable;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Nwrman\LaravelToolkit\Concerns\ManagesFrontendBuild;
use Nwrman\LaravelToolkit\Concerns\SendsDesktopNotifications;
use Override;

final class PreflightCommand extends Command
{
    use ManagesFrontendBuild;
    use Prohibitable;
    use SendsDesktopNotifications;

    private const array SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    #[Override]
    protected $signature = 'toolkit:preflight
        {--retry : Only re-run previously failed gates}
        {--gate=* : Run specific gates by key}
        {--force-build : Force frontend rebuild}
        {--no-notify : Skip macOS notification}';

    #[Override]
    protected $description = 'Run all CI gates in parallel with retry support';

    public function handle(): int
    {
        if ($this->isProhibited()) {
            return 1;
        }

        $isAgent = AgentDetector::detect()->isAgent;

        $gatesToRun = $this->determineGates();

        if ($gatesToRun === []) {
            return 0;
        }

        $gates = $this->gates();
        $requiresBuild = array_any($gatesToRun, fn (string $gate): bool => (bool) ($gates[$gate]['requires_build'] ?? false));

        if ($requiresBuild && ! $this->ensureFrontendBuild()) {
            $this->notify('Preflight', 'Frontend build failed ✗', 'Basso');

            return 1;
        }

        $gateCount = count($gatesToRun);
        $this->info(sprintf('Running %d gate%s in parallel...', $gateCount, $gateCount > 1 ? 's' : ''));
        $this->newLine();

        $results = $this->runGatesInParallel($gatesToRun, $isAgent);

        $hasFailures = $this->hasFailures($results);

        if ($hasFailures) {
            $this->renderFailedOutput($results, $isAgent);
            $this->saveState($results);
        }

        $this->renderSummaryTable($results);

        if ($hasFailures) {
            $this->newLine();
            $this->line('Retry failed gates: php artisan toolkit:preflight --retry');

            if (! $isAgent) {
                $this->notify('Preflight', 'Some gates failed ✗', 'Basso');
            }

            return 1;
        }

        File::delete($this->stateFile());
        $this->newLine();
        $this->info('✓ All gates passed!');

        if (! $isAgent) {
            $this->notify('Preflight', 'All gates passed! ✓', 'Glass');
        }

        return 0;
    }

    /**
     * @return array<string, array{label: string, command: string, env?: array<string, string>, requires_build?: bool}>
     */
    private function gates(): array
    {
        /** @var array<string, array{label: string, command: string, env?: array<string, string>, requires_build?: bool}> $gates */
        $gates = config('toolkit.gates', []);

        return $gates;
    }

    private function stateFile(): string
    {
        /** @var string $path */
        $path = config('toolkit.paths.preflight_state', 'storage/logs/preflight-result.json');

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function determineGates(): array
    {
        $gates = $this->gates();

        if ($this->option('retry')) {
            return $this->getFailedGatesFromState();
        }

        /** @var array<int, string> $gateOption */
        $gateOption = $this->option('gate');

        if ($gateOption !== []) {
            $invalid = array_diff($gateOption, array_keys($gates));

            if ($invalid !== []) {
                $this->error('Invalid gate(s): '.implode(', ', $invalid));
                $this->line('<comment>Available gates: '.implode(', ', array_keys($gates)).'</comment>');

                return [];
            }

            return $gateOption;
        }

        return array_keys($gates);
    }

    /**
     * @return array<int, string>
     */
    private function getFailedGatesFromState(): array
    {
        $stateFile = $this->stateFile();

        if (! File::exists($stateFile)) {
            $this->warn('No previous preflight results found. Running all gates.');

            return array_keys($this->gates());
        }

        $data = json_decode(File::get($stateFile), true);

        if (! is_array($data) || ! isset($data['gates'])) {
            $this->warn('Could not parse preflight state. Running all gates.');

            return array_keys($this->gates());
        }

        /** @var array<string, array{status: string}> $gates */
        $gates = $data['gates'];

        $failed = [];
        foreach ($gates as $name => $gate) {
            if ($gate['status'] === 'fail') {
                $failed[] = $name;
            }
        }

        if ($failed === []) {
            $this->info('No failed gates to retry. All gates passed last run.');

            return [];
        }

        $this->info(sprintf('Retrying %d failed gate(s): %s', count($failed), implode(', ', $failed)));

        return $failed;
    }

    /**
     * @param  array<int, string>  $gatesToRun
     * @return array<string, array{status: string, duration: float, output: string}>
     */
    private function runGatesInParallel(array $gatesToRun, bool $isAgent = false): array
    {
        $gates = $this->gates();

        /** @var array<string, string> $outputs */
        $outputs = [];

        /** @var array<string, bool> $completed */
        $completed = [];

        /** @var array<string, bool> $exitCodes */
        $exitCodes = [];

        /** @var array<string, float> $durations */
        $durations = [];

        foreach ($gatesToRun as $gate) {
            $outputs[$gate] = '';
            $completed[$gate] = false;
        }

        $wallStart = microtime(true);
        $useAnsi = ! $isAgent && $this->output->isDecorated();

        // @codeCoverageIgnoreStart
        if (! $isAgent) {
            foreach ($gatesToRun as $gate) {
                $label = $gates[$gate]['label'];

                if ($useAnsi) {
                    $this->output->write(sprintf("  %s %s\n", self::SPINNER_FRAMES[0], $label));
                } else {
                    $this->line(sprintf('  ⏳ %s ...', $label));
                }
            }
        }

        // @codeCoverageIgnoreEnd

        /** @var array<string, InvokedProcess> $processes */
        $processes = [];

        foreach ($gatesToRun as $gate) {
            $config = $gates[$gate];
            $env = $config['env'] ?? [];

            $processes[$gate] = Process::env($env)->forever()
                ->start($config['command'], function (string $type, string $output) use ($gate, &$outputs): void {
                    $outputs[$gate] .= $output;
                });
        }

        $frame = 0;

        while (true) {
            $allDone = true;

            foreach ($gatesToRun as $gate) {
                if ($completed[$gate]) {
                    continue; // @codeCoverageIgnore
                }

                if ($processes[$gate]->running()) {
                    $allDone = false; // @codeCoverageIgnore
                } else {
                    $completed[$gate] = true;
                    $durations[$gate] = round(microtime(true) - $wallStart, 1);
                    $exitCodes[$gate] = $processes[$gate]->wait()->successful();
                }
            }

            // @codeCoverageIgnoreStart
            if ($useAnsi) {
                $this->updateSpinnerDisplay($gatesToRun, $completed, $durations, $exitCodes, $frame);
            }

            // @codeCoverageIgnoreEnd

            if ($allDone) {
                break;
            }

            $frame++; // @codeCoverageIgnore
            Sleep::usleep(150_000); // @codeCoverageIgnore
        }

        $this->newLine();

        $results = [];
        foreach ($gatesToRun as $gate) {
            $results[$gate] = [
                'status' => $exitCodes[$gate] ? 'pass' : 'fail',
                'duration' => $durations[$gate],
                'output' => $outputs[$gate],
            ];
        }

        return $results;
    }

    /**
     * @codeCoverageIgnore
     *
     * @param  array<int, string>  $gatesToRun
     * @param  array<string, bool>  $completed
     * @param  array<string, float>  $durations
     * @param  array<string, bool>  $exitCodes
     */
    private function updateSpinnerDisplay(array $gatesToRun, array $completed, array $durations, array $exitCodes, int $frame): void
    {
        $gates = $this->gates();
        $lineCount = count($gatesToRun);

        $this->output->write(sprintf("\033[%dA", $lineCount));

        foreach ($gatesToRun as $gate) {
            $label = mb_str_pad($gates[$gate]['label'], 20);

            if ($completed[$gate]) {
                $elapsed = $durations[$gate] ?? 0.0;
                $icon = ($exitCodes[$gate] ?? false) ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->output->write(sprintf("\033[2K  %s %s (%ss)\n", $icon, $label, number_format($elapsed, 1)));
            } else {
                $spinnerChar = self::SPINNER_FRAMES[$frame % count(self::SPINNER_FRAMES)];
                $this->output->write(sprintf("\033[2K  <fg=cyan>%s</> %s\n", $spinnerChar, $label));
            }
        }
    }

    /**
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     */
    private function renderSummaryTable(array $results): void
    {
        $gates = $this->gates();
        $durations = array_column($results, 'duration');
        $wallClock = $durations !== [] ? max($durations) : 0.0;
        $totalSequential = array_sum($durations);

        $headers = ['Gate', 'Status', 'Time'];
        $rows = [];

        foreach ($results as $gate => $result) {
            $status = $result['status'] === 'pass'
                ? '<fg=green>✓ pass</>'
                : '<fg=red>✗ FAIL</>';

            $rows[] = [
                $gates[$gate]['label'],
                $status,
                number_format($result['duration'], 1).'s',
            ];
        }

        $this->table($headers, $rows);
        $this->line(sprintf(
            '<comment>Wall clock: %ss (saved %ss vs sequential)</comment>',
            number_format($wallClock, 1),
            number_format($totalSequential - $wallClock, 1)
        ));
    }

    /**
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     */
    private function hasFailures(array $results): bool
    {
        return array_any($results, fn (array $result): bool => $result['status'] === 'fail');
    }

    /**
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     */
    private function renderFailedOutput(array $results, bool $isAgent = false): void
    {
        $gates = $this->gates();

        foreach ($results as $gate => $result) {
            if ($result['status'] !== 'fail') {
                continue;
            }

            $label = $gates[$gate]['label'];
            $output = mb_trim($result['output']);
            $cleanOutput = $output !== '' ? (string) preg_replace('/\x1b\[[0-9;]*m/', '', $output) : '';

            // @codeCoverageIgnoreStart
            if ($isAgent) {
                $this->newLine();
                $this->line(sprintf('--- %s FAILED (%ss) ---', $label, number_format($result['duration'], 1)));

                if ($cleanOutput !== '') {
                    $lines = explode("\n", $cleanOutput);
                    $tailLines = array_slice($lines, -40);
                    $this->output->writeln(implode("\n", $tailLines));
                }

                continue;
            }

            $title = sprintf(' ✗ %s ', $label);
            $pad = str_repeat('─', max(0, 60 - mb_strlen($title)));

            $this->newLine(2);
            $this->line(sprintf('<fg=red>╭%s%s╮</>', $title, $pad));
            $this->line(sprintf('<fg=red>│  %-58s│</>', sprintf('Gate failed after %ss', number_format($result['duration'], 1))));
            $this->line(sprintf('<fg=red>╰%s╯</>', str_repeat('─', 60)));

            if ($cleanOutput !== '') {
                $lines = explode("\n", $cleanOutput);
                $tailLines = array_slice($lines, -80);
                $this->output->writeln(implode("\n", $tailLines));
            } else {
                $this->line('<comment>No output captured.</comment>');
            }

            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     */
    private function saveState(array $results): void
    {
        $durations = array_column($results, 'duration');
        $wallClock = $durations !== [] ? max($durations) : 0.0;

        $gateStates = [];
        foreach ($results as $gate => $result) {
            $gateStates[$gate] = [
                'status' => $result['status'],
                'duration' => $result['duration'],
            ];
        }

        $state = [
            'timestamp' => date('c'),
            'duration' => round($wallClock, 1),
            'gates' => $gateStates,
        ];

        $encoded = json_encode($state, JSON_PRETTY_PRINT);
        File::put($this->stateFile(), is_string($encoded) ? $encoded : '{}');
    }
}
