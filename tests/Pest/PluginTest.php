<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The plugin as an app gets it: a real Pest process, loading the plugin from this
 * package's vendor/pest-plugins.json. A `fail` file in the project makes two of its
 * tests fail.
 *
 * Pest takes the project root from the real path of its own bin script, so the project
 * gets a copy of that script and a vendor/autoload.php that hands over to this
 * package's. Paratest workers find their root from Pest's installed sources instead,
 * which stay in this package, so these runs are serial; the parallel path (workers
 * applying --toolkit-no-browser through a Pest global) is proved on a real app.
 */
beforeEach(function (): void {
    $this->project = realpath(sys_get_temp_dir()).'/toolkit-pest-'.uniqid();
    $vendor = dirname(__DIR__, 2).'/vendor';
    exec(sprintf('cp -R %s %s', escapeshellarg(dirname(__DIR__).'/Fixtures/pest-project'), escapeshellarg($this->project)));
    mkdir($this->project.'/vendor/bin', 0755, true);
    file_put_contents($this->project.'/vendor/autoload.php', sprintf("<?php\n\nreturn require %s;\n", var_export($vendor.'/autoload.php', true)));
    file_put_contents($this->project.'/vendor/bin/pest', sprintf(
        "<?php\n\n\$GLOBALS['_composer_bin_dir'] = %s;\n\$GLOBALS['_composer_autoload_path'] = __DIR__.'/../autoload.php';\n\ninclude %s;\n",
        var_export($vendor.'/bin', true),
        var_export($this->project.'/vendor/pestphp/pest/bin/pest', true),
    ));
    mkdir($this->project.'/vendor/pestphp/pest/bin', 0755, true);
    copy($vendor.'/pestphp/pest/bin/pest', $this->project.'/vendor/pestphp/pest/bin/pest');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->project));
});

/**
 * @param  array<int, string>  $arguments
 */
function runPest(string $project, array $arguments = []): Process
{
    // This suite's own run marks its children as nested runs; the fixture's is a run of its own.
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', ...$arguments], $project, ['XDEBUG_MODE' => 'off', 'TOOLKIT_PEST_RUN' => false]);
    $process->run();

    return $process;
}

/**
 * Pest decorates its output even when it is not a terminal's.
 */
function plainOutput(Process $process): string
{
    return (string) preg_replace('/\e\[[0-9;]*m/', '', $process->getOutput());
}

/**
 * @return array<int, string>
 */
function testsRun(string $project): array
{
    $xml = simplexml_load_file($project.'/storage/logs/test-results.xml');
    $names = array_map(fn (SimpleXMLElement $case): string => (string) $case['class'].'::'.$case['name'], $xml === false ? [] : ($xml->xpath('//testcase') ?: []));
    sort($names);

    return $names;
}

/**
 * @return array<int, array{file: string, class: string, name: string}>
 */
function reportedFailures(string $project): array
{
    $report = json_decode((string) file_get_contents($project.'/storage/logs/test-failures.json'), true);

    return array_map(
        fn (array $failure): array => ['file' => $failure['file'], 'class' => $failure['class'], 'name' => $failure['name']],
        $report['sections']['backend']['failures'],
    );
}

const FAILED_IN_FIXTURE = [
    ['file' => 'tests/Unit/MathTest.php', 'class' => 'Tests\\Unit\\MathTest', 'name' => 'it subtracts'],
    ['file' => 'tests/Unit/MathTest.php', 'class' => 'Tests\\Unit\\MathTest', 'name' => 'it counts with data set "dataset "#1 a@b.c""'],
];

it('writes the failure report from a plain Pest run', function (array $arguments): void {
    touch($this->project.'/fail');

    $run = runPest($this->project, $arguments);

    expect($run->getExitCode())->toBe(1)
        ->and(reportedFailures($this->project))->toBe(FAILED_IN_FIXTURE)
        ->and(file_get_contents($this->project.'/storage/logs/test-failures.md'))->toContain('### 2. `it counts with data set "dataset "#1 a@b.c""`')
        ->and(plainOutput($run))->toContain('Failure report: storage/logs/test-failures.md');
})->with(['no arguments' => [[]], 'a filter' => [['--filter=it']]]);

it('deletes the report once a run passes', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    unlink($this->project.'/fail');

    expect(runPest($this->project)->getExitCode())->toBe(0)
        ->and(file_exists($this->project.'/storage/logs/test-failures.json'))->toBeFalse()
        ->and(file_exists($this->project.'/storage/logs/test-failures.md'))->toBeFalse();
});

it('retries exactly the failed tests, by file and name', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);

    $retry = runPest($this->project, ['--toolkit-retry']);

    expect($retry->getExitCode())->toBe(1)
        ->and(testsRun($this->project))->toBe([
            'Tests\\Unit\\MathTest::it counts with data set "dataset "#1 a@b.c""',
            'Tests\\Unit\\MathTest::it subtracts',
        ])
        ->and(reportedFailures($this->project))->toBe(FAILED_IN_FIXTURE);
});

it('clears the report when the retried tests pass', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    unlink($this->project.'/fail');

    expect(runPest($this->project, ['--toolkit-retry'])->getExitCode())->toBe(0)
        ->and(testsRun($this->project))->toHaveCount(2)
        ->and(file_exists($this->project.'/storage/logs/test-failures.json'))->toBeFalse();
});

it('runs nothing on a retry with no failure on record', function (): void {
    $retry = runPest($this->project, ['--toolkit-retry']);

    expect($retry->getExitCode())->toBe(0)
        ->and(plainOutput($retry))->toContain('No failed backend tests in the failure report: nothing to retry.')
        ->and(file_exists($this->project.'/storage/logs/test-results.xml'))->toBeFalse();
});

it('re-runs exactly one failed test with the command the report prints for it', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    preg_match('/Re-run it:\n\n```bash\n(.+)\n```/', (string) file_get_contents($this->project.'/storage/logs/test-failures.md'), $printed);

    $rerun = Process::fromShellCommandline(str_replace('vendor/bin/pest', escapeshellarg(PHP_BINARY).' vendor/bin/pest', $printed[1]), $this->project, ['XDEBUG_MODE' => 'off', 'TOOLKIT_PEST_RUN' => false]);
    $rerun->run();

    expect($printed[1])->toStartWith('vendor/bin/pest ')
        ->and($rerun->getExitCode())->toBe(1)
        ->and(testsRun($this->project))->toBe(['Tests\\Unit\\MathTest::it subtracts']);
});

it('keeps the other failures on record after one failed test is re-run and passes', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    unlink($this->project.'/fail');

    runPest($this->project, ['--filter=it subtracts$']);

    expect(reportedFailures($this->project))->toBe([FAILED_IN_FIXTURE[1]]);
});

it('drops an earlier failure whose test file is gone', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    unlink($this->project.'/fail');
    rename($this->project.'/tests/Unit/MathTest.php', $this->project.'/tests/Unit/ArithmeticTest.php');

    runPest($this->project, ['--filter=it adds']);

    expect(file_exists($this->project.'/storage/logs/test-failures.json'))->toBeFalse();
});

it('retries nothing, and clears the report, when every failed test file is gone', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    rename($this->project.'/tests/Unit/MathTest.php', $this->project.'/tests/Unit/ArithmeticTest.php');

    $retry = runPest($this->project, ['--toolkit-retry']);

    expect($retry->getExitCode())->toBe(0)
        ->and(plainOutput($retry))->toContain('No failed backend tests in the failure report: nothing to retry.')
        ->and(file_exists($this->project.'/storage/logs/test-failures.json'))->toBeFalse();
});

it('leaves the report and JUnit file alone in a Pest run another reporting run started', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    $report = file_get_contents($this->project.'/storage/logs/test-failures.json');
    $junit = file_get_contents($this->project.'/storage/logs/test-results.xml');

    $nested = new Process([PHP_BINARY, 'vendor/bin/pest', '--filter=it adds'], $this->project, ['XDEBUG_MODE' => 'off', 'TOOLKIT_PEST_RUN' => '1']);
    $nested->run();

    expect($nested->getExitCode())->toBe(0)
        ->and(file_get_contents($this->project.'/storage/logs/test-failures.json'))->toBe($report)
        ->and(file_get_contents($this->project.'/storage/logs/test-results.xml'))->toBe($junit);
});

it('leaves the Browser suite out with --toolkit-no-browser', function (): void {
    runPest($this->project, ['--toolkit-no-browser']);
    $withoutBrowser = testsRun($this->project);
    runPest($this->project);

    expect($withoutBrowser)->toBe([
        'Tests\\Unit\\MathTest::it adds',
        'Tests\\Unit\\MathTest::it counts with data set "dataset "#1 a@b.c""',
        'Tests\\Unit\\MathTest::it counts with data set "dataset "#2 c@d.e""',
        'Tests\\Unit\\MathTest::it subtracts',
        'Tests\\Unit\\MathTest::it subtracts twice',
    ])->and(testsRun($this->project))->toContain('Tests\\Browser\\PageTest::it renders the page');
});

it('reads the results from the JUnit file the run was given', function (): void {
    touch($this->project.'/fail');

    runPest($this->project, ['--log-junit', 'build/mine.xml']);

    expect(is_file($this->project.'/build/mine.xml'))->toBeTrue()
        ->and(reportedFailures($this->project))->toBe(FAILED_IN_FIXTURE);
});

it('leaves the report alone on a run that executes no test', function (): void {
    touch($this->project.'/fail');
    runPest($this->project);
    $report = file_get_contents($this->project.'/storage/logs/test-failures.json');

    runPest($this->project, ['--list-tests']);

    expect(file_get_contents($this->project.'/storage/logs/test-failures.json'))->toBe($report)
        ->and(is_file($this->project.'/storage/logs/test-results.xml'))->toBeTrue();
});
