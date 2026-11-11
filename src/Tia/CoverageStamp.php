<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

use Illuminate\Filesystem\Filesystem;

/**
 * What Pest's coverage cache matches: the checkout that recorded it, and the commit
 * and dirty-file hashes of the run that last wrote it.
 *
 * Pest keeps one commit per branch in graph.json and uses it both to pick the tests a
 * change can reach and as the point its coverage cache is current to. Any TIA run with
 * a coverage driver loaded moves that commit, but only a coverage run rewrites the
 * cache. After a plain `composer test`, a coverage replay skips the tests that cover
 * the commits in between and reads their lines from a cache that predates them. The
 * stamp is the cache's own commit, so preflight can move Pest's back to it.
 *
 * It also says whether the run counted Browser specs' hits. A cache from a run that
 * did, read by a gate that leaves them out, reports lines only a Browser spec covers
 * as covered. A stamp from before v1 has no such field: v0's coverage gate ran every
 * suite, so it counted them.
 *
 * Its format says how it hashes uncommitted files. Before format 2 they were stamped
 * with an empty hash, and a coverage cache kept under those stamps can lack files
 * whose tests TIA never replayed, so a stamp in an older format is not trusted.
 */
final readonly class CoverageStamp
{
    public const string FILE = 'toolkit-coverage.json';

    public const int FORMAT = 2;

    /**
     * @param  array<string, string>  $tree
     */
    public function __construct(
        public string $root,
        public string $sha,
        public array $tree,
        public bool $withBrowser = false,
        public int $format = self::FORMAT,
    ) {}

    public static function read(Filesystem $files, string $directory): ?self
    {
        $path = $directory.DIRECTORY_SEPARATOR.self::FILE;

        if (! $files->isFile($path)) {
            return null;
        }

        $stamp = json_decode($files->get($path), true);

        if (! is_array($stamp) || ! is_string($stamp['root'] ?? null) || ! is_string($stamp['sha'] ?? null) || $stamp['sha'] === '') {
            return null;
        }

        /** @var array<string, string> $tree */
        $tree = is_array($stamp['tree'] ?? null) ? $stamp['tree'] : [];

        return new self($stamp['root'], $stamp['sha'], $tree, (bool) ($stamp['browser'] ?? true), is_int($stamp['format'] ?? null) ? $stamp['format'] : 1);
    }

    public function withRoot(string $root): self
    {
        return new self($root, $this->sha, $this->tree, $this->withBrowser, $this->format);
    }

    public function writeTo(Filesystem $files, string $directory): void
    {
        $files->ensureDirectoryExists($directory);
        $files->put($directory.DIRECTORY_SEPARATOR.self::FILE, (string) json_encode([
            'root' => $this->root,
            'sha' => $this->sha,
            'tree' => (object) $this->tree,
            'browser' => $this->withBrowser,
            'format' => $this->format,
        ], JSON_UNESCAPED_SLASHES));
    }
}
