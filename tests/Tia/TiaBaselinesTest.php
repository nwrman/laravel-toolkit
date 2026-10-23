<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Nwrman\LaravelToolkit\Tia\CoverageStamp;
use Nwrman\LaravelToolkit\Tia\PestCache;
use Nwrman\LaravelToolkit\Tia\TiaBaselines;

/**
 * A real repository: `main` with a bare `origin`, tickets in their own worktrees.
 * Pest itself is the one edge faked — its cache directory per checkout — and the
 * graphs written into it are what a Pest run leaves behind.
 */
beforeEach(function (): void {
    $this->tmp = realpath(sys_get_temp_dir()).'/tia-'.uniqid();
    $this->repo = $this->tmp.'/app';
    $this->caches = $this->tmp.'/caches';

    File::ensureDirectoryExists($this->repo);
    git($this->tmp, 'init', '--quiet', '--bare', '--initial-branch=main', 'origin.git');
    git($this->repo, 'init', '--quiet', '--initial-branch=main');
    git($this->repo, 'remote', 'add', 'origin', $this->tmp.'/origin.git');
    git($this->repo, 'commit', '--quiet', '--allow-empty', '-m', 'base');
    git($this->repo, 'push', '--quiet', 'origin', 'main');

    $caches = $this->caches;
    app()->instance(PestCache::class, new class($caches) extends PestCache
    {
        public function __construct(private readonly string $caches) {}

        public function directory(string $root): string
        {
            return $this->caches.'/'.md5($root);
        }
    });

    $this->baselines = app(TiaBaselines::class);
});

afterEach(function (): void {
    File::deleteDirectory($this->tmp);
});

/**
 * Cuts a worktree for $branch from $base and commits once on it, as a ticket would.
 */
function ticket(string $repo, string $branch, string $base = 'main'): string
{
    $path = dirname($repo).'/'.str_replace('/', '-', $branch);
    git($repo, 'worktree', 'add', '--quiet', '-b', $branch, $path, $base);
    git($path, 'commit', '--quiet', '--allow-empty', '-m', $branch);

    return $path;
}

/**
 * What a run leaves in a checkout's cache: the graph with this branch's baseline, and
 * (after a coverage preflight) the coverage cache with its stamp. The coverage names
 * one source file by its absolute path, as Pest's does.
 */
function recordRun(string $caches, string $checkout, string $branch, ?string $sha, bool $complete = true, bool $coverage = true, bool $stamp = true): void
{
    $cacheDir = cacheOf($caches, $checkout);
    $baseline = ['sha' => $sha, 'tree' => [], 'results' => ['tests/ExampleTest.php::it works' => ['status' => 0, 'message' => '', 'time' => 0.01, 'assertions' => 1]]];

    if ($complete) {
        $baseline['complete'] = true;
    }

    File::ensureDirectoryExists($cacheDir);
    File::put($cacheDir.'/graph.json', (string) json_encode([
        'schema' => 1,
        'fingerprint' => ['structural' => 'abc'],
        'files' => ['app/Example.php'],
        'edges' => ['tests/ExampleTest.php' => [0]],
        'baselines' => [$branch => $baseline],
    ]));

    if ($coverage) {
        File::put($cacheDir.'/coverage.bin.gz', (string) gzencode(serialize([$checkout.'/app/Example.php' => [3 => ['tests/ExampleTest.php::it works']]])));
    }

    if ($coverage && $stamp && $sha !== null) {
        new CoverageStamp($checkout, $sha, [])->writeTo($cacheDir);
    }
}

/**
 * The source files the checkout's coverage cache names.
 *
 * @return array<int, string>
 */
function coveredFiles(string $cacheDir): array
{
    return array_keys(unserialize((string) gzdecode(File::get($cacheDir.'/coverage.bin.gz'))));
}

/**
 * Moves Pest's commit for $branch, as a plain `composer test` does without touching coverage.
 *
 * @param  array<string, string>  $tree
 */
function movePestCommit(string $cacheDir, string $branch, string $sha, array $tree = []): void
{
    $graph = json_decode(File::get($cacheDir.'/graph.json'), true);
    $graph['baselines'][$branch]['sha'] = $sha;
    $graph['baselines'][$branch]['tree'] = $tree;
    File::put($cacheDir.'/graph.json', (string) json_encode($graph));
}

function cacheOf(string $caches, string $checkout): string
{
    return $caches.'/'.md5($checkout);
}

/**
 * @return array<string, array<string, mixed>>
 */
function baselinesIn(string $cacheDir): array
{
    return json_decode(File::get($cacheDir.'/graph.json'), true)['baselines'];
}

it('publishes a merged ticket\'s baseline, and the next ticket inherits it', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    $sha = git($t1, 'rev-parse', 'HEAD');
    recordRun($this->caches, $t1, 'feature/t1-one', $sha);
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');

    expect($this->baselines->publish($t1))->toBe(sprintf('Published the TIA baseline at %s for main.', mb_substr($sha, 0, 7)));

    $t2 = ticket($this->repo, 'feature/t2-two');

    expect($this->baselines->inherit($t2))->toBe(sprintf('Inherited the main TIA baseline at %s.', mb_substr($sha, 0, 7)))
        ->and(array_keys(baselinesIn(cacheOf($this->caches, $t2))))->toBe(['feature/t2-two'])
        ->and(baselinesIn(cacheOf($this->caches, $t2))['feature/t2-two']['sha'])->toBe($sha)
        ->and(coveredFiles(cacheOf($this->caches, $t2)))->toBe([$t2.'/app/Example.php'])
        ->and(CoverageStamp::read(cacheOf($this->caches, $t2))?->root)->toBe($t2)
        ->and($this->baselines->problem($t2))->toBeNull();
});

it('does not publish a ticket that has not merged', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));

    expect($this->baselines->publish($t1))->toStartWith('Not published: ')
        ->and($this->baselines->publish($t1))->toEndWith(' is not merged into main yet.')
        ->and(File::isDirectory($this->repo.'/.git/pest-tia'))->toBeFalse();
});

it('publishes a PR merged on the remote before the local base has pulled it', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    $sha = git($t1, 'rev-parse', 'HEAD');
    recordRun($this->caches, $t1, 'feature/t1-one', $sha);
    git($t1, 'push', '--quiet', 'origin', 'feature/t1-one:main');

    expect(Process::path($this->repo)->run(['git', 'merge-base', '--is-ancestor', $sha, 'main'])->successful())->toBeFalse()
        ->and($this->baselines->publish($t1))->toBe(sprintf('Published the TIA baseline at %s for main.', mb_substr($sha, 0, 7)));
});

it('refuses to hand over a baseline a later run cannot trust', function (?string $sha, bool $complete, bool $coverage, bool $stamp, string $reason): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', $sha === 'HEAD' ? git($t1, 'rev-parse', 'HEAD') : $sha, $complete, $coverage, $stamp);
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');

    expect($this->baselines->publish($t1))->toBe('Not published: '.$reason.'.');
})->with([
    'no commit' => [null, true, true, true, 'the feature/t1-one baseline has no commit (a filtered or interrupted run)'],
    'incomplete' => ['HEAD', false, true, true, 'the feature/t1-one baseline is incomplete'],
    'no coverage cache' => ['HEAD', true, false, true, 'no coverage cache (preflight would re-record everything)'],
    'coverage no preflight stamped' => ['HEAD', true, true, false, 'no record of the commit its coverage cache matches (a cache no toolkit preflight wrote)'],
]);

it('keeps the newer baseline when an older ticket lands last', function (): void {
    $older = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $older, 'feature/t1-one', git($older, 'rev-parse', 'HEAD'));
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');
    $newer = ticket($this->repo, 'feature/t2-two');
    recordRun($this->caches, $newer, 'feature/t2-two', $newerSha = git($newer, 'rev-parse', 'HEAD'));
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t2', 'feature/t2-two');

    $this->baselines->publish($newer);

    expect($this->baselines->publish($older))->toBe('Not published: the main baseline already on file is newer.')
        ->and(baselinesIn($this->repo.'/.git/pest-tia/main')['main']['sha'])->toBe($newerSha);
});

it('files a ticket cut from an integration branch under that branch', function (): void {
    git($this->repo, 'branch', 'feature/shop');
    $t1 = ticket($this->repo, 'feature/t1-one', 'feature/shop');
    git($this->repo, 'config', 'branch.feature/t1-one.tiaBase', 'feature/shop');
    $sha = git($t1, 'rev-parse', 'HEAD');
    recordRun($this->caches, $t1, 'feature/t1-one', $sha);
    git($this->repo, 'switch', '--quiet', 'feature/shop');
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');
    git($this->repo, 'switch', '--quiet', 'main');

    expect($this->baselines->publish($t1))->toBe(sprintf('Published the TIA baseline at %s for feature/shop.', mb_substr($sha, 0, 7)));

    $t2 = ticket($this->repo, 'feature/t2-two', 'feature/shop');

    expect($this->baselines->inherit($t2, 'feature/shop'))->toBe(sprintf('Inherited the feature/shop TIA baseline at %s.', mb_substr($sha, 0, 7)))
        ->and($this->baselines->inherit(ticket($this->repo, 'feature/t3-three')))->toStartWith('No usable TIA baseline published for main');
});

it('does not inherit a baseline outside the branch\'s history', function (): void {
    $t2 = ticket($this->repo, 'feature/t2-two');
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');
    $this->baselines->publish($t1);

    expect($this->baselines->inherit($t2))->toEndWith('is not in this branch\'s history. The first `composer test` records one.')
        ->and(File::exists(cacheOf($this->caches, $t2).'/graph.json'))->toBeFalse();
});

it('leaves a branch that already has a usable baseline alone', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));

    expect($this->baselines->inherit($t1))->toBe('feature/t1-one already has a usable TIA baseline.');
});

it('reports why a branch has no usable baseline', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');

    expect($this->baselines->problem($t1))->toBe('no TIA graph');

    recordRun($this->caches, $t1, 'main', git($this->repo, 'rev-parse', 'HEAD'));

    expect($this->baselines->problem($t1))->toBe('no baseline for feature/t1-one');
});

it('replays from the coverage cache\'s commit after a plain run moved Pest\'s past it', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    $covered = git($t1, 'rev-parse', 'HEAD');
    recordRun($this->caches, $t1, 'feature/t1-one', $covered);
    git($t1, 'commit', '--quiet', '--allow-empty', '-m', 'tested without coverage');
    movePestCommit(cacheOf($this->caches, $t1), 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'), ['app/Dirty.php' => 'abc']);

    expect($this->baselines->alignCoverage($t1))->toBe(sprintf('TIA: replaying from %s, the commit the coverage cache matches.', mb_substr($covered, 0, 7)))
        ->and(baselinesIn(cacheOf($this->caches, $t1))['feature/t1-one']['sha'])->toBe($covered)
        ->and(baselinesIn(cacheOf($this->caches, $t1))['feature/t1-one']['tree'])->toBe([])
        ->and(baselinesIn(cacheOf($this->caches, $t1))['feature/t1-one']['results'])->toHaveKey('tests/ExampleTest.php::it works')
        ->and($this->baselines->alignCoverage($t1))->toBeNull();
});

it('gives a branch running on its base\'s baseline the coverage cache\'s commit', function (): void {
    $covered = git($this->repo, 'rev-parse', 'HEAD');
    recordRun($this->caches, $this->repo, 'main', $covered);
    git($this->repo, 'switch', '--quiet', '-c', 'feature/shop');
    git($this->repo, 'commit', '--quiet', '--allow-empty', '-m', 'shop');

    expect($this->baselines->alignCoverage($this->repo))->toBe(sprintf('TIA: replaying from %s, the commit the coverage cache matches.', mb_substr($covered, 0, 7)))
        ->and(baselinesIn(cacheOf($this->caches, $this->repo))['feature/shop'])->toBe(['results' => [], 'sha' => $covered, 'tree' => []])
        ->and(baselinesIn(cacheOf($this->caches, $this->repo))['main']['sha'])->toBe($covered);
});

it('moves a coverage cache recorded in another checkout to this one', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    $sha = git($t1, 'rev-parse', 'HEAD');
    recordRun($this->caches, $t1, 'feature/t1-one', $sha);
    new CoverageStamp('/elsewhere/t0', $sha, [])->writeTo(cacheOf($this->caches, $t1));
    File::put(cacheOf($this->caches, $t1).'/coverage.bin.gz', (string) gzencode(serialize(['/elsewhere/t0/app/Example.php' => [3 => ['t']]])));

    expect($this->baselines->alignCoverage($t1))->toBe('TIA: moved the coverage cache\'s paths from /elsewhere/t0.')
        ->and(coveredFiles(cacheOf($this->caches, $t1)))->toBe([$t1.'/app/Example.php'])
        ->and(CoverageStamp::read(cacheOf($this->caches, $t1))?->root)->toBe($t1);
});

it('deletes a coverage cache it cannot place, so the run records coverage afresh', function (bool $stamped, string $message): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    $t2 = ticket($this->repo, 'feature/t2-two');
    recordRun($this->caches, $t1, 'feature/t1-one', git($t2, 'rev-parse', 'HEAD'), stamp: $stamped);

    expect($this->baselines->alignCoverage($t1))->toBe(sprintf($message, mb_substr(git($t2, 'rev-parse', 'HEAD'), 0, 7)))
        ->and(File::exists(cacheOf($this->caches, $t1).'/coverage.bin.gz'))->toBeFalse()
        ->and(File::exists(cacheOf($this->caches, $t1).'/'.CoverageStamp::FILE))->toBeFalse();
})->with([
    'no stamp' => [false, 'TIA: no record of the commit the coverage cache matches. Recording coverage afresh.'],
    'a commit outside the branch' => [true, 'TIA: the coverage cache is from %s, outside this branch\'s history. Recording coverage afresh.'],
]);

/**
 * What Pest leaves when a coverage run finishes: a coverage cache written just now.
 */
function rewriteCoverage(string $cacheDir): void
{
    File::ensureDirectoryExists($cacheDir);
    File::put($cacheDir.'/coverage.bin.gz', (string) gzencode(serialize(['rewritten' => true])));
}

it('stamps the coverage cache with the commit a passing gate covered, uncommitted files marked to replay', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    $sha = git($t1, 'rev-parse', 'HEAD');
    File::put($t1.'/Dirty.php', '<?php');

    $run = $this->baselines->startCoverageRun($t1);
    rewriteCoverage(cacheOf($this->caches, $t1));

    expect($this->baselines->finishCoverageRun($t1, $run, passed: true))->toBeNull()
        ->and(CoverageStamp::read(cacheOf($this->caches, $t1)))->toEqual(new CoverageStamp($t1, $sha, ['Dirty.php' => '']));
});

it('drops the coverage a failed gate saved, so the next run records afresh', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));

    $run = $this->baselines->startCoverageRun($t1);
    rewriteCoverage(cacheOf($this->caches, $t1));

    expect($this->baselines->finishCoverageRun($t1, $run, passed: false))->toBe('TIA: the coverage gate failed, so the coverage it saved is not trusted. The next run records coverage afresh.')
        ->and(File::exists(cacheOf($this->caches, $t1).'/coverage.bin.gz'))->toBeFalse()
        ->and(File::exists(cacheOf($this->caches, $t1).'/'.CoverageStamp::FILE))->toBeFalse();
});

it('leaves no stamp while a coverage gate runs, so an interrupted run is not trusted', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));

    $this->baselines->startCoverageRun($t1);
    rewriteCoverage(cacheOf($this->caches, $t1));

    expect(File::exists(cacheOf($this->caches, $t1).'/'.CoverageStamp::FILE))->toBeFalse()
        ->and($this->baselines->alignCoverage($t1))->toBe('TIA: no record of the commit the coverage cache matches. Recording coverage afresh.');
});

it('gives the stamp back when the gate failed before it wrote coverage', function (bool $passed): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', $sha = git($t1, 'rev-parse', 'HEAD'));
    touch(cacheOf($this->caches, $t1).'/coverage.bin.gz', time() - 60);

    $run = $this->baselines->startCoverageRun($t1);

    expect($this->baselines->finishCoverageRun($t1, $run, $passed))->toBeNull()
        ->and(CoverageStamp::read(cacheOf($this->caches, $t1)))->toEqual(new CoverageStamp($t1, $sha, []));
})->with(['failed' => false, 'passed' => true]);

it('leaves unstamped a cache written while the checkout moved to another commit', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');

    $run = $this->baselines->startCoverageRun($t1);
    rewriteCoverage(cacheOf($this->caches, $t1));
    git($t1, 'commit', '--quiet', '--allow-empty', '-m', 'committed during the run');

    expect($this->baselines->finishCoverageRun($t1, $run, passed: true))->toBeNull()
        ->and(File::exists(cacheOf($this->caches, $t1).'/'.CoverageStamp::FILE))->toBeFalse();
});

it('re-covers the files changed since the stamp, so their old line numbers cannot linger', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun($this->caches, $t1, 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));
    coverageCacheFile(cacheOf($this->caches, $t1).'/coverage.bin.gz', [
        $t1.'/app/Committed.php' => [13 => ['t1']],
        $t1.'/app/Uncommitted.php' => [5 => ['t2']],
        $t1.'/app/Unchanged.php' => [8 => ['t3']],
    ]);
    File::ensureDirectoryExists($t1.'/app');
    File::put($t1.'/app/Committed.php', '<?php');
    git($t1, 'add', 'app/Committed.php');
    git($t1, 'commit', '--quiet', '-m', 'change a file');
    movePestCommit(cacheOf($this->caches, $t1), 'feature/t1-one', git($t1, 'rev-parse', 'HEAD^'));
    File::put($t1.'/app/Uncommitted.php', '<?php');

    expect($this->baselines->alignCoverage($t1))->toBe('TIA: re-covering 2 changed files.')
        ->and(cachedLines(cacheOf($this->caches, $t1).'/coverage.bin.gz'))->toBe([$t1.'/app/Unchanged.php' => [8 => ['t3']]]);
});
