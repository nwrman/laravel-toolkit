<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Preflight;

use Illuminate\Process\Factory;

/**
 * Stops a process and everything it started: a gate is a shell running Pest, which
 * runs Paratest workers, which run browsers. Stopping only the shell leaves the rest
 * running and holding the machine.
 *
 * Not final: tests fake it, so they never signal a real process.
 */
class ProcessTree
{
    private const float GRACE_SECONDS = 3.0;

    public function __construct(private readonly Factory $process) {}

    public function terminate(int $pid): void
    {
        $descendants = $this->descendants($pid);

        $this->signal([...$descendants, $pid], SIGTERM);

        // $pid is this process's child: once it exits it stays a zombie until the caller
        // reaps it, so only the descendants are waited for.
        $deadline = microtime(true) + self::GRACE_SECONDS;

        while (microtime(true) < $deadline && array_any($descendants, $this->alive(...))) {
            usleep(100_000);
        }

        $this->kill([...array_values(array_filter($descendants, $this->alive(...))), $pid]);
    }

    /**
     * Kills what ignored SIGTERM. A survivor may have started children since the tree
     * was read, so survivors are frozen first, so none can start another, and their
     * children are read again before everything is killed.
     *
     * @param  array<int, int>  $survivors
     */
    private function kill(array $survivors): void
    {
        $this->signal($survivors, SIGSTOP);

        $targets = $survivors;

        foreach ($survivors as $survivor) {
            $targets = [...$targets, ...$this->descendants($survivor)];
        }

        $targets = array_values(array_unique($targets));

        $this->signal($targets, SIGSTOP);
        $this->signal($targets, SIGKILL);
    }

    /**
     * Children first found, grandchildren after: collected before any is signalled, so
     * none is reparented out of reach.
     *
     * @return array<int, int>
     */
    private function descendants(int $pid): array
    {
        $found = [];
        $queue = [$pid];

        while ($queue !== []) {
            $parent = array_shift($queue);
            $result = $this->process->newPendingProcess()->run(['pgrep', '-P', (string) $parent]);
            $children = array_map(intval(...), preg_split('/\s+/', mb_trim($result->output()), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            foreach ($children as $child) {
                if (! in_array($child, $found, true) && $child !== $pid) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<int, int>  $pids
     */
    private function signal(array $pids, int $signal): void
    {
        if ($pids === []) {
            return;
        }

        if (function_exists('posix_kill')) {
            foreach ($pids as $pid) {
                @posix_kill($pid, $signal);
            }

            return;
        }

        $this->process->newPendingProcess()->run(['kill', '-'.$signal, ...array_map(strval(...), $pids)]);
    }

    /**
     * Whether $pid is still running. A zombie has exited and only waits for its parent
     * to collect it, so it counts as gone.
     */
    private function alive(int $pid): bool
    {
        $state = mb_trim($this->process->newPendingProcess()->run(['ps', '-o', 'stat=', '-p', (string) $pid])->output());

        return $state !== '' && ! str_starts_with($state, 'Z');
    }
}
