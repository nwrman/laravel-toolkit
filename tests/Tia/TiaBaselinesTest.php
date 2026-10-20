<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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

function git(string $cwd, string ...$arguments): string
{
    $result = Process::path($cwd)
        ->env(['GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com'])
        ->run(['git', ...$arguments])
        ->throw();

    return mb_trim($result->output());
}

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
 * What a Pest run leaves in a checkout's cache: the graph with this branch's
 * baseline, and (after a coverage run) the coverage cache.
 */
function recordRun(string $cacheDir, string $branch, ?string $sha, bool $complete = true, bool $coverage = true): void
{
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
        File::put($cacheDir.'/coverage.bin.gz', 'coverage of '.$branch);
    }
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
    recordRun(cacheOf($this->caches, $t1), 'feature/t1-one', $sha);
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');

    expect($this->baselines->publish($t1))->toBe(sprintf('Published the TIA baseline at %s for main.', mb_substr($sha, 0, 7)));

    $t2 = ticket($this->repo, 'feature/t2-two');

    expect($this->baselines->inherit($t2))->toBe(sprintf('Inherited the main TIA baseline at %s.', mb_substr($sha, 0, 7)))
        ->and(array_keys(baselinesIn(cacheOf($this->caches, $t2))))->toBe(['feature/t2-two'])
        ->and(baselinesIn(cacheOf($this->caches, $t2))['feature/t2-two']['sha'])->toBe($sha)
        ->and(File::get(cacheOf($this->caches, $t2).'/coverage.bin.gz'))->toBe('coverage of feature/t1-one')
        ->and($this->baselines->problem($t2))->toBeNull();
});

it('does not publish a ticket that has not merged', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun(cacheOf($this->caches, $t1), 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));

    expect($this->baselines->publish($t1))->toStartWith('Not published: ')
        ->and($this->baselines->publish($t1))->toEndWith(' is not merged into main yet.')
        ->and(File::isDirectory($this->repo.'/.git/pest-tia'))->toBeFalse();
});

it('publishes a PR merged on the remote before the local base has pulled it', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    $sha = git($t1, 'rev-parse', 'HEAD');
    recordRun(cacheOf($this->caches, $t1), 'feature/t1-one', $sha);
    git($t1, 'push', '--quiet', 'origin', 'feature/t1-one:main');

    expect(Process::path($this->repo)->run(['git', 'merge-base', '--is-ancestor', $sha, 'main'])->successful())->toBeFalse()
        ->and($this->baselines->publish($t1))->toBe(sprintf('Published the TIA baseline at %s for main.', mb_substr($sha, 0, 7)));
});

it('refuses to hand over a baseline a later run cannot trust', function (?string $sha, bool $complete, bool $coverage, string $reason): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun(cacheOf($this->caches, $t1), 'feature/t1-one', $sha === 'HEAD' ? git($t1, 'rev-parse', 'HEAD') : $sha, $complete, $coverage);
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');

    expect($this->baselines->publish($t1))->toBe('Not published: '.$reason.'.');
})->with([
    'no commit' => [null, true, true, 'the feature/t1-one baseline has no commit (a filtered or interrupted run)'],
    'incomplete' => ['HEAD', false, true, 'the feature/t1-one baseline is incomplete'],
    'no coverage cache' => ['HEAD', true, false, 'no coverage cache (preflight would re-record everything)'],
]);

it('keeps the newer baseline when an older ticket lands last', function (): void {
    $older = ticket($this->repo, 'feature/t1-one');
    recordRun(cacheOf($this->caches, $older), 'feature/t1-one', git($older, 'rev-parse', 'HEAD'));
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');
    $newer = ticket($this->repo, 'feature/t2-two');
    recordRun(cacheOf($this->caches, $newer), 'feature/t2-two', $newerSha = git($newer, 'rev-parse', 'HEAD'));
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
    recordRun(cacheOf($this->caches, $t1), 'feature/t1-one', $sha);
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
    recordRun(cacheOf($this->caches, $t1), 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));
    git($this->repo, 'merge', '--quiet', '--no-ff', '-m', 'merge t1', 'feature/t1-one');
    $this->baselines->publish($t1);

    expect($this->baselines->inherit($t2))->toEndWith('is not in this branch\'s history. The first `composer test` records one.')
        ->and(File::exists(cacheOf($this->caches, $t2).'/graph.json'))->toBeFalse();
});

it('leaves a branch that already has a usable baseline alone', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');
    recordRun(cacheOf($this->caches, $t1), 'feature/t1-one', git($t1, 'rev-parse', 'HEAD'));

    expect($this->baselines->inherit($t1))->toBe('feature/t1-one already has a usable TIA baseline.');
});

it('reports why a branch has no usable baseline', function (): void {
    $t1 = ticket($this->repo, 'feature/t1-one');

    expect($this->baselines->problem($t1))->toBe('no TIA graph');

    recordRun(cacheOf($this->caches, $t1), 'main', git($this->repo, 'rev-parse', 'HEAD'));

    expect($this->baselines->problem($t1))->toBe('no baseline for feature/t1-one');
});
