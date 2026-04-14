<?php

declare(strict_types=1);

namespace Nwrman\Toolkit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Nwrman\Toolkit\Concerns\SendsNativeNotifications;
use Override;

final class TestRetryCommand extends Command
{
    use SendsNativeNotifications;

    #[Override]
    protected $signature = 'test:retry
        {--no-notify : Skip macOS notification}';

    #[Override]
    protected $description = 'Re-run only the previously failed tests';

    private string $reportFile = 'storage/logs/test-failures.md';

    private string $jsonFile = 'storage/logs/test-failures.json';

    public function handle(): int
    {
        if (! File::exists($this->jsonFile)) {
            $this->info('No failed tests to retry. Run `php artisan test:report` first.');

            return 0;
        }

        $data = json_decode(File::get($this->jsonFile), true);

        if (! is_array($data)) {
            $this->error('Failed to parse '.$this->jsonFile);

            return 1;
        }

        /** @var array<int, string> $backendFailures */
        $backendFailures = is_array($data['backend_failures'] ?? null) ? $data['backend_failures'] : [];
        $frontendFailed = $data['frontend_failed'] ?? false;

        if (empty($backendFailures) && ! $frontendFailed) {
            $this->info('No failed tests inside the report format.');
            File::delete([$this->reportFile, $this->jsonFile]);

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
            File::delete([$this->reportFile, $this->jsonFile]);
            $this->notify($this->notificationTitle(), 'All tests passed! ✓', 'Glass');

            return 0;
        }

        $this->error('✗ Some tests are still failing.');
        $this->line('<comment>Run `php artisan test:report` again to generate a fresh report.</comment>');
        $this->notify($this->notificationTitle(), 'Some tests are still failing ✗', 'Basso');

        return 1;
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
        $this->line('<comment>Running Frontend Tests...</comment>');

        /** @var string $testCommand */
        $testCommand = config('toolkit.frontend_test_command', 'bun run test:ui');

        return Process::forever()
            ->start($testCommand, function (string $type, string $output): void {
                $this->output->write($output);
            })->wait()->exitCode() ?? 1;
    }
}
