<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

/**
 * A coverage gate in progress: the cache directory, the stamp its cache earns if the run
 * rewrites it, and when the run started.
 */
final readonly class CoverageRun
{
    public function __construct(
        public string $directory,
        public CoverageStamp $checkpoint,
        public int $startedAt,
    ) {}
}
