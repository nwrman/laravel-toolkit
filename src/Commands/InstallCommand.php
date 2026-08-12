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

    private const string GUARD_HOOK_COMMAND = 'php "$CLAUDE_PROJECT_DIR/.claude/hooks/enforce-test-command.php"';

    /**
     * Non-routing recommended scripts. Test-routing scripts are built dynamically
     * by {@see canonicalTestScripts()} so they can pick up `--env=testing`.
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
        'test:ci' => [
            '@test:type-coverage',
            'XDEBUG_MODE="coverage" pest --parallel --coverage --exactly=100.0',
            'bun run test:coverage',
            '@test:lint',
            '@test:types',
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
        }

        if (confirm('Install Claude Code test-command guard hook?', true)) {
            $this->call('vendor:publish', [
                '--tag' => 'toolkit-claude',
                '--force' => $this->option('force'),
            ]);
            $this->mergeClaudeSettings();
        }

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

        if ($changed === []) {
            $this->line('  <info>Test scripts already match the toolkit convention.</info>');

            return;
        }

        $composerData['scripts'] = $scripts;
        $encoded = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($composerPath, is_string($encoded) ? $encoded."\n" : '');

        $this->line(sprintf('  <info>Standardized %d test script(s):</info> %s', count($changed), implode(', ', $changed)));
    }

    /**
     * All recommended scripts: the static set plus the dynamic test-routing set.
     *
     * @return array<string, string|array<int, string>>
     */
    private function recommendedScripts(): array
    {
        return array_merge(self::RECOMMENDED_SCRIPTS, $this->canonicalTestScripts());
    }

    /**
     * The canonical test-routing scripts. Every suite runs through `toolkit:report`
     * so failures land in the structured report. `--env=testing` is injected only
     * when a committed `.env.testing` exists (see {@see warnIfTestDatabaseUnisolated()}).
     *
     * @return array<string, string|array<int, string>>
     */
    private function canonicalTestScripts(): array
    {
        $env = $this->hasEnvTesting() ? ' --env=testing' : '';

        return [
            'test:unit' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report'.$env.' --no-interaction --suite=unit'],
            'test:feature' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report'.$env.' --no-interaction --suite=feature'],
            'test:browser' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report'.$env.' --no-interaction --suite=browser'],
            'test:frontend' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report'.$env.' --no-interaction --suite=frontend'],
            'test' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report'.$env.' --no-interaction'],
            'test:report' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:report'.$env],
            'test:retry' => '@php artisan toolkit:retry'.$env,
            'preflight' => ['Composer\\Config::disableProcessTimeout', '@php artisan toolkit:preflight'.$env],
        ];
    }

    /**
     * Add the Claude Code test-command guard hook to .claude/settings.json,
     * creating the file when absent and skipping when already present.
     */
    private function mergeClaudeSettings(): void
    {
        $settingsDir = base_path('.claude');
        $settingsPath = $settingsDir.'/settings.json';

        File::ensureDirectoryExists($settingsDir);

        $settings = [];

        if (File::exists($settingsPath)) {
            $decoded = json_decode(File::get($settingsPath), true);

            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        /** @var array<int, mixed> $preToolUse */
        $preToolUse = data_get($settings, 'hooks.PreToolUse', []);

        if (! is_array($preToolUse)) {
            $preToolUse = [];
        }

        foreach ($preToolUse as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<int, mixed> $hooks */
            $hooks = is_array($entry['hooks'] ?? null) ? $entry['hooks'] : [];

            foreach ($hooks as $hook) {
                if (is_array($hook) && str_contains((string) ($hook['command'] ?? ''), 'enforce-test-command.php')) {
                    $this->line('  <info>Claude Code guard hook already present in .claude/settings.json.</info>');

                    return;
                }
            }
        }

        $preToolUse[] = [
            'matcher' => 'Bash',
            'hooks' => [
                ['type' => 'command', 'command' => self::GUARD_HOOK_COMMAND],
            ],
        ];

        data_set($settings, 'hooks.PreToolUse', $preToolUse);

        $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($settingsPath, is_string($encoded) ? $encoded."\n" : '');

        $this->line('  <info>Added Claude Code test-command guard hook to .claude/settings.json.</info>');
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

        $found = array_map(
            static fn (string $directory): string => basename($directory),
            File::directories($skillsPath),
        );

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
     * with neither a committed .env.testing nor force-pinned DB_* in phpunit.xml.
     * The `composer test:*` scripts run `php artisan toolkit:report` (an artisan
     * parent that loads .env), so the dev DB config can leak into the pest child.
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
        $this->line("  DB_CONNECTION={$connection} in .env, but there's no committed .env.testing and no force-pinned");
        $this->line('  DB_* in phpunit.xml. `composer test:*` runs `php artisan toolkit:report` (an artisan parent that');
        $this->line('  loads .env), so the dev DB config can leak into the pest child. Fix one of:');
        $this->line('    • Add a committed .env.testing with your test DB, then re-run this installer to wire --env=testing.');
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
