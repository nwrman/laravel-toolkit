<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Concerns;

use Illuminate\Support\Facades\Process;

trait SendsDesktopNotifications
{
    private function notify(string $title, string $message, string $sound): void
    {
        if (method_exists($this, 'hasOption') && $this->hasOption('no-notify') && $this->option('no-notify')) {
            return;
        }

        /** @var bool $enabled */
        $enabled = config('toolkit.notifications.desktop.enabled', true);

        if (! $enabled) {
            return;
        }

        $escapedTitle = escapeshellarg($title);
        $escapedMessage = escapeshellarg($message);
        $escapedSound = escapeshellarg($sound);

        $process = Process::run('command -v terminal-notifier');

        if ($process->successful()) {
            Process::run(sprintf('terminal-notifier -message %s -title %s -sound %s -activate com.apple.Terminal 2>/dev/null', $escapedMessage, $escapedTitle, $escapedSound));
        } else {
            Process::run(sprintf('afplay /System/Library/Sounds/%s.aiff &', $escapedSound));
        }
    }
}
