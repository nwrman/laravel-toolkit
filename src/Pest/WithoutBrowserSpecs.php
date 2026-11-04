<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Pest;

use Pest\Contracts\TestCaseFilter;

/**
 * Leaves the Browser suite's files out of a run without telling Pest it is a partial
 * one: `--exclude-testsuite` would switch Test Impact Analysis off.
 */
final readonly class WithoutBrowserSpecs implements TestCaseFilter
{
    public function __construct(private BrowserSuite $suite) {}

    public function accept(string $testCaseFilename): bool
    {
        return ! $this->suite->contains($testCaseFilename);
    }
}
