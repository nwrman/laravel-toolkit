<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Installer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Plans and executes the installation of the canonical Filament shape
 * into a consumer Laravel application.
 *
 * Idempotent: running twice is a safe no-op for files that already match.
 * Supports dry-run (planning without side effects) and force (overwrite).
 *
 * The installer is intentionally framework-agnostic — it does not touch the
 * Laravel container, the composer binary, or Artisan. Callers (typically
 * InstallFilamentCommand) are responsible for:
 *   - Running `composer require filament/filament`.
 *   - Running `php artisan filament:install --panels`.
 *   - Running `php artisan migrate`.
 *
 * This class only handles filesystem operations: copying snapshot files,
 * patching the panel path in AdminPanelProvider, and registering the panel
 * provider in bootstrap/providers.php.
 */
final class FilamentInstaller
{
    public const string DEFAULT_PANEL_PATH = 'admin';

    public const string PANEL_PROVIDER_RELATIVE_PATH = 'app/Providers/Filament/AdminPanelProvider.php';

    public const string PANEL_PROVIDER_CLASS = 'App\\Providers\\Filament\\AdminPanelProvider';

    public function __construct(
        private readonly string $snapshotPath,
        private readonly string $appBasePath,
        private readonly bool $force = false,
        private readonly bool $dryRun = false,
        private readonly string $panelPath = self::DEFAULT_PANEL_PATH,
        private readonly bool $installSeeder = true,
    ) {
        //
    }

    /**
     * @return array{
     *     files_to_create: list<string>,
     *     files_to_overwrite: list<string>,
     *     files_unchanged: list<string>,
     *     provider_registration: 'already_registered'|'will_register',
     *     dry_run: bool
     * }
     */
    public function plan(): array
    {
        $this->assertSnapshotExists();

        $toCreate = [];
        $toOverwrite = [];
        $unchanged = [];

        foreach ($this->snapshotFiles() as $relative) {
            if ($this->shouldSkipSeeder($relative)) {
                continue;
            }

            $destination = $this->appBasePath.'/'.$relative;
            $plannedContents = $this->transformContentsForDestination($relative);

            if (! file_exists($destination)) {
                $toCreate[] = $relative;

                continue;
            }

            $existing = (string) file_get_contents($destination);

            if ($existing === $plannedContents) {
                $unchanged[] = $relative;

                continue;
            }

            $toOverwrite[] = $relative;
        }

        return [
            'files_to_create' => $toCreate,
            'files_to_overwrite' => $toOverwrite,
            'files_unchanged' => $unchanged,
            'provider_registration' => $this->isProviderRegistered() ? 'already_registered' : 'will_register',
            'dry_run' => $this->dryRun,
        ];
    }

    /**
     * @return array{
     *     created: list<string>,
     *     overwritten: list<string>,
     *     skipped_existing: list<string>,
     *     unchanged: list<string>,
     *     provider_registered: bool
     * }
     */
    public function install(): array
    {
        $this->assertSnapshotExists();

        if ($this->dryRun) {
            throw new RuntimeException('Cannot install in dry-run mode. Call plan() instead.');
        }

        $created = [];
        $overwritten = [];
        $skippedExisting = [];
        $unchanged = [];

        foreach ($this->snapshotFiles() as $relative) {
            if ($this->shouldSkipSeeder($relative)) {
                continue;
            }

            $destination = $this->appBasePath.'/'.$relative;
            $plannedContents = $this->transformContentsForDestination($relative);

            if (! file_exists($destination)) {
                $this->writeFile($destination, $plannedContents);
                $created[] = $relative;

                continue;
            }

            $existing = (string) file_get_contents($destination);

            if ($existing === $plannedContents) {
                $unchanged[] = $relative;

                continue;
            }

            if (! $this->force) {
                $skippedExisting[] = $relative;

                continue;
            }

            $this->writeFile($destination, $plannedContents);
            $overwritten[] = $relative;
        }

        $providerRegistered = $this->registerProvider();

        sort($created);
        sort($overwritten);
        sort($skippedExisting);
        sort($unchanged);

        return [
            'created' => $created,
            'overwritten' => $overwritten,
            'skipped_existing' => $skippedExisting,
            'unchanged' => $unchanged,
            'provider_registered' => $providerRegistered,
        ];
    }

    public function isFilamentInstalled(): bool
    {
        $composerJson = $this->appBasePath.'/composer.json';

        if (! file_exists($composerJson)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);

        if (! is_array($data)) {
            return false;
        }

        $require = is_array($data['require'] ?? null) ? $data['require'] : [];

        return array_key_exists('filament/filament', $require);
    }

    public function isPanelProviderScaffolded(): bool
    {
        return file_exists($this->appBasePath.'/'.self::PANEL_PROVIDER_RELATIVE_PATH);
    }

    /**
     * @return list<string> Relative paths inside the snapshot, sorted.
     */
    private function snapshotFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->snapshotPath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if (! $entry->isFile()) {
                continue;
            }

            $files[] = mb_ltrim(mb_substr($entry->getPathname(), mb_strlen($this->snapshotPath)), '/');
        }

        sort($files);

        return $files;
    }

    private function shouldSkipSeeder(string $relativePath): bool
    {
        if ($this->installSeeder) {
            return false;
        }

        return str_starts_with($relativePath, 'database/seeders/');
    }

    private function transformContentsForDestination(string $relativePath): string
    {
        $source = $this->snapshotPath.'/'.$relativePath;
        $contents = (string) file_get_contents($source);

        if ($relativePath === self::PANEL_PROVIDER_RELATIVE_PATH && $this->panelPath !== self::DEFAULT_PANEL_PATH) {
            $contents = str_replace(
                "->path('".self::DEFAULT_PANEL_PATH."')",
                "->path('".$this->panelPath."')",
                $contents,
            );
        }

        return $contents;
    }

    private function writeFile(string $destination, string $contents): void
    {
        $dir = dirname($destination);

        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($destination, $contents);
    }

    private function isProviderRegistered(): bool
    {
        $path = $this->appBasePath.'/bootstrap/providers.php';

        if (! file_exists($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        return str_contains($contents, self::PANEL_PROVIDER_CLASS.'::class');
    }

    private function registerProvider(): bool
    {
        $path = $this->appBasePath.'/bootstrap/providers.php';

        if (! file_exists($path)) {
            return false;
        }

        if ($this->isProviderRegistered()) {
            return true;
        }

        $contents = (string) file_get_contents($path);

        // Try to insert before the closing `];`. This is the shape Laravel ships.
        $entry = '    '.self::PANEL_PROVIDER_CLASS."::class,\n";

        $pattern = '/(\n)(\];\s*)$/';

        if (preg_match($pattern, $contents) !== 1) {
            // Shape is unexpected — refuse to modify silently. Caller can decide.
            return false;
        }

        $updated = (string) preg_replace($pattern, "\n".$entry.'$2', $contents, 1);

        file_put_contents($path, $updated);

        return true;
    }

    private function assertSnapshotExists(): void
    {
        if (! is_dir($this->snapshotPath)) {
            throw new RuntimeException(sprintf(
                'Filament snapshot not found at: %s. Run `composer snapshot:filament` in the toolkit first.',
                $this->snapshotPath,
            ));
        }

        if ($this->snapshotFiles() === []) {
            throw new RuntimeException(sprintf(
                'Filament snapshot directory is empty: %s',
                $this->snapshotPath,
            ));
        }
    }
}
