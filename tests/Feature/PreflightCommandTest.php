<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Nwrman\Toolkit\Commands\PreflightCommand;

beforeEach(function () {
    File::delete(storage_path('logs/preflight-result.json'));
});

it('registers the test:preflight command', function () {
    $this->artisan('list')
        ->assertSuccessful()
        ->expectsOutputToContain('test:preflight');
});

it('runs all gates in parallel and passes when all succeed', function () {
    config(['toolkit.gates' => [
        'fast-pass' => [
            'label' => 'Fast Pass',
            'command' => 'echo "ok"',
        ],
    ]]);

    // Ensure no build check is triggered (no pest-coverage gate)
    $this->artisan('test:preflight', ['--no-notify' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('All gates passed');
});

it('fails when a gate fails and saves state', function () {
    config(['toolkit.gates' => [
        'will-fail' => [
            'label' => 'Will Fail',
            'command' => 'exit 1',
        ],
    ]]);

    $this->artisan('test:preflight', ['--no-notify' => true])
        ->assertFailed()
        ->expectsOutputToContain('Retry failed gates');

    expect(File::exists(storage_path('logs/preflight-result.json')))->toBeTrue();

    $state = json_decode(File::get(storage_path('logs/preflight-result.json')), true);
    expect($state['gates']['will-fail']['status'])->toBe('fail');
});

it('retries only failed gates from state', function () {
    // First run: one passes, one fails
    config(['toolkit.gates' => [
        'pass-gate' => [
            'label' => 'Pass Gate',
            'command' => 'echo "ok"',
        ],
        'fail-gate' => [
            'label' => 'Fail Gate',
            'command' => 'exit 1',
        ],
    ]]);

    $this->artisan('test:preflight', ['--no-notify' => true])
        ->assertFailed();

    // Now make both pass and retry only the failed one
    config(['toolkit.gates' => [
        'pass-gate' => [
            'label' => 'Pass Gate',
            'command' => 'echo "ok"',
        ],
        'fail-gate' => [
            'label' => 'Fail Gate',
            'command' => 'echo "fixed"',
        ],
    ]]);

    $this->artisan('test:preflight', ['--retry' => true, '--no-notify' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Retrying 1 failed gate');
});

it('validates gate names when --gate is used', function () {
    config(['toolkit.gates' => [
        'real-gate' => [
            'label' => 'Real Gate',
            'command' => 'echo "ok"',
        ],
    ]]);

    $this->artisan('test:preflight', ['--gate' => ['fake-gate'], '--no-notify' => true])
        ->assertSuccessful() // returns 0 because determineGates returns []
        ->expectsOutputToContain('Invalid gate');
});

it('runs only specified gates with --gate option', function () {
    config(['toolkit.gates' => [
        'gate-a' => [
            'label' => 'Gate A',
            'command' => 'echo "a"',
        ],
        'gate-b' => [
            'label' => 'Gate B',
            'command' => 'echo "b"',
        ],
    ]]);

    $this->artisan('test:preflight', ['--gate' => ['gate-a'], '--no-notify' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Running 1 gate');
});

it('respects prohibition in production', function () {
    PreflightCommand::prohibit();

    $this->artisan('test:preflight', ['--no-notify' => true])
        ->assertFailed();

    // Reset for other tests
    PreflightCommand::prohibit(false);
});
