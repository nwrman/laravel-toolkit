<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Tia\CoverageCache;

it('moves paths under one checkout to another, keeping the data readable', function (): void {
    $coverage = serialize([
        '/repo/t1/app/Models/User.php' => [12 => ['P\\Tests\\UserTest::it works']],
        'files' => ['/repo/t1/app/Example.php', '/repo/t1'],
    ]);

    expect(unserialize(CoverageCache::rebaseSerialized($coverage, '/repo/t1', '/Users/me/projects/main')))->toBe([
        '/Users/me/projects/main/app/Models/User.php' => [12 => ['P\\Tests\\UserTest::it works']],
        'files' => ['/Users/me/projects/main/app/Example.php', '/Users/me/projects/main'],
    ]);
});

it('leaves strings that only look like the old checkout alone', function (string $path): void {
    $coverage = serialize([$path => [1 => ['test']]]);

    expect(CoverageCache::rebaseSerialized($coverage, '/repo/t1', '/repo/main'))->toBe($coverage);
})->with([
    'a sibling checkout sharing the prefix' => '/repo/t1-two/app/Example.php',
    'the path inside a longer string' => 'see /repo/t1/app/Example.php',
    'a string that holds serialized text' => 's:21:"/repo/t1/app/User.php"',
]);
