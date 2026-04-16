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
        ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
        ->expectsConfirmation('Merge recommended composer scripts?', 'no')
        ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
        ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
        ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
        ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
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
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'yes')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
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
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'yes')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
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

it('moves the package from require to require-dev when confirmed', function (): void {
    $composerPath = base_path('composer.json');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;

    $composerData = [
        'name' => 'test/project',
        'require' => [
            'php' => '^8.5',
            'nwrman/laravel-toolkit' => '^1.0',
        ],
        'require-dev' => [
            'pestphp/pest' => '^4.0',
        ],
    ];
    File::put($composerPath, json_encode($composerData, JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'yes')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->expectsOutputToContain('Moved nwrman/laravel-toolkit to require-dev.')
            ->assertExitCode(0);

        $result = json_decode(File::get($composerPath), true);

        expect($result['require'])->not->toHaveKey('nwrman/laravel-toolkit');
        expect($result['require'])->toHaveKey('php');
        expect($result['require-dev'])->toHaveKey('nwrman/laravel-toolkit');
        expect($result['require-dev']['nwrman/laravel-toolkit'])->toBe('^1.0');
        expect($result['require-dev'])->toHaveKey('pestphp/pest');
    } finally {
        if ($originalContent !== null) {
            File::put($composerPath, $originalContent);
        } else {
            File::delete($composerPath);
        }
    }
});

it('reports when the package is already in require-dev', function (): void {
    $composerPath = base_path('composer.json');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;

    $composerData = [
        'name' => 'test/project',
        'require' => [
            'php' => '^8.5',
        ],
        'require-dev' => [
            'nwrman/laravel-toolkit' => '^1.0',
        ],
    ];
    File::put($composerPath, json_encode($composerData, JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'yes')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->expectsOutputToContain('nwrman/laravel-toolkit is already in require-dev.')
            ->assertExitCode(0);

        // File should be unchanged
        $result = json_decode(File::get($composerPath), true);
        expect($result['require-dev'])->toHaveKey('nwrman/laravel-toolkit');
        expect($result['require'])->not->toHaveKey('nwrman/laravel-toolkit');
    } finally {
        if ($originalContent !== null) {
            File::put($composerPath, $originalContent);
        } else {
            File::delete($composerPath);
        }
    }
});

it('silently skips the require-dev migration when the package is in neither section', function (): void {
    $composerPath = base_path('composer.json');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;

    $composerData = [
        'name' => 'test/project',
        'require' => [
            'php' => '^8.5',
        ],
        'require-dev' => [
            'pestphp/pest' => '^4.0',
        ],
    ];
    File::put($composerPath, json_encode($composerData, JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'yes')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->doesntExpectOutputToContain('Moved nwrman/laravel-toolkit')
            ->doesntExpectOutputToContain('already in require-dev')
            ->assertExitCode(0);

        // File should be unchanged
        $result = json_decode(File::get($composerPath), true);
        expect($result['require'])->not->toHaveKey('nwrman/laravel-toolkit');
        expect($result['require-dev'])->not->toHaveKey('nwrman/laravel-toolkit');
    } finally {
        if ($originalContent !== null) {
            File::put($composerPath, $originalContent);
        } else {
            File::delete($composerPath);
        }
    }
});
