<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Nwrman\LaravelToolkit\Installer\FilamentInstaller;
use Override;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class InstallFilamentCommand extends Command
{
    private const string FILAMENT_PACKAGE = 'filament/filament';

    #[Override]
    protected $signature = 'starter:install-filament
        {--force : Overwrite existing files without prompting}
        {--dry-run : Show what would happen without making changes}
        {--panel-path= : Path the admin panel is served from (default: admin)}
        {--no-seeder : Skip installing the AdminUserSeeder}';

    #[Override]
    protected $description = 'Install the canonical Filament admin panel shape into this app';

    public function handle(): int
    {
        $snapshotPath = realpath(__DIR__.'/../../resources/filament-snapshot');

        if ($snapshotPath === false) {
            $this->error('Filament snapshot not found. The toolkit may be out of date.');

            return self::FAILURE;
        }

        $appBasePath = base_path();
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $panelPath = $this->resolvePanelPath();
        $installSeeder = $this->resolveInstallSeeder();

        $installer = new FilamentInstaller(
            snapshotPath: $snapshotPath,
            appBasePath: $appBasePath,
            force: $force,
            dryRun: $dryRun,
            panelPath: $panelPath,
            installSeeder: $installSeeder,
        );

        // Show the plan first so the user knows what's coming.
        try {
            $plan = $installer->plan();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderPlan($plan, $installer, $panelPath);

        if ($dryRun) {
            $this->info('Dry run complete. No changes were made.');

            return self::SUCCESS;
        }

        // Install Filament via composer if missing.
        if (! $installer->isFilamentInstalled() && ! $this->requireFilament()) {
            return self::FAILURE;
        }

        // Scaffold the panel provider if Filament's own installer hasn't run.
        if (! $installer->isPanelProviderScaffolded() && ! $this->runFilamentInstall()) {
            return self::FAILURE;
        }

        // Copy snapshot files + register provider.
        try {
            $result = $installer->install();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderInstallResult($result);

        if ($this->shouldRunMigrations($result)) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('✓ Filament admin panel installed.');
        $this->line('  Next: run <comment>php artisan db:seed --class=AdminUserSeeder</comment> to create the first admin user.');
        $this->line('  Login at: <comment>/'.$panelPath.'/login</comment>');

        return self::SUCCESS;
    }

    private function resolvePanelPath(): string
    {
        $option = $this->option('panel-path');

        if (is_string($option) && $option !== '') {
            return mb_ltrim($option, '/');
        }

        if ($this->option('force') || ! $this->input->isInteractive()) {
            return FilamentInstaller::DEFAULT_PANEL_PATH;
        }

        $answer = text(
            label: 'Panel path',
            placeholder: FilamentInstaller::DEFAULT_PANEL_PATH,
            default: FilamentInstaller::DEFAULT_PANEL_PATH,
            hint: 'The URL segment the admin panel is served from.',
        );

        return mb_ltrim($answer, '/') ?: FilamentInstaller::DEFAULT_PANEL_PATH;
    }

    private function resolveInstallSeeder(): bool
    {
        if ($this->option('no-seeder')) {
            return false;
        }

        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return confirm(
            label: 'Install AdminUserSeeder?',
            default: true,
            hint: 'Seeds an admin@example.com user with is_admin=true.',
        );
    }

    /**
     * @param array{
     *     files_to_create: list<string>,
     *     files_to_overwrite: list<string>,
     *     files_unchanged: list<string>,
     *     provider_registration: string,
     *     dry_run: bool
     * } $plan
     */
    private function renderPlan(array $plan, FilamentInstaller $installer, string $panelPath): void
    {
        $this->info(($plan['dry_run'] ? '[dry-run] ' : '').'Installation plan');
        $this->line('  Panel path: <comment>/'.$panelPath.'</comment>');
        $this->line('  Filament package installed: '.($installer->isFilamentInstalled() ? '<info>yes</info>' : '<comment>no — will run composer require</comment>'));
        $this->line('  Panel provider scaffolded: '.($installer->isPanelProviderScaffolded() ? '<info>yes</info>' : '<comment>no — will run filament:install</comment>'));
        $this->line('  Provider registration: '.$plan['provider_registration']);

        if ($plan['files_to_create'] !== []) {
            $this->line(sprintf('  Files to create (<info>%d</info>):', count($plan['files_to_create'])));

            foreach ($plan['files_to_create'] as $path) {
                $this->line('    + '.$path);
            }
        }

        if ($plan['files_to_overwrite'] !== []) {
            $label = $this->option('force') ? 'will overwrite' : 'differ (use --force to overwrite)';
            $this->line(sprintf('  Files that %s (<comment>%d</comment>):', $label, count($plan['files_to_overwrite'])));

            foreach ($plan['files_to_overwrite'] as $path) {
                $this->line('    ~ '.$path);
            }
        }

        if ($plan['files_unchanged'] !== []) {
            $this->line(sprintf('  Files unchanged: <info>%d</info>', count($plan['files_unchanged'])));
        }

        $this->newLine();
    }

    /**
     * @param array{
     *     created: list<string>,
     *     overwritten: list<string>,
     *     skipped_existing: list<string>,
     *     unchanged: list<string>,
     *     provider_registered: bool
     * } $result
     */
    private function renderInstallResult(array $result): void
    {
        $this->newLine();

        if ($result['created'] !== []) {
            $this->info(sprintf('Created %d file(s).', count($result['created'])));
        }

        if ($result['overwritten'] !== []) {
            $this->info(sprintf('Overwrote %d file(s).', count($result['overwritten'])));
        }

        if ($result['skipped_existing'] !== []) {
            $this->warn(sprintf('Skipped %d existing file(s) (use --force to overwrite):', count($result['skipped_existing'])));

            foreach ($result['skipped_existing'] as $path) {
                $this->line('    ~ '.$path);
            }
        }

        if ($result['unchanged'] !== []) {
            $this->line(sprintf('  Unchanged: %d file(s).', count($result['unchanged'])));
        }

        if ($result['provider_registered']) {
            $this->line('  Panel provider registered in bootstrap/providers.php.');
        } else {
            $this->warn('  Could not register panel provider in bootstrap/providers.php — add manually:');
            $this->line('    '.FilamentInstaller::PANEL_PROVIDER_CLASS.'::class,');
        }
    }

    /**
     * @param  array{created: list<string>, overwritten: list<string>, skipped_existing: list<string>, unchanged: list<string>, provider_registered: bool}  $result
     */
    private function shouldRunMigrations(array $result): bool
    {
        foreach ([...$result['created'], ...$result['overwritten']] as $path) {
            if (str_starts_with($path, 'database/migrations/')) {
                return true;
            }
        }

        return false;
    }

    private function requireFilament(): bool
    {
        $this->line('Running: <comment>composer require '.self::FILAMENT_PACKAGE.'</comment>');

        $result = Process::path(base_path())
            ->forever()
            ->run(['composer', 'require', self::FILAMENT_PACKAGE, '--no-interaction']);

        if ($result->failed()) {
            $this->error('composer require failed.');
            $this->line($result->errorOutput());

            return false;
        }

        return true;
    }

    private function runFilamentInstall(): bool
    {
        $this->line('Running: <comment>php artisan filament:install --panels</comment>');

        return $this->call('filament:install', ['--panels' => true, '--no-interaction' => true]) === self::SUCCESS;
    }
}
