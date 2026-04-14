<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete([
        storage_path('logs/test-failures.md'),
        storage_path('logs/test-failures.json'),
    ]);
});

it('registers the test:retry command', function () {
    $this->artisan('list')
        ->assertSuccessful()
        ->expectsOutputToContain('test:retry');
});

it('reports no tests to retry when no state file exists', function () {
    $this->artisan('test:retry', ['--no-notify' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('No failed tests to retry');
});

it('reports no failures when state file has empty failures', function () {
    File::ensureDirectoryExists(storage_path('logs'));
    File::put(storage_path('logs/test-failures.json'), json_encode([
        'timestamp' => date('c'),
        'suites_run' => ['unit'],
        'backend_failures' => [],
        'frontend_failed' => false,
    ]));

    $this->artisan('test:retry', ['--no-notify' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('No failed tests');
});

it('handles corrupted state file gracefully', function () {
    File::ensureDirectoryExists(storage_path('logs'));
    File::put(storage_path('logs/test-failures.json'), 'not valid json');

    $this->artisan('test:retry', ['--no-notify' => true])
        ->assertFailed()
        ->expectsOutputToContain('Failed to parse');
});
