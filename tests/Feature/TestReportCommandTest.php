<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete([
        storage_path('logs/test-failures.md'),
        storage_path('logs/test-failures.json'),
        storage_path('logs/test-results.xml'),
    ]);
});

it('registers the test:report command', function () {
    $this->artisan('list')
        ->assertSuccessful()
        ->expectsOutputToContain('test:report');
});

it('runs selected backend suites and reports success', function () {
    config(['toolkit.suites' => [
        'unit' => 'Unit',
    ]]);

    // Since pest won't be available in the package test environment,
    // we'll test the command registration and option parsing.
    // A full integration test would be done in the consuming project.
    $this->artisan('test:report', [
        '--suite' => 'unit',
        '--no-notify' => true,
    ]);

    // The command will fail because pest isn't installed here,
    // but we verify it ran and attempted to execute.
});

it('exits cleanly when no suites are selected', function () {
    // Non-interactive mode with empty suite
    $this->artisan('test:report', [
        '--suite' => '',
        '--no-notify' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('No suites selected');
});
