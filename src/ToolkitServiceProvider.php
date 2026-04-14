<?php

declare(strict_types=1);

namespace Nwrman\Toolkit;

use Illuminate\Support\ServiceProvider;
use Nwrman\Toolkit\Commands\DeployNotifyTelegramCommand;
use Nwrman\Toolkit\Commands\InstallCommand;
use Nwrman\Toolkit\Commands\PreflightCommand;
use Nwrman\Toolkit\Commands\TestReportCommand;
use Nwrman\Toolkit\Commands\TestRetryCommand;

final class ToolkitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/toolkit.php', 'toolkit');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/toolkit.php' => config_path('toolkit.php'),
        ], 'toolkit-config');

        $this->publishes([
            __DIR__.'/../stubs/configs/pint.json' => base_path('pint.json'),
            __DIR__.'/../stubs/configs/rector.php' => base_path('rector.php'),
            __DIR__.'/../stubs/configs/phpstan.neon' => base_path('phpstan.neon'),
        ], 'toolkit-quality-configs');

        $this->publishes([
            __DIR__.'/../stubs/workflows/tests.yml' => base_path('.github/workflows/tests.yml'),
        ], 'toolkit-ci');

        $this->publishes([
            __DIR__.'/../stubs/scripts/lint-dirty.ts' => resource_path('js/scripts/lint-dirty.ts'),
        ], 'toolkit-scripts');

        $this->commands([
            PreflightCommand::class,
            TestReportCommand::class,
            TestRetryCommand::class,
            DeployNotifyTelegramCommand::class,
            InstallCommand::class,
        ]);
    }
}
