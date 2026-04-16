<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/toolkit-install-'.uniqid();
    File::ensureDirectoryExists($this->tempDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->tempDir);
});

it('runs the install command and publishes config', function (): void {
    $this->artisan('toolkit:install')
        ->expectsConfirmation('Merge recommended composer scripts?', 'no')
        ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
        ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
        ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
        ->expectsConfirmation('Publish deployment scripts?', 'no')
        ->expectsOutput('✓ Laravel Toolkit installed successfully!')
        ->assertExitCode(0);
});

it('merges composer scripts into composer.json', function (): void {
    $composerPath = base_path('composer.json');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;

    // Create a minimal composer.json
    $composerData = [
        'name' => 'test/project',
        'scripts' => [
            'dev' => 'existing-dev-command',
        ],
    ];
    File::put($composerPath, json_encode($composerData, JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Merge recommended composer scripts?', 'yes')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->assertExitCode(0);

        $result = json_decode(File::get($composerPath), true);

        // Existing script should be preserved
        expect($result['scripts']['dev'])->toBe('existing-dev-command');

        // New scripts should be added
        expect($result['scripts'])->toHaveKey('preflight')
            ->toHaveKey('test:report')
            ->toHaveKey('test:retry')
            ->toHaveKey('test:unit')
            ->toHaveKey('lint');
    } finally {
        if ($originalContent !== null) {
            File::put($composerPath, $originalContent);
        } else {
            File::delete($composerPath);
        }
    }
});

it('skips existing scripts and reports them', function (): void {
    $composerPath = base_path('composer.json');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;

    // Create a composer.json with all recommended scripts already present
    $composerData = [
        'name' => 'test/project',
        'scripts' => [
            'dev' => 'my-dev',
            'lint:dirty' => 'my-lint-dirty',
            'lint' => 'my-lint',
            'test:type-coverage' => 'my-type-coverage',
            'test:unit' => 'my-test-unit',
            'test:feature' => 'my-test-feature',
            'test:browser' => 'my-test-browser',
            'test:lint' => 'my-test-lint',
            'test:types' => 'my-test-types',
            'test' => 'my-test',
            'test:ci' => 'my-test-ci',
            'test:report' => 'my-test-report',
            'test:retry' => 'my-test-retry',
            'preflight' => 'my-preflight',
            'optimize' => 'my-optimize',
            'cloud:build' => 'my-cloud-build',
            'cloud:deploy' => 'my-cloud-deploy',
        ],
    ];
    File::put($composerPath, json_encode($composerData, JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Merge recommended composer scripts?', 'yes')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->expectsOutputToContain('All recommended scripts already exist')
            ->assertExitCode(0);

        // Original scripts should be unchanged
        $result = json_decode(File::get($composerPath), true);
        expect($result['scripts']['dev'])->toBe('my-dev');
        expect($result['scripts']['preflight'])->toBe('my-preflight');
    } finally {
        if ($originalContent !== null) {
            File::put($composerPath, $originalContent);
        } else {
            File::delete($composerPath);
        }
    }
});
