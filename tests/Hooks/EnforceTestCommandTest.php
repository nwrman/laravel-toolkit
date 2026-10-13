<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Run the guard script with a simulated PreToolUse payload.
 *
 * @return array{exit:int, output:string}
 */
function runGuard(string $command, ?string $toolName = 'Bash'): array
{
    $script = dirname(__DIR__, 2).'/bin/enforce-test-command.php';

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

it('blocks raw test runners', function (string $command): void {
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
    'env-prefixed pest' => ['XDEBUG_MODE=coverage pest --parallel'],
    'chained after cd' => ['cd app && php artisan test'],
    'chained after install' => ['composer install; vendor/bin/pest'],
    'after a heredoc that ends' => ["cat > notes.md <<'EOF'\nsome notes\nEOF\nvendor/bin/pest"],
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
    'composer test' => ['composer test'],
    'composer run test' => ['composer run test'],
    'composer test with a filter' => ["composer test -- --filter='GalleryTest'"],
    'composer test:ci' => ['composer test:ci'],
    'composer preflight' => ['composer preflight'],
    'preflight retry' => ['php artisan toolkit:preflight --retry'],
    'composer run test:unit' => ['composer run test:unit'],
    'artisan test --filter' => ["php artisan test --filter='UserTest'"],
    'pest --filter' => ["pest --filter='it does the thing'"],
    'vitest' => ['bunx vitest run resources/js/x.test.tsx'],
    'bun run test' => ['bun run test:ui'],
    'synthetic e2e' => ['composer test:synthetic'],
    'prose mentioning pest' => ['git commit -m "fix pest setup"'],
    'a runner named inside single quotes' => ["grep -E 'artisan test|vendor/bin/pest' scan.log"],
    'a runner named inside double quotes' => ['echo "then run && vendor/bin/pest"'],
    'a script written through a heredoc' => ["python3 - <<'EOF'\ncmd = 'pest --type-coverage && vendor/bin/pest --parallel'\nEOF"],
    'unrelated command' => ['php artisan migrate'],
]);

it('fails open on empty or malformed input', function (): void {
    $script = dirname(__DIR__, 2).'/bin/enforce-test-command.php';

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

it('points a blocked run at the checking order, not at hand-picked suites', function (): void {
    $output = runGuard('vendor/bin/pest')['output'];

    expect($output)->toContain('composer preflight')
        ->toContain('--retry')
        ->not->toContain('test:unit | test:feature');
});
