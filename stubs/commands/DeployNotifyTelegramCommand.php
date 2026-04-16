<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Override;

final class DeployNotifyTelegramCommand extends Command
{
    private const array VALID_STATUSES = ['started', 'failed', 'succeeded'];

    private const array VALID_STAGES = ['build', 'deploy'];

    private const array STATUS_LABELS = [
        'started' => 'CI started',
        'failed' => 'Deployment failed',
        'succeeded' => 'Deployment succeeded',
    ];

    private const array STATUS_ICONS = [
        'started' => '🚀',
        'failed' => '❌',
        'succeeded' => '✅',
    ];

    #[Override]
    protected $signature = 'deploy:notify-telegram
        {status : Deployment status (started|failed|succeeded)}
        {--stage=deploy : Deployment stage (build|deploy)}
        {--reason= : Optional failure reason}';

    #[Override]
    protected $description = 'Send a Laravel Cloud deployment notification to Telegram';

    public function handle(): int
    {
        $status = mb_strtolower((string) $this->argument('status'));
        $stage = mb_strtolower((string) $this->option('stage'));
        $reason = mb_trim((string) $this->option('reason'));

        if (! in_array($status, self::VALID_STATUSES, true)) {
            $this->error(sprintf(
                'Invalid status "%s". Allowed values: %s',
                $status,
                implode(', ', self::VALID_STATUSES)
            ));

            return self::INVALID;
        }

        if (! in_array($stage, self::VALID_STAGES, true)) {
            $this->error(sprintf(
                'Invalid stage "%s". Allowed values: %s',
                $stage,
                implode(', ', self::VALID_STAGES)
            ));

            return self::INVALID;
        }

        $apiBase = $this->stringConfig('services.telegram.api_base', 'https://api.telegram.org');
        $botToken = $this->nullableStringConfig('services.telegram.bot_token');
        $chatId = $this->nullableStringConfig('services.telegram.chat_id');
        $threadId = $this->nullableStringConfig('services.telegram.thread_id');

        if ($botToken === null || $botToken === '' || $chatId === null || $chatId === '') {
            $this->warn('Telegram credentials are missing; skipping deployment notification.');

            return self::SUCCESS;
        }

        $message = $this->buildMessage($status, $stage, $reason);
        $payload = [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML',
            'text' => $message,
        ];

        if ($threadId !== null && $threadId !== '') {
            $payload['message_thread_id'] = $threadId;
        }

        $response = Http::asForm()->post(mb_rtrim($apiBase, '/').sprintf('/bot%s/sendMessage', $botToken), $payload);

        if ($response->failed()) {
            $this->error('Telegram notification failed: HTTP '.$response->status());

            return self::FAILURE;
        }

        $this->info('Telegram deployment notification sent.');

        return self::SUCCESS;
    }

    private function buildMessage(string $status, string $stage, string $reason): string
    {
        $statusLine = self::STATUS_LABELS[$status];
        $icon = self::STATUS_ICONS[$status];

        $appName = $this->stringConfig('app.name', 'Laravel');
        $appEnv = $this->stringConfig('app.env', 'production');

        $lines = [
            sprintf('%s <b>%s</b>', $icon, $statusLine),
            '',
            sprintf('<b>App:</b> %s', e($appName)),
            sprintf('<b>Env:</b> %s', e($appEnv)),
            sprintf('<b>Stage:</b> %s', e($stage)),
        ];

        if ($status === 'failed' && $reason !== '') {
            $lines[] = sprintf('<b>Reason:</b> %s', e($reason));
        }

        return implode("\n", $lines);
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) ? $value : $default;
    }

    private function nullableStringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }
}
