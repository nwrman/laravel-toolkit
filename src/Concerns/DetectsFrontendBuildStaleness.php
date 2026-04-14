<?php

declare(strict_types=1);

namespace Nwrman\Toolkit\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

trait DetectsFrontendBuildStaleness
{
    private function needsBuild(): bool
    {
        $buildstamp = 'public/build/.buildstamp';
        $manifest = 'public/build/manifest.json';

        if (! File::exists($buildstamp) || ! File::exists($manifest)) {
            return true;
        }

        $cmd = sprintf(
            'find resources/ vite.config.* tsconfig.* tailwind.config.* postcss.config.* package.json -newer %s -print -quit 2>/dev/null',
            $buildstamp
        );
        $process = Process::run($cmd);

        return ! in_array(mb_trim($process->output()), ['', '0'], true);
    }

    private function ensureFrontendBuild(bool $forceBuild = false): bool
    {
        if ($forceBuild) {
            $this->line('<comment>--force-build:</comment> rebuilding frontend assets...');
        } elseif ($this->needsBuild()) {
            $this->line('<comment>Frontend source changes detected — building assets...</comment>');
        } else {
            $this->line('<info>✓ Frontend assets up to date (skip build)</info>');

            return true;
        }

        /** @var string $buildCommand */
        $buildCommand = config('toolkit.frontend_build_command', 'bun run build');

        $process = Process::timeout(120)->start($buildCommand, function (string $type, string $output): void {
            if ($type === 'err') {
                $this->output->write('<error>'.$output.'</error>');
            } else {
                $this->output->write($output);
            }
        })->wait();

        if ($process->failed()) {
            $this->error('✗ Frontend build failed. Fix build errors before continuing.');

            return false;
        }

        File::put('public/build/.buildstamp', (string) time());
        $this->info('✓ Frontend build succeeded');
        $this->newLine();

        return true;
    }
}
