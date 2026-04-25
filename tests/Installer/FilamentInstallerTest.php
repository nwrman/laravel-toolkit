<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Installer\FilamentInstaller;

function makeSnapshot(string $base): void
{
    mkdir($base.'/app/Filament/Resources/Users', 0o755, true);
    mkdir($base.'/app/Providers/Filament', 0o755, true);
    mkdir($base.'/database/migrations', 0o755, true);
    mkdir($base.'/database/seeders', 0o755, true);

    file_put_contents($base.'/app/Filament/Resources/Users/UserResource.php', "<?php\n// UserResource\n");
    file_put_contents(
        $base.'/app/Providers/Filament/AdminPanelProvider.php',
        "<?php\nreturn ['path' => 'admin', 'call' => \$panel->path('admin')];\n",
    );
    file_put_contents(
        $base.'/database/migrations/2026_04_16_164559_add_is_admin_to_users_table.php',
        "<?php\n// migration\n",
    );
    file_put_contents($base.'/database/seeders/AdminUserSeeder.php', "<?php\n// seeder\n");
}

function makeAppBase(string $base): void
{
    mkdir($base.'/bootstrap', 0o755, true);

    file_put_contents($base.'/composer.json', json_encode(['require' => []], JSON_PRETTY_PRINT));
    file_put_contents(
        $base.'/bootstrap/providers.php',
        "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n",
    );
}

function tempPath(string $prefix): string
{
    $p = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
    mkdir($p, 0o755, true);

    return $p;
}

beforeEach(function (): void {
    $this->snapshot = tempPath('installer-snap');
    $this->app = tempPath('installer-app');
    makeSnapshot($this->snapshot);
    makeAppBase($this->app);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->snapshot));
    exec('rm -rf '.escapeshellarg($this->app));
});

it('plans file creation on a clean app', function (): void {
    $installer = new FilamentInstaller($this->snapshot, $this->app);
    $plan = $installer->plan();

    expect($plan['files_to_create'])->toContain('app/Filament/Resources/Users/UserResource.php');
    expect($plan['files_to_overwrite'])->toBe([]);
    expect($plan['provider_registration'])->toBe('will_register');
});

it('installs snapshot files into the consumer app', function (): void {
    $result = (new FilamentInstaller($this->snapshot, $this->app))->install();

    expect($result['created'])->toContain('app/Filament/Resources/Users/UserResource.php');
    expect(file_exists($this->app.'/app/Filament/Resources/Users/UserResource.php'))->toBeTrue();
});

it('registers the panel provider in bootstrap/providers.php', function (): void {
    (new FilamentInstaller($this->snapshot, $this->app))->install();

    $contents = (string) file_get_contents($this->app.'/bootstrap/providers.php');
    expect($contents)->toContain('App\\Providers\\Filament\\AdminPanelProvider::class');
});

it('is idempotent — running twice leaves the same state', function (): void {
    $installer = new FilamentInstaller($this->snapshot, $this->app);

    $first = $installer->install();
    $second = $installer->install();

    expect($first['created'])->not->toBe([]);
    expect($second['created'])->toBe([]);
    expect($second['unchanged'])->toBe($first['created']);
    expect($second['skipped_existing'])->toBe([]);
});

it('does not double-register the provider on a second run', function (): void {
    $installer = new FilamentInstaller($this->snapshot, $this->app);
    $installer->install();
    $installer->install();

    $contents = (string) file_get_contents($this->app.'/bootstrap/providers.php');
    expect(mb_substr_count($contents, 'App\\Providers\\Filament\\AdminPanelProvider::class'))->toBe(1);
});

it('skips existing files by default (no overwrite)', function (): void {
    // Pre-seed a differing file in the app.
    mkdir($this->app.'/app/Filament/Resources/Users', 0o755, true);
    file_put_contents(
        $this->app.'/app/Filament/Resources/Users/UserResource.php',
        "<?php\n// consumer modified\n",
    );

    $installer = new FilamentInstaller($this->snapshot, $this->app);
    $result = $installer->install();

    expect($result['skipped_existing'])->toContain('app/Filament/Resources/Users/UserResource.php');

    $contents = (string) file_get_contents($this->app.'/app/Filament/Resources/Users/UserResource.php');
    expect($contents)->toContain('consumer modified');
});

it('overwrites existing files with --force', function (): void {
    mkdir($this->app.'/app/Filament/Resources/Users', 0o755, true);
    file_put_contents(
        $this->app.'/app/Filament/Resources/Users/UserResource.php',
        "<?php\n// consumer modified\n",
    );

    $installer = new FilamentInstaller($this->snapshot, $this->app, force: true);
    $result = $installer->install();

    expect($result['overwritten'])->toContain('app/Filament/Resources/Users/UserResource.php');

    $contents = (string) file_get_contents($this->app.'/app/Filament/Resources/Users/UserResource.php');
    expect($contents)->toContain('UserResource');
    expect($contents)->not->toContain('consumer modified');
});

it('skips the seeder when installSeeder is false', function (): void {
    $installer = new FilamentInstaller($this->snapshot, $this->app, installSeeder: false);
    $result = $installer->install();

    expect($result['created'])->not->toContain('database/seeders/AdminUserSeeder.php');
    expect(file_exists($this->app.'/database/seeders/AdminUserSeeder.php'))->toBeFalse();
});

it('rewrites the panel path in AdminPanelProvider when customised', function (): void {
    $installer = new FilamentInstaller(
        $this->snapshot,
        $this->app,
        panelPath: 'backend',
    );
    $installer->install();

    $contents = (string) file_get_contents($this->app.'/app/Providers/Filament/AdminPanelProvider.php');
    expect($contents)->toContain("->path('backend')");
    expect($contents)->not->toContain("->path('admin')");
});

it('leaves the default panel path when no override is provided', function (): void {
    (new FilamentInstaller($this->snapshot, $this->app))->install();

    $contents = (string) file_get_contents($this->app.'/app/Providers/Filament/AdminPanelProvider.php');
    expect($contents)->toContain("->path('admin')");
});

it('refuses to install() in dry-run mode', function (): void {
    $installer = new FilamentInstaller($this->snapshot, $this->app, dryRun: true);

    expect(fn () => $installer->install())
        ->toThrow(RuntimeException::class, 'dry-run');
});

it('plans correctly in dry-run mode', function (): void {
    $plan = (new FilamentInstaller($this->snapshot, $this->app, dryRun: true))->plan();

    expect($plan['dry_run'])->toBeTrue();
    expect($plan['files_to_create'])->not->toBe([]);
    expect(file_exists($this->app.'/app/Filament/Resources/Users/UserResource.php'))->toBeFalse();
});

it('throws when the snapshot directory does not exist', function (): void {
    $installer = new FilamentInstaller('/nonexistent/snap', $this->app);

    expect(fn () => $installer->plan())
        ->toThrow(RuntimeException::class, 'snapshot not found');
});

it('detects when filament is already installed via composer.json', function (): void {
    file_put_contents(
        $this->app.'/composer.json',
        json_encode(['require' => ['filament/filament' => '^5.0']], JSON_PRETTY_PRINT),
    );

    $installer = new FilamentInstaller($this->snapshot, $this->app);

    expect($installer->isFilamentInstalled())->toBeTrue();
});

it('detects when panel provider is already scaffolded', function (): void {
    mkdir($this->app.'/app/Providers/Filament', 0o755, true);
    file_put_contents(
        $this->app.'/app/Providers/Filament/AdminPanelProvider.php',
        "<?php // existing\n",
    );

    $installer = new FilamentInstaller($this->snapshot, $this->app);

    expect($installer->isPanelProviderScaffolded())->toBeTrue();
});
