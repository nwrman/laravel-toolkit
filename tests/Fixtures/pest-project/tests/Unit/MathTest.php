<?php

declare(strict_types=1);

// Fails while a `fail` file sits in the project root.
function failing(): bool
{
    return file_exists(dirname(__DIR__, 2).'/fail');
}

it('adds', function (): void {
    expect(1 + 1)->toBe(2);
});

it('subtracts', function (): void {
    expect(failing())->toBeFalse();
});

it('subtracts twice', function (): void {
    expect(true)->toBeTrue();
});

it('counts', function (string $email): void {
    expect(failing() && $email === 'a@b.c')->toBeFalse();
})->with(['#1 a@b.c' => ['a@b.c'], '#2 c@d.e' => ['c@d.e']]);
