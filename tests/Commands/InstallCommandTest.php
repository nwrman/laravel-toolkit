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
        ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
        ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
        ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
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
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
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
            ->toHaveKey('test:frontend')
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
            'test:frontend' => 'my-test-frontend',
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
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
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

it('standardizes drifted test scripts to the toolkit convention when confirmed', function (): void {
    $composerPath = base_path('composer.json');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;

    $composerData = [
        'name' => 'test/project',
        'scripts' => [
            // Raw pest invocation that bypasses the toolkit's reporting.
            'test:unit' => 'pest --testsuite=Unit --parallel --compact',
            'test' => ['@test:unit', 'bun run test:ui'],
        ],
    ];
    File::put($composerPath, json_encode($composerData, JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'yes')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->assertExitCode(0);

        $result = json_decode(File::get($composerPath), true);

        expect($result['scripts']['test:unit'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            '@php artisan toolkit:report --no-interaction --suite=unit',
        ]);
        expect($result['scripts']['test'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            '@php artisan toolkit:report --no-interaction',
        ]);
        // No committed .env.testing here, so no --env=testing is injected.
        expect($result['scripts']['test:unit'][1])->not->toContain('--env=testing');
    } finally {
        if ($originalContent !== null) {
            File::put($composerPath, $originalContent);
        } else {
            File::delete($composerPath);
        }
    }
});

it('injects --env=testing into test scripts when a committed .env.testing exists', function (): void {
    $composerPath = base_path('composer.json');
    $envTestingPath = base_path('.env.testing');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;

    File::put($composerPath, json_encode(['name' => 'test/project', 'scripts' => []], JSON_PRETTY_PRINT));
    File::put($envTestingPath, "APP_ENV=testing\nDB_CONNECTION=pgsql\n");

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'yes')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->assertExitCode(0);

        $result = json_decode(File::get($composerPath), true);

        expect($result['scripts']['test:unit'][1])
            ->toBe('@php artisan toolkit:report --env=testing --no-interaction --suite=unit');
        expect($result['scripts']['test:report'][1])->toBe('@php artisan toolkit:report --env=testing');
        expect($result['scripts']['test:retry'])->toBe('@php artisan toolkit:retry --env=testing');
    } finally {
        File::delete($envTestingPath);

        if ($originalContent !== null) {
            File::put($composerPath, $originalContent);
        } else {
            File::delete($composerPath);
        }
    }
});

it('publishes the guard hook and merges the PreToolUse entry into .claude/settings.json', function (): void {
    $claudeDir = base_path('.claude');

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'yes')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->assertExitCode(0);

        expect(File::exists($claudeDir.'/hooks/enforce-test-command.php'))->toBeTrue();

        $settings = json_decode(File::get($claudeDir.'/settings.json'), true);
        expect(data_get($settings, 'hooks.PreToolUse.0.matcher'))->toBe('Bash');
        expect(data_get($settings, 'hooks.PreToolUse.0.hooks.0.command'))->toContain('enforce-test-command.php');
    } finally {
        File::deleteDirectory($claudeDir);
    }
});

it('does not duplicate the guard hook when it already exists in settings.json', function (): void {
    $claudeDir = base_path('.claude');
    File::ensureDirectoryExists($claudeDir);
    File::put($claudeDir.'/settings.json', json_encode([
        'hooks' => [
            'PreToolUse' => [
                ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'php "$CLAUDE_PROJECT_DIR/.claude/hooks/enforce-test-command.php"']]],
            ],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'yes')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->expectsOutputToContain('guard hook already present')
            ->assertExitCode(0);

        $settings = json_decode(File::get($claudeDir.'/settings.json'), true);
        expect($settings['hooks']['PreToolUse'])->toHaveCount(1);
    } finally {
        File::deleteDirectory($claudeDir);
    }
});

it('warns when a non-sqlite project has no test-database isolation', function (): void {
    $envPath = base_path('.env');
    $originalEnv = File::exists($envPath) ? File::get($envPath) : null;
    File::put($envPath, "APP_ENV=local\nDB_CONNECTION=pgsql\nDB_DATABASE=app\n");

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->expectsOutputToContain('Tests may run against your DEV database')
            ->assertExitCode(0);
    } finally {
        if ($originalEnv !== null) {
            File::put($envPath, $originalEnv);
        } else {
            File::delete($envPath);
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
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
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
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
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
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Install Claude Code test-command guard hook?', 'no')
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
