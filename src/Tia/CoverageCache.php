<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

use Illuminate\Support\Facades\File;
use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * Pest's coverage cache: a gzipped, PHP-serialized CodeCoverage object that names every
 * source file by its absolute path. A cache recorded in one checkout and read in another
 * lists the first checkout's files beside the second's, so each file is counted twice
 * and the total drops. Moving the cache between checkouts rewrites those paths.
 */
final class CoverageCache
{
    public const string FILE = 'coverage.bin.gz';

    /**
     * Rewrites every path under $from to sit under $to.
     */
    public static function rebase(string $file, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $bytes = @gzdecode(File::get($file));

        if ($bytes === false) {
            return;
        }

        File::put($file, (string) gzencode(self::rebaseSerialized($bytes, $from, $to)));
    }

    /**
     * Rewrites serialized strings that are $from or start with "$from/". Each string is
     * read whole by its declared length, so a path inside another string's bytes is never
     * mistaken for one, and every rewritten string gets its new length.
     */
    public static function rebaseSerialized(string $serialized, string $from, string $to): string
    {
        $prefix = mb_rtrim($from, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $out = '';
        $offset = 0;

        while (preg_match('/s:(\d+):"/', $serialized, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $length = (int) $match[1][0];
            $bodyStart = $start + strlen($match[0][0]);
            $body = substr($serialized, $bodyStart, $length);

            if ($body === mb_rtrim($from, DIRECTORY_SEPARATOR)) {
                $body = mb_rtrim($to, DIRECTORY_SEPARATOR);
            } elseif (str_starts_with($body, $prefix)) {
                $body = mb_rtrim($to, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.substr($body, strlen($prefix));
            }

            $out .= substr($serialized, $offset, $start - $offset).sprintf('s:%d:"', strlen($body)).$body;
            $offset = $bodyStart + $length;
        }

        return $out.substr($serialized, $offset);
    }

    /**
     * Removes $paths from the cache, and returns how many it held, or null when the cache
     * cannot be read.
     *
     * Pest merges a run's coverage into the cache line by line. When a file changed, the
     * cache still holds the old version's line numbers: a line that was code then and is
     * a comment or a closing bracket now stays "executable, not covered", because the new
     * run never writes that line. Removing a changed file lets the run fill it in whole;
     * TIA replays every test that touches it.
     *
     * @param  array<int, string>  $paths
     */
    public static function forget(string $file, array $paths): ?int
    {
        $bytes = @gzdecode(File::get($file));
        $coverage = $bytes === false ? null : @unserialize($bytes);

        if (! $coverage instanceof CodeCoverage) {
            return null;
        }

        $data = $coverage->getData(true);
        $lines = $data->lineCoverage();
        $functions = $data->functionCoverage();
        $held = array_intersect_key($lines, array_flip($paths));

        if ($held === []) {
            return 0;
        }

        $data->setLineCoverage(array_diff_key($lines, $held));
        $data->setFunctionCoverage(array_diff_key($functions, $held));
        File::put($file, (string) gzencode(serialize($coverage)));

        return count($held);
    }

    /**
     * Last-modified time, or null when there is no cache.
     */
    public static function modifiedAt(string $directory): ?int
    {
        $file = $directory.DIRECTORY_SEPARATOR.self::FILE;
        clearstatcache(true, $file);

        return File::isFile($file) ? File::lastModified($file) : null;
    }
}
