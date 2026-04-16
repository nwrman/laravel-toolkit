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

    /** @var array<string, string|array<int, string>> */
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
        'test:unit' => 'pest --testsuite=Unit --parallel --compact',
        'test:feature' => 'pest --testsuite=Feature --parallel --compact',
        'test:browser' => 'pest --testsuite=Browser --parallel --compact',
        'test:lint' => [
            'pint --parallel --test',
            'rector --dry-run',
            'bun run test:lint',
        ],
        'test:types' => [
            'phpstan',
            'bun run test:types',
        ],
        'test' => [
            '@test:unit',
            '@test:feature',
            '@test:browser',
            'bun run test:ui',
        ],
        'test:ci' => [
            '@test:type-coverage',
            'XDEBUG_MODE="coverage" pest --parallel --coverage --exactly=100.0',
            'bun run test:coverage',
            '@test:lint',
            '@test:types',
        ],
        'test:report' => [
            'Composer\\Config::disableProcessTimeout',
            '@php artisan toolkit:report',
        ],
        'test:retry' => '@php artisan toolkit:retry',
        'preflight' => [
            'Composer\\Config::disableProcessTimeout',
            '@php artisan toolkit:preflight',
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

        if (confirm('Publish AI skills & guidelines?', true)) {
            $this->call('vendor:publish', [
                '--tag' => 'toolkit-ai',
                '--force' => $this->option('force'),
            ]);
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

        foreach (self::RECOMMENDED_SCRIPTS as $name => $command) {
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
}
