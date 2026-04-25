<?php

declare(strict_types=1);

/**
 * Snapshot Filament shape from a reference Laravel app into the toolkit.
 *
 * Usage:
 *   php bin/snapshot-filament.php --from=../nwrman-filament-reference
 *   php bin/snapshot-filament.php --from=/path/to/reference --to=resources/filament-snapshot
 *
 * This script is the single source of truth for how the toolkit produces the
 * Filament snapshot. The GitHub Actions workflow is a thin wrapper that calls
 * this same script — so local runs and CI runs produce byte-identical output.
 */

use Nwrman\LaravelToolkit\Snapshot\FilamentSnapshotter;

$autoload = __DIR__.'/../vendor/autoload.php';

if (! file_exists($autoload)) {
    fwrite(STDERR, "Autoload not found. Run `composer install` first.\n");
    exit(1);
}

require $autoload;

/**
 * @param  list<string>  $argv
 * @return array{from: ?string, to: string}
 */
function parse_args(array $argv): array
{
    $from = null;
    $to = dirname(__DIR__).'/resources/filament-snapshot';

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--from=')) {
            $from = mb_substr($arg, mb_strlen('--from='));

            continue;
        }

        if (str_starts_with($arg, '--to=')) {
            $to = mb_substr($arg, mb_strlen('--to='));

            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, <<<'TEXT'
            Usage:
              php bin/snapshot-filament.php --from=<reference-app-path> [--to=<snapshot-dir>]

            Options:
              --from    Path to the reference Laravel app (required).
              --to      Snapshot output directory (default: resources/filament-snapshot).
              --help    Show this message.

            TEXT);
            exit(0);
        }

        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }

    return ['from' => $from, 'to' => $to];
}

$args = parse_args($argv);

if ($args['from'] === null) {
    fwrite(STDERR, "Missing required --from=<reference-app-path>\n");
    exit(2);
}

$from = realpath($args['from']) ?: $args['from'];
$to = $args['to'];

fwrite(STDOUT, "Snapshotting Filament shape\n");
fwrite(STDOUT, "  from: {$from}\n");
fwrite(STDOUT, "  to:   {$to}\n\n");

try {
    $snapshotter = new FilamentSnapshotter($from, $to);
    $result = $snapshotter->run();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Copied %d file(s), %s bytes.\n", count($result['copied']), number_format($result['bytes'])));

foreach ($result['copied'] as $path) {
    fwrite(STDOUT, "  + {$path}\n");
}

if ($result['skipped_missing'] !== []) {
    fwrite(STDOUT, "\nWarning: the following included paths were not present in the reference app:\n");

    foreach ($result['skipped_missing'] as $path) {
        fwrite(STDOUT, "  ? {$path}\n");
    }
}

fwrite(STDOUT, "\nDone.\n");
exit(0);
