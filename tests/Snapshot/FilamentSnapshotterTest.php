<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Snapshot\FilamentSnapshotter;

function makeReferenceApp(string $base): void
{
    mkdir($base.'/app/Filament/Resources/Users/Pages', 0o755, true);
    mkdir($base.'/app/Providers/Filament', 0o755, true);
    mkdir($base.'/database/migrations', 0o755, true);
    mkdir($base.'/database/seeders', 0o755, true);

    // Laravel-app markers
    file_put_contents($base.'/artisan', "#!/usr/bin/env php\n");
    file_put_contents($base.'/composer.json', "{}\n");

    // Filament files
    file_put_contents($base.'/app/Filament/Resources/Users/UserResource.php', "<?php // UserResource\n");
    file_put_contents($base.'/app/Filament/Resources/Users/Pages/ListUsers.php', "<?php // ListUsers\n");
    file_put_contents($base.'/app/Providers/Filament/AdminPanelProvider.php', "<?php // AdminPanelProvider\n");
    file_put_contents($base.'/database/migrations/2026_04_16_164559_add_is_admin_to_users_table.php', "<?php // migration\n");
    file_put_contents($base.'/database/seeders/AdminUserSeeder.php', "<?php // AdminUserSeeder\n");

    // Decoys that must NOT be snapshotted
    file_put_contents($base.'/app/Filament/.DS_Store', 'garbage');
    file_put_contents($base.'/database/migrations/0001_01_01_000000_create_users_table.php', "<?php // unrelated migration\n");
}

function tempDir(string $prefix): string
{
    $base = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
    mkdir($base, 0o755, true);

    return $base;
}

beforeEach(function (): void {
    $this->referenceApp = tempDir('snapshotter-ref');
    $this->snapshotDir = tempDir('snapshotter-snap');
    makeReferenceApp($this->referenceApp);
});

afterEach(function (): void {
    // Cleanup best-effort
    exec('rm -rf '.escapeshellarg($this->referenceApp));
    exec('rm -rf '.escapeshellarg($this->snapshotDir));
});

it('copies the expected set of Filament files', function (): void {
    $result = (new FilamentSnapshotter($this->referenceApp, $this->snapshotDir))->run();

    expect($result['copied'])->toBe([
        'app/Filament/Resources/Users/Pages/ListUsers.php',
        'app/Filament/Resources/Users/UserResource.php',
        'app/Providers/Filament/AdminPanelProvider.php',
        'database/migrations/2026_04_16_164559_add_is_admin_to_users_table.php',
        'database/seeders/AdminUserSeeder.php',
    ]);
});

it('skips hidden files like .DS_Store', function (): void {
    (new FilamentSnapshotter($this->referenceApp, $this->snapshotDir))->run();

    expect(file_exists($this->snapshotDir.'/app/Filament/.DS_Store'))->toBeFalse();
});

it('does not include unrelated migrations', function (): void {
    (new FilamentSnapshotter($this->referenceApp, $this->snapshotDir))->run();

    expect(file_exists($this->snapshotDir.'/database/migrations/0001_01_01_000000_create_users_table.php'))->toBeFalse();
});

it('is deterministic across runs', function (): void {
    $snapshotter = new FilamentSnapshotter($this->referenceApp, $this->snapshotDir);

    $snapshotter->run();
    $firstHashes = hashSnapshot($this->snapshotDir);

    $snapshotter->run();
    $secondHashes = hashSnapshot($this->snapshotDir);

    expect($secondHashes)->toBe($firstHashes);
});

it('normalizes CRLF line endings to LF', function (): void {
    file_put_contents(
        $this->referenceApp.'/app/Filament/Resources/Users/UserResource.php',
        "<?php\r\n// windows line endings\r\n",
    );

    (new FilamentSnapshotter($this->referenceApp, $this->snapshotDir))->run();

    $contents = (string) file_get_contents($this->snapshotDir.'/app/Filament/Resources/Users/UserResource.php');
    expect($contents)->not->toContain("\r");
    expect($contents)->toBe("<?php\n// windows line endings\n");
});

it('throws when reference app path does not exist', function (): void {
    expect(fn () => (new FilamentSnapshotter('/nonexistent/path/abc', $this->snapshotDir))->run())
        ->toThrow(RuntimeException::class, 'does not exist');
});

it('throws when reference app is not a Laravel app', function (): void {
    $bogus = tempDir('snapshotter-bogus');

    try {
        expect(fn () => (new FilamentSnapshotter($bogus, $this->snapshotDir))->run())
            ->toThrow(RuntimeException::class, 'does not look like a Laravel app');
    } finally {
        exec('rm -rf '.escapeshellarg($bogus));
    }
});

it('resets the snapshot directory so removed files do not linger', function (): void {
    $snapshotter = new FilamentSnapshotter($this->referenceApp, $this->snapshotDir);
    $snapshotter->run();

    // Simulate a file being removed from the reference app.
    unlink($this->referenceApp.'/app/Filament/Resources/Users/Pages/ListUsers.php');

    $snapshotter->run();

    expect(file_exists($this->snapshotDir.'/app/Filament/Resources/Users/Pages/ListUsers.php'))->toBeFalse();
});

it('reports missing included paths in skipped_missing', function (): void {
    unlink($this->referenceApp.'/app/Providers/Filament/AdminPanelProvider.php');
    rmdir($this->referenceApp.'/app/Providers/Filament');
    rmdir($this->referenceApp.'/app/Providers');

    $result = (new FilamentSnapshotter($this->referenceApp, $this->snapshotDir))->run();

    expect($result['skipped_missing'])->toContain('app/Providers/Filament');
});

/**
 * @return array<string, string>
 */
function hashSnapshot(string $dir): array
{
    $hashes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile()) {
            $relative = mb_substr($entry->getPathname(), mb_strlen($dir) + 1);
            $hashes[$relative] = (string) hash_file('sha256', $entry->getPathname());
        }
    }

    ksort($hashes);

    return $hashes;
}
