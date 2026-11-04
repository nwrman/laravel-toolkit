<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Pest;

/**
 * Keeps the project's dependencies and generated files out of Xdebug's code coverage.
 *
 * Pest's TIA recorder starts Xdebug coverage around every test with no filter
 * (`Pest\Plugins\Tia\Recorder::beginTest()`) and only afterwards drops the files
 * outside its source scope. Xdebug then records every `vendor/` line of every test: a
 * recording run took 505s on a 659-test suite whose plain run takes 40s, and 57s with
 * this filter. These are the directories `Pest\Plugins\Tia\SourceScope` leaves out
 * anyway, so the recorded graph is the same.
 *
 * A coverage-report run is left alone: PHPUnit's Xdebug driver sets its own filter.
 * An app whose phpunit.xml `<source>` includes a directory inside `vendor/` would lose
 * that directory's edges.
 */
final class XdebugFilter
{
    private const array EXCLUDED = ['vendor', 'node_modules', '.git', 'storage/framework', 'bootstrap/cache'];

    /**
     * @param  array<int, string>  $arguments
     */
    public static function apply(string $root, array $arguments): void
    {
        if (! self::collectsCoverage() || array_any($arguments, fn (string $argument): bool => str_starts_with($argument, '--coverage'))) {
            return;
        }

        $paths = [];

        foreach (self::EXCLUDED as $directory) {
            $path = $root.DIRECTORY_SEPARATOR.$directory;

            if (is_dir($path)) {
                // Xdebug matches the path a file was loaded by: list both through a symlinked root.
                $paths[] = $path.DIRECTORY_SEPARATOR;
                $paths[] = (realpath($path) ?: $path).DIRECTORY_SEPARATOR;
            }
        }

        if ($paths !== []) {
            xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_EXCLUDE, array_values(array_unique($paths)));
        }
    }

    private static function collectsCoverage(): bool
    {
        if (! function_exists('xdebug_set_filter') || ! function_exists('xdebug_info')) {
            return false;
        }

        $modes = xdebug_info('mode');

        return is_array($modes) && in_array('coverage', $modes, true);
    }
}
