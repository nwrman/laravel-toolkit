<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('registers the deploy:notify-telegram command', function () {
    $this->artisan('list')
        ->assertSuccessful()
        ->expectsOutputToContain('deploy:notify-telegram');
});

it('skips notification when credentials are missing', function () {
    config([
        'services.telegram.bot_token' => null,
        'services.telegram.chat_id' => null,
    ]);

    $this->artisan('deploy:notify-telegram', ['status' => 'started'])
        ->assertSuccessful()
        ->expectsOutputToContain('Telegram credentials are missing');
});

it('rejects invalid status values', function () {
    $this->artisan('deploy:notify-telegram', ['status' => 'invalid'])
        ->assertExitCode(2) // INVALID
        ->expectsOutputToContain('Invalid status');
});

it('rejects invalid stage values', function () {
    $this->artisan('deploy:notify-telegram', ['status' => 'started', '--stage' => 'invalid'])
        ->assertExitCode(2)
        ->expectsOutputToContain('Invalid stage');
});

it('sends notification successfully', function () {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '12345',
        'services.telegram.thread_id' => null,
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    $this->artisan('deploy:notify-telegram', ['status' => 'started'])
        ->assertSuccessful()
        ->expectsOutputToContain('Telegram deployment notification sent');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'sendMessage')
            && str_contains($request->body(), 'CI+started');
    });
});

it('includes failure reason when status is failed', function () {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '12345',
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    $this->artisan('deploy:notify-telegram', [
        'status' => 'failed',
        '--reason' => 'Migration failed',
    ])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->body(), 'Migration+failed');
    });
});

it('includes thread_id when configured', function () {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '12345',
        'services.telegram.thread_id' => '99',
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    $this->artisan('deploy:notify-telegram', ['status' => 'succeeded'])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->body(), 'message_thread_id=99');
    });
});

it('reports failure when Telegram API returns error', function () {
    config([
        'services.telegram.api_base' => 'https://api.telegram.org',
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '12345',
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false], 400),
    ]);

    $this->artisan('deploy:notify-telegram', ['status' => 'started'])
        ->assertFailed()
        ->expectsOutputToContain('Telegram notification failed');
});
