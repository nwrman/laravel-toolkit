<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Nwrman\LaravelToolkit\Concerns\ManagesFrontendBuild;
use Nwrman\LaravelToolkit\Concerns\SendsDesktopNotifications;
use Override;
use SimpleXMLElement;
use Throwable;

use function Laravel\Prompts\multiselect;

final class TestReportCommand extends Command
{
    use ManagesFrontendBuild;
    use SendsDesktopNotifications;

    #[Override]
    protected $signature = 'toolkit:report
        {--force-build : Force frontend rebuild}
        {--suite= : Run specific suites (comma-separated: unit,feature,browser,frontend)}
        {--no-notify : Skip macOS notification}';

    #[Override]
    protected $description = 'Run tests and generate a failure report';

    public function handle(): int
    {
        $reportDir = $this->configPath('report_dir');
        File::ensureDirectoryExists($reportDir);

        $suitesToRun = $this->determineSuites();

        if ($suitesToRun === []) {
            $this->warn('No suites selected. Exiting.');

            return 0;
        }

        if (in_array('browser', $suitesToRun, true) && ! $this->ensureFrontendBuild()) {
            $this->notify($this->notifyTitle(), 'Frontend build failed ✗', 'Basso');

            return 1;
        }

        $this->info('Running tests for suites: '.implode(', ', $suitesToRun));
        $this->newLine();

        $startTime = microtime(true);
        $backendExitCode = 0;
        $frontendExitCode = 0;
        $frontendOutput = '';

        $backendSuites = array_intersect($suitesToRun, ['unit', 'feature', 'browser']);
        if ($backendSuites !== []) {
            $backendExitCode = $this->runBackendTests($backendSuites);
        }

        if (in_array('frontend', $suitesToRun, true)) {
            $result = $this->runFrontendTests();
            $frontendExitCode = $result['exitCode'];
            $frontendOutput = $result['output'];
        }

        $duration = microtime(true) - $startTime;

        $hasFailures = $backendExitCode !== 0 || $frontendExitCode !== 0;

        if (! $hasFailures) {
            $this->info('✓ All tests passed!');
            File::delete([$this->configPath('report_file'), $this->configPath('json_file')]);
            $this->notify($this->notifyTitle(), 'All tests passed! ✓', 'Glass');

            return 0;
        }

        $this->error('✗ Some tests failed. Generating report...');
        $this->generateReport($backendSuites, $frontendExitCode !== 0, $frontendOutput, $suitesToRun, $duration);

        return 1;
    }

    /**
     * @return array<string, string>
     */
    private function suites(): array
    {
        /** @var array<string, string> $suites */
        $suites = config('toolkit.suites', []);

        return $suites;
    }

    private function configPath(string $key): string
    {
        /** @var string $path */
        $path = config('toolkit.paths.'.$key, '');

        return $path;
    }

    private function notifyTitle(): string
    {
        /** @var string $appName */
        $appName = config('app.name', 'Laravel');

        return $appName.' Tests';
    }

    /**
     * @return array<int, string>
     */
    private function determineSuites(): array
    {
        $suites = $this->suites();

        if ($this->option('suite')) {
            $suiteStr = mb_strtolower(mb_trim((string) $this->option('suite')));

            return array_filter(array_map(trim(...), explode(',', $suiteStr)));
        }

        if (! $this->input->isInteractive()) {
            return array_keys($suites);
        }

        return array_map(strval(...), array_values(multiselect(
            label: 'Which test suites do you want to run?',
            options: $suites,
            default: array_keys($suites)
        )));
    }

    /**
     * @param  array<int, string>  $suites
     */
    private function runBackendTests(array $suites): int
    {
        $suiteMap = $this->suites();
        $suiteNames = array_map(fn (string $s): string => $suiteMap[$s] ?? $s, $suites);
        $suiteArg = implode(',', $suiteNames);

        $xmlFile = $this->configPath('xml_file');
        $cmd = sprintf('pest --testsuite=%s --ci --parallel --log-junit %s', $suiteArg, $xmlFile);

        $this->line(sprintf('<comment>Running Backend Tests (%s)...</comment>', $suiteArg));

        return Process::forever()->env([
            'XDEBUG_MODE' => 'off',
        ])->start($cmd, function (string $type, string $output): void {
            $this->output->write($output);
        })->wait()->exitCode() ?? 1;
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    private function runFrontendTests(): array
    {
        $this->line('<comment>Running Frontend Tests (Vitest)...</comment>');

        /** @var string $frontendCommand */
        $frontendCommand = config('toolkit.frontend_test_command', 'bun run test:ui');

        $output = '';

        $exitCode = Process::forever()
            ->start($frontendCommand, function (string $type, string $data) use (&$output): void {
                $output .= $data;
                $this->output->write($data);
            })->wait()->exitCode();

        return ['exitCode' => $exitCode ?? 1, 'output' => $output];
    }

    /**
     * @param  array<int, string>  $backendSuites
     * @param  array<int, string>  $suitesRun
     */
    private function generateReport(array $backendSuites, bool $frontendFailed, string $frontendOutput, array $suitesRun, float $duration): void
    {
        $suiteMap = $this->suites();
        $timestamp = date('Y-m-d H:i:s');
        $suiteNames = implode(', ', array_map(fn (string $s): string => $suiteMap[$s] ?? $s, $suitesRun));
        $formattedDuration = number_format($duration, 1).'s';

        $backendFailures = [];
        $totalTests = 0;
        $failedCount = 0;

        $reportContent = "# Test Failure Report\n\n";
        $reportContent .= sprintf('**Generated:** %s%s', $timestamp, PHP_EOL);
        $reportContent .= "**Suites:** {$suiteNames} | **Duration:** {$formattedDuration}\n\n";
        $reportContent .= "---\n\n";
        $reportContent .= "## Failed Tests\n\n";

        $xmlFile = $this->configPath('xml_file');

        if ($backendSuites !== []) {
            if (! File::exists($xmlFile)) {
                $reportContent .= "> [!WARNING]\n> Backend test results XML not found. Pest might have crashed.\n\n";
            } else {
                try {
                    $xml = simplexml_load_file($xmlFile);
                    if ($xml !== false) {
                        $this->parseBackendTestSuites($xml, $backendFailures, $reportContent, $totalTests, $failedCount);
                    }
                } catch (Throwable) {
                    $reportContent .= "> [!WARNING]\n> Could not parse Backend test results XML.\n\n";
                }
            }
        }

        if ($frontendFailed) {
            $failedCount++;
            $reportContent .= "### Frontend (Vitest) Failure\n\n";
            $reportContent .= "**Error Output:**\n```\n";
            $cleanOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $frontendOutput);
            $lines = explode("\n", mb_trim((string) $cleanOutput));
            $tailLines = array_slice($lines, -50);
            $reportContent .= implode("\n", $tailLines)."\n";
            $reportContent .= "```\n\n---\n\n";
        }

        if ($failedCount === 0) {
            $reportContent .= "_No explicit failures found in output logs (check terminal for details)_\n";
        } else {
            $reportContent .= "## Quick Commands\n\n";
            $reportContent .= "Re-run all failed tests:\n```bash\nphp artisan toolkit:retry\n```\n\n";

            if (count($backendFailures) > 1) {
                $reportContent .= "Re-run individually:\n```bash\n";
                foreach ($backendFailures as $test) {
                    $reportContent .= sprintf("pest --filter='%s'\n", $test);
                }

                $reportContent .= "```\n";
            }
        }

        $reportContent = str_replace(
            '| **Duration:** '.$formattedDuration,
            sprintf('| **Total:** %d | **Failed:** %d | **Duration:** %s', $totalTests, $failedCount, $formattedDuration),
            $reportContent
        );

        $reportFile = $this->configPath('report_file');
        $jsonFile = $this->configPath('json_file');

        File::put($reportFile, $reportContent);

        $jsonData = [
            'timestamp' => date('c'),
            'suites_run' => $suitesRun,
            'backend_failures' => $backendFailures,
            'frontend_failed' => $frontendFailed,
        ];
        $jsonEncoded = json_encode($jsonData, JSON_PRETTY_PRINT);
        File::put($jsonFile, is_string($jsonEncoded) ? $jsonEncoded : '{}');

        $this->line('<comment>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</comment>');
        $this->line(sprintf('<error>  %d test(s) failed</error>', $failedCount));
        $this->line('<comment>  Report saved to:</comment> '.$reportFile);

        if ($failedCount > 0 && $failedCount < 5) {
            $this->newLine();
            $this->line('<comment>  Quick re-run commands:</comment>');
            $this->line('<info>    php artisan toolkit:retry</info>');
            foreach ($backendFailures as $test) {
                $this->line(sprintf("<info>    pest --filter='%s'</info>", $test));
            }
        }

        $this->line('<comment>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</comment>');

        $this->notify($this->notifyTitle(), $failedCount.' test(s) failed — check report ✗', 'Basso');
    }

    /**
     * @param  array<int, string>  $backendFailures
     */
    private function parseBackendTestSuites(SimpleXMLElement $suite, array &$backendFailures, string &$reportContent, int &$totalTests, int &$failedCount): void
    {
        if (isset($suite['tests'])) {
            $totalTests += (int) $suite['tests'];
        }

        foreach ($suite->testcase ?? [] as $testcase) {
            if (! isset($suite['tests'])) {
                $totalTests++;
            }

            $errorNode = $testcase->failure ?? $testcase->error ?? null;
            if ($errorNode !== null) {
                $failedCount++;
                $name = (string) $testcase['name'];
                $class = (string) $testcase['class'];
                $file = (string) $testcase['file'];
                $line = (string) $testcase['line'];
                $message = (string) $errorNode;
                $type = property_exists($testcase, 'failure') && $testcase->failure !== null ? 'Failure' : 'Error';
                $errorType = (string) ($errorNode['type'] ?? '');

                $backendFailures[] = $name;

                $reportContent .= "### {$failedCount}. `{$name}`\n\n";
                $reportContent .= "- **Class:** `{$class}`\n";
                if ($file !== '') {
                    $reportContent .= '- **File:** `'.$file.($line !== '' ? ':'.$line : '')."`\n";
                }

                $reportContent .= '- **Type:** '.$type.($errorType !== '' ? sprintf(' (`%s`)', $errorType) : '')."\n\n";
                $reportContent .= "**Error:**\n```\n".mb_trim($message)."\n```\n\n---\n\n";
            }
        }

        foreach ($suite->testsuite ?? [] as $nestedSuite) {
            $this->parseBackendTestSuites($nestedSuite, $backendFailures, $reportContent, $totalTests, $failedCount);
        }
    }
}
