<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Nwrman\LaravelToolkit\Commands\PreflightCommand;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/preflight-'.uniqid();
    File::ensureDirectoryExists($this->tempDir);

    config(['toolkit.paths.preflight_state' => $this->tempDir.'/preflight-result.json']);
});

afterEach(function (): void {
    File::deleteDirectory($this->tempDir);
});

/**
 * Temporarily clear agent-detection env vars so isAgent=false,
 * execute the callback, then restore original values.
 */
function withoutAgentDetection(Closure $callback): void
{
    $agentEnvVars = ['AI_AGENT', 'OPENCODE_CLIENT', 'OPENCODE', 'CLAUDECODE', 'CLAUDE_CODE', 'CURSOR_AGENT'];
    $saved = [];

    foreach ($agentEnvVars as $var) {
        $val = getenv($var);

        if ($val !== false) {
            $saved[$var] = $val;
            putenv($var);
        }
    }

    try {
        $callback();
    } finally {
        foreach ($saved as $var => $val) {
            putenv(sprintf('%s=%s', $var, $val));
        }
    }
}

it('blocks execution when prohibited', function (): void {
    PreflightCommand::prohibit();

    $this->artisan('toolkit:preflight', ['--no-notify' => true])
        ->expectsOutputToContain('prohibited')
        ->assertExitCode(1);

    PreflightCommand::prohibit(false);
});

it('runs all gates and succeeds when all pass', function (): void {
    Process::fake([
        'find *' => Process::result(output: '', exitCode: 0),
        'vendor/bin/pest --parallel --coverage*' => Process::result(output: 'Pest OK', exitCode: 0),
        'bun run test:coverage' => Process::result(output: 'Vitest OK', exitCode: 0),
        'vendor/bin/pint*' => Process::result(output: 'Pint OK', exitCode: 0),
        'vendor/bin/pest --type-coverage*' => Process::result(output: 'Types OK', exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--no-notify' => true])
        ->expectsOutputToContain('Running 4 gates in parallel')
        ->expectsOutputToContain('✓ All gates passed!')
        ->assertExitCode(0);

    expect(File::exists($this->tempDir.'/preflight-result.json'))->toBeFalse();
});

it('reports failure when a gate fails and saves state', function (): void {
    Process::fake([
        'find *' => Process::result(output: '', exitCode: 0),
        'vendor/bin/pest --parallel --coverage*' => Process::result(output: 'Pest OK', exitCode: 0),
        'bun run test:coverage' => Process::result(output: 'Vitest OK', exitCode: 0),
        'vendor/bin/pint*' => Process::result(output: 'Pint failed: formatting issues', exitCode: 1),
        'vendor/bin/pest --type-coverage*' => Process::result(output: 'Types OK', exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--no-notify' => true])
        ->expectsOutputToContain('Retry failed gates')
        ->assertExitCode(1);

    expect(File::exists($this->tempDir.'/preflight-result.json'))->toBeTrue();

    $state = json_decode(File::get($this->tempDir.'/preflight-result.json'), true);
    expect($state['gates']['lint']['status'])->toBe('fail')
        ->and($state['gates']['pest-coverage']['status'])->toBe('pass');
});

it('retries only failed gates from state file', function (): void {
    $state = [
        'timestamp' => date('c'),
        'duration' => 18.4,
        'gates' => [
            'pest-coverage' => ['status' => 'pass', 'duration' => 18.4],
            'frontend-coverage' => ['status' => 'pass', 'duration' => 8.1],
            'lint' => ['status' => 'fail', 'duration' => 6.7],
            'types' => ['status' => 'pass', 'duration' => 9.3],
        ],
    ];

    File::put($this->tempDir.'/preflight-result.json', (string) json_encode($state, JSON_PRETTY_PRINT));

    Process::fake([
        'vendor/bin/pint*' => Process::result(output: 'Pint OK', exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--retry' => true, '--no-notify' => true])
        ->expectsOutputToContain('Retrying 1 failed gate(s): lint')
        ->expectsOutputToContain('Running 1 gate in parallel')
        ->expectsOutputToContain('✓ All gates passed!')
        ->assertExitCode(0);
});

it('runs all gates when --retry has no state file', function (): void {
    Process::fake([
        'find *' => Process::result(output: '', exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--retry' => true, '--no-notify' => true])
        ->expectsOutputToContain('No previous preflight results found. Running all gates.')
        ->assertExitCode(0);
});

it('runs all gates when --retry state file has no failures', function (): void {
    $state = [
        'timestamp' => date('c'),
        'duration' => 18.4,
        'gates' => [
            'pest-coverage' => ['status' => 'pass', 'duration' => 18.4],
            'lint' => ['status' => 'pass', 'duration' => 6.7],
        ],
    ];

    File::put($this->tempDir.'/preflight-result.json', (string) json_encode($state, JSON_PRETTY_PRINT));

    $this->artisan('toolkit:preflight', ['--retry' => true, '--no-notify' => true])
        ->expectsOutputToContain('No failed gates to retry')
        ->assertExitCode(0);
});

it('runs only specified gates with --gate', function (): void {
    Process::fake([
        'vendor/bin/pint*' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--gate' => ['lint'], '--no-notify' => true])
        ->expectsOutputToContain('Running 1 gate in parallel')
        ->assertExitCode(0);
});

it('rejects invalid gate names', function (): void {
    $this->artisan('toolkit:preflight', ['--gate' => ['invalid-gate'], '--no-notify' => true])
        ->expectsOutputToContain('Invalid gate(s): invalid-gate')
        ->assertExitCode(0);
});

it('triggers frontend build when --force-build is specified', function (): void {
    Process::fake([
        'vp build' => Process::result(output: 'Build OK', exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--gate' => ['pest-coverage'], '--force-build' => true, '--no-notify' => true])
        ->expectsOutputToContain('rebuilding frontend assets...')
        ->assertExitCode(0);

    Process::assertRan('vp build');
});

it('aborts when frontend build fails', function (): void {
    Process::fake([
        'vp build' => Process::result(errorOutput: 'Build failed', exitCode: 1),
    ]);

    $this->artisan('toolkit:preflight', ['--gate' => ['pest-coverage'], '--force-build' => true, '--no-notify' => true])
        ->expectsOutputToContain('Frontend build failed')
        ->assertExitCode(1);
});

it('skips build check when no gate requires build', function (): void {
    Process::fake([
        'vendor/bin/pint*' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--gate' => ['lint'], '--no-notify' => true])
        ->assertExitCode(0);

    Process::assertNotRan('vp build');
});

it('skips notification when --no-notify is used', function (): void {
    Process::fake([
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--gate' => ['lint'], '--no-notify' => true])
        ->assertExitCode(0);

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'terminal-notifier'));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'afplay'));
});

it('triggers notification on success when --no-notify is not used', function (): void {
    withoutAgentDetection(function (): void {
        Process::fake([
            'vendor/bin/pint*' => Process::result(exitCode: 0),
            'command -v terminal-notifier' => Process::result(exitCode: 0),
            'terminal-notifier *' => Process::result(exitCode: 0),
            '*' => Process::result(exitCode: 0),
        ]);

        $this->artisan('toolkit:preflight', ['--gate' => ['lint']])
            ->assertExitCode(0);

        Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'terminal-notifier'));
    });
});

it('runs multiple specified gates', function (): void {
    Process::fake([
        'vendor/bin/pint*' => Process::result(exitCode: 0),
        'vendor/bin/phpstan*' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--gate' => ['lint', 'types'], '--no-notify' => true])
        ->expectsOutputToContain('Running 2 gates in parallel')
        ->assertExitCode(0);
});

it('triggers failure notification when --no-notify is not used', function (): void {
    withoutAgentDetection(function (): void {
        Process::fake([
            'vendor/bin/pint*' => Process::result(exitCode: 1),
            'command -v terminal-notifier' => Process::result(exitCode: 0),
            'terminal-notifier *' => Process::result(exitCode: 0),
            '*' => Process::result(exitCode: 0),
        ]);

        $this->artisan('toolkit:preflight', ['--gate' => ['lint']])
            ->assertExitCode(1);

        Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'terminal-notifier'));
    });
});

it('falls back to afplay when terminal-notifier is not available', function (): void {
    withoutAgentDetection(function (): void {
        Process::fake([
            'vendor/bin/pint*' => Process::result(exitCode: 0),
            'command -v terminal-notifier' => Process::result(exitCode: 1),
            'afplay *' => Process::result(exitCode: 0),
            '*' => Process::result(exitCode: 0),
        ]);

        $this->artisan('toolkit:preflight', ['--gate' => ['lint']])
            ->assertExitCode(0);

        Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'afplay'));
        Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'terminal-notifier -message'));
    });
});

it('falls back to all gates when state file contains invalid JSON', function (): void {
    File::put($this->tempDir.'/preflight-result.json', 'not-valid-json');

    Process::fake([
        'find *' => Process::result(output: '', exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:preflight', ['--retry' => true, '--no-notify' => true])
        ->expectsOutputToContain('Could not parse preflight state')
        ->assertExitCode(0);
});
