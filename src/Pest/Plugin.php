<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Pest;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Laravel\AgentDetector\AgentDetector;
use Nwrman\LaravelToolkit\Config\ToolkitConfig;
use Nwrman\LaravelToolkit\Preflight\Preflight;
use Nwrman\LaravelToolkit\Report\FailureReport;
use Nwrman\LaravelToolkit\Support\AssetBuild;
use Nwrman\LaravelToolkit\Support\Notifier;
use Pest\Browser\Plugin as BrowserPlugin;
use Pest\Contracts\Plugins\AddsOutput;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Plugins\Concerns\HandleArguments;
use Pest\Plugins\Parallel;
use Pest\TestSuite;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The toolkit's Pest plugin. Every Pest run started from a shell, `vendor/bin/pest`
 * included, gets the failure report and the asset build check, with no artisan parent
 * to load `.env` into the tests' environment.
 *
 * In every process that runs tests, it keeps vendor/ out of Xdebug coverage outside a
 * coverage-report run ({@see XdebugFilter}). In the parent process it:
 * - builds the frontend first when a Browser spec could run and the build is stale;
 * - writes the report's backend section from the run's JUnit file when the run ends;
 * - with `--toolkit-retry`, runs only the tests the report lists as failed;
 * - with `--toolkit-no-browser`, leaves the Browser suite out without making the run a
 *   partial one, so Test Impact Analysis stays on (workers learn it through a Pest
 *   global, which lasts only for this run).
 *
 * Third-party plugins run before Pest's own, so the arguments added here are the ones
 * Pest's TIA and Parallel plugins see, and `addOutput` runs before Pest's coverage
 * report: the exit code here is the tests' own.
 */
final class Plugin implements AddsOutput, HandlesArguments
{
    use HandleArguments;

    public const string NO_BROWSER_OPTION = '--toolkit-no-browser';

    public const string RETRY_OPTION = '--toolkit-retry';

    public const string FORCE_BUILD_ENV = 'TOOLKIT_FORCE_BUILD';

    /** Set by preflight on its gates, so a gate does not notify on top of preflight. */
    public const string PREFLIGHT_ENV = 'TOOLKIT_PREFLIGHT';

    private const string NO_BROWSER_GLOBAL = 'TOOLKIT_NO_BROWSER';

    /**
     * Marks the processes a reporting run starts, through `$_ENV` as Pest's own globals
     * do: Symfony Process hands `$_ENV` to every child. A test that starts Pest itself
     * (a guard test running the suite against a bad database, say) would otherwise
     * overwrite this run's report and JUnit file with its own.
     */
    private const string RUN_ENV = 'TOOLKIT_PEST_RUN';

    /**
     * Runs that execute no tests, or that another plugin takes over.
     */
    private const array PASSIVE_OPTIONS = [
        '--type-coverage', '--baseline', '--help', '-h', '--version', '--init',
        '--list-tests', '--list-tests-xml', '--list-test-files', '--list-groups', '--list-suites',
        '--mutate', '--profanity', '--migrate-configuration', '--generate-configuration',
    ];

    /**
     * Options that end a run before every selected test has run, or hand this process
     * only part of a file's tests. A test such a run did not reach may still exist.
     */
    private const array PARTIAL_RUN_OPTIONS = [
        '--bail', '--stop-on-defect', '--stop-on-error', '--stop-on-failure', '--stop-on-warning',
        '--stop-on-risky', '--stop-on-deprecation', '--stop-on-notice', '--stop-on-skipped',
        '--stop-on-incomplete', '--shard',
    ];

    private ?string $junitFile = null;

    private int $startedAt = 0;

    private string $command = '';

    /**
     * The test files a retry re-runs, or null when this run is not a retry.
     *
     * @var array<int, string>|null
     */
    private ?array $retryFiles = null;

    /** @var BackendSection::WHOLE_FILES|BackendSection::RETRY|BackendSection::NARROWED */
    private string $selection = BackendSection::NARROWED;

    /** @var array<string, mixed> */
    private array $config = [];

    public function __construct(private readonly OutputInterface $output) {}

    public function handleArguments(array $arguments): array
    {
        $root = TestSuite::getInstance()->rootPath;
        XdebugFilter::apply($root, $arguments);

        if (Parallel::isWorker()) {
            if (Parallel::getGlobal(self::NO_BROWSER_GLOBAL) === true) {
                $this->excludeBrowserSpecs($root);
            }

            return $arguments;
        }

        $withoutBrowser = $this->hasArgument(self::NO_BROWSER_OPTION, $arguments);
        $retry = $this->hasArgument(self::RETRY_OPTION, $arguments);
        $arguments = $this->popArgument(self::RETRY_OPTION, $this->popArgument(self::NO_BROWSER_OPTION, $arguments));

        if ($withoutBrowser) {
            Parallel::setGlobal(self::NO_BROWSER_GLOBAL, true);
            $this->excludeBrowserSpecs($root);
        }

        if (self::insideAnotherRun() || array_any(self::PASSIVE_OPTIONS, fn (string $option): bool => $this->hasArgument($option, $arguments))) {
            return $arguments;
        }

        // Pest processes a test starts inherit this, and leave this run's report alone.
        $_ENV[self::RUN_ENV] = '1';

        $this->config = ToolkitConfig::load($root);
        /** @var array<int, string> $argv */
        $argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
        $this->command = mb_rtrim('vendor/bin/pest '.implode(' ', array_slice($argv, 1)));
        $report = $this->report($root);

        $this->selection = match (true) {
            $retry => BackendSection::RETRY,
            array_any([...Preflight::PARTIAL_SELECTION_FLAGS, ...self::PARTIAL_RUN_OPTIONS], fn (string $flag): bool => $this->hasArgument($flag, $arguments)) => BackendSection::NARROWED,
            default => BackendSection::WHOLE_FILES,
        };

        if ($retry) {
            $arguments = $this->retryArguments($report, $arguments);
        }

        $arguments = $this->withJUnit($root, $arguments);

        if (! $withoutBrowser && $this->couldRunBrowserSpecs($root, $arguments) && ! $this->ensureAssets($root)) {
            $report->put(FailureReport::BACKEND, BackendSection::notice($this->command, 'The frontend build failed, so no test ran: Browser specs would have rendered a stale bundle. The build output is in the terminal.'));
            exit(1);
        }

        $this->startedAt = time();

        return $arguments;
    }

    public function addOutput(int $exitCode): int
    {
        if ($this->junitFile === null || Parallel::isWorker()) {
            return $exitCode;
        }

        $root = TestSuite::getInstance()->rootPath;
        $report = $this->report($root);
        $earlier = $report->section(FailureReport::BACKEND);

        /** @var array<int, array{file: string, class: string, name: string, line: int, type: string, message: string}> $earlierFailures */
        $earlierFailures = is_array($earlier['failures'] ?? null) ? $earlier['failures'] : [];
        $earlierFailures = array_values(array_filter($earlierFailures, fn (array $failure): bool => is_file($this->absolute($root, $failure['file']))));
        $section = BackendSection::from($this->junitFile, $this->startedAt, $exitCode, $this->command, $earlierFailures, $this->selection);
        $report->put(FailureReport::BACKEND, $section);

        if ($section === null) {
            return $exitCode;
        }

        $this->output->writeln(['', sprintf('  <fg=red>Failure report:</> %s', ToolkitConfig::path($this->config, 'report_file', 'storage/logs/test-failures.md'))]);

        /** @var array<int, array{file: string, class: string, name: string}> $failures */
        $failures = $section['failures'];

        if ($failures !== []) {
            $this->output->writeln('  Re-run the failed tests: <info>composer test:retry</info> (backend only: <info>vendor/bin/pest --parallel --toolkit-retry</info>)');

            foreach (array_slice($failures, 0, 5) as $failure) {
                $this->output->writeln('  <info>'.RetryFilter::command($failure['file'], $failure['class'], $failure['name']).'</info>');
            }
        }

        $this->output->writeln('');
        $this->notifier()->notify('Tests', $failures === [] ? 'The run failed — check the report ✗' : sprintf('%d test(s) failed ✗', count($failures)), 'Basso');

        return $exitCode;
    }

    private static function insideAnotherRun(): bool
    {
        return ($_SERVER[self::RUN_ENV] ?? $_ENV[self::RUN_ENV] ?? getenv(self::RUN_ENV)) === '1';
    }

    private function excludeBrowserSpecs(string $root): void
    {
        TestSuite::getInstance()->tests->addTestCaseFilter(new WithoutBrowserSpecs(BrowserSuite::in($root)));
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    private function retryArguments(FailureReport $report, array $arguments): array
    {
        $section = $report->section(FailureReport::BACKEND);

        /** @var array<int, array{file: string, class: string, name: string}> $failures */
        $failures = is_array($section['failures'] ?? null) ? $section['failures'] : [];

        // A failed test whose file is gone can't be re-run: naming the file would stop Pest
        // before any test ran, and leave a crash notice in the report.
        $root = TestSuite::getInstance()->rootPath;
        $recorded = $failures;
        $failures = array_values(array_filter($failures, fn (array $failure): bool => is_file($this->absolute($root, $failure['file']))));

        if ($failures === [] && $recorded !== []) {
            $report->put(FailureReport::BACKEND, null);
        }

        if ($failures === []) {
            $this->output->writeln(['', '  No failed backend tests in the failure report: nothing to retry.', '']);

            exit(0);
        }

        $this->output->writeln(['', sprintf('  Retrying %d failed test(s) from the failure report.', count($failures))]);

        $this->retryFiles = array_values(array_unique(array_column($failures, 'file')));
        $path = self::enclosingPath($this->retryFiles);

        return [...$arguments, ...($path === null ? [] : [$path]), '--filter='.RetryFilter::pattern($failures)];
    }

    /**
     * One path that holds every failed file: the file itself, or their closest common
     * directory. Paratest takes a single path, so a retry can't name one per file; the
     * anchored filter picks the exact tests, and the path only keeps Pest from loading
     * test files that hold none of them. Null when the files share no directory.
     *
     * @param  array<int, string>  $files
     */
    private static function enclosingPath(array $files): ?string
    {
        if (count($files) === 1) {
            return $files[0];
        }

        $common = explode('/', dirname($files[0]));

        foreach (array_slice($files, 1) as $file) {
            $segments = explode('/', dirname($file));
            $length = 0;

            while ($length < count($common) && $length < count($segments) && $common[$length] === $segments[$length]) {
                $length++;
            }

            $common = array_slice($common, 0, $length);
        }

        $path = implode('/', $common);

        return in_array($path, ['', '.'], true) ? null : $path;
    }

    /**
     * Makes sure the run writes a JUnit file, and that the one read at the end is this
     * run's: any file left from an earlier run is removed first.
     *
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    private function withJUnit(string $root, array $arguments): array
    {
        foreach ($arguments as $index => $argument) {
            if ($argument === '--log-junit' && isset($arguments[$index + 1])) {
                $this->junitFile = $this->absolute($root, $arguments[$index + 1]);
            } elseif (str_starts_with($argument, '--log-junit=')) {
                $this->junitFile = $this->absolute($root, mb_substr($argument, mb_strlen('--log-junit=')));
            }
        }

        if ($this->junitFile === null) {
            $this->junitFile = $this->absolute($root, ToolkitConfig::path($this->config, 'xml_file', 'storage/logs/test-results.xml'));
            $arguments[] = '--log-junit='.$this->junitFile;
        }

        if (! is_dir(dirname($this->junitFile))) {
            mkdir(dirname($this->junitFile), 0755, true);
        }

        @unlink($this->junitFile);

        return $arguments;
    }

    /**
     * Whether a Browser spec could be selected. A suite selection or explicit paths can
     * rule them out; a filter, or TIA's choice, cannot be known in advance.
     *
     * @param  array<int, string>  $arguments
     */
    private function couldRunBrowserSpecs(string $root, array $arguments): bool
    {
        if (! class_exists(BrowserPlugin::class)) {
            return false;
        }

        $suite = BrowserSuite::in($root);

        // A retry names a directory that may hold Browser specs it won't run.
        if ($this->retryFiles !== null) {
            return $suite->directories === [] || array_any($this->retryFiles, fn (string $file): bool => $suite->contains($this->absolute($root, $file)));
        }

        foreach ($arguments as $index => $argument) {
            [$option, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, $arguments[$index + 1] ?? ''];

            $names = array_map(mb_strtolower(...), array_map(mb_trim(...), explode(',', $value)));

            if ($option === '--testsuite' && ! in_array(mb_strtolower(BrowserSuite::NAME), $names, true)) {
                return false;
            }

            if ($option === '--exclude-testsuite' && in_array(mb_strtolower(BrowserSuite::NAME), $names, true)) {
                return false;
            }
        }

        $paths = array_values(array_filter(
            array_slice($arguments, 1),
            fn (string $argument): bool => ! str_starts_with($argument, '-') && file_exists($this->absolute($root, explode('::', $argument)[0])),
        ));

        if ($paths === [] || $suite->directories === []) {
            return true;
        }

        return array_any($paths, function (string $path) use ($root, $suite): bool {
            $absolute = realpath($this->absolute($root, $path)) ?: $path;

            return $suite->contains($absolute)
                || array_any($suite->directories, fn (string $directory): bool => str_starts_with($directory.DIRECTORY_SEPARATOR, mb_rtrim($absolute, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
        });
    }

    private function ensureAssets(string $root): bool
    {
        /** @var array{command?: string, timeout?: int, stamp_file?: string, manifest_file?: string, watch_paths?: string, hot_file?: string} $config */
        $config = is_array($this->config['build'] ?? null) ? $this->config['build'] : [];
        $build = new AssetBuild($root, $config, new Filesystem, new Factory);
        $forced = in_array(getenv(self::FORCE_BUILD_ENV), ['1', 'true'], true);

        if (! $forced && ! $build->isStale()) {
            return true;
        }

        $this->output->writeln(sprintf('  <comment>%s — building the frontend assets Browser specs render...</comment>', $forced ? self::FORCE_BUILD_ENV : 'Frontend sources changed'));

        if ($build->build(fn (string $buffer) => $this->output->write($buffer))) {
            $this->output->writeln('  <info>✓ Frontend build succeeded</info>');

            return true;
        }

        $this->output->writeln('  <error>✗ Frontend build failed. Fix the build errors above; Browser specs would render a stale bundle.</error>');

        return false;
    }

    private function report(string $root): FailureReport
    {
        return new FailureReport(
            $this->absolute($root, ToolkitConfig::path($this->config, 'json_file', 'storage/logs/test-failures.json')),
            $this->absolute($root, ToolkitConfig::path($this->config, 'report_file', 'storage/logs/test-failures.md')),
        );
    }

    private function notifier(): Notifier
    {
        return new Notifier(new Factory, ToolkitConfig::notificationsEnabled($this->config) && getenv(self::PREFLIGHT_ENV) === false && ! AgentDetector::detect()->isAgent);
    }

    private function absolute(string $root, string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $root.DIRECTORY_SEPARATOR.$path;
    }
}
