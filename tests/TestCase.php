<?php

declare(strict_types=1);

namespace Nwrman\Toolkit\Tests;

use Nwrman\Toolkit\ToolkitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ToolkitServiceProvider::class,
        ];
    }
}
