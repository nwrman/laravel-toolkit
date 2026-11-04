<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

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
            ->toHaveKey('test:retry')
            ->toHaveKey('test:unit')
            ->toHaveKey('test:frontend')
            ->toHaveKey('lint');

        // Seconds-long gates before the minutes-long coverage run. --no-tia only where the
        // installed Pest has TIA: Pest without it rejects the flag outright.
        $coverageRun = class_exists('Pest\\Plugins\\Tia') ? '--exactly=100.0 --no-tia' : '--exactly=100.0';

        expect($result['scripts']['test:ci'][0])->toBe('@test:lint')
            ->and($result['scripts']['test:ci'][1])->toBe('@test:types')
            ->and($result['scripts']['test:ci'][3])->toEndWith($coverageRun);
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
            'cloud:setup' => 'my-cloud-setup',
        ],
    ];
    File::put($composerPath, json_encode($composerData, JSON_PRETTY_PRINT));

    try {
        $this->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'yes')
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
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

/**
 * Runs the installer saying yes only to standardizing the test scripts, and returns
 * the scripts it left in composer.json.
 *
 * @param  array<string, mixed>  $scripts
 * @return array<string, mixed>
 */
function standardizedScripts(array $scripts): array
{
    $composerPath = base_path('composer.json');
    $originalContent = File::exists($composerPath) ? File::get($composerPath) : null;
    File::put($composerPath, (string) json_encode(['name' => 'test/project', 'scripts' => $scripts], JSON_PRETTY_PRINT));

    try {
        test()->artisan('toolkit:install')
            ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
            ->expectsConfirmation('Merge recommended composer scripts?', 'no')
            ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'yes')
            ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
            ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
            ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
            ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
            ->expectsConfirmation('Publish deployment scripts?', 'no')
            ->assertExitCode(0);

        /** @var array{scripts: array<string, mixed>} $result */
        $result = json_decode(File::get($composerPath), true);

        return $result['scripts'];
    } finally {
        $originalContent !== null ? File::put($composerPath, $originalContent) : File::delete($composerPath);
    }
}

it('standardizes the test scripts to plain Pest and Vitest runs, with no artisan parent', function (): void {
    $scripts = standardizedScripts([
        'test:unit' => 'pest --testsuite=Unit --parallel --compact',
        'test' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report --env=testing --no-interaction'],
        'test:report' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report --env=testing'],
        'test:fast' => 'XDEBUG_MODE=coverage pest --parallel',
    ]);

    $timeout = 'Composer\\Config::disableProcessTimeout';

    expect($scripts)->toBe([
        'test:unit' => [$timeout, 'XDEBUG_MODE=off pest --parallel --testsuite=Unit'],
        'test' => [$timeout, 'XDEBUG_MODE=off pest --parallel --toolkit-no-browser; status=$?; bun run test:ui || status=1; exit $status'],
        'test:fast' => 'XDEBUG_MODE=coverage pest --parallel',
        'test:feature' => [$timeout, 'XDEBUG_MODE=off pest --parallel --testsuite=Feature'],
        'test:browser' => [$timeout, 'XDEBUG_MODE=off pest --parallel --testsuite=Browser'],
        'test:frontend' => [$timeout, 'bun run test:ui'],
        'test:retry' => [$timeout, 'XDEBUG_MODE=off pest --parallel --toolkit-retry; status=$?; node vendor/nwrman/laravel-toolkit/resources/js/vitest-retry.mjs || status=1; exit $status'],
        'preflight' => [$timeout, 'preflight'],
    ]);
});

it('runs composer test with a coverage driver when TIA is on, so TIA can record', function (): void {
    config(['toolkit.tia.enabled' => true]);

    expect(standardizedScripts([])['test'][1])
        ->toBe('XDEBUG_MODE=coverage pest --parallel --toolkit-no-browser; status=$?; bun run test:ui || status=1; exit $status');
});

it('writes the app\'s frontend command into composer test and test:frontend', function (): void {
    config(['toolkit.frontend_test_command' => 'npm run test:unit']);

    $scripts = standardizedScripts([]);

    expect($scripts['test'][1])->toBe('XDEBUG_MODE=off pest --parallel --toolkit-no-browser; status=$?; npm run test:unit || status=1; exit $status')
        ->and($scripts['test:frontend'][1])->toBe('npm run test:unit');
});

it('never adds --env=testing, even when a .env.testing exists', function (): void {
    $envTestingPath = base_path('.env.testing');
    File::put($envTestingPath, "APP_ENV=testing\nDB_CONNECTION=pgsql\n");

    try {
        $scripts = standardizedScripts([]);
    } finally {
        File::delete($envTestingPath);
    }

    expect(json_encode($scripts))->not->toContain('--env');
});

it('keeps a test script that does not call a retired toolkit command', function (): void {
    expect(standardizedScripts(['test:report' => 'pest --testsuite=Unit'])['test:report'])->toBe('pest --testsuite=Unit');
});

/**
 * Runs the installer saying no to every prompt.
 */
function installWithEveryPromptDeclined(): PendingCommand
{
    return test()->artisan('toolkit:install')
        ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
        ->expectsConfirmation('Merge recommended composer scripts?', 'no')
        ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
        ->expectsConfirmation('Publish AI skills & guidelines?', 'no')
        ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
        ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
        ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
        ->expectsConfirmation('Publish deployment scripts?', 'no');
}

it('removes the retired guard hook and keeps every other hook, in two-space JSON', function (): void {
    $claudeDir = base_path('.claude');
    File::ensureDirectoryExists($claudeDir);
    File::put($claudeDir.'/settings.json', (string) json_encode([
        'permissions' => ['allow' => ['Bash(ls:*)']],
        'hooks' => [
            'PreToolUse' => [
                ['matcher' => 'Bash', 'hooks' => [
                    ['type' => 'command', 'command' => 'php "$CLAUDE_PROJECT_DIR/vendor/nwrman/laravel-toolkit/bin/enforce-test-command.php"'],
                    ['type' => 'command', 'command' => 'echo kept-beside'],
                ]],
                ['matcher' => 'Edit', 'hooks' => [['type' => 'command', 'command' => 'echo kept-alone']]],
            ],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        installWithEveryPromptDeclined()
            ->expectsOutputToContain('Removed the retired test-command guard hook')
            ->assertExitCode(0);

        $contents = File::get($claudeDir.'/settings.json');

        expect(json_decode($contents, true))->toBe([
            'permissions' => ['allow' => ['Bash(ls:*)']],
            'hooks' => [
                'PreToolUse' => [
                    ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'echo kept-beside']]],
                    ['matcher' => 'Edit', 'hooks' => [['type' => 'command', 'command' => 'echo kept-alone']]],
                ],
            ],
        ])->and($contents)->toContain("\n  \"hooks\"");
    } finally {
        File::deleteDirectory($claudeDir);
    }
});

it('drops a hook entry the guard was the only hook of, the old published copy included, and flags the copy', function (): void {
    $claudeDir = base_path('.claude');
    File::ensureDirectoryExists($claudeDir.'/hooks');
    File::put($claudeDir.'/hooks/enforce-test-command.php', '<?php // frozen copy');
    File::put($claudeDir.'/settings.json', (string) json_encode([
        'hooks' => [
            'PreToolUse' => [
                ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'php "$CLAUDE_PROJECT_DIR/.claude/hooks/enforce-test-command.php"']]],
            ],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        installWithEveryPromptDeclined()
            ->expectsOutputToContain('nothing runs any more')
            ->assertExitCode(0);

        expect(json_decode(File::get($claudeDir.'/settings.json'), true))->toBe(['hooks' => ['PreToolUse' => []]]);
    } finally {
        File::deleteDirectory($claudeDir);
    }
});

it('leaves settings.json untouched when it has no guard hook', function (): void {
    $claudeDir = base_path('.claude');
    File::ensureDirectoryExists($claudeDir);
    $original = "{\n    \"hooks\": {\"PreToolUse\": [{\"matcher\": \"Bash\", \"hooks\": [{\"type\": \"command\", \"command\": \"echo mine\"}]}]}\n}\n";
    File::put($claudeDir.'/settings.json', $original);

    try {
        installWithEveryPromptDeclined()->assertExitCode(0);

        expect(File::get($claudeDir.'/settings.json'))->toBe($original);
    } finally {
        File::deleteDirectory($claudeDir);
    }
});

it('creates no settings.json when the app has none', function (): void {
    installWithEveryPromptDeclined()->assertExitCode(0);

    expect(File::exists(base_path('.claude/settings.json')))->toBeFalse();
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

/**
 * Answer the installer's prompts, opting into the AI-skills step only.
 */
function publishSkillsOnly(object $test): PendingCommand
{
    /** @var PendingCommand $pending */
    $pending = $test->artisan('toolkit:install')
        ->expectsConfirmation('Move nwrman/laravel-toolkit to require-dev?', 'no')
        ->expectsConfirmation('Merge recommended composer scripts?', 'no')
        ->expectsConfirmation('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', 'no')
        ->expectsConfirmation('Publish AI skills & guidelines?', 'yes')
        ->expectsConfirmation('Publish GitHub Actions workflow?', 'no')
        ->expectsConfirmation('Publish static analysis configs (pint.json, phpstan.neon)?', 'no')
        ->expectsConfirmation('Publish deploy notification command (and test)?', 'no')
        ->expectsConfirmation('Publish deployment scripts?', 'no');

    return $pending;
}

it('registers unregistered skills from .ai/skills in boost.json', function (): void {
    $boostPath = base_path('boost.json');
    $skillsPath = base_path('.ai/skills');

    File::put($boostPath, json_encode(['guidelines' => true, 'skills' => ['already-there']], JSON_PRETTY_PRINT));
    File::ensureDirectoryExists($skillsPath.'/hand-written-skill');

    try {
        publishSkillsOnly($this)->assertExitCode(0);

        /** @var array{skills: array<int, string>} $result */
        $result = json_decode(File::get($boostPath), true);

        // Pre-existing registrations survive, and a skill that was only ever on disk is picked up.
        expect($result['skills'])->toContain('already-there')
            ->toContain('hand-written-skill')
            ->toContain('provision-laravel-cloud')
            ->toContain('run-preflight');

        // Unrelated keys are left alone.
        expect($result['guidelines'])->toBeTrue();
    } finally {
        File::delete($boostPath);
        File::deleteDirectory(base_path('.ai'));
    }
});

it('does not duplicate skills already registered in boost.json', function (): void {
    $boostPath = base_path('boost.json');
    $skillsPath = base_path('.ai/skills');

    File::ensureDirectoryExists($skillsPath.'/solo-skill');
    File::put($boostPath, json_encode(['skills' => ['solo-skill']], JSON_PRETTY_PRINT));

    try {
        publishSkillsOnly($this)->assertExitCode(0);

        /** @var array{skills: array<int, string>} $result */
        $result = json_decode(File::get($boostPath), true);

        $occurrences = array_count_values($result['skills']);

        expect($occurrences['solo-skill'])->toBe(1);
    } finally {
        File::delete($boostPath);
        File::deleteDirectory(base_path('.ai'));
    }
});

it('skips skill registration when boost.json is absent', function (): void {
    $boostPath = base_path('boost.json');
    $originalContent = File::exists($boostPath) ? File::get($boostPath) : null;
    File::delete($boostPath);

    try {
        publishSkillsOnly($this)
            ->expectsOutputToContain('boost.json not found')
            ->assertExitCode(0);

        expect(File::exists($boostPath))->toBeFalse();
    } finally {
        if ($originalContent !== null) {
            File::put($boostPath, $originalContent);
        }

        File::deleteDirectory(base_path('.ai'));
    }
});

it('lists the toolkit under boost.json packages so Boost renders its test guideline', function (): void {
    $boostPath = base_path('boost.json');
    File::put($boostPath, json_encode(['packages' => ['other/package'], 'skills' => []], JSON_PRETTY_PRINT));

    try {
        publishSkillsOnly($this)->assertExitCode(0);
        publishSkillsOnly($this)->assertExitCode(0);

        /** @var array{packages: array<int, string>} $result */
        $result = json_decode(File::get($boostPath), true);

        expect($result['packages'])->toBe(['other/package', 'nwrman/laravel-toolkit']);
    } finally {
        File::delete($boostPath);
        File::deleteDirectory(base_path('.ai'));
    }
});

it('no longer publishes the test guideline, and flags a copy an older install left behind', function (): void {
    $boostPath = base_path('boost.json');
    File::put($boostPath, json_encode(['skills' => []], JSON_PRETTY_PRINT));
    File::ensureDirectoryExists(base_path('.ai/guidelines'));
    File::put(base_path('.ai/guidelines/enforce-tests.blade.php'), '# Running Tests (old)');

    try {
        publishSkillsOnly($this)
            ->expectsOutputToContain('is a copy an older toolkit published')
            ->assertExitCode(0);

        expect(File::get(base_path('.ai/guidelines/enforce-tests.blade.php')))->toBe('# Running Tests (old)')
            ->and(File::exists(dirname(__DIR__, 2).'/resources/boost/guidelines/core.blade.php'))->toBeTrue();
    } finally {
        File::delete($boostPath);
        File::deleteDirectory(base_path('.ai'));
    }
});
