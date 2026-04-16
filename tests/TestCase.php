<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tests;

use Nwrman\LaravelToolkit\LaravelToolkitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelToolkitServiceProvider::class,
        ];
    }
}
