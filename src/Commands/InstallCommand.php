<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Override;

use function Laravel\Prompts\confirm;

final class InstallCommand extends Command
{
    private const string PACKAGE_NAME = 'nwrman/laravel-toolkit';

    /**
     * The retired test-command guard hook. Every Pest run now writes the report and
     * uses TIA, so there is nothing left to steer agents away from.
     */
    private const string GUARD_HOOK_SCRIPT = 'enforce-test-command.php';

    private const string LEGACY_GUARD_HOOK_PATH = '.claude/hooks/enforce-test-command.php';

    /**
     * Published by toolkit versions that shipped the "Running Tests" guideline as a
     * copy. Boost now reads it from this package, and a leftover copy overrides
     * Boost's own test guideline with the old advice.
     */
    private const string LEGACY_TEST_GUIDELINE_PATH = '.ai/guidelines/enforce-tests.blade.php';

    /**
     * Toolkit scripts from before v1: they ran an artisan parent, which loaded `.env`
     * into the test run. A script still calling one is replaced or removed.
     */
    private const array RETIRED_COMMANDS = ['toolkit:report', 'toolkit:retry', 'toolkit:preflight'];

    /**
     * Non-test recommended scripts. The test scripts are built by
     * {@see canonicalTestScripts()} from the app's TIA and frontend settings.
     *
     * @var array<string, string|array<int, string>>
     */
    private const array RECOMMENDED_SCRIPTS = [
        'dev' => [
            'Composer\\Config::disableProcessTimeout',
            'bunx concurrently -c "#c4b5fd,#fb7185,#fdba74" "php artisan queue:listen --tries=1" "php artisan pail --timeout=0" "vp dev" --names=queue,logs,vite --kill-others',
        ],
        'lint:dirty' => [
            'Composer\\Config::disableProcessTimeout',
            'node --experimental-strip-types resources/js/scripts/lint-dirty.ts',
        ],
        'lint' => [
            'rector',
            'pint --parallel',
            'bun run lint',
        ],
        'test:type-coverage' => 'pest --type-coverage --min=100',
        'test:lint' => [
            'pint --parallel --test',
            'rector --dry-run',
            'bun run test:lint',
        ],
        'test:types' => [
            'phpstan',
            'bun run test:types',
        ],
        'optimize' => [
            '@php artisan optimize',
            '@php artisan config:cache',
            '@php artisan event:cache',
            '@php artisan route:cache',
            '@php artisan view:cache',
        ],
        'cloud:build' => ['sh scripts/cloud-build.sh'],
        'cloud:deploy' => ['sh scripts/cloud-deploy.sh'],
        'cloud:setup' => ['sh scripts/cloud-setup.sh'],
    ];

    #[Override]
    protected $signature = 'toolkit:install
        {--force : Overwrite existing files}';

    #[Override]
    protected $description = 'Install Laravel Toolkit scaffolding';

    public function handle(): int
    {
        $this->info('Installing Laravel Toolkit...');
        $this->newLine();

        if (confirm('Move '.self::PACKAGE_NAME.' to require-dev?', true)) {
            $this->moveToRequireDev();
        }

        $this->call('vendor:publish', [
            '--tag' => 'toolkit-config',
            '--force' => $this->option('force'),
        ]);

        if (confirm('Merge recommended composer scripts?', true)) {
            $this->mergeComposerScripts();
        }

        if (confirm('Standardize composer test:* scripts to the toolkit convention (overwrites existing test:* / test)?', true)) {
            $this->standardizeTestScripts();
        }

        if (confirm('Publish AI skills & guidelines?', true)) {
            $this->call('vendor:publish', [
                '--tag' => 'toolkit-ai',
                '--force' => $this->option('force'),
            ]);
            $this->registerSkillsInBoostConfig();
            $this->registerGuidelinesInBoostConfig();
            $this->warnAboutLegacyTestGuideline();
        }

        $this->removeGuardHook();

        if (confirm('Publish GitHub Actions workflow?', true)) {
            $this->call('vendor:publish', [
                '--tag' => 'toolkit-github',
                '--force' => $this->option('force'),
            ]);
        }

        if (confirm('Publish static analysis configs (pint.json, phpstan.neon)?', true)) {
            $this->call('vendor:publish', [
                '--tag' => 'toolkit-static-analysis',
                '--force' => $this->option('force'),
            ]);
        }

        if (confirm('Publish deploy notification command (and test)?', true)) {
            $this->call('vendor:publish', [
                '--tag' => 'toolkit-commands',
                '--force' => $this->option('force'),
            ]);
        }

        if (confirm('Publish deployment scripts?', true)) {
            $this->call('vendor:publish', [
                '--tag' => 'toolkit-scripts',
                '--force' => $this->option('force'),
            ]);
        }

        $this->warnIfTestDatabaseUnisolated();

        $this->newLine();
        $this->info('✓ Laravel Toolkit installed successfully!');

        return self::SUCCESS;
    }

    private function moveToRequireDev(): void
    {
        $composerPath = base_path('composer.json');

        if (! File::exists($composerPath)) {
            $this->warn('composer.json not found at '.base_path());

            return;
        }

        $composerData = json_decode(File::get($composerPath), true);

        if (! is_array($composerData)) {
            $this->error('Failed to parse composer.json');

            return;
        }

        /** @var array<string, mixed> $require */
        $require = is_array($composerData['require'] ?? null) ? $composerData['require'] : [];

        /** @var array<string, mixed> $requireDev */
        $requireDev = is_array($composerData['require-dev'] ?? null) ? $composerData['require-dev'] : [];

        $inRequire = array_key_exists(self::PACKAGE_NAME, $require);
        $inRequireDev = array_key_exists(self::PACKAGE_NAME, $requireDev);

        if ($inRequireDev && ! $inRequire) {
            $this->line('  <info>'.self::PACKAGE_NAME.' is already in require-dev.</info>');

            return;
        }

        if (! $inRequire) {
            // Not installed in either section; nothing to migrate.
            return;
        }

        $constraint = $require[self::PACKAGE_NAME];
        unset($require[self::PACKAGE_NAME]);
        $requireDev[self::PACKAGE_NAME] = $constraint;

        $composerData['require'] = $require;
        $composerData['require-dev'] = $requireDev;

        $encoded = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($composerPath, is_string($encoded) ? $encoded."\n" : '');

        $this->line('  <info>Moved '.self::PACKAGE_NAME.' to require-dev.</info> Run <comment>composer update</comment> to refresh the lock file.');
    }

    private function mergeComposerScripts(): void
    {
        $composerPath = base_path('composer.json');

        if (! File::exists($composerPath)) {
            $this->warn('composer.json not found at '.base_path());

            return;
        }

        $composerData = json_decode(File::get($composerPath), true);

        if (! is_array($composerData)) {
            $this->error('Failed to parse composer.json');

            return;
        }

        /** @var array<string, mixed> $existingScripts */
        $existingScripts = $composerData['scripts'] ?? [];

        $added = [];
        $skipped = [];

        foreach ($this->recommendedScripts() as $name => $command) {
            if (isset($existingScripts[$name])) {
                $skipped[] = $name;
            } else {
                $existingScripts[$name] = $command;
                $added[] = $name;
            }
        }

        if ($added !== []) {
            $composerData['scripts'] = $existingScripts;
            $encoded = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            File::put($composerPath, is_string($encoded) ? $encoded."\n" : '');

            $this->line(sprintf('  <info>Added %d script(s):</info> %s', count($added), implode(', ', $added)));
        }

        if ($skipped !== []) {
            $this->line(sprintf('  <comment>Skipped %d existing script(s):</comment> %s', count($skipped), implode(', ', $skipped)));
        }

        if ($added === []) {
            $this->line('  <info>All recommended scripts already exist.</info>');
        }
    }

    /**
     * Overwrite the canonical test-routing scripts so every `composer test:*` flows
     * through the toolkit's reporting. Unlike {@see mergeComposerScripts()}, this
     * replaces existing entries that have drifted from the convention.
     */
    private function standardizeTestScripts(): void
    {
        $composerPath = base_path('composer.json');

        if (! File::exists($composerPath)) {
            $this->warn('composer.json not found at '.base_path());

            return;
        }

        $composerData = json_decode(File::get($composerPath), true);

        if (! is_array($composerData)) {
            $this->error('Failed to parse composer.json');

            return;
        }

        /** @var array<string, mixed> $scripts */
        $scripts = is_array($composerData['scripts'] ?? null) ? $composerData['scripts'] : [];

        $changed = [];

        foreach ($this->canonicalTestScripts() as $name => $command) {
            if (($scripts[$name] ?? null) !== $command) {
                $scripts[$name] = $command;
                $changed[] = $name;
            }
        }

        foreach ($scripts as $name => $command) {
            if (! isset($this->canonicalTestScripts()[$name]) && $this->callsRetiredCommand($command)) {
                unset($scripts[$name]);
                $changed[] = $name.' (removed)';
            }
        }

        if ($changed === []) {
            $this->line('  <info>Test scripts already match the toolkit convention.</info>');

            return;
        }

        $composerData['scripts'] = $scripts;
        $encoded = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($composerPath, is_string($encoded) ? $encoded."\n" : '');

        $this->line(sprintf('  <info>Standardized %d test script(s):</info> %s', count($changed), implode(', ', $changed)));
        $this->line('  <comment>Frontend failures reach the report through the Vitest reporter: add</comment>');
        $this->line("  <comment>'./vendor/nwrman/laravel-toolkit/resources/js/vitest-reporter.mjs' to test.reporters in vitest.config,</comment>");
        $this->line('  <comment>only when that file exists: a CI job without composer install would stop Vitest (see the README).</comment>');
    }

    private function callsRetiredCommand(mixed $command): bool
    {
        $commands = is_array($command) ? $command : [$command];

        return array_any($commands, fn (mixed $line): bool => is_string($line)
            && array_any(self::RETIRED_COMMANDS, fn (string $retired): bool => str_contains($line, $retired)));
    }

    /**
     * All recommended scripts: the static set plus the dynamic test-routing set.
     *
     * @return array<string, string|array<int, string>>
     */
    private function recommendedScripts(): array
    {
        return array_merge(self::RECOMMENDED_SCRIPTS, ['test:ci' => $this->ciScript()], $this->canonicalTestScripts());
    }

    /**
     * What CI runs, in the order that fails fastest: lint and types take seconds, the
     * coverage run takes minutes, so a lint error surfaces before the suite instead of
     * after it. TIA stays out wherever this Pest has it: CI runs every test against a
     * clean checkout, and a "would CI pass?" check has to do the same. Pest without
     * TIA does not know --no-tia, hence the check.
     *
     * @return array<int, string>
     */
    private function ciScript(): array
    {
        $noTia = class_exists('Pest\\Plugins\\Tia') ? ' --no-tia' : '';

        return [
            '@test:lint',
            '@test:types',
            '@test:type-coverage',
            'XDEBUG_MODE="coverage" pest --parallel --coverage --exactly=100.0'.$noTia,
            'bun run test:coverage',
        ];
    }

    /**
     * The canonical test scripts. None boots Laravel: each runs Pest (or the frontend
     * runner) from the shell, so Pest loads `.env.testing` and the toolkit's Pest
     * plugin writes the failure report. `composer test` and `test:retry` run both
     * halves even when the first fails, so the report never mixes this run's backend
     * with an older frontend run, and fail when either half does.
     *
     * `composer test` leaves Browser specs out with --toolkit-no-browser, which keeps TIA
     * on. Under Xdebug, beside every other worker, they time out whenever TIA replays
     * many of them, as after a graph rebuild. They run in `test:browser` and in
     * preflight's `browser` gate, without Xdebug and on fewer workers.
     *
     * @return array<string, string|array<int, string>>
     */
    private function canonicalTestScripts(): array
    {
        /** @var string $frontend */
        $frontend = config('toolkit.frontend_test_command', 'bun run test:ui');
        $xdebug = (bool) config('toolkit.tia.enabled', false) ? 'coverage' : 'off';
        $timeout = 'Composer\\Config::disableProcessTimeout';

        return [
            'test' => [$timeout, sprintf('XDEBUG_MODE=%s pest --parallel --toolkit-no-browser; status=$?; %s || status=1; exit $status', $xdebug, $frontend)],
            'test:unit' => [$timeout, 'XDEBUG_MODE=off pest --parallel --testsuite=Unit'],
            'test:feature' => [$timeout, 'XDEBUG_MODE=off pest --parallel --testsuite=Feature'],
            'test:browser' => [$timeout, 'XDEBUG_MODE=off pest --parallel --testsuite=Browser'],
            'test:frontend' => [$timeout, $frontend],
            'test:retry' => [$timeout, 'XDEBUG_MODE=off pest --parallel --toolkit-retry; status=$?; node vendor/nwrman/laravel-toolkit/resources/js/vitest-retry.mjs || status=1; exit $status'],
            'preflight' => [$timeout, 'preflight'],
        ];
    }

    /**
     * Removes the retired test-command guard hook from .claude/settings.json, leaving
     * every other hook as it was.
     */
    private function removeGuardHook(): void
    {
        $settingsPath = base_path('.claude/settings.json');
        $settings = File::exists($settingsPath) ? json_decode(File::get($settingsPath), true) : null;
        $preToolUse = is_array($settings) ? data_get($settings, 'hooks.PreToolUse') : null;

        if (! is_array($settings) || ! is_array($preToolUse)) {
            $this->warnAboutLegacyGuardHook();

            return;
        }

        $removed = false;
        $kept = [];

        foreach ($preToolUse as $entry) {
            if (! is_array($entry) || ! is_array($entry['hooks'] ?? null)) {
                $kept[] = $entry;

                continue;
            }

            $hooks = array_values(array_filter($entry['hooks'], function (mixed $hook): bool {
                $command = is_array($hook) ? ($hook['command'] ?? null) : null;

                return ! is_string($command) || ! str_contains($command, self::GUARD_HOOK_SCRIPT);
            }));

            if (count($hooks) === count($entry['hooks'])) {
                $kept[] = $entry;

                continue;
            }

            $removed = true;

            if ($hooks !== []) {
                $kept[] = [...$entry, 'hooks' => $hooks];
            }
        }

        if ($removed) {
            data_set($settings, 'hooks.PreToolUse', $kept);
            File::put($settingsPath, $this->encodeTwoSpaceJson(is_array($settings) ? $settings : [])."\n");
            $this->line('  <info>Removed the retired test-command guard hook from .claude/settings.json:</info> a raw Pest run now writes the report and uses TIA.');
        }

        $this->warnAboutLegacyGuardHook();
    }

    private function warnAboutLegacyGuardHook(): void
    {
        if (File::exists(base_path(self::LEGACY_GUARD_HOOK_PATH))) {
            $this->line('  <comment>'.self::LEGACY_GUARD_HOOK_PATH.' is an old published copy nothing runs any more — delete it.</comment>');
        }
    }

    /**
     * Boost includes a package's guidelines only for packages listed under "packages"
     * in boost.json, and `boost:update` never adds one without asking. List this
     * package so its "Running Tests" guideline reaches the generated agent files.
     */
    private function registerGuidelinesInBoostConfig(): void
    {
        $boostPath = base_path('boost.json');

        if (! File::exists($boostPath)) {
            return;
        }

        $boostData = json_decode(File::get($boostPath), true);

        if (! is_array($boostData)) {
            return;
        }

        /** @var array<int, string> $packages */
        $packages = array_values(array_filter(
            is_array($boostData['packages'] ?? null) ? $boostData['packages'] : [],
            is_string(...),
        ));

        if (in_array(self::PACKAGE_NAME, $packages, true)) {
            return;
        }

        $boostData['packages'] = [...$packages, self::PACKAGE_NAME];

        $encoded = json_encode($boostData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($boostPath, is_string($encoded) ? $encoded."\n" : '');

        $this->line('  <info>Listed '.self::PACKAGE_NAME.' under "packages" in boost.json.</info> Run <comment>php artisan boost:update</comment> to regenerate the agent guidelines.');
    }

    private function warnAboutLegacyTestGuideline(): void
    {
        if (! File::exists(base_path(self::LEGACY_TEST_GUIDELINE_PATH))) {
            return;
        }

        $this->newLine();
        $this->warn('⚠ '.self::LEGACY_TEST_GUIDELINE_PATH.' is a copy an older toolkit published.');
        $this->line('  It overrides Boost\'s own test guideline, so agents read it beside the toolkit\'s current one —');
        $this->line('  usually with older, contradicting advice. Delete it unless you have edited it on purpose.');
    }

    /**
     * Encode as JSON with two-space indentation.
     *
     * PHP's JSON_PRETTY_PRINT is fixed at four spaces, but the JS formatters
     * consumer apps run over their repos expect two. Writing four means the app's
     * own `bun run build` fails its format check on a file this installer wrote —
     * and reformatting it locally only lasts until the next `toolkit:install`.
     * Emitting the shape the app's tooling wants ends that loop.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function encodeTwoSpaceJson(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            return '';
        }

        return (string) preg_replace_callback(
            '/^ +/m',
            static fn (array $matches): string => str_repeat(' ', intdiv(mb_strlen($matches[0]), 2)),
            $encoded,
        );
    }

    /**
     * Register every skill present in .ai/skills with Laravel Boost.
     *
     * Publishing a skill only puts it on disk. Boost's `skills` array is what surfaces it in the
     * generated guidelines an agent always has in context, so an unregistered skill is effectively
     * invisible — it exists and nothing will ever be told to use it. Scans the directory rather
     * than a fixed list, so skills the application adds itself are picked up too.
     */
    private function registerSkillsInBoostConfig(): void
    {
        $boostPath = base_path('boost.json');

        if (! File::exists($boostPath)) {
            $this->line('  <comment>boost.json not found; skipped skill registration.</comment>');

            return;
        }

        $skillsPath = base_path('.ai/skills');

        if (! File::isDirectory($skillsPath)) {
            return;
        }

        $boostData = json_decode(File::get($boostPath), true);

        if (! is_array($boostData)) {
            $this->error('Failed to parse boost.json');

            return;
        }

        /** @var array<int, string> $registered */
        $registered = array_values(array_filter(
            is_array($boostData['skills'] ?? null) ? $boostData['skills'] : [],
            is_string(...),
        ));

        /** @var array<int, string> $directories */
        $directories = File::directories($skillsPath);

        $found = array_map(static fn (string $directory): string => basename($directory), $directories);

        sort($found);

        $added = array_values(array_diff($found, $registered));

        if ($added === []) {
            $this->line('  <info>All skills already registered in boost.json.</info>');

            return;
        }

        $boostData['skills'] = [...$registered, ...$added];

        $encoded = json_encode($boostData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($boostPath, is_string($encoded) ? $encoded."\n" : '');

        $this->line(sprintf('  <info>Registered %d skill(s) in boost.json:</info> %s', count($added), implode(', ', $added)));
    }

    /**
     * Warn when tests would run against the dev database: a non-sqlite connection
     * with neither a .env.testing nor force-pinned DB_* in phpunit.xml. Pest runs with
     * APP_ENV=testing, so Laravel loads .env.testing, but falls back to .env when
     * there is none.
     */
    private function warnIfTestDatabaseUnisolated(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return;
        }

        if (! preg_match('/^\s*DB_CONNECTION\s*=\s*(.+)$/m', File::get($envPath), $matches)) {
            return;
        }

        $connection = strtolower(trim($matches[1], " \t\"'"));

        if ($connection === '' || $connection === 'sqlite') {
            return;
        }

        if ($this->hasEnvTesting() || $this->phpunitForcesDatabase()) {
            return;
        }

        $this->newLine();
        $this->warn('⚠ Tests may run against your DEV database.');
        $this->line("  DB_CONNECTION={$connection} in .env, but there's no .env.testing and no force-pinned DB_* in phpunit.xml.");
        $this->line('  Pest runs with APP_ENV=testing, so Laravel looks for .env.testing, and without one it loads .env');
        $this->line('  and its database. Fix one of:');
        $this->line('    • Add a .env.testing that names your test database.');
        $this->line('    • Or force-pin the test DB in phpunit.xml, e.g. <env name="DB_DATABASE" value="..." force="true"/>.');
    }

    private function hasEnvTesting(): bool
    {
        return File::exists(base_path('.env.testing'));
    }

    private function phpunitForcesDatabase(): bool
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            $path = base_path($file);

            if (! File::exists($path)) {
                continue;
            }

            if (preg_match('/<env\s+name="DB_DATABASE"[^>]*force="true"/i', File::get($path)) === 1) {
                return true;
            }
        }

        return false;
    }
}
