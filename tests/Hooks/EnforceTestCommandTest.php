<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Run the published guard script with a simulated PreToolUse payload.
 *
 * @return array{exit:int, output:string}
 */
function runGuard(string $command, ?string $toolName = 'Bash'): array
{
    $script = dirname(__DIR__, 2).'/stubs/claude/hooks/enforce-test-command.php';

    $payload = ['tool_input' => ['command' => $command]];

    if ($toolName !== null) {
        $payload['tool_name'] = $toolName;
    }

    $process = new Process([PHP_BINARY, $script]);
    $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput()];
}

function guardDenies(string $command): bool
{
    $result = runGuard($command);

    return $result['exit'] === 0 && str_contains($result['output'], '"permissionDecision":"deny"');
}

it('blocks raw test runners and the bare composer test', function (string $command): void {
    expect(guardDenies($command))->toBeTrue();
})->with([
    'pest' => ['pest'],
    'pest parallel' => ['pest --parallel'],
    'vendor/bin/pest' => ['vendor/bin/pest --testsuite=Unit'],
    './vendor/bin/pest' => ['./vendor/bin/pest'],
    'phpunit' => ['phpunit'],
    'vendor/bin/phpunit' => ['vendor/bin/phpunit'],
    'php artisan test' => ['php artisan test'],
    'php artisan test with suite' => ['php artisan test --testsuite=Feature'],
    'composer test' => ['composer test'],
    'composer run test' => ['composer run test'],
    'env-prefixed pest' => ['XDEBUG_MODE=coverage pest --parallel'],
    'chained after cd' => ['cd app && php artisan test'],
    'chained after install' => ['composer install; composer test'],
]);

it('allows toolkit wrappers, filtered debugging, and frontend runners', function (string $command): void {
    expect(guardDenies($command))->toBeFalse();
    expect(trim(runGuard($command)['output']))->toBe('');
})->with([
    'composer test:unit' => ['composer test:unit'],
    'composer test:feature' => ['composer test:feature'],
    'composer test:browser' => ['composer test:browser'],
    'composer test:frontend' => ['composer test:frontend'],
    'composer test:report' => ['composer test:report'],
    'composer test:retry' => ['composer test:retry'],
    'composer test:ci' => ['composer test:ci'],
    'composer preflight' => ['composer preflight'],
    'composer run test:unit' => ['composer run test:unit'],
    'artisan test --filter' => ["php artisan test --filter='UserTest'"],
    'pest --filter' => ["pest --filter='it does the thing'"],
    'vitest' => ['bunx vitest run resources/js/x.test.tsx'],
    'bun run test' => ['bun run test:ui'],
    'synthetic e2e' => ['composer test:synthetic'],
    'prose mentioning pest' => ['git commit -m "fix pest setup"'],
    'unrelated command' => ['php artisan migrate'],
]);

it('fails open on empty or malformed input', function (): void {
    $script = dirname(__DIR__, 2).'/stubs/claude/hooks/enforce-test-command.php';

    foreach (['', 'not json', '{"tool_input":{}}'] as $input) {
        $process = new Process([PHP_BINARY, $script]);
        $process->setInput($input);
        $process->run();

        expect($process->getExitCode())->toBe(0);
        expect(trim($process->getOutput()))->toBe('');
    }
});

it('ignores non-Bash tools', function (): void {
    $result = runGuard('php artisan test', 'Edit');

    expect($result['exit'])->toBe(0);
    expect(trim($result['output']))->toBe('');
});
