<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Nwrman\LaravelToolkit\Preflight\Preflight;
use Nwrman\LaravelToolkit\Preflight\ProcessTree;
use Nwrman\LaravelToolkit\Tia\CoverageStamp;
use Nwrman\LaravelToolkit\Tia\PestCache;
use Nwrman\LaravelToolkit\Tia\TiaBaselines;
use Pest\Plugins\Tia\Fingerprint;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Gates are faked processes named after their key ("run <key>"), except where a test
 * needs a real one. The process tree is the edge faked for stopping: it records the
 * process ids it was asked to stop instead of signalling anything.
 */
beforeEach(function (): void {
    $this->root = realpath(sys_get_temp_dir()).'/toolkit-preflight-'.uniqid();
    mkdir($this->root, 0755, true);
    $this->output = new BufferedOutput;
    $this->process = new Factory;
    $this->tree = new class(new Factory) extends ProcessTree
    {
        /** @var array<int, int> */
        public array $stopped = [];

        public function terminate(int ...$pids): void
        {
            array_push($this->stopped, ...$pids);
        }
    };
    $this->cache = $this->root.'/pest-cache';
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * Fakes the named gates; every other gate passes at once, and nothing runs for real.
 *
 * @param  array<string, mixed>  $gates
 */
function fakeGates(Factory $process, array $gates): void
{
    $process->preventStrayProcesses();
    $process->fake([...$gates, '*' => $process->result()]);
}

/**
 * @param  array<string, mixed>  $config
 */
function preflight(object $test, array $config, bool $isAgent = false): Preflight
{
    $cache = $test->cache;
    $files = new Filesystem;
    $pestCache = new class($cache) extends PestCache
    {
        public function __construct(private readonly string $cache) {}

        public function directory(string $root): string
        {
            return $this->cache;
        }
    };

    return new Preflight($test->root, $config, $test->output, $test->process, $files, $test->tree, new TiaBaselines($pestCache, $files, new Factory), $isAgent);
}

/**
 * @return array<string, mixed>
 */
function gatesConfig(): array
{
    return ['gates' => [
        'pest-coverage' => ['label' => 'Pest Coverage', 'command' => 'run pest-coverage'],
        'browser' => ['label' => 'Browser', 'command' => 'run browser', 'after' => 'pest-coverage'],
        'lint' => ['label' => 'Lint', 'command' => 'run lint'],
    ]];
}

/**
 * The summary table's rows: gate, status, and whether a time is shown.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function tableRows(BufferedOutput $output): array
{
    $text = $output->fetch();
    preg_match_all('/^\| (.+?) +\| (.+?) +\| (.+?) +\|$/m', $text, $rows, PREG_SET_ORDER);

    return array_map(fn (array $row): array => [$row[1], $row[2]], array_slice($rows, 1));
}

it('runs every gate and shows each one passed', function (): void {
    $this->process->fake();

    $exit = preflight($this, gatesConfig())->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($exit)->toBe(0)
        ->and(tableRows($this->output))->toBe([
            ['Pest Coverage', '✓ passed'],
            ['Browser', '✓ passed'],
            ['Lint', '✓ passed'],
        ]);
    $this->process->assertRan('run pest-coverage');
    $this->process->assertRan('run browser');
    $this->process->assertRan('run lint');
});

it('stops the running gates and everything they started at the first failure', function (): void {
    fakeGates($this->process, [
        'run lint' => $this->process->describe()->output('Rector would change app/Foo.php')->exitCode(1),
        'run pest-coverage' => $this->process->describe()->id(4242)->iterations(3),
    ]);

    $exit = preflight($this, gatesConfig())->run(retry: false, only: [], forceBuild: false, notify: false);
    $text = $this->output->fetch();

    expect($exit)->toBe(1)
        ->and($this->tree->stopped)->toBe([4242])
        ->and($text)->toContain('--- Lint failed after')
        ->and($text)->toContain('Rector would change app/Foo.php')
        ->and($text)->toContain('Stopped after the first failure: Pest Coverage.')
        ->and($text)->toContain('Retry the gates that did not pass: vendor/bin/preflight --retry');
    $this->process->assertNotRan('run browser');
});

it('shows a stopped gate and the gate that waited for it as such', function (): void {
    fakeGates($this->process, [
        'run lint' => $this->process->describe()->exitCode(1),
        'run pest-coverage' => $this->process->describe()->iterations(3),
    ]);

    preflight($this, gatesConfig())->run(retry: false, only: [], forceBuild: false, notify: false);

    expect(tableRows($this->output))->toBe([
        ['Pest Coverage', '■ stopped'],
        ['Browser', '– not run'],
        ['Lint', '✗ FAILED'],
    ]);
});

it('starts a gate that waits for another once that one passed', function (): void {
    fakeGates($this->process, [
        'run pest-coverage' => $this->process->describe()->iterations(2),
    ]);

    preflight($this, gatesConfig())->run(retry: false, only: [], forceBuild: false, notify: false);

    $this->process->assertRan('run browser');
});

it('never starts a gate that waits for one that failed', function (): void {
    fakeGates($this->process, [
        'run pest-coverage' => $this->process->describe()->exitCode(1)->iterations(2),
    ]);

    preflight($this, gatesConfig())->run(retry: false, only: [], forceBuild: false, notify: false);

    $this->process->assertNotRan('run browser');
});

it('fails instead of waiting forever on gates that wait for each other', function (): void {
    fakeGates($this->process, []);

    $exit = preflight($this, ['gates' => [
        'a' => ['label' => 'A', 'command' => 'run a', 'after' => 'b'],
        'b' => ['label' => 'B', 'command' => 'run b', 'after' => 'a'],
        'lint' => ['label' => 'Lint', 'command' => 'run lint'],
    ]])->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($exit)->toBe(1)
        ->and(tableRows($this->output))->toBe([
            ['A', '– not run'],
            ['B', '– not run'],
            ['Lint', '✓ passed'],
        ]);
    $this->process->assertNotRan('run a');
});

it('names a stopped gate by its label for an agent', function (): void {
    fakeGates($this->process, [
        'run lint' => $this->process->describe()->exitCode(1),
        'run pest-coverage' => $this->process->describe()->iterations(3),
    ]);

    preflight($this, gatesConfig(), isAgent: true)->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($this->output->fetch())->toContain("  ■ Pest Coverage stopped\n");
});

it('retries only the gates that did not pass, and shows the others from the last run', function (): void {
    fakeGates($this->process, [
        'run lint' => $this->process->describe()->exitCode(1),
        'run pest-coverage' => $this->process->describe()->iterations(2),
    ]);
    preflight($this, gatesConfig())->run(retry: false, only: [], forceBuild: false, notify: false);
    $this->output->fetch();
    $this->process = new Factory;
    $this->process->fake();

    $exit = preflight($this, gatesConfig())->run(retry: true, only: [], forceBuild: false, notify: false);

    expect($exit)->toBe(0)
        ->and(tableRows($this->output))->toBe([
            ['Pest Coverage', '✓ passed'],
            ['Browser', '✓ passed'],
            ['Lint', '✓ passed'],
        ]);
    $this->process->assertRan('run lint');
});

it('shows a gate this run skipped with its result from the last run that ran it', function (): void {
    fakeGates($this->process, ['run lint' => $this->process->describe()->exitCode(1)]);
    preflight($this, ['gates' => ['lint' => ['label' => 'Lint', 'command' => 'run lint'], 'types' => ['label' => 'Types', 'command' => 'run types']]])
        ->run(retry: false, only: [], forceBuild: false, notify: false);
    $this->output->fetch();
    $this->process = new Factory;
    $this->process->fake();

    preflight($this, ['gates' => ['lint' => ['label' => 'Lint', 'command' => 'run lint'], 'types' => ['label' => 'Types', 'command' => 'run types'], 'new' => ['label' => 'New', 'command' => 'run new']]])
        ->run(retry: false, only: ['types'], forceBuild: false, notify: false);

    expect(tableRows($this->output))->toBe([
        ['Lint', '✗ FAILED, previous run'],
        ['Types', '✓ passed'],
        ['New', '– never run'],
    ]);
    $this->process->assertNotRan('run lint');
});

it('names the gates that have not passed when only some gates ran', function (): void {
    fakeGates($this->process, ['run lint' => $this->process->describe()->exitCode(1)]);
    $config = ['gates' => [
        'lint' => ['label' => 'Lint', 'command' => 'run lint'],
        'types' => ['label' => 'Types', 'command' => 'run types'],
        'new' => ['label' => 'New', 'command' => 'run new'],
    ]];
    preflight($this, [...$config, 'gates' => array_slice($config['gates'], 0, 2)])->run(retry: false, only: [], forceBuild: false, notify: false);
    $this->output->fetch();
    $this->process = new Factory;
    $this->process->fake();

    $exit = preflight($this, $config)->run(retry: false, only: ['types'], forceBuild: false, notify: false);

    expect($exit)->toBe(0)
        ->and($this->output->fetch())->not->toContain('All gates passed')
        ->toContain('✓ Types passed. Not passed yet: Lint, New.');
});

it('runs nothing on a retry after a run where every gate passed', function (): void {
    $this->process->fake();
    preflight($this, gatesConfig())->run(retry: false, only: [], forceBuild: false, notify: false);
    $this->output->fetch();
    $this->process = new Factory;
    $this->process->fake();

    $exit = preflight($this, gatesConfig())->run(retry: true, only: [], forceBuild: false, notify: false);

    expect($exit)->toBe(0)
        ->and($this->output->fetch())->toContain('Every gate passed on the last run: nothing to retry.');
    $this->process->assertNothingRan();
});

it('rejects an unknown gate and runs nothing', function (): void {
    $this->process->fake();

    $exit = preflight($this, gatesConfig())->run(retry: false, only: ['lnit'], forceBuild: false, notify: false);

    expect($exit)->toBe(1)
        ->and($this->output->fetch())->toContain('Unknown gate(s): lnit');
    $this->process->assertNothingRan();
});

it('runs a gate named alone without waiting for the gate it follows', function (): void {
    $this->process->fake();

    preflight($this, gatesConfig())->run(retry: false, only: ['browser'], forceBuild: false, notify: false);

    $this->process->assertRan('run browser');
    $this->process->assertNotRan('run pest-coverage');
});

it('gives gates the shell environment plus vendor/bin, their own env and the preflight markers', function (bool $forceBuild): void {
    $environments = [];
    $this->process->fake(function (PendingProcess $process) use (&$environments) {
        $environments[] = $process->environment;

        return $this->process->result();
    });

    preflight($this, ['gates' => ['lint' => ['label' => 'Lint', 'command' => 'run lint', 'env' => ['XDEBUG_MODE' => 'off']]]])
        ->run(retry: false, only: [], forceBuild: $forceBuild, notify: false);

    expect($environments)->toBe([[
        'PATH' => $this->root.'/vendor/bin'.PATH_SEPARATOR.getenv('PATH'),
        'TOOLKIT_PREFLIGHT' => '1',
        ...($forceBuild ? ['TOOLKIT_FORCE_BUILD' => '1'] : []),
        'XDEBUG_MODE' => 'off',
    ]]);
})->with(['by default' => false, 'with --force-build' => true]);

it('refuses a coverage gate that would switch TIA off', function (): void {
    $this->process->fake();

    $exit = preflight($this, ['tia' => ['enabled' => true, 'record_command' => 'true'], 'gates' => [
        'pest-coverage' => ['label' => 'Pest Coverage', 'command' => 'vendor/bin/pest --coverage --exclude-testsuite=Browser'],
    ]])->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($exit)->toBe(1)
        ->and($this->output->fetch())->toContain('The Pest Coverage gate passes --exclude-testsuite to a Pest coverage run.');
    $this->process->assertNothingRan();
});

/**
 * A real repository the TIA coverage gate covers.
 */
function repositoryAt(string $root): string
{
    git($root, 'init', '--quiet', '--initial-branch=main');
    git($root, 'commit', '--quiet', '--allow-empty', '-m', 'base');

    return git($root, 'rev-parse', 'HEAD');
}

it('keeps and stamps the coverage a gate saved when it failed only after its tests passed', function (): void {
    $sha = repositoryAt($this->root);
    mkdir($this->cache);

    // Pest writes the cache only when the tests pass; the coverage minimum fails after.
    $exit = preflight($this, ['tia' => ['enabled' => true, 'record_command' => 'true'], 'gates' => ['pest-coverage' => [
        'label' => 'Pest Coverage',
        'command' => sprintf('printf fresh | gzip > %s/coverage.bin.gz && echo pest --coverage && exit 1', $this->cache),
    ]]])->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($exit)->toBe(1)
        ->and(gzdecode((string) file_get_contents($this->cache.'/coverage.bin.gz')))->toBe('fresh')
        ->and(CoverageStamp::read(new Filesystem, $this->cache))->toEqual(new CoverageStamp($this->root, $sha, [], withBrowser: true));
});

it('lines the coverage cache up before a TIA coverage gate runs', function (): void {
    repositoryAt($this->root);
    mkdir($this->cache);
    file_put_contents($this->cache.'/coverage.bin.gz', (string) gzencode('unstamped'));

    preflight($this, ['tia' => ['enabled' => true, 'record_command' => 'true'], 'gates' => ['pest-coverage' => [
        'label' => 'Pest Coverage',
        'command' => sprintf('test ! -e %s/coverage.bin.gz && echo pest --coverage', $this->cache),
    ]]])->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($this->output->fetch())->toContain('TIA: no record of the commit the coverage cache matches. Recording coverage afresh.')
        ->toContain('✓ All gates passed!');
});

it('records coverage afresh when the cache counted Browser specs differently from the coverage gate', function (?bool $cachedWithBrowser, string $flag, string $message): void {
    $sha = repositoryAt($this->root);
    mkdir($this->cache);
    file_put_contents($this->cache.'/coverage.bin.gz', (string) gzencode('recorded before'));
    // A v0 stamp has no browser field.
    file_put_contents($this->cache.'/'.CoverageStamp::FILE, (string) json_encode(['root' => $this->root, 'sha' => $sha, 'tree' => (object) [], ...($cachedWithBrowser === null ? [] : ['browser' => $cachedWithBrowser])]));

    preflight($this, ['tia' => ['enabled' => true, 'record_command' => 'true'], 'gates' => ['pest-coverage' => [
        'label' => 'Pest Coverage',
        'command' => sprintf('test ! -e %s/coverage.bin.gz && echo pest --coverage%s', $this->cache, $flag),
    ]]])->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($this->output->fetch())->toContain($message)
        ->toContain('✓ All gates passed!');
})->with([
    'a v0 cache, a gate without Browser specs' => [null, ' --toolkit-no-browser', 'TIA: the coverage cache counts Browser specs, and this gate leaves them out.'],
    'a cache without them, a gate that runs them' => [false, '', 'TIA: the coverage cache leaves Browser specs out, and this gate runs them.'],
]);

it('records Pest\'s graph with a plain run before a coverage gate that could not', function (bool $graph, bool $records): void {
    repositoryAt($this->root);
    mkdir($this->cache);

    if ($graph) {
        file_put_contents($this->cache.'/graph.json', (string) json_encode(['fingerprint' => Fingerprint::compute($this->root), 'baselines' => (object) []]));
    }

    $exit = preflight($this, ['tia' => ['enabled' => true, 'record_command' => sprintf('touch %s/recorded', $this->root)], 'gates' => ['pest-coverage' => [
        'label' => 'Pest Coverage',
        'command' => 'echo pest --coverage --toolkit-no-browser',
    ]]])->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($exit)->toBe(0)
        ->and(file_exists($this->root.'/recorded'))->toBe($records);

    if ($records) {
        expect($this->output->fetch())->toContain('TIA: Pest has no graph for this checkout, and a coverage run can\'t record one: the coverage gate records it with a plain run first.');
    }
})->with(['no graph' => [false, true], 'a usable graph' => [true, false]]);

it('prints each gate\'s result as it finishes for an agent, which has no live display', function (): void {
    fakeGates($this->process, ['run lint' => $this->process->describe()->exitCode(1)]);

    preflight($this, ['gates' => ['lint' => ['label' => 'Lint', 'command' => 'run lint']]], isAgent: true)
        ->run(retry: false, only: [], forceBuild: false, notify: false);

    expect($this->output->fetch())->toMatch('/^  ✗ Lint \(\d+\.\ds\)$/m');
});
