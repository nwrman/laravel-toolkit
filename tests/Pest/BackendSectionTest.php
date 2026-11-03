<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Pest\BackendSection;

beforeEach(function (): void {
    $this->junit = sys_get_temp_dir().'/toolkit-junit-'.uniqid().'.xml';
});

afterEach(function (): void {
    if (is_file($this->junit)) {
        unlink($this->junit);
    }
});

/**
 * A JUnit file as Paratest merges it for Pest: suites nested per file.
 */
function junitWith(string $file, string $cases): void
{
    file_put_contents($file, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="" tests="4">
    <testsuite name="Tests\Unit\MathTest" file="tests/Unit/MathTest.php" tests="4">
      {$cases}
    </testsuite>
  </testsuite>
</testsuites>
XML);
}

it('lists each failed or erroring test with its file, class, name, type and message', function (): void {
    junitWith($this->junit, <<<'XML'
<testcase name="it adds" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it adds" line="0"/>
<testcase name="it subtracts" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it subtracts" line="0"><failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that 1 is 2.
at tests/Unit/MathTest.php:9</failure></testcase>
<testcase name="it divides with data set &quot;(0)&quot;" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it divides with data set &quot;(0)&quot;" line="0"><error type="DivisionByZeroError">Division by zero</error></testcase>
<testcase name="it skips" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it skips" line="0"><skipped/></testcase>
XML);

    $section = BackendSection::from($this->junit, time() - 5, 1, 'vendor/bin/pest --parallel');

    expect($section['total'])->toBe(4)
        ->and($section['failed'])->toBe(2)
        ->and($section['notes'])->toBe([])
        ->and($section['failures'])->toBe([
            ['file' => 'tests/Unit/MathTest.php', 'class' => 'Tests\\Unit\\MathTest', 'name' => 'it subtracts', 'line' => 0, 'type' => 'Failure (PHPUnit\\Framework\\ExpectationFailedException)', 'message' => "Failed asserting that 1 is 2.\nat tests/Unit/MathTest.php:9"],
            ['file' => 'tests/Unit/MathTest.php', 'class' => 'Tests\\Unit\\MathTest', 'name' => 'it divides with data set "(0)"', 'line' => 0, 'type' => 'Error (DivisionByZeroError)', 'message' => 'Division by zero'],
        ])
        ->and($section['markdown'])->toContain("vendor/bin/pest 'tests/Unit/MathTest.php' --filter='/^(?:Tests\\\\Unit\\\\MathTest\\:\\:it subtracts)$/'");
});

it('drops the test name Pest prefixes to a failure message', function (): void {
    junitWith($this->junit, '<testcase name="it subtracts" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it subtracts"><failure type="E">it subtractsFailed asserting that 1 is 2.</failure></testcase>');

    expect(BackendSection::from($this->junit, time() - 5, 1, 'pest')['failures'][0]['message'])->toBe('Failed asserting that 1 is 2.');
});

it('reports nothing for a run that passed', function (): void {
    junitWith($this->junit, '<testcase name="it adds" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it adds"/>');

    expect(BackendSection::from($this->junit, time() - 5, 0, 'pest'))->toBeNull();
});

const NO_RESULTS_NOTE = 'Pest exited with code 255 and wrote no test results: it stopped before the run ended (a fatal error, a crash, or a bootstrap failure). Read the terminal output above the summary.';

it('says why when Pest failed without writing results', function (): void {
    $section = BackendSection::from($this->junit, time() - 5, 255, 'pest');

    expect($section['failures'])->toBe([])
        ->and($section['notes'])->toBe([NO_RESULTS_NOTE]);
});

it('reads a JUnit file older than the run as no results, not as this run\'s', function (): void {
    junitWith($this->junit, '<testcase name="it adds" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it adds"><failure>old</failure></testcase>');
    touch($this->junit, time() - 600);

    $section = BackendSection::from($this->junit, time() - 5, 255, 'pest');

    expect($section['failures'])->toBe([])
        ->and($section['notes'])->toBe([NO_RESULTS_NOTE]);
});

it('says why when Pest failed although no test did', function (): void {
    junitWith($this->junit, '<testcase name="it adds" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it adds"/>');

    expect(BackendSection::from($this->junit, time() - 5, 1, 'pest')['notes'])
        ->toBe(['Pest exited with code 1, but no test failed. Look for a risky test, a warning or a deprecation the configuration treats as a failure, in the terminal output.']);
});

it('keeps the earlier failures of tests this run did not run, and drops those it ran', function (): void {
    junitWith($this->junit, '<testcase name="it subtracts" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it subtracts"/>');
    $earlier = [
        ['file' => 'tests/Unit/MathTest.php', 'class' => 'Tests\\Unit\\MathTest', 'name' => 'it subtracts', 'line' => 0, 'type' => 'Failure', 'message' => 'was red'],
        ['file' => 'tests/Unit/MathTest.php', 'class' => 'Tests\\Unit\\MathTest', 'name' => 'it divides', 'line' => 0, 'type' => 'Failure', 'message' => 'still red'],
    ];

    $section = BackendSection::from($this->junit, time() - 5, 0, 'pest', $earlier);

    expect(array_column($section['failures'], 'name'))->toBe(['it divides'])
        ->and($section['notes'])->toBe(['1 failure(s) below are from an earlier run: this run did not run those tests.']);
});

it('reports nothing once every earlier failure ran and passed', function (): void {
    junitWith($this->junit, '<testcase name="it divides" class="Tests\Unit\MathTest" file="tests/Unit/MathTest.php::it divides"/>');

    expect(BackendSection::from($this->junit, time() - 5, 0, 'pest', [
        ['file' => 'tests/Unit/MathTest.php', 'class' => 'Tests\\Unit\\MathTest', 'name' => 'it divides', 'line' => 0, 'type' => 'Failure', 'message' => 'red'],
    ]))->toBeNull();
});

it('says so when a failing run matched no test', function (): void {
    file_put_contents($this->junit, '<?xml version="1.0"?><testsuites><testsuite name="" tests="0"/></testsuites>');

    expect(BackendSection::from($this->junit, time() - 5, 1, 'pest')['notes'])
        ->toBe(['Pest exited with code 1 and ran no test: the selection matched none.']);
});
