<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'app.name' => 'Laravel',
        'app.env' => 'production',
    ]);
});

it('sends started deployment notification', function (): void {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'bot-token',
        'services.telegram.chat_id' => '123456',
        'services.telegram.thread_id' => null,
    ]);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $this->artisan('toolkit:deploy-notify started --stage=build')
        ->expectsOutput('Telegram deployment notification sent.')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.telegram.org/botbot-token/sendMessage'
            && $data['chat_id'] === '123456'
            && $data['parse_mode'] === 'HTML'
            && str_contains((string) $data['text'], '🚀 <b>CI started</b>')
            && str_contains((string) $data['text'], 'CI started')
            && str_contains((string) $data['text'], '<b>Stage:</b> build')
            && ! array_key_exists('message_thread_id', $data);
    });
});

it('sends failed deployment notification with reason and thread id', function (): void {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'bot-token',
        'services.telegram.chat_id' => '123456',
        'services.telegram.thread_id' => '99',
    ]);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $this->artisan('toolkit:deploy-notify failed --stage=deploy --reason="Migration failed"')
        ->expectsOutput('Telegram deployment notification sent.')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_contains((string) $data['text'], '❌ <b>Deployment failed</b>')
            && str_contains((string) $data['text'], 'Deployment failed')
            && str_contains((string) $data['text'], '<b>Reason:</b> Migration failed')
            && $data['message_thread_id'] === '99';
    });
});

it('sends succeeded deployment notification', function (): void {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'bot-token',
        'services.telegram.chat_id' => '123456',
        'services.telegram.thread_id' => null,
    ]);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $this->artisan('toolkit:deploy-notify succeeded')
        ->expectsOutput('Telegram deployment notification sent.')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['text'] ?? ''), '✅ <b>Deployment succeeded</b>'));
});

it('exits successfully when telegram config is missing', function (): void {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => null,
        'services.telegram.chat_id' => null,
        'services.telegram.thread_id' => null,
    ]);

    Http::fake();

    $this->artisan('toolkit:deploy-notify started')
        ->expectsOutput('Telegram credentials are missing; skipping deployment notification.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('returns failure when telegram api returns an error', function (): void {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'bot-token',
        'services.telegram.chat_id' => '123456',
        'services.telegram.thread_id' => null,
    ]);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => false], 500),
    ]);

    $this->artisan('toolkit:deploy-notify started')
        ->expectsOutput('Telegram notification failed: HTTP 500')
        ->assertExitCode(1);
});

it('validates status and stage options', function (): void {
    $this->artisan('toolkit:deploy-notify unknown')
        ->expectsOutputToContain('Invalid status "unknown"')
        ->assertExitCode(2);

    $this->artisan('toolkit:deploy-notify started --stage=invalid')
        ->expectsOutputToContain('Invalid stage "invalid"')
        ->assertExitCode(2);
});
