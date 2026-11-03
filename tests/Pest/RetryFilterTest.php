<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Pest\RetryFilter;

/**
 * What PHPUnit (with Pest's override) matches a filter against: "<class>::<name>".
 */
function selects(string $pattern, string $test): bool
{
    return preg_match($pattern, $test) === 1;
}

it('selects exactly the listed tests, and not a test whose name contains one', function (): void {
    $pattern = RetryFilter::pattern([
        ['class' => 'Tests\\Feature\\LoginTest', 'name' => 'it logs in'],
        ['class' => 'Tests\\Unit\\EmailTest', 'name' => 'it accepts with data set "(\'a@b.c\')"'],
    ]);

    expect(selects($pattern, 'Tests\\Feature\\LoginTest::it logs in'))->toBeTrue()
        ->and(selects($pattern, 'Tests\\Unit\\EmailTest::it accepts with data set "(\'a@b.c\')"'))->toBeTrue()
        ->and(selects($pattern, 'Tests\\Feature\\LoginTest::it logs in with a passkey'))->toBeFalse()
        ->and(selects($pattern, 'Tests\\Feature\\Admin\\LoginTest::it logs in'))->toBeFalse()
        ->and(selects($pattern, 'Tests\\Unit\\EmailTest::it accepts with data set "(\'aXb.c\')"'))->toBeFalse();
});

it('quotes the characters a regular expression or PHPUnit\'s filter syntax would read', function (string $name): void {
    $pattern = RetryFilter::pattern([['class' => 'Tests\\Unit\\NameTest', 'name' => $name]]);

    expect(selects($pattern, 'Tests\\Unit\\NameTest::'.$name))->toBeTrue()
        ->and(selects($pattern, 'Tests\\Unit\\NameTest::'.$name.' and more'))->toBeFalse();
})->with([
    'a data-set number' => ['it counts #3'],
    'a named data set' => ['it mails user@example.com'],
    'regex characters' => ['it matches (a|b)* [x] {2} ^$ + ? . \\d'],
    'the delimiter' => ['it reads a/b/c'],
]);

it('prints a command a shell passes to Pest unchanged', function (): void {
    $name = "it handles 'quotes' and \$dollars with data set \"(\\'x\\')\"";
    $command = RetryFilter::command('tests/Unit/Odd Name Test.php', 'Tests\\Unit\\OddNameTest', $name);
    $arguments = mb_substr($command, mb_strlen('vendor/bin/pest '));

    $received = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo json_encode(array_slice($argv, 1));').' -- '.$arguments), true);

    expect($received)->toBe([
        'tests/Unit/Odd Name Test.php',
        '--filter='.RetryFilter::pattern([['class' => 'Tests\\Unit\\OddNameTest', 'name' => $name]]),
    ]);
});
