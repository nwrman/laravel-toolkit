<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

trait ManagesFrontendBuild
{
    /**
     * @codeCoverageIgnore
     */
    private function needsBuild(): bool
    {
        /** @var string $stampFile */
        $stampFile = config('toolkit.build.stamp_file', 'public/build/.buildstamp');

        /** @var string $manifestFile */
        $manifestFile = config('toolkit.build.manifest_file', 'public/build/manifest.json');

        if (! File::exists($stampFile) || ! File::exists($manifestFile)) {
            return true;
        }

        /** @var string $watchPaths */
        $watchPaths = config('toolkit.build.watch_paths', 'resources/ vite.config.* tsconfig.* tailwind.config.* postcss.config.* package.json');

        $cmd = sprintf('find %s -newer %s -print -quit 2>/dev/null', $watchPaths, $stampFile);
        $process = Process::run($cmd);

        return ! in_array(mb_trim($process->output()), ['', '0'], true);
    }

    private function ensureFrontendBuild(): bool
    {
        if (method_exists($this, 'option') && $this->option('force-build')) {
            $this->line('<comment>--force-build:</comment> rebuilding frontend assets...');
            // @codeCoverageIgnoreStart
        } elseif ($this->needsBuild()) {
            $this->line('<comment>Frontend source changes detected — building assets...</comment>');
        } else {
            $this->line('<info>✓ Frontend assets up to date (skip build)</info>');

            return true;
        }

        // @codeCoverageIgnoreEnd

        /** @var string $buildCommand */
        $buildCommand = config('toolkit.build.command', 'vp build');

        /** @var int $timeout */
        $timeout = config('toolkit.build.timeout', 120);

        /** @var string $stampFile */
        $stampFile = config('toolkit.build.stamp_file', 'public/build/.buildstamp');

        $process = Process::timeout($timeout)->start($buildCommand, function (string $type, string $output): void {
            if ($type === 'err') {
                $this->output->write('<error>'.$output.'</error>');
            } else {
                $this->output->write($output);
            }
        })->wait();

        if ($process->failed()) {
            $this->error('✗ Frontend build failed. Fix build errors before proceeding.');

            return false;
        }

        File::ensureDirectoryExists(dirname($stampFile));
        File::put($stampFile, (string) time());
        $this->info('✓ Frontend build succeeded');
        $this->newLine();

        return true;
    }
}
