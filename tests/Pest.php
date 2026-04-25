<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Tests\TestCase;

// Orchestra Testbench is only needed for tests that exercise Artisan
// commands, service providers, or the Laravel container. Pure-PHP unit
// tests (Snapshot, Installer) do not need it.
uses(TestCase::class)->in('Commands', 'LaravelToolkitServiceProviderTest.php');
