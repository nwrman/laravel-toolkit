<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Support;

use Closure;
use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The frontend build browser specs render: rebuilt when a watched file is newer than
 * the last build's stamp. The build tool deletes the dev server's hot file, which would
 * leave the dev site serving the old build, so a hot file present before the build is
 * put back after it.
 */
final readonly class AssetBuild
{
    /**
     * @param  array{command?: string, timeout?: int, stamp_file?: string, manifest_file?: string, watch_paths?: string, hot_file?: string}  $config
     */
    public function __construct(
        private string $root,
        private array $config,
        private Filesystem $files,
        private Factory $process,
    ) {}

    public function isStale(): bool
    {
        $stamp = $this->path($this->config['stamp_file'] ?? 'public/build/.buildstamp');

        if (! $this->files->isFile($stamp) || ! $this->files->isFile($this->path($this->config['manifest_file'] ?? 'public/build/manifest.json'))) {
            return true;
        }

        $builtAt = $this->files->lastModified($stamp);
        $patterns = preg_split('/\s+/', mb_trim($this->config['watch_paths'] ?? 'resources/ package.json'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($patterns as $pattern) {
            foreach (glob($this->path($pattern)) ?: [] as $path) {
                if (is_dir($path) ? $this->hasFileNewerThan($path, $builtAt) : $this->files->lastModified($path) > $builtAt) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Runs the build, streaming its output to $output. Returns whether it succeeded.
     *
     * @param  Closure(string): void  $output
     */
    public function build(Closure $output): bool
    {
        $hot = $this->path($this->config['hot_file'] ?? 'public/hot');
        $hotContents = $this->files->isFile($hot) ? $this->files->get($hot) : null;

        try {
            $result = $this->process->newPendingProcess()->path($this->root)
                ->timeout($this->config['timeout'] ?? 120)
                ->run($this->config['command'] ?? 'vp build', function (string $type, string $buffer) use ($output): void {
                    $output($buffer);
                });
        } finally {
            // The build tool deletes the hot file while it runs.
            if ($hotContents !== null && ! $this->files->exists($hot)) {
                $this->files->put($hot, $hotContents);
            }
        }

        if ($result->failed()) {
            return false;
        }

        $stamp = $this->path($this->config['stamp_file'] ?? 'public/build/.buildstamp');
        $this->files->ensureDirectoryExists(dirname($stamp));
        $this->files->put($stamp, (string) time());

        return true;
    }

    private function path(string $relative): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$relative;
    }

    private function hasFileNewerThan(string $directory, int $time): bool
    {
        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getMTime() > $time) {
                return true;
            }
        }

        return false;
    }
}
