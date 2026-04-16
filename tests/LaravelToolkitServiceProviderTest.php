<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Nwrman\LaravelToolkit\LaravelToolkitServiceProvider;

it('registers all toolkit commands', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('toolkit:preflight')
        ->toHaveKey('toolkit:report')
        ->toHaveKey('toolkit:retry')
        ->toHaveKey('toolkit:deploy-notify')
        ->toHaveKey('toolkit:install');
});

it('merges default config', function (): void {
    expect(config('toolkit.gates'))->toBeArray()
        ->toHaveKey('pest-coverage')
        ->toHaveKey('frontend-coverage')
        ->toHaveKey('lint')
        ->toHaveKey('types');

    expect(config('toolkit.gates.pest-coverage.label'))->toBe('Pest Coverage');
    expect(config('toolkit.paths.preflight_state'))->toBe('storage/logs/preflight-result.json');
});

it('deep merges consumer gate overrides without losing other gates', function (): void {
    // Simulate consumer publishing config with only one gate override
    config(['toolkit.gates.lint.command' => 'pint --test']);

    // Re-boot the provider to trigger deep merge
    $provider = new LaravelToolkitServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    $gates = config('toolkit.gates');

    // The overridden gate should have the new command
    expect($gates['lint']['command'])->toBe('pint --test');

    // Other gates should still exist
    expect($gates)->toHaveKey('pest-coverage')
        ->toHaveKey('frontend-coverage')
        ->toHaveKey('types');
});

it('allows removing a gate by setting it to null', function (): void {
    config(['toolkit.gates.frontend-coverage' => null]);

    $provider = new LaravelToolkitServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    $gates = config('toolkit.gates');

    expect($gates)->not->toHaveKey('frontend-coverage')
        ->toHaveKey('pest-coverage')
        ->toHaveKey('lint')
        ->toHaveKey('types');
});

it('publishes config file with toolkit-config tag', function (): void {
    $publishable = ServiceProvider::$publishes[LaravelToolkitServiceProvider::class] ?? [];

    $configTarget = config_path('toolkit.php');

    expect(in_array($configTarget, $publishable, true))->toBeTrue();
});
