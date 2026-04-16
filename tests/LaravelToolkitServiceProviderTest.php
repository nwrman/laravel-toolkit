<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Mockery\MockInterface;
use Nwrman\LaravelToolkit\LaravelToolkitServiceProvider;

it('registers toolkit commands (except deploy-notify, which is published into the consumer app)', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('toolkit:preflight')
        ->toHaveKey('toolkit:report')
        ->toHaveKey('toolkit:retry')
        ->toHaveKey('toolkit:install');

    // DeployNotifyTelegramCommand is no longer package-resident; it's published as a stub.
    expect($commands)->not->toHaveKey('toolkit:deploy-notify');
    expect($commands)->not->toHaveKey('deploy:notify-telegram');
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

it('publishes deploy-notify command and its test under toolkit-commands tag', function (): void {
    $tagGroups = ServiceProvider::$publishGroups['toolkit-commands'] ?? [];

    $commandTarget = app_path('Console/Commands/DeployNotifyTelegramCommand.php');
    $testTarget = base_path('tests/Feature/Console/Commands/DeployNotifyTelegramCommandTest.php');

    expect(in_array($commandTarget, $tagGroups, true))->toBeTrue();
    expect(in_array($testTarget, $tagGroups, true))->toBeTrue();
});

it('is inert when not running in console (register is a no-op)', function (): void {
    /** @var MockInterface&Application $app */
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturn(false);
    // register() must short-circuit before touching mergeConfigFrom, so no other
    // methods should be called on the container.
    $app->shouldNotReceive('make');

    $provider = new LaravelToolkitServiceProvider($app);
    $provider->register();

    // No assertion error means the short-circuit worked.
    expect(true)->toBeTrue();
});

it('is inert when not running in console (boot is a no-op)', function (): void {
    /** @var MockInterface&Application $app */
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturn(false);
    // boot() must short-circuit before calling any of the helpers. Those helpers
    // call $this->commands(...), $this->publishes(...) and config() which would
    // need a full container; the mock has none of that wired.
    $app->shouldNotReceive('isProduction');

    $provider = new LaravelToolkitServiceProvider($app);
    $provider->boot();

    expect(true)->toBeTrue();
});
