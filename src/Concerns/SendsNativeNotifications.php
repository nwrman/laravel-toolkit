<?php

declare(strict_types=1);

namespace Nwrman\Toolkit\Concerns;

use Illuminate\Support\Facades\Process;

trait SendsNativeNotifications
{
    private function notify(string $title, string $message, string $sound): void
    {
        if ($this->option('no-notify') || ! config('toolkit.notifications', true)) {
            return;
        }

        $escapedTitle = escapeshellarg($title);
        $escapedMessage = escapeshellarg($message);
        $escapedSound = escapeshellarg($sound);

        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }

        $process = Process::run('command -v terminal-notifier');

        if ($process->successful()) {
            Process::run(sprintf(
                'terminal-notifier -message %s -title %s -sound %s -activate com.apple.Terminal 2>/dev/null',
                $escapedMessage,
                $escapedTitle,
                $escapedSound
            ));
        } else {
            Process::run(sprintf('afplay /System/Library/Sounds/%s.aiff &', $escapedSound));
        }
    }

    private function notificationTitle(): string
    {
        /** @var string|null $configTitle */
        $configTitle = config('toolkit.notification_title');

        return $configTitle ?? config('app.name', 'Laravel');
    }
}
