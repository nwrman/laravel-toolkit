<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Nwrman\LaravelToolkit\Concerns\SendsDesktopNotifications;
use Override;

final class TestRetryCommand extends Command
{
    use SendsDesktopNotifications;

    #[Override]
    protected $signature = 'toolkit:retry';

    #[Override]
    protected $description = 'Re-run only the previously failed tests';

    public function handle(): int
    {
        $jsonFile = $this->configPath('json_file');
        $reportFile = $this->configPath('report_file');

        if (! File::exists($jsonFile)) {
            $this->info('No failed tests to retry. Run `php artisan toolkit:report` first.');

            return 0;
        }

        $data = json_decode(File::get($jsonFile), true);

        if (! is_array($data)) {
            $this->error('Failed to parse '.$jsonFile);

            return 1;
        }

        /** @var array<int, string> $backendFailures */
        $backendFailures = is_array($data['backend_failures'] ?? null) ? $data['backend_failures'] : [];
        $frontendFailed = $data['frontend_failed'] ?? false;

        if (empty($backendFailures) && ! $frontendFailed) {
            $this->info('No failed tests inside the report format.');
            File::delete([$reportFile, $jsonFile]);

            return 0;
        }

        $this->info('Retrying failed tests...');
        $this->newLine();

        $backendExitCode = 0;
        $frontendExitCode = 0;

        if (! empty($backendFailures)) {
            $backendExitCode = $this->runBackendRetry($backendFailures);
        }

        if ($frontendFailed) {
            $this->newLine();
            $frontendExitCode = $this->runFrontendRetry();
        }

        if ($backendExitCode === 0 && $frontendExitCode === 0) {
            $this->info('✓ All retried tests passed!');
            File::delete([$reportFile, $jsonFile]);
            $this->notify($this->notifyTitle(), 'All tests passed! ✓', 'Glass');

            return 0;
        }

        $this->error('✗ Some tests are still failing.');
        $this->line('<comment>Run `php artisan toolkit:report` again to generate a fresh report.</comment>');
        $this->notify($this->notifyTitle(), 'Some tests are still failing ✗', 'Basso');

        return 1;
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
     * @param  array<int, string>  $backendFailures
     */
    private function runBackendRetry(array $backendFailures): int
    {
        $filter = implode('|', array_map(addslashes(...), $backendFailures));

        $this->line('<comment>Running Backend Tests (Filtered)...</comment>');

        $cmd = sprintf("pest --filter='%s'", $filter);

        return Process::forever()->env([
            'XDEBUG_MODE' => 'off',
        ])->start($cmd, function (string $type, string $output): void {
            $this->output->write($output);
        })->wait()->exitCode() ?? 1;
    }

    private function runFrontendRetry(): int
    {
        $this->line('<comment>Running Frontend Tests (Vitest)...</comment>');

        /** @var string $frontendCommand */
        $frontendCommand = config('toolkit.frontend_test_command', 'bun run test:ui');

        return Process::forever()
            ->start($frontendCommand, function (string $type, string $output): void {
                $this->output->write($output);
            })->wait()->exitCode() ?? 1;
    }
}
