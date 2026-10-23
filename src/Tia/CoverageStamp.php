<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

use Illuminate\Support\Facades\File;

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
 */
final readonly class CoverageStamp
{
    public const string FILE = 'toolkit-coverage.json';

    /**
     * @param  array<string, string>  $tree
     */
    public function __construct(
        public string $root,
        public string $sha,
        public array $tree,
    ) {}

    public static function read(string $directory): ?self
    {
        $path = $directory.DIRECTORY_SEPARATOR.self::FILE;

        if (! File::isFile($path)) {
            return null;
        }

        $stamp = json_decode(File::get($path), true);

        if (! is_array($stamp) || ! is_string($stamp['root'] ?? null) || ! is_string($stamp['sha'] ?? null) || $stamp['sha'] === '') {
            return null;
        }

        /** @var array<string, string> $tree */
        $tree = is_array($stamp['tree'] ?? null) ? $stamp['tree'] : [];

        return new self($stamp['root'], $stamp['sha'], $tree);
    }

    public function withRoot(string $root): self
    {
        return new self($root, $this->sha, $this->tree);
    }

    public function writeTo(string $directory): void
    {
        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.self::FILE, (string) json_encode([
            'root' => $this->root,
            'sha' => $this->sha,
            'tree' => (object) $this->tree,
        ], JSON_UNESCAPED_SLASHES));
    }
}
