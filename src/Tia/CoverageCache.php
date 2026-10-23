<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

use Illuminate\Support\Facades\File;

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
     * Last-modified time, or null when there is no cache.
     */
    public static function modifiedAt(string $directory): ?int
    {
        $file = $directory.DIRECTORY_SEPARATOR.self::FILE;
        clearstatcache(true, $file);

        return File::isFile($file) ? File::lastModified($file) : null;
    }
}
