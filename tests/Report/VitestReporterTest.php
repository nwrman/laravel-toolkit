<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The Vitest reporter and its shared report format are JavaScript; their tests run on
 * Node's own test runner (tests/js), so the toolkit needs no Vitest of its own.
 */
it('passes the Vitest reporter\'s own tests', function (): void {
    $node = new Process(['node', '--test', 'tests/js/vitest-reporter.test.mjs'], dirname(__DIR__, 2));
    $node->run();

    expect($node->getExitCode())->toBe(0, $node->getOutput().$node->getErrorOutput());
});
