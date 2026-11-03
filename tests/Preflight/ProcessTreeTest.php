<?php

declare(strict_types=1);

use Illuminate\Process\Factory;
use Nwrman\LaravelToolkit\Preflight\ProcessTree;
use Symfony\Component\Process\Process;

/**
 * Real processes: a shell that starts a child shell, which starts sleepers, as a gate
 * starts Pest and Pest starts its workers.
 */
it('stops a process and every process under it', function (): void {
    $gate = Process::fromShellCommandline('sh -c "sleep 30 & sleep 30 & wait" & wait');
    $gate->start();
    usleep(300_000);
    $descendants = descendantsOf((int) $gate->getPid());

    new ProcessTree(new Factory)->terminate((int) $gate->getPid());
    waitFor($gate);

    expect($descendants)->toHaveCount(3)
        ->and(array_filter($descendants, processRunning(...)))->toBe([]);
});

it('kills a process that ignores the request to stop', function (): void {
    $gate = Process::fromShellCommandline('sh -c "trap \'\' TERM; while true; do sleep 1; done" & wait');
    $gate->start();
    usleep(300_000);
    $descendants = descendantsOf((int) $gate->getPid());

    new ProcessTree(new Factory)->terminate((int) $gate->getPid());
    waitFor($gate);

    expect(array_filter($descendants, processRunning(...)))->toBe([]);
});

/**
 * A zombie has exited; it only waits to be collected.
 */
function processRunning(int $pid): bool
{
    $state = mb_trim((string) shell_exec('ps -o stat= -p '.$pid));

    return $state !== '' && ! str_starts_with($state, 'Z');
}

/**
 * Waits without wait(), which throws for a process a signal ended.
 */
function waitFor(Process $process): void
{
    while ($process->isRunning()) {
        usleep(50_000);
    }
}

/**
 * @return array<int, int>
 */
function descendantsOf(int $pid): array
{
    $found = [];
    $queue = [$pid];

    while ($queue !== []) {
        $children = array_map(intval(...), array_filter(explode("\n", (string) shell_exec('pgrep -P '.array_shift($queue)))));
        array_push($found, ...$children);
        array_push($queue, ...$children);
    }

    return $found;
}
