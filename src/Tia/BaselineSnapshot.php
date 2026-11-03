<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

use Illuminate\Filesystem\Filesystem;

/**
 * One branch's complete TIA baseline: Pest's graph with that branch's recorded
 * results, plus the coverage cache that lets a coverage run replay instead of record,
 * and the stamp saying which checkout and commit that cache matches.
 *
 * Reads and writes Pest's graph.json (schema 1) directly. Pest keys baselines by
 * branch name, so moving a baseline between branches is a re-key. The baseline's
 * commit is the coverage stamp's, not Pest's own: a later plain run may have moved
 * Pest's past what the coverage cache holds.
 */
final readonly class BaselineSnapshot
{
    public const string GRAPH = 'graph.json';

    public const string COVERAGE = CoverageCache::FILE;

    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>  $baseline
     */
    private function __construct(
        private Filesystem $files,
        public string $directory,
        public string $sha,
        private array $graph,
        private array $baseline,
        private CoverageStamp $stamp,
    ) {}

    /**
     * @throws UnusableBaseline
     */
    public static function read(Filesystem $files, string $directory, string $branch): self
    {
        $graphPath = $directory.DIRECTORY_SEPARATOR.self::GRAPH;

        if (! $files->isFile($graphPath)) {
            throw new UnusableBaseline('no TIA graph');
        }

        $graph = json_decode($files->get($graphPath), true);

        if (! is_array($graph) || ($graph['schema'] ?? null) !== 1) {
            throw new UnusableBaseline('a TIA graph this toolkit cannot read');
        }

        /** @var array<string, mixed> $graph */
        $baselines = $graph['baselines'] ?? null;
        $baseline = is_array($baselines) ? ($baselines[$branch] ?? null) : null;

        if (! is_array($baseline)) {
            throw new UnusableBaseline(sprintf('no baseline for %s', $branch));
        }

        /** @var array<string, mixed> $baseline */
        $sha = $baseline['sha'] ?? null;

        // A filtered or stopped-early run writes results with no commit. Diffing
        // from nothing hides every committed change, so it is worse than none.
        if (! is_string($sha) || $sha === '') {
            throw new UnusableBaseline(sprintf('the %s baseline has no commit (a filtered or interrupted run)', $branch));
        }

        if (($baseline['complete'] ?? false) !== true) {
            throw new UnusableBaseline(sprintf('the %s baseline is incomplete', $branch));
        }

        if (! $files->isFile($directory.DIRECTORY_SEPARATOR.self::COVERAGE)) {
            throw new UnusableBaseline('no coverage cache (preflight would re-record everything)');
        }

        $stamp = CoverageStamp::read($files, $directory);

        if (! $stamp instanceof CoverageStamp) {
            throw new UnusableBaseline('no record of the commit its coverage cache matches (a cache no toolkit preflight wrote)');
        }

        return new self($files, $directory, $stamp->sha, $graph, [...$baseline, 'sha' => $stamp->sha, 'tree' => $stamp->tree], $stamp);
    }

    /**
     * Writes this snapshot into $directory as the only baseline, filed under $branch.
     * Given the checkout it is for, the coverage cache's paths move to that checkout.
     */
    public function writeTo(string $directory, string $branch, ?string $root = null): void
    {
        $this->files->ensureDirectoryExists($directory);

        $graph = [...$this->graph, 'baselines' => [$branch => $this->baseline]];

        $this->files->put($directory.DIRECTORY_SEPARATOR.self::GRAPH, (string) json_encode($graph, JSON_UNESCAPED_SLASHES));
        $this->files->copy($this->directory.DIRECTORY_SEPARATOR.self::COVERAGE, $directory.DIRECTORY_SEPARATOR.self::COVERAGE);

        $stamp = $this->stamp;

        if ($root !== null) {
            CoverageCache::rebase($this->files, $directory.DIRECTORY_SEPARATOR.self::COVERAGE, $stamp->root, $root);
            $stamp = $stamp->withRoot($root);
        }

        $stamp->writeTo($this->files, $directory);
    }
}
