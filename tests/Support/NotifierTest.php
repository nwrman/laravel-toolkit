<?php

declare(strict_types=1);

use Illuminate\Process\Factory;
use Nwrman\LaravelToolkit\Support\Notifier;

it('notifies through terminal-notifier when it is installed', function (): void {
    $process = new Factory;
    $process->preventStrayProcesses();
    $process->fake(['command -v terminal-notifier' => $process->result('/opt/homebrew/bin/terminal-notifier'), '*' => $process->result()]);

    new Notifier($process, enabled: true)->notify('App Tests', "2 tests failed — it's red", 'Basso');

    $process->assertRan("terminal-notifier -message '2 tests failed — it'\\''s red' -title 'App Tests' -sound 'Basso' -activate com.apple.Terminal 2>/dev/null");
});

it('falls back to a system sound without terminal-notifier', function (): void {
    $process = new Factory;
    $process->preventStrayProcesses();
    $process->fake(['command -v terminal-notifier' => $process->result(exitCode: 1), '*' => $process->result()]);

    new Notifier($process, enabled: true)->notify('App Tests', 'passed', 'Glass');

    $process->assertRan("afplay /System/Library/Sounds/'Glass'.aiff &");
});

it('runs nothing when disabled', function (): void {
    $process = new Factory;
    $process->fake();

    new Notifier($process, enabled: false)->notify('App Tests', 'passed', 'Glass');

    $process->assertNothingRan();
});
