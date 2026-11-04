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
    $descendants = descendantsOnceStarted((int) $gate->getPid(), 3);

    new ProcessTree(new Factory)->terminate((int) $gate->getPid());
    waitFor($gate);

    expect($descendants)->toHaveCount(3)
        ->and(array_filter($descendants, processRunning(...)))->toBe([]);
});

it('stops several trees within one grace period', function (): void {
    $gates = [
        Process::fromShellCommandline('sh -c "trap \'\' TERM; while true; do sleep 1; done" & wait'),
        Process::fromShellCommandline('sh -c "trap \'\' TERM; while true; do sleep 1; done" & wait'),
    ];
    array_map(fn (Process $gate) => $gate->start(), $gates);
    $descendants = array_merge(...array_map(fn (Process $gate): array => descendantsOnceStarted((int) $gate->getPid(), 2), $gates));
    $started = microtime(true);

    new ProcessTree(new Factory)->terminate(...array_map(fn (Process $gate): int => (int) $gate->getPid(), $gates));
    array_map(waitFor(...), $gates);

    expect(array_filter($descendants, processRunning(...)))->toBe([])
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('kills a process that ignores the request to stop', function (): void {
    $gate = Process::fromShellCommandline('sh -c "trap \'\' TERM; while true; do sleep 1; done" & wait');
    $gate->start();
    $descendants = descendantsOnceStarted((int) $gate->getPid(), 2);

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
 * The process tree under $pid once it has $count processes: the shells start their
 * children asynchronously.
 *
 * @return array<int, int>
 */
function descendantsOnceStarted(int $pid, int $count): array
{
    $descendants = descendantsOf($pid);

    while (count($descendants) < $count) {
        usleep(20_000);
        $descendants = descendantsOf($pid);
    }

    return $descendants;
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
