<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit;

use Illuminate\Support\ServiceProvider;
use Nwrman\LaravelToolkit\Commands\InstallCommand;
use Nwrman\LaravelToolkit\Commands\PreflightCommand;
use Nwrman\LaravelToolkit\Commands\TestReportCommand;
use Nwrman\LaravelToolkit\Commands\TestRetryCommand;

final class LaravelToolkitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->mergeConfigFrom(__DIR__.'/../config/toolkit.php', 'toolkit');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->deepMergeConfig();
        $this->registerCommands();
        $this->registerPublishing();
    }

    private function deepMergeConfig(): void
    {
        /** @var array<string, mixed> $published */
        $published = config('toolkit', []);

        /** @var array<string, mixed> $package */
        $package = require __DIR__.'/../config/toolkit.php';

        // Deep-merge gates so consumers can override a single gate without losing others
        if (isset($published['gates']) && is_array($published['gates'])) {
            $merged = array_replace_recursive($package['gates'] ?? [], $published['gates']);

            // Allow null to remove a gate
            $merged = array_filter($merged, fn (mixed $value): bool => $value !== null);

            config(['toolkit.gates' => $merged]);
        }
    }

    private function registerCommands(): void
    {
        PreflightCommand::prohibit($this->app->isProduction());

        $this->commands([
            PreflightCommand::class,
            TestReportCommand::class,
            TestRetryCommand::class,
            InstallCommand::class,
        ]);
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/toolkit.php' => config_path('toolkit.php'),
        ], 'toolkit-config');

        $this->publishes([
            __DIR__.'/../stubs/pint.json' => base_path('pint.json'),
            __DIR__.'/../stubs/phpstan.neon' => base_path('phpstan.neon'),
        ], 'toolkit-static-analysis');

        $this->publishes([
            __DIR__.'/../stubs/ai/skills' => base_path('.ai/skills'),
            __DIR__.'/../stubs/ai/guidelines' => base_path('.ai/guidelines'),
        ], 'toolkit-ai');

        $this->publishes([
            __DIR__.'/../stubs/github/workflows/tests.yml' => base_path('.github/workflows/tests.yml'),
        ], 'toolkit-github');

        $this->publishes([
            __DIR__.'/../stubs/scripts/cloud-build.sh' => base_path('scripts/cloud-build.sh'),
            __DIR__.'/../stubs/scripts/cloud-deploy.sh' => base_path('scripts/cloud-deploy.sh'),
            __DIR__.'/../stubs/scripts/lint-dirty.ts' => base_path('resources/js/scripts/lint-dirty.ts'),
        ], 'toolkit-scripts');

        $this->publishes([
            __DIR__.'/../stubs/commands/DeployNotifyTelegramCommand.php' => app_path('Console/Commands/DeployNotifyTelegramCommand.php'),
            __DIR__.'/../stubs/commands/DeployNotifyTelegramCommandTest.php' => base_path('tests/Feature/Console/Commands/DeployNotifyTelegramCommandTest.php'),
        ], 'toolkit-commands');
    }
}
