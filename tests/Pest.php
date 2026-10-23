<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Nwrman\LaravelToolkit\Tests\TestCase;

// Orchestra Testbench is only needed for tests that exercise Artisan
// commands, service providers, or the Laravel container. Pure-PHP unit
// tests (Snapshot, Installer) do not need it.
uses(TestCase::class)->in('Commands', 'Tia', 'LaravelToolkitServiceProviderTest.php');

function git(string $cwd, string ...$arguments): string
{
    $result = Process::path($cwd)
        ->env(['GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com'])
        ->run(['git', ...$arguments])
        ->throw();

    return mb_trim($result->output());
}
