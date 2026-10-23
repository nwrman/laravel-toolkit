<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Nwrman\LaravelToolkit\Tia\CoverageCache;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;

it('moves paths under one checkout to another, keeping the data readable', function (): void {
    $coverage = serialize([
        '/repo/t1/app/Models/User.php' => [12 => ['P\\Tests\\UserTest::it works']],
        'files' => ['/repo/t1/app/Example.php', '/repo/t1'],
    ]);

    expect(unserialize(CoverageCache::rebaseSerialized($coverage, '/repo/t1', '/Users/me/projects/main')))->toBe([
        '/Users/me/projects/main/app/Models/User.php' => [12 => ['P\\Tests\\UserTest::it works']],
        'files' => ['/Users/me/projects/main/app/Example.php', '/Users/me/projects/main'],
    ]);
});

it('leaves strings that only look like the old checkout alone', function (string $path): void {
    $coverage = serialize([$path => [1 => ['test']]]);

    expect(CoverageCache::rebaseSerialized($coverage, '/repo/t1', '/repo/main'))->toBe($coverage);
})->with([
    'a sibling checkout sharing the prefix' => '/repo/t1-two/app/Example.php',
    'the path inside a longer string' => 'see /repo/t1/app/Example.php',
    'a string that holds serialized text' => 's:21:"/repo/t1/app/User.php"',
]);

/**
 * A coverage cache as Pest writes it: a gzipped, serialized CodeCoverage.
 *
 * @param  array<string, array<int, array<int, string>|null>>  $lines
 */
function coverageCacheFile(string $path, array $lines): void
{
    $coverage = new CodeCoverage(new class extends Driver
    {
        public function nameAndVersion(): string
        {
            return 'fake';
        }

        public function start(): void {}

        public function stop(): RawCodeCoverageData
        {
            return RawCodeCoverageData::fromXdebugWithoutPathCoverage([]);
        }
    }, new Filter);
    $coverage->getData(true)->setLineCoverage($lines);

    File::put($path, (string) gzencode(serialize($coverage)));
}

/**
 * @return array<string, array<int, array<int, string>|null>>
 */
function cachedLines(string $path): array
{
    return unserialize((string) gzdecode(File::get($path)))->getData(true)->lineCoverage();
}

it('forgets changed files and keeps the rest', function (): void {
    $path = sys_get_temp_dir().'/coverage-'.uniqid().'.bin.gz';
    coverageCacheFile($path, [
        '/repo/app/Changed.php' => [13 => ['t1'], 14 => []],
        '/repo/app/Kept.php' => [8 => ['t2']],
    ]);

    expect(CoverageCache::forget($path, ['/repo/app/Changed.php', '/repo/app/New.php']))->toBe(1)
        ->and(cachedLines($path))->toBe(['/repo/app/Kept.php' => [8 => ['t2']]]);

    File::delete($path);
});

it('reports a cache it cannot read', function (): void {
    $path = sys_get_temp_dir().'/coverage-'.uniqid().'.bin.gz';
    File::put($path, (string) gzencode(serialize(['not' => 'coverage'])));

    expect(CoverageCache::forget($path, ['/repo/app/Changed.php']))->toBeNull();

    File::delete($path);
});
