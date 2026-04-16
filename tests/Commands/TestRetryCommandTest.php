<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/test-retry-'.uniqid();
    File::ensureDirectoryExists($this->tempDir);

    config([
        'toolkit.paths.report_file' => $this->tempDir.'/test-failures.md',
        'toolkit.paths.json_file' => $this->tempDir.'/test-failures.json',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->tempDir);
});

it('displays message if no report exists', function (): void {
    $this->artisan('toolkit:retry')
        ->expectsOutput('No failed tests to retry. Run `php artisan toolkit:report` first.')
        ->assertExitCode(0);
});

it('retries failed backend tests from report', function (): void {
    Process::fake([
        'pest *' => Process::result(output: 'Backend output', exitCode: 0),
        'command -v terminal-notifier' => Process::result(exitCode: 1),
        'afplay *' => Process::result(exitCode: 0),
    ]);

    $data = [
        'suites_run' => ['unit'],
        'backend_failures' => ['it fails something', 'it also fails this'],
        'frontend_failed' => false,
    ];

    File::put($this->tempDir.'/test-failures.json', json_encode($data));
    File::put($this->tempDir.'/test-failures.md', '# Dummy Markdown Content');

    $this->artisan('toolkit:retry')
        ->expectsOutput('Retrying failed tests...')
        ->expectsOutput('✓ All retried tests passed!')
        ->assertExitCode(0);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "pest --filter='it fails something|it also fails this'"));

    expect(File::exists($this->tempDir.'/test-failures.json'))->toBeFalse()
        ->and(File::exists($this->tempDir.'/test-failures.md'))->toBeFalse();
});

it('leaves report if retry still fails', function (): void {
    Process::fake([
        'pest *' => Process::result(output: 'Backend error output', exitCode: 1),
        'command -v terminal-notifier' => Process::result(exitCode: 1),
        'afplay *' => Process::result(exitCode: 0),
    ]);

    $data = [
        'suites_run' => ['unit'],
        'backend_failures' => ['it fails something'],
        'frontend_failed' => false,
    ];

    File::put($this->tempDir.'/test-failures.json', json_encode($data));
    File::put($this->tempDir.'/test-failures.md', '# Dummy');

    $this->artisan('toolkit:retry')
        ->expectsOutput('✗ Some tests are still failing.')
        ->assertExitCode(1);

    expect(File::exists($this->tempDir.'/test-failures.json'))->toBeTrue();
});

it('handles invalid json report file', function (): void {
    File::put($this->tempDir.'/test-failures.json', 'invalid-json');

    $this->artisan('toolkit:retry')
        ->expectsOutput('Failed to parse '.$this->tempDir.'/test-failures.json')
        ->assertExitCode(1);
});

it('displays message if report has no valid failures', function (): void {
    $data = [
        'suites_run' => ['unit'],
        'backend_failures' => [],
        'frontend_failed' => false,
    ];

    File::put($this->tempDir.'/test-failures.json', json_encode($data));

    $this->artisan('toolkit:retry')
        ->expectsOutput('No failed tests inside the report format.')
        ->assertExitCode(0);
});

it('retries frontend failure', function (): void {
    Process::fake([
        'bun run test:ui' => Process::result(output: 'Frontend passed now', exitCode: 0),
        'command -v terminal-notifier' => Process::result(exitCode: 0),
        'terminal-notifier *' => Process::result(exitCode: 0),
    ]);

    $data = [
        'suites_run' => ['frontend'],
        'backend_failures' => [],
        'frontend_failed' => true,
    ];

    File::put($this->tempDir.'/test-failures.json', json_encode($data));
    File::put($this->tempDir.'/test-failures.md', '# Dummy');

    $this->artisan('toolkit:retry')->assertExitCode(0);

    Process::assertRan('bun run test:ui');
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'terminal-notifier'));
});
