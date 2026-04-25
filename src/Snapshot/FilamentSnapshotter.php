<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Snapshot;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Snapshots the canonical Filament shape from a reference Laravel app
 * into the toolkit's `resources/filament-snapshot/` directory.
 *
 * Deterministic: given the same reference app state, produces byte-identical
 * output across machines and runs. Local and CI invocations share this logic.
 */
final class FilamentSnapshotter
{
    /**
     * Paths relative to the reference app root that are part of the Filament shape.
     *
     * Each entry is either a file path or a directory path. Directories are
     * recursively included. Hidden files (`.DS_Store`, `.gitignore`, etc.)
     * are always skipped.
     *
     * @var list<string>
     */
    public const array INCLUDED_PATHS = [
        'app/Filament',
        'app/Providers/Filament',
        'database/seeders/AdminUserSeeder.php',
    ];

    /**
     * Glob-like patterns (matched against relative paths) that are also included
     * but only if they match — used for migrations whose filenames contain dates.
     *
     * @var list<string>
     */
    public const array INCLUDED_MIGRATION_PATTERNS = [
        'database/migrations/*_add_is_admin_to_users_table.php',
    ];

    public function __construct(
        private readonly string $referenceAppPath,
        private readonly string $snapshotPath,
    ) {
        //
    }

    /**
     * Run the snapshot. Returns a summary.
     *
     * @return array{copied: list<string>, skipped_missing: list<string>, bytes: int}
     */
    public function run(): array
    {
        $this->assertValidReferenceApp();
        $this->resetSnapshotDir();

        $copied = [];
        $skippedMissing = [];
        $bytes = 0;

        foreach ($this->collectFiles() as $relativePath => $status) {
            if ($status === 'missing') {
                $skippedMissing[] = $relativePath;

                continue;
            }

            $source = $this->referenceAppPath.'/'.$relativePath;
            $destination = $this->snapshotPath.'/'.$relativePath;

            $this->ensureDirectory(dirname($destination));

            $contents = (string) file_get_contents($source);
            $normalized = $this->normalize($contents);

            file_put_contents($destination, $normalized);

            $copied[] = $relativePath;
            $bytes += mb_strlen($normalized);
        }

        sort($copied);
        sort($skippedMissing);

        return [
            'copied' => $copied,
            'skipped_missing' => $skippedMissing,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return iterable<string, 'present'|'missing'>
     */
    private function collectFiles(): iterable
    {
        $seen = [];

        foreach (self::INCLUDED_PATHS as $path) {
            $absolute = $this->referenceAppPath.'/'.$path;

            if (! file_exists($absolute)) {
                yield $path => 'missing';

                continue;
            }

            if (is_dir($absolute)) {
                foreach ($this->walkDirectory($absolute) as $file) {
                    $relative = mb_ltrim(mb_substr($file, mb_strlen($this->referenceAppPath)), '/');

                    if (isset($seen[$relative])) {
                        continue;
                    }

                    $seen[$relative] = true;
                    yield $relative => 'present';
                }

                continue;
            }

            $seen[$path] = true;
            yield $path => 'present';
        }

        foreach (self::INCLUDED_MIGRATION_PATTERNS as $pattern) {
            $matches = glob($this->referenceAppPath.'/'.$pattern) ?: [];
            sort($matches);

            foreach ($matches as $match) {
                $relative = mb_ltrim(mb_substr($match, mb_strlen($this->referenceAppPath)), '/');

                if (isset($seen[$relative])) {
                    continue;
                }

                $seen[$relative] = true;
                yield $relative => 'present';
            }
        }
    }

    /**
     * @return list<string>
     */
    private function walkDirectory(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                continue;
            }

            $name = $entry->getFilename();

            // Skip hidden files and common cruft.
            if (str_starts_with($name, '.')) {
                continue;
            }

            $files[] = $entry->getPathname();
        }

        sort($files);

        return $files;
    }

    private function normalize(string $contents): string
    {
        // Normalize line endings to LF for cross-platform determinism.
        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);

        // Ensure trailing newline for text files.
        if ($normalized !== '' && ! str_ends_with($normalized, "\n")) {
            $normalized .= "\n";
        }

        return $normalized;
    }

    private function assertValidReferenceApp(): void
    {
        if (! is_dir($this->referenceAppPath)) {
            throw new RuntimeException(sprintf(
                'Reference app path does not exist: %s',
                $this->referenceAppPath,
            ));
        }

        $markers = ['artisan', 'composer.json', 'app'];

        foreach ($markers as $marker) {
            if (! file_exists($this->referenceAppPath.'/'.$marker)) {
                throw new RuntimeException(sprintf(
                    'Reference app path does not look like a Laravel app (missing %s): %s',
                    $marker,
                    $this->referenceAppPath,
                ));
            }
        }
    }

    private function resetSnapshotDir(): void
    {
        if (is_dir($this->snapshotPath)) {
            $this->removeDirectory($this->snapshotPath);
        }

        $this->ensureDirectory($this->snapshotPath);
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0o755, true);
        }
    }
}
