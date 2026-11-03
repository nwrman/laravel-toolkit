<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Pest;

/**
 * Selects exact tests by class and name: a delimited, anchored, quoted pattern.
 *
 * Pest matches `--filter` against "<class>::<description><data set>". A bare name
 * matches as a substring (and runs every test whose name contains it); one that is not
 * a delimited pattern is read as PHPUnit's filter syntax, where `#` and `@` start a
 * data-set selector. A `/^…$/` pattern of quoted names is taken as is.
 */
final class RetryFilter
{
    /**
     * @param  array<int, array{class: string, name: string}>  $tests
     */
    public static function pattern(array $tests): string
    {
        $names = array_values(array_unique(array_map(
            fn (array $test): string => preg_quote($test['class'].'::'.$test['name'], '/'),
            $tests,
        )));

        return '/^(?:'.implode('|', $names).')$/';
    }

    /**
     * The command that re-runs one test, ready to paste into a shell.
     */
    public static function command(string $file, string $class, string $name): string
    {
        return sprintf(
            'vendor/bin/pest %s --filter=%s',
            escapeshellarg($file),
            escapeshellarg(self::pattern([['class' => $class, 'name' => $name]])),
        );
    }
}
