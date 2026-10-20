<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Tia;

use Illuminate\Support\Facades\Process;

/**
 * Where Pest keeps a checkout's TIA cache.
 *
 * Pest keys the cache by repository identity, read from `<root>/.git/config`. In a
 * git worktree `.git` is a file, so the key falls back to the worktree's own path:
 * every checkout has its own directory. `pest --baseline` prints it, so the key
 * derivation is never reimplemented here.
 *
 * Not final: Pest is the edge tests fake.
 */
class PestCache
{
    public function directory(string $root): ?string
    {
        // laravel/pao would wrap the path in JSON when an agent runs this.
        $result = Process::path($root)->env(['PAO_DISABLE' => '1'])->run(['vendor/bin/pest', '--baseline']);

        if (! $result->successful()) {
            return null;
        }

        $lines = preg_split('/\R/', mb_trim($result->output())) ?: [];
        $directory = mb_trim((string) end($lines));

        return str_starts_with($directory, DIRECTORY_SEPARATOR) ? $directory : null;
    }
}
