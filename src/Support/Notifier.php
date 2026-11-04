<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Support;

use Illuminate\Process\Factory;

/**
 * macOS desktop notifications: terminal-notifier when installed, else a system sound.
 */
final readonly class Notifier
{
    public function __construct(private Factory $process, private bool $enabled) {}

    public function notify(string $title, string $message, string $sound): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->process->newPendingProcess()->run('command -v terminal-notifier')->successful()) {
            $this->process->newPendingProcess()->run(sprintf(
                'terminal-notifier -message %s -title %s -sound %s -activate com.apple.Terminal 2>/dev/null',
                escapeshellarg($message),
                escapeshellarg($title),
                escapeshellarg($sound),
            ));

            return;
        }

        $this->process->newPendingProcess()->run(sprintf('afplay /System/Library/Sounds/%s.aiff &', escapeshellarg($sound)));
    }
}
