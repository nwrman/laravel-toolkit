<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Hands complete TIA baselines from landed work to new work, so no checkout records
 * one up front.
 *
 * A ticket ends with a green coverage run at its final commit: a complete baseline of
 * its base branch plus the ticket. Once that commit is merged, `publish` files it in a
 * per-repository store under the base branch's name. A new checkout cut from that base
 * `inherit`s it under its own branch name, and its first run replays only what
 * changed since. The store lives in the repository's common git directory, which
 * every worktree shares and nothing else reads.
 *
 * Pest diffs a working tree against the baseline's commit and ignores a commit
 * outside the branch's history, so every hand-over checks reachability.
 */
final readonly class TiaBaselines
{
    public function __construct(private PestCache $cache) {}

    /**
     * Why the checkout's current branch has no usable baseline, or null when it has one.
     */
    public function problem(string $root): ?string
    {
        $branch = $this->branch($root);
        $directory = $this->cache->directory($root);

        if ($branch === null || $directory === null) {
            return $branch === null ? 'not on a branch' : 'Pest reports no TIA cache directory';
        }

        return $this->problemWith($root, $branch, $directory);
    }

    public function inherit(string $root, ?string $base = null): string
    {
        $branch = $this->branch($root);
        $directory = $this->cache->directory($root);

        if ($branch === null || $directory === null) {
            return $branch === null ? 'Not on a branch: nothing to inherit into.' : 'Pest reports no TIA cache directory: nothing to inherit into.';
        }

        if ($this->problemWith($root, $branch, $directory) === null) {
            return sprintf('%s already has a usable TIA baseline.', $branch);
        }

        $base ??= $this->baseFor($root, $branch);

        try {
            $snapshot = BaselineSnapshot::read($this->storeFor($root, $base), $base);
        } catch (UnusableBaseline $unusable) {
            return sprintf('No usable TIA baseline published for %s (%s). The first `composer test` records one.', $base, $unusable->getMessage());
        }

        if (! $this->isAncestor($root, $snapshot->sha, 'HEAD')) {
            return sprintf('The published %s baseline (%s) is not in this branch\'s history. The first `composer test` records one.', $base, mb_substr($snapshot->sha, 0, 7));
        }

        $snapshot->writeTo($directory, $branch, $this->realRoot($root));

        return sprintf('Inherited the %s TIA baseline at %s.', $base, mb_substr($snapshot->sha, 0, 7));
    }

    /**
     * Makes the coverage cache safe for the next coverage run, before it starts.
     *
     * A cache from another checkout has its paths moved here. A cache whose commit is
     * known and in this branch's history gets Pest's commit moved back to it, so the run
     * replays every test the commits since can reach. A cache with no known commit, or
     * one outside the history, is deleted and the run records coverage afresh.
     * Returns what it did, or null when the cache was already right.
     */
    public function alignCoverage(string $root): ?string
    {
        $directory = $this->cache->directory($root);
        $branch = $this->branch($root);

        if ($directory === null || $branch === null || CoverageCache::modifiedAt($directory) === null) {
            return null;
        }

        $stamp = CoverageStamp::read($directory);

        if (! $stamp instanceof CoverageStamp || ! $this->isAncestor($root, $stamp->sha, 'HEAD')) {
            File::delete($directory.DIRECTORY_SEPARATOR.CoverageCache::FILE);
            File::delete($directory.DIRECTORY_SEPARATOR.CoverageStamp::FILE);

            return $stamp instanceof CoverageStamp
                ? sprintf('TIA: the coverage cache is from %s, outside this branch\'s history. Recording coverage afresh.', mb_substr($stamp->sha, 0, 7))
                : 'TIA: no record of the commit the coverage cache matches. Recording coverage afresh.';
        }

        $done = [];
        $here = $this->realRoot($root);

        if ($stamp->root !== $here) {
            CoverageCache::rebase($directory.DIRECTORY_SEPARATOR.CoverageCache::FILE, $stamp->root, $here);
            $stamp->withRoot($here)->writeTo($directory);
            $done[] = sprintf('moved the coverage cache\'s paths from %s', $stamp->root);
        }

        if ($this->rewindGraph($directory, $branch, $stamp)) {
            $done[] = sprintf('replaying from %s, the commit the coverage cache matches', mb_substr($stamp->sha, 0, 7));
        }

        $changed = array_map(fn (string $path): string => $here.DIRECTORY_SEPARATOR.$path, $this->changedSince($root, $stamp));
        $forgotten = $changed === [] ? 0 : CoverageCache::forget($directory.DIRECTORY_SEPARATOR.CoverageCache::FILE, $changed);

        if ($forgotten === null) {
            File::delete($directory.DIRECTORY_SEPARATOR.CoverageCache::FILE);
            File::delete($directory.DIRECTORY_SEPARATOR.CoverageStamp::FILE);

            return 'TIA: the coverage cache could not be read. Recording coverage afresh.';
        }

        if ($forgotten > 0) {
            $done[] = sprintf('re-covering %d changed file%s', $forgotten, $forgotten === 1 ? '' : 's');
        }

        return $done === [] ? null : 'TIA: '.implode('; ', $done).'.';
    }

    /**
     * Sets the coverage cache's stamp aside before a coverage gate runs, and notes what
     * the run covers: the checked-out commit, plus every file that differs from it.
     * Uncommitted files go in with an empty hash, which matches no file, so the run after
     * this one replays them whatever they hold by then. With the stamp aside, a run that
     * dies part-way leaves a cache the next run will not trust.
     */
    public function startCoverageRun(string $root): ?CoverageRun
    {
        $directory = $this->cache->directory($root);
        $sha = $this->git($root, ['rev-parse', 'HEAD']);

        if ($directory === null || $sha === null || $sha === '') {
            return null;
        }

        $changed = (string) $this->git($root, ['diff', '--name-only', 'HEAD']);
        $untracked = (string) $this->git($root, ['ls-files', '--others', '--exclude-standard']);
        $dirty = preg_split('/\R/', mb_trim($changed."\n".$untracked), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $previous = CoverageStamp::read($directory);
        File::delete($directory.DIRECTORY_SEPARATOR.CoverageStamp::FILE);

        return new CoverageRun($directory, new CoverageStamp($this->realRoot($root), $sha, array_fill_keys($dirty, '')), $previous, time());
    }

    /**
     * Settles the stamp once the coverage gate is done.
     *
     * Pest writes its coverage cache whether the tests passed or not, and a test that
     * stopped part-way leaves only the lines it reached. So a cache the run rewrote is
     * stamped only when the gate passed, on the commit it started on. A failed gate's
     * cache is deleted, and the next run records coverage afresh. A cache the run did
     * not touch gets its old stamp back. Returns a line to print, or null.
     */
    public function finishCoverageRun(string $root, CoverageRun $run, bool $passed): ?string
    {
        $modifiedAt = CoverageCache::modifiedAt($run->directory);

        if ($modifiedAt === null || $modifiedAt < $run->startedAt) {
            $run->previous?->writeTo($run->directory);

            return null;
        }

        if (! $passed) {
            File::delete($run->directory.DIRECTORY_SEPARATOR.CoverageCache::FILE);

            return 'TIA: the coverage gate failed, so the coverage it saved is not trusted. The next run records coverage afresh.';
        }

        if ($this->git($root, ['rev-parse', 'HEAD']) === $run->checkpoint->sha) {
            $run->checkpoint->writeTo($run->directory);
        }

        return null;
    }

    /**
     * Files that differ from what the stamp covered: committed since its commit, uncommitted
     * now, or uncommitted when it was taken. Paths relative to the checkout.
     *
     * @return array<int, string>
     */
    private function changedSince(string $root, CoverageStamp $stamp): array
    {
        $output = implode("\n", [
            (string) $this->git($root, ['diff', '--name-only', $stamp->sha]),
            (string) $this->git($root, ['ls-files', '--others', '--exclude-standard']),
        ]);
        $files = preg_split('/\R/', $output, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique([...$files, ...array_keys($stamp->tree)]));
    }

    /**
     * Moves Pest's commit for $branch back to the stamp's. Returns whether it moved.
     */
    private function rewindGraph(string $directory, string $branch, CoverageStamp $stamp): bool
    {
        $path = $directory.DIRECTORY_SEPARATOR.BaselineSnapshot::GRAPH;
        $graph = File::isFile($path) ? json_decode(File::get($path), true) : null;

        if (! is_array($graph) || ! is_array($graph['baselines'] ?? null)) {
            return false;
        }

        // A branch with no baseline of its own runs on its base's, so it gets one that
        // carries only the commit; its results still come from the base.
        $baseline = is_array($graph['baselines'][$branch] ?? null) ? $graph['baselines'][$branch] : ['results' => []];

        if (($baseline['sha'] ?? null) === $stamp->sha && ($baseline['tree'] ?? []) == $stamp->tree) {
            return false;
        }

        $graph['baselines'][$branch] = [...$baseline, 'sha' => $stamp->sha, 'tree' => (object) $stamp->tree];
        File::put($path, (string) json_encode($graph, JSON_UNESCAPED_SLASHES));

        return true;
    }

    /**
     * The checkout's path as Pest's coverage names it: symlinks resolved.
     */
    private function realRoot(string $root): string
    {
        return realpath($root) ?: $root;
    }

    private function problemWith(string $root, string $branch, string $directory): ?string
    {
        try {
            $snapshot = BaselineSnapshot::read($directory, $branch);
        } catch (UnusableBaseline $unusable) {
            return $unusable->getMessage();
        }

        return $this->isAncestor($root, $snapshot->sha, 'HEAD')
            ? null
            : sprintf('the baseline commit %s is not in this branch\'s history', mb_substr($snapshot->sha, 0, 7));
    }

    public function publish(string $root, ?string $base = null): string
    {
        $branch = $this->branch($root);
        $directory = $this->cache->directory($root);

        if ($branch === null || $directory === null) {
            return $branch === null ? 'Not on a branch: nothing to publish.' : 'Pest reports no TIA cache directory: nothing to publish.';
        }

        $base ??= $this->baseFor($root, $branch);

        try {
            $snapshot = BaselineSnapshot::read($directory, $branch);
        } catch (UnusableBaseline $unusable) {
            return sprintf('Not published: %s.', $unusable->getMessage());
        }

        $target = $this->landedOn($root, $snapshot->sha, $base);

        if ($target === null) {
            return sprintf('Not published: %s is not merged into %s yet.', mb_substr($snapshot->sha, 0, 7), $base);
        }

        $store = $this->storeFor($root, $base);

        if ($this->hasNewer($root, $store, $base, $snapshot->sha, $target)) {
            return sprintf('Not published: the %s baseline already on file is newer.', $base);
        }

        $staging = $store.'.tmp-'.getmypid();
        File::deleteDirectory($staging);
        $snapshot->writeTo($staging, $base);
        File::deleteDirectory($store);
        File::moveDirectory($staging, $store);

        return sprintf('Published the TIA baseline at %s for %s.', mb_substr($snapshot->sha, 0, 7), $base);
    }

    /**
     * The branch a ticket was cut from: what `start-ticket.sh` recorded in
     * `branch.<name>.tiaBase`, else the remote's default branch.
     */
    public function baseFor(string $root, string $branch): string
    {
        $recorded = $this->git($root, ['config', '--get', sprintf('branch.%s.tiaBase', $branch)]);

        if ($recorded !== null && $recorded !== '') {
            return $recorded;
        }

        $default = $this->git($root, ['symbolic-ref', '--short', 'refs/remotes/origin/HEAD']);

        return $default !== null && str_starts_with($default, 'origin/') ? mb_substr($default, 7) : 'main';
    }

    private function branch(string $root): ?string
    {
        $branch = $this->git($root, ['branch', '--show-current']);

        return $branch === null || $branch === '' ? null : $branch;
    }

    private function storeFor(string $root, string $base): string
    {
        $common = (string) $this->git($root, ['rev-parse', '--path-format=absolute', '--git-common-dir']);

        return $common.DIRECTORY_SEPARATOR.'pest-tia'.DIRECTORY_SEPARATOR.rawurlencode($base);
    }

    /**
     * The ref of $base that contains $sha, local first. A PR merged on GitHub reaches
     * the remote-tracking ref before the local branch, hence the fetch.
     */
    private function landedOn(string $root, string $sha, string $base): ?string
    {
        $this->git($root, ['fetch', '--quiet', 'origin', $base]);

        foreach ([$base, 'origin/'.$base] as $ref) {
            if ($this->isAncestor($root, $sha, $ref)) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * Whether the store already holds a baseline closer to the tip of $ref than $sha.
     * Tickets land out of order, so the last one to publish is not always the newest.
     */
    private function hasNewer(string $root, string $store, string $base, string $sha, string $ref): bool
    {
        try {
            $current = BaselineSnapshot::read($store, $base);
        } catch (UnusableBaseline) {
            return false;
        }

        if (! $this->isAncestor($root, $current->sha, $ref)) {
            return false;
        }

        return $this->distance($root, $current->sha, $ref) < $this->distance($root, $sha, $ref);
    }

    private function distance(string $root, string $sha, string $ref): int
    {
        return (int) $this->git($root, ['rev-list', '--count', $sha.'..'.$ref]);
    }

    private function isAncestor(string $root, string $sha, string $ref): bool
    {
        return Process::path($root)->run(['git', 'merge-base', '--is-ancestor', $sha, $ref])->successful();
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function git(string $root, array $arguments): ?string
    {
        $result = Process::path($root)->run(['git', ...$arguments]);

        return $result->successful() ? mb_trim($result->output()) : null;
    }
}
