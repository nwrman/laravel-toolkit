<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Preflight;

use Illuminate\Filesystem\Filesystem;

/**
 * Each gate's result from the last run that ran it. A run of some gates keeps the
 * others' results, so `--retry` and `--gate=` can still show every gate's row.
 */
final readonly class GateState
{
    public function __construct(private Filesystem $files, private string $path) {}

    /**
     * @return array<string, array{status: string, duration: float}>
     */
    public function read(): array
    {
        if (! $this->files->isFile($this->path)) {
            return [];
        }

        $data = json_decode($this->files->get($this->path), true);
        $gates = is_array($data) && is_array($data['gates'] ?? null) ? $data['gates'] : [];
        $state = [];

        foreach ($gates as $key => $gate) {
            if (is_array($gate) && is_string($gate['status'] ?? null)) {
                $state[(string) $key] = ['status' => $gate['status'], 'duration' => is_numeric($gate['duration'] ?? null) ? (float) $gate['duration'] : 0.0];
            }
        }

        return $state;
    }

    /**
     * @param  array<string, array{status: string, duration: float, output: string}>  $results
     */
    public function write(array $results): void
    {
        $gates = $this->read();

        foreach ($results as $key => $result) {
            $gates[$key] = ['status' => $result['status'], 'duration' => $result['duration'], 'ran_at' => date('c')];
        }

        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, (string) json_encode(['updated_at' => date('c'), 'gates' => $gates], JSON_PRETTY_PRINT));
    }
}
