# Laravel Toolkit

Reusable developer infrastructure for Laravel: parallel CI gates, test reporting with smart retry, deploy notifications, and project scaffolding.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nwrman/laravel-toolkit.svg?style=flat-square)](https://packagist.org/packages/nwrman/laravel-toolkit)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/nwrman/laravel-toolkit/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/nwrman/laravel-toolkit/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/nwrman/laravel-toolkit.svg?style=flat-square)](https://packagist.org/packages/nwrman/laravel-toolkit)

## Requirements

- PHP 8.5+
- Laravel 13+

## Installation

This package is **dev-only**. Install it with `--dev`:

```bash
composer require --dev nwrman/laravel-toolkit
```

The package auto-discovers via Laravel's package discovery. Run the interactive installer to scaffold your project:

```bash
php artisan toolkit:install
```

The installer walks you through:

1. Moving `nwrman/laravel-toolkit` from `require` to `require-dev` in your `composer.json` (if needed)
2. Publishing the config file (`config/toolkit.php`)
3. Merging recommended composer scripts into your `composer.json`
4. Publishing AI skills & guidelines (`.ai/`)
5. Publishing a GitHub Actions CI workflow (`.github/workflows/tests.yml`)
6. Publishing static analysis configs (`pint.json`, `phpstan.neon`)
7. Publishing the deploy notification command into `app/Console/Commands/` (plus its Pest test)
8. Publishing deployment scripts (`scripts/`)

Each step is optional and skips files that already exist.

### Production safety

The package is a pure developer-tool: it registers no routes, middleware, bindings, listeners, migrations, or scheduled tasks. The service provider is fully inert outside `runningInConsole()` context, so it adds zero overhead to HTTP requests and queue workers even if it were accidentally installed via `require`.

Production deploys that run `composer install --no-dev` do not need this package. The one class the deploy pipeline calls — the Telegram deploy-notification command — is published as a stub into your app's `app/Console/Commands/DeployNotifyTelegramCommand.php` (along with a Pest test in `tests/Feature/Console/Commands/`) so it lives in your application code, independent of whether this package is installed on the deploy host. Re-publish on upgrades with:

```bash
php artisan vendor:publish --tag=toolkit-commands --force
```

## Commands

| Command | Description |
|---------|-------------|
| `toolkit:preflight` | Run all CI quality gates in parallel |
| `toolkit:report` | Run test suites and generate failure reports |
| `toolkit:retry` | Re-run only previously failed tests |
| `toolkit:install` | Interactive project setup wizard |
| `starter:install-filament` | Install the canonical Filament admin panel into a consumer app |

A `deploy:notify-telegram` command is also available for deploy pipelines, but it is published into your application (not registered by this package) — see [Published deploy notification command](#published-deploy-notification-command).

### `toolkit:preflight`

Runs all configured quality gates in parallel with a live spinner UI, summary table, and macOS desktop notifications.

```bash
php artisan toolkit:preflight
```

```
Running 4 gates in parallel...

  ✓ Pest Coverage        (18.4s)
  ✓ Frontend Coverage    (8.1s)
  ✓ Lint                 (6.7s)
  ✓ Types                (9.3s)

+--------------------+--------+-------+
| Gate               | Status | Time  |
+--------------------+--------+-------+
| Pest Coverage      | ✓ pass | 18.4s |
| Frontend Coverage  | ✓ pass | 8.1s  |
| Lint               | ✓ pass | 6.7s  |
| Types              | ✓ pass | 9.3s  |
+--------------------+--------+-------+
Wall clock: 18.4s (saved 24.1s vs sequential)

✓ All gates passed!
```

**Options:**

| Option | Description |
|--------|-------------|
| `--gate=<name>` | Run specific gate(s). Repeatable: `--gate=lint --gate=types` |
| `--retry` | Re-run only the gates that failed in the previous run |
| `--force-build` | Force a frontend rebuild before running gates |
| `--no-notify` | Suppress macOS desktop notification |

**Retry workflow:** When gates fail, the command saves state to a JSON file. On the next run with `--retry`, only the failed gates are re-executed:

```bash
# Initial run — lint and types fail
php artisan toolkit:preflight

# Fix the issues, then retry only the failed gates
php artisan toolkit:preflight --retry
```

**Agent detection:** When running inside a CI agent (Claude Code, Cursor, OpenCode, etc.), the command automatically suppresses desktop notifications and uses a plain-text output format instead of ANSI spinners. Detection is powered by [`shipfastlabs/agent-detector`](https://github.com/shipfastlabs/agent-detector).

**Production safety:** `PreflightCommand` uses Laravel's `Prohibitable` trait and is automatically prohibited in production environments by the service provider.

### `toolkit:report`

Runs test suites (Pest + Vitest), parses JUnit XML results, and generates a Markdown failure report with individual test details and quick re-run commands.

```bash
php artisan toolkit:report
```

**Options:**

| Option | Description |
|--------|-------------|
| `--suite=<list>` | Comma-separated suites to run: `unit,feature,browser,frontend` |
| `--force-build` | Force a frontend rebuild before browser tests |
| `--no-notify` | Suppress macOS desktop notification |

When run interactively without `--suite`, it prompts you to select which suites to run using Laravel Prompts.

**On failure**, two files are generated:

- **Markdown report** (`storage/logs/test-failures.md`) — Human-readable with test names, file locations, error messages, and quick re-run commands
- **JSON state** (`storage/logs/test-failures.json`) — Machine-readable, used by `toolkit:retry`

**On success**, both files are cleaned up automatically.

### `toolkit:retry`

Re-runs only the tests that failed in the last `toolkit:report` run. Reads the JSON state file to determine which backend tests to filter and whether to re-run the frontend suite.

```bash
php artisan toolkit:retry
```

On success, the report and state files are cleaned up. On continued failure, they're preserved so you can iterate.

**Typical workflow:**

```bash
# Run all tests with reporting
composer test:report

# Fix failures, then re-run only what failed
composer test:retry

# Iterate until clean, then run full preflight
composer preflight
```

### Published deploy notification command

`deploy:notify-telegram` sends deployment status notifications to a Telegram chat via the Bot API. Unlike the other commands in this table, it is **not** registered by the package. It is published as a stub into your application so it remains available in production after `composer install --no-dev`.

Publish it with:

```bash
php artisan vendor:publish --tag=toolkit-commands --force
```

This writes two files:

- `app/Console/Commands/DeployNotifyTelegramCommand.php` — the command itself, in your `App\Console\Commands` namespace. Laravel auto-discovers it (Laravel 11+).
- `tests/Feature/Console/Commands/DeployNotifyTelegramCommandTest.php` — a Pest feature test covering every branch, so publishing does not regress your coverage.

Use it like any other artisan command:

```bash
php artisan deploy:notify-telegram started
php artisan deploy:notify-telegram succeeded
php artisan deploy:notify-telegram failed --reason="Migration failed" --stage=build
```

**Arguments & options:**

| Argument/Option | Description |
|-----------------|-------------|
| `status` | Required. One of: `started`, `succeeded`, `failed` |
| `--stage=<stage>` | `build` or `deploy` (default: `deploy`) |
| `--reason=<text>` | Failure reason (only used when status is `failed`) |

**Required config** in `config/services.php`:

```php
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_CHAT_ID'),
    'thread_id' => env('TELEGRAM_THREAD_ID'), // optional, for topic-based groups
],
```

If credentials are missing, the command exits gracefully with a warning instead of failing. The published `scripts/cloud-build.sh` and `scripts/cloud-deploy.sh` invoke this command during deploys.

### `toolkit:install`

Interactive setup wizard that publishes stubs and merges composer scripts. Each step asks for confirmation and respects `--force` to overwrite existing files.

```bash
php artisan toolkit:install
php artisan toolkit:install --force  # Overwrite existing files
```

### `starter:install-filament`

Installs the canonical Filament admin panel shape into the current Laravel application. Designed for consumers of [`nwrman/laravel-starter-kit`](https://github.com/nwrman/laravel-starter-kit) who want an admin panel, either on day one or weeks later.

```bash
php artisan starter:install-filament
php artisan starter:install-filament --dry-run             # Preview changes without writing anything
php artisan starter:install-filament --force               # Overwrite existing files
php artisan starter:install-filament --panel-path=backend  # Serve the panel from /backend
php artisan starter:install-filament --no-seeder           # Skip installing AdminUserSeeder
```

What it does:

1. Runs `composer require filament/filament` if missing.
2. Runs `php artisan filament:install --panels` if not yet scaffolded.
3. Copies the snapshotted Filament shape from `resources/filament-snapshot/` into your `app/` directory (UserResource, AdminPanelProvider, migrations, seeder).
4. Registers the panel provider in `bootstrap/providers.php`.
5. Runs migrations if new ones were added.

The command is **idempotent**: running it twice is a safe no-op. Files that already match the canonical shape are left alone; files that differ are skipped unless `--force` is used.

The shape itself (what gets installed) is maintained in a separate reference app — see [Filament reference app](#filament-reference-app) below.

## Filament Reference App

The Filament admin shape that `starter:install-filament` ships is developed as a **real, running Laravel application**, not a workbench or a collection of stubs. This keeps the dev loop ergonomic — you work in a live app, click around in a browser, and iterate like a consumer would.

- **Reference app repo:** [`nwrman/nwrman-filament-reference`](https://github.com/nwrman/nwrman-filament-reference) (created via `composer create-project nwrman/laravel-starter-kit` + `composer require filament/filament` + `filament:install`)
- **Snapshot location in this package:** `resources/filament-snapshot/`
- **Snapshot script:** `bin/snapshot-filament.php`

### Updating the snapshot

When you want to evolve the canonical Filament shape:

1. Iterate in the reference app (`~/Herd/nwrman-filament-reference`). Real app, real routes, real admin panel.
2. Commit and tag the reference app.
3. Run the snapshot:

   ```bash
   # Locally (in this toolkit repo)
   composer snapshot:filament -- --from=../nwrman-filament-reference

   # Or via CI — trigger the "snapshot-filament" workflow from GitHub Actions
   #   with input `reference_ref=<tag>`. It opens a PR with the diff.
   ```
4. Review the diff in `resources/filament-snapshot/`, merge, and tag a new toolkit release.

Consumers pick up the new shape on their next `composer update`. Their already-installed `app/Filament/` is unaffected — they own those files.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=toolkit-config
```

### Smart Merge

You only need to override what you want to change. The service provider deep-merges your published config with the package defaults using `array_replace_recursive`. This means you can override a single gate's command without redeclaring all gates:

```php
// config/toolkit.php — only override what you need
return [
    'gates' => [
        'lint' => [
            'command' => 'vendor/bin/pint --test',
        ],
    ],
];
```

All other gates (`pest-coverage`, `frontend-coverage`, `types`) keep their default configuration.

### Removing a Gate

Set a gate to `null` to remove it entirely:

```php
return [
    'gates' => [
        'frontend-coverage' => null, // removed — won't run in preflight
    ],
];
```

### Config Reference

#### `gates`

Each gate runs a shell command as part of preflight. Gates run in parallel.

| Key | Type | Description |
|-----|------|-------------|
| `label` | `string` | Display name shown in the summary table |
| `command` | `string` | Shell command to execute |
| `env` | `array` | Optional environment variables (e.g., `['XDEBUG_MODE' => 'coverage']`) |
| `requires_build` | `bool` | If `true`, ensures frontend is built before running this gate |

**Default gates:**

| Gate | Label | Default Command |
|------|-------|----------------|
| `pest-coverage` | Pest Coverage | `vendor/bin/pest --type-coverage --min=100 && vendor/bin/pest --parallel --coverage --exactly=100.0` |
| `frontend-coverage` | Frontend Coverage | `bun run test:coverage` |
| `lint` | Lint | `vendor/bin/pint --parallel --test && vendor/bin/rector --dry-run && bun run test:lint` |
| `types` | Types | `vendor/bin/phpstan && bun run test:types` |

#### `notifications`

```php
'notifications' => [
    'desktop' => [
        'enabled' => true, // Set to false to disable macOS notifications globally
    ],
],
```

Desktop notifications use `terminal-notifier` when available, falling back to `afplay` for sound-only alerts.

#### `build`

Controls the frontend build check that runs before gates marked with `requires_build`.

```php
'build' => [
    'command' => 'vp build',           // Build command
    'timeout' => 120,                   // Timeout in seconds
    'stamp_file' => 'public/build/.buildstamp',    // Timestamp file for staleness check
    'manifest_file' => 'public/build/manifest.json', // Vite manifest
    'watch_paths' => 'resources/ vite.config.* tsconfig.* tailwind.config.* postcss.config.* package.json',
],
```

The build check uses `find ... -newer <stamp_file>` to detect source changes. If no changes are detected, the build is skipped.

#### `paths`

File paths for state and reports, relative to the project root.

```php
'paths' => [
    'preflight_state' => 'storage/logs/preflight-result.json',
    'report_dir' => 'storage/logs',
    'xml_file' => 'storage/logs/test-results.xml',
    'report_file' => 'storage/logs/test-failures.md',
    'json_file' => 'storage/logs/test-failures.json',
],
```

#### `suites`

Named test suites for `toolkit:report`. Keys are identifiers; values are display labels.

```php
'suites' => [
    'unit' => 'Unit',
    'feature' => 'Feature',
    'browser' => 'Browser',
    'frontend' => 'Frontend (Vitest)',
],
```

#### `frontend_test_command`

Command used to run frontend tests in `toolkit:report` and `toolkit:retry`.

```php
'frontend_test_command' => 'bun run test:ui',
```

## Publishable Stubs

| Tag | Contents | Destination |
|-----|----------|-------------|
| `toolkit-config` | Configuration file | `config/toolkit.php` |
| `toolkit-static-analysis` | Pint + PHPStan configs | `pint.json`, `phpstan.neon` |
| `toolkit-ai` | AI coding skills & guidelines | `.ai/skills/`, `.ai/guidelines/` |
| `toolkit-github` | GitHub Actions CI workflow | `.github/workflows/tests.yml` |
| `toolkit-commands` | Deploy-notify command + Pest test | `app/Console/Commands/DeployNotifyTelegramCommand.php`, `tests/Feature/Console/Commands/DeployNotifyTelegramCommandTest.php` |
| `toolkit-scripts` | Deployment & lint scripts | `scripts/cloud-build.sh`, `scripts/cloud-deploy.sh`, `resources/js/scripts/lint-dirty.ts` |

Publish individual tags:

```bash
php artisan vendor:publish --tag=toolkit-ai
php artisan vendor:publish --tag=toolkit-github
php artisan vendor:publish --tag=toolkit-static-analysis
php artisan vendor:publish --tag=toolkit-commands
php artisan vendor:publish --tag=toolkit-scripts
```

### AI Skills

The `toolkit-ai` tag publishes AI coding assistant skills and guidelines to `.ai/`:

| Skill | Description |
|-------|-------------|
| `run-preflight` | Run preflight before pushing code |
| `create-feature-branch` | Start a new feature branch |
| `finish-feature-branch` | Create a PR when implementation is complete |
| `land-feature-branch` | Merge an approved PR into main |

Guidelines cover: action pattern, domain folders, test enforcement, general conventions, Pest testing, React best practices, and Vitest.

## Recommended Composer Scripts

The `toolkit:install` command can merge these scripts into your `composer.json`:

| Script | Command |
|--------|---------|
| `composer dev` | Concurrent queue worker + Pail + Vite dev server |
| `composer lint` | Rector + Pint + frontend linting |
| `composer lint:dirty` | Lint only files changed since last commit |
| `composer test` | Run all test suites (unit, feature, browser, frontend) |
| `composer test:unit` | Pest unit tests (parallel) |
| `composer test:feature` | Pest feature tests (parallel) |
| `composer test:browser` | Pest browser tests (parallel) |
| `composer test:lint` | Pint + Rector + frontend lint (verification mode) |
| `composer test:types` | PHPStan + frontend type checking |
| `composer test:ci` | Full CI pipeline (coverage + lint + types) |
| `composer test:report` | `toolkit:report` with timeout disabled |
| `composer test:retry` | `toolkit:retry` |
| `composer preflight` | `toolkit:preflight` with timeout disabled |
| `composer optimize` | Cache config, events, routes, and views |
| `composer cloud:build` | Run cloud build script |
| `composer cloud:deploy` | Run cloud deploy script |

Scripts are added only if the key doesn't already exist in your `composer.json`.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
