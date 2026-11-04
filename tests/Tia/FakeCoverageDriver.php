<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tests\Tia;

use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

/**
 * A coverage driver that collects nothing, for building coverage caches by hand.
 * A named class, because a CodeCoverage serializes its driver and PHP cannot
 * serialize an anonymous class.
 */
final class FakeCoverageDriver extends Driver
{
    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function start(): void {}

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromLineCoverage([]);
    }
}
