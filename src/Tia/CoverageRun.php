<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

/**
 * A coverage gate in progress: what it covers, and the stamp it set aside. The stamp
 * comes back only when the run leaves the cache as it was, or is replaced once the
 * gate passes, so a cache written by a failed or interrupted run is never trusted.
 */
final readonly class CoverageRun
{
    public function __construct(
        public string $directory,
        public CoverageStamp $checkpoint,
        public ?CoverageStamp $previous,
        public int $startedAt,
    ) {}
}
