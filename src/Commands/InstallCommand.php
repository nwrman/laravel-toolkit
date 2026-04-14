<?php

declare(strict_types=1);

namespace Nwrman\Toolkit\Commands;

use Illuminate\Console\Command;
use Override;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

final class InstallCommand extends Command
{
    #[Override]
    protected $signature = 'toolkit:install
        {--force : Overwrite existing files}
        {--no-interaction : Skip confirmation prompts}';

    #[Override]
    protected $description = 'Install toolkit configuration files and composer scripts';

    public function handle(): int
    {
        info('Installing nwrman/laravel-toolkit...');

        $this->publishConfigs();
        $this->publishCiWorkflow();
        $this->publishScripts();
        $this->injectComposerScripts();

        info('✓ Toolkit installed successfully!');
        $this->newLine();
        $this->line('Available commands:');
        $this->line('  <info>php artisan test:preflight</info>   Run all CI gates in parallel');
        $this->line('  <info>php artisan test:report</info>      Run tests with failure reporting');
        $this->line('  <info>php artisan test:retry</info>       Re-run previously failed tests');
        $this->line('  <info>composer preflight</info>            Shortcut for test:preflight');
        $this->line('  <info>composer test:report</info>          Shortcut for test:report');
        $this->line('  <info>composer test:retry</info>           Shortcut for test:retry');

        return 0;
    }

    private function publishConfigs(): void
    {
        $force = (bool) $this->option('force');

        $this->callSilent('vendor:publish', [
            '--tag' => 'toolkit-config',
            '--force' => $force,
        ]);

        $configs = [
            'pint.json' => base_path('pint.json'),
            'rector.php' => base_path('rector.php'),
            'phpstan.neon' => base_path('phpstan.neon'),
        ];

        foreach ($configs as $name => $target) {
            if (! $force && file_exists($target)) {
                if ($this->option('no-interaction') || ! confirm("Overwrite existing {$name}?", default: false)) {
                    warning("Skipped {$name} (already exists).");

                    continue;
                }
            }

            $source = __DIR__.'/../../stubs/configs/'.$name;
            if (file_exists($source)) {
                copy($source, $target);
                info("Published {$name}");
            }
        }
    }

    private function publishCiWorkflow(): void
    {
        $target = base_path('.github/workflows/tests.yml');

        if (! $this->option('force') && file_exists($target)) {
            if ($this->option('no-interaction') || ! confirm('Overwrite existing .github/workflows/tests.yml?', default: false)) {
                warning('Skipped CI workflow (already exists).');

                return;
            }
        }

        $dir = dirname($target);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $source = __DIR__.'/../../stubs/workflows/tests.yml';
        if (file_exists($source)) {
            copy($source, $target);
            info('Published .github/workflows/tests.yml');
        }
    }

    private function publishScripts(): void
    {
        $target = resource_path('js/scripts/lint-dirty.ts');

        if (! $this->option('force') && file_exists($target)) {
            if ($this->option('no-interaction') || ! confirm('Overwrite existing lint-dirty.ts?', default: false)) {
                warning('Skipped lint-dirty.ts (already exists).');

                return;
            }
        }

        $dir = dirname($target);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $source = __DIR__.'/../../stubs/scripts/lint-dirty.ts';
        if (file_exists($source)) {
            copy($source, $target);
            info('Published resources/js/scripts/lint-dirty.ts');
        }
    }

    private function injectComposerScripts(): void
    {
        $composerPath = base_path('composer.json');

        if (! file_exists($composerPath)) {
            warning('composer.json not found. Skipping script injection.');

            return;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);

        if (! is_array($composer)) {
            warning('Could not parse composer.json. Skipping script injection.');

            return;
        }

        $scripts = [
            'dev' => [
                'Composer\\Config::disableProcessTimeout',
                'bunx concurrently -c "#c4b5fd,#fb7185,#fdba74" "php artisan queue:listen --tries=1" "php artisan pail --timeout=0" "bun run dev" --names=queue,logs,vite --kill-others',
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
                '@php artisan test:report',
            ],
            'test:retry' => '@php artisan test:retry',
            'preflight' => [
                'Composer\\Config::disableProcessTimeout',
                '@php artisan test:preflight',
            ],
        ];

        $existingScripts = $composer['scripts'] ?? [];
        $added = 0;
        $skipped = 0;

        foreach ($scripts as $name => $command) {
            if (isset($existingScripts[$name])) {
                $skipped++;

                continue;
            }

            $existingScripts[$name] = $command;
            $added++;
        }

        $composer['scripts'] = $existingScripts;

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (is_string($encoded)) {
            file_put_contents($composerPath, $encoded."\n");
        }

        info(sprintf('Composer scripts: %d added, %d skipped (already exist).', $added, $skipped));
    }
}
