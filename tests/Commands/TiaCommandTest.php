<?php

declare(strict_types=1);

it('does nothing when TIA is off', function (string $action): void {
    config(['toolkit.tia.enabled' => false]);

    $this->artisan('toolkit:tia', ['action' => $action])
        ->expectsOutput('TIA is off (toolkit.tia.enabled): nothing to do.')
        ->assertExitCode(0);
})->with(['check', 'inherit', 'publish']);

it('rejects an unknown action', function (): void {
    $this->artisan('toolkit:tia', ['action' => 'record'])
        ->expectsOutput('Unknown action "record". Use check, inherit or publish.')
        ->assertExitCode(2);
});

it('fails the check with the reason when there is no usable baseline', function (): void {
    config(['toolkit.tia.enabled' => true]);
    $this->app->setBasePath(sys_get_temp_dir());

    $this->artisan('toolkit:tia', ['action' => 'check'])
        ->expectsOutput('No usable TIA baseline: not on a branch.')
        ->assertExitCode(1);
});
