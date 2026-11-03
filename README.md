# Laravel Toolkit

Reusable developer infrastructure for Laravel: a fail-fast parallel ship gate, a Pest plugin for failure reports and exact retries, deploy notifications, and project scaffolding.

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
3. Merging recommended composer scripts into your `composer.json`, and standardizing the test scripts
4. Publishing AI skills & guidelines (`.ai/`), and registering every skill found in `.ai/skills` with Laravel Boost (`boost.json`) so agents are actually told they exist
5. Removing the retired test-command guard hook from `.claude/settings.json`, if present
6. Publishing a GitHub Actions CI workflow (`.github/workflows/tests.yml`)
7. Publishing static analysis configs (`pint.json`, `phpstan.neon`)
8. Publishing the deploy notification command into `app/Console/Commands/` (plus its Pest test)
9. Publishing deployment scripts (`scripts/cloud-build.sh`, `cloud-deploy.sh`, `cloud-setup.sh`)

Each step is optional and skips files that already exist.

### Production safety

The package is a pure developer-tool: it registers no routes, middleware, bindings, listeners, migrations, or scheduled tasks. The service provider is fully inert outside `runningInConsole()` context, so it adds zero overhead to HTTP requests and queue workers even if it were accidentally installed via `require`.

Production deploys that run `composer install --no-dev` do not need this package. The one class the deploy pipeline calls — the Telegram deploy-notification command — is published as a stub into your app's `app/Console/Commands/DeployNotifyTelegramCommand.php` (along with a Pest test in `tests/Feature/Console/Commands/`) so it lives in your application code, independent of whether this package is installed on the deploy host. Re-publish on upgrades with:

```bash
php artisan vendor:publish --tag=toolkit-commands --force
```

## Commands

Nothing that runs tests boots Laravel. Pest runs from the shell, where `phpunit.xml`'s `APP_ENV=testing` makes Laravel
load `.env.testing`, and no artisan parent puts `.env`'s database into the test run.

| Command | Description |
|---------|-------------|
| `vendor/bin/pest` (any Pest run) | The toolkit's Pest plugin writes the failure report, and builds the frontend first when a Browser spec could run |
| `vendor/bin/pest --toolkit-retry` | Re-run exactly the tests the failure report lists |
| `vendor/bin/preflight` | Run every CI gate in parallel, stopping at the first failure |
| `php artisan toolkit:tia` | Hand complete TIA baselines from landed branches to new ones |
| `php artisan toolkit:install` | Interactive project setup wizard |
| `php artisan starter:install-filament` | Install the canonical Filament admin panel into a consumer app |

A `deploy:notify-telegram` command is also available for deploy pipelines, but it is published into your application (not registered by this package) — see [Published deploy notification command](#published-deploy-notification-command).

### The Pest plugin

Registered through `extra.pest.plugins`, so every Pest run in the app gets it — `composer test`, a raw
`vendor/bin/pest`, a preflight gate. In the parent process it:

- **writes the failure report when the run ends** (parallel runs too: it reads the JUnit file Paratest merges):
  - `storage/logs/test-failures.md` — each failure's file, class, type and message, and a command that re-runs that
    one test;
  - `storage/logs/test-failures.json` — the same, structured; `--toolkit-retry` reads it;
  - `storage/logs/test-results.xml` — the JUnit file (or the one you pass with `--log-junit`).

  A run that passes removes its section, and the files once no section is left. A run that crashed, or failed with no
  failed test (a risky test, a deprecation the config treats as failure), says so in the report instead of leaving it
  stale.
- **`--toolkit-retry`** re-runs exactly the failed tests: their files, and a `--filter` of each test's class and
  name, quoted and anchored, so `it logs in` never also runs `it logs in with a passkey`. That makes it a partial run,
  so TIA is off for it. With nothing on record it runs nothing. Pest's own `--retry` is not the same: it runs
  everything, failures first, and stops at the first failure.
- **`--toolkit-no-browser`** leaves the Browser suite out (the suite named `Browser` in `phpunit.xml`) without making
  the run a partial one, so TIA stays on. `--exclude-testsuite` would switch TIA off.
- **builds the frontend** when a Browser spec could run (unless a `--testsuite` or explicit paths rule them out) and a
  watched file is newer than the last build. The dev server's `public/hot` file is put back if the build deletes it.
  `TOOLKIT_FORCE_BUILD=1` forces the build.

The Vitest side is a reporter that writes the report's frontend section beside Pest's:

```ts
// vitest.config.ts
test: { reporters: ['default', './vendor/nwrman/laravel-toolkit/resources/js/vitest-reporter.mjs'] }
```

`node vendor/nwrman/laravel-toolkit/resources/js/vitest-retry.mjs` re-runs only the failed Vitest tests; `composer
test:retry` runs it after the Pest retry.

### `vendor/bin/preflight`

The ship gate: every configured gate in parallel, with a live display, a summary table and macOS notifications. It
never boots Laravel: it reads `config/toolkit.php` as plain arrays and `.env` only to resolve `env()` in that file, and
gates inherit the shell's environment.

- **Fail-fast.** When a gate fails, preflight stops every running gate with every process it started (Pest's workers,
  browsers), and the gates still waiting do not start.
- **`after`.** A gate with `'after' => 'pest-coverage'` starts once that gate passes. The default `browser` gate runs
  Browser specs after the coverage gate, without coverage or Xdebug and on fewer workers; coverage comes from unit and
  feature tests (`--toolkit-no-browser`).
- **Every gate's row, every time.** `--retry` and `--gate=` print the full table; a gate this run did not run shows its
  result from the last run that did (`✓ passed, previous run`).
- **TIA.** With `toolkit.tia.enabled`, preflight lines the coverage cache up with the commit it matches before the
  coverage gate, and stamps it after a run that rewrote it. It refuses a coverage gate that passes a Pest selection
  flag (`--exclude-testsuite`, `--group`, `--filter`, …), which would switch TIA off.

```
Running 5 gates...

  ✓ Lint                 (14.1s)
  ✓ Frontend Coverage    (18.4s)
  ✓ Types                (20.3s)
  ✓ Pest Coverage        (61.0s)
  ✓ Browser              (40.2s)

+-------------------+----------+-------+
| Gate              | Status   | Time  |
+-------------------+----------+-------+
| Pest Coverage     | ✓ passed | 61.0s |
| Browser           | ✓ passed | 40.2s |
| Frontend Coverage | ✓ passed | 18.4s |
| Lint              | ✓ passed | 14.1s |
| Types             | ✓ passed | 20.3s |
+-------------------+----------+-------+

✓ All gates passed!
```

**Options:**

| Option | Description |
|--------|-------------|
| `--gate=<name>` | Run specific gate(s). Repeatable: `--gate=lint --gate=types` |
| `--retry` | Run only the gates that did not pass last run (failed, stopped or not run) |
| `--force-build` | Rebuild the frontend before Browser specs run |
| `--no-notify` | Suppress the macOS desktop notification |

**Agent detection:** inside an agent (Claude Code, Cursor, OpenCode, …) preflight skips notifications and prints each
gate's result as a plain line instead of the live display. Detection is powered by
[`laravel/agent-detector`](https://github.com/laravel/agent-detector).

**Typical workflow:**

```bash
composer test                      # what the change can reach (TIA), with the report
composer test:retry                # after a failure: only what failed
composer preflight                 # the ship gate, once
vendor/bin/preflight --retry       # after a gate fails: only what did not pass
```

### `toolkit:tia`

Hands a complete Test Impact Analysis baseline from work that has landed to work that is starting,
so no checkout records one up front. Each git worktree gets its own Pest cache (Pest keys it by the worktree's path), so
without this every new worktree starts with no baseline and its first runs re-run everything.

```bash
php artisan toolkit:tia publish   # after a branch merges: file its baseline under the branch it merged into
php artisan toolkit:tia inherit   # in a new checkout: copy its base branch's baseline under this branch's name
php artisan toolkit:tia check     # exit 0 when this branch has a usable baseline, 1 with the reason otherwise
```

- **publish** files the current branch's baseline for its base only when it is complete, has a commit, has a coverage
  cache, and that commit is merged into the base (locally or on `origin`). A newer baseline already on file is kept.
- **inherit** does nothing when the branch already has a usable baseline, and copies nothing whose commit is outside
  the branch's history (Pest would ignore it).
- The base is `--base`, else `git config branch.<name>.tiaBase`, else the remote's default branch.
- Published baselines live in the repository's common git directory (`.git/pest-tia/<base>/`), shared by every
  worktree.

Every action is a no-op when `toolkit.tia.enabled` is off.

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

You only need to override what you want to change. Gates merge per gate (`array_replace_recursive`), the same way in artisan and in `vendor/bin/preflight`, so you can override a single gate's command without redeclaring all gates. The file is read without booting Laravel: it can call `env()` (the shell's environment, then `.env`), but no helper that needs the application.

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

Each gate runs a shell command as part of preflight. Gates run in parallel; the first to fail stops the others.

| Key | Type | Description |
|-----|------|-------------|
| `label` | `string` | Display name shown in the summary table |
| `command` | `string` | Shell command to execute |
| `env` | `array` | Optional environment variables (e.g., `['XDEBUG_MODE' => 'coverage']`), on top of the shell's |
| `after` | `string` | Optional gate key: this gate starts once that gate passed, and does not run if it failed |

**Default gates:**

| Gate | Label | Default Command |
|------|-------|----------------|
| `pest-coverage` | Pest Coverage | `vendor/bin/pest --type-coverage --min=100 && vendor/bin/pest --parallel --coverage --exactly=100.0 --toolkit-no-browser` (`XDEBUG_MODE=coverage`) |
| `browser` | Browser | `vendor/bin/pest --testsuite=Browser --parallel --processes=4` (`XDEBUG_MODE=off`, after `pest-coverage`) |
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

The build Browser specs render. The Pest plugin checks it before a run that could select a Browser spec.

```php
'build' => [
    'command' => 'vp build',           // Build command
    'timeout' => 120,                   // Timeout in seconds
    'stamp_file' => 'public/build/.buildstamp',    // Timestamp file for staleness check
    'manifest_file' => 'public/build/manifest.json', // Vite manifest
    'watch_paths' => 'resources/ vite.config.* tsconfig.* tailwind.config.* postcss.config.* package.json',
    'hot_file' => 'public/hot',          // Put back if the build deletes it
],
```

The build runs when a watched file is newer than the stamp file, or when `TOOLKIT_FORCE_BUILD=1` is set (`preflight --force-build` sets it for its gates).

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

#### `frontend_test_command`

The frontend command `toolkit:install` writes into `composer test` and `composer test:frontend`.

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
| `toolkit-scripts` | Provisioning, deployment & lint scripts | `scripts/cloud-setup.sh`, `scripts/cloud-build.sh`, `scripts/cloud-deploy.sh`, `resources/js/scripts/lint-dirty.ts` |

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
| `provision-laravel-cloud` | Stand up a Laravel Cloud application for the first time |

Guidelines cover: action pattern, domain folders, general conventions, Pest testing, React best practices, and Vitest.

The **"Running Tests" guideline is not published** — it ships from this package at
`resources/boost/guidelines/core.blade.php`, and Boost reads it from `vendor/`, so `composer update` +
`php artisan boost:update` keep every app on the current advice. `toolkit:install` lists the package under
`"packages"` in `boost.json`, which is what makes Boost include it. It sets the order an agent checks a change in —
the touched test, then `composer test`, then one `composer preflight`, then `--retry` for whatever failed — and gives
`composer test:ci` its single job: the "would CI pass?" check.

The **"What a Good Test Is" guideline and the `test-quality` skill** ship the same way
(`resources/boost/guidelines/testing.blade.php`, `resources/boost/skills/test-quality/`). The guideline is the
always-on half: tests pin behavior, stage only reachable states, fake only at the edges, assert exact known values.
The skill is the tear-apart checklist an agent loads to write tests and to review someone else's — weak assertions,
conditional test logic, tautologies, change detectors, setup that mirrors the implementation, missing edges.

Apps installed before this carry a published copy at `.ai/guidelines/enforce-tests.blade.php`. That copy overrides
Boost's own test guideline with the old advice; `toolkit:install` flags it, and it should be deleted.

Publishing a skill only puts it on disk. Boost's `skills` array in `boost.json` is what surfaces
it in the guidelines an agent always has in context, so `toolkit:install` registers every skill
it finds in `.ai/skills` — including ones the application wrote itself.

### Laravel Cloud provisioning

`scripts/cloud-setup.sh` stands up a Cloud application from two committed files:

| File | Owner | Holds |
|------|-------|-------|
| `.cloud/config.json` | Laravel's `cloud` CLI | `organization_id` (the org pin), `application_id` |
| `.cloud/provision.json` | your application | repository, names, region, database and instance sizing, optional `domain` |

Get the files in place with `php artisan toolkit:install` — it publishes the scripts *and* adds
the `cloud:setup` composer script and registers the skill in `boost.json`. `vendor:publish
--tag=toolkit-scripts` copies the files only, leaving both of those to be wired by hand.

Then run it with `composer cloud:setup`, or let the `provision-laravel-cloud` skill gather the
inputs and run it for you.

It is create-only and re-runnable: it never deploys, deletes, renames, or resizes. Omit `domain`
and the app runs on its Cloud-assigned URL; add the key later and re-run to create the domain
then. `APP_URL` is derived from the live environment rather than committed, and environment
variables still valued `REPLACE_IN_DASHBOARD` are skipped rather than written, so a re-run never
overwrites a secret you pasted into the dashboard.

Because no `:create` command accepts an `--organization` flag, the pinned `organization_id` is
the only thing selecting an account. The script refuses to run without it and aborts if a created
application lands in a different organization than the one pinned.

## Recommended Composer Scripts

The `toolkit:install` command can merge these scripts into your `composer.json`:

| Script | Command |
|--------|---------|
| `composer dev` | Concurrent queue worker + Pail + Vite dev server |
| `composer lint` | Rector + Pint + frontend linting |
| `composer lint:dirty` | Lint only files changed since last commit |
| `composer test` | `pest --parallel`, then the frontend tests; both always run. With TIA, only the backend tests a change can reach |
| `composer test:unit` | Pest unit tests (parallel) |
| `composer test:feature` | Pest feature tests (parallel) |
| `composer test:browser` | Pest browser tests (parallel) |
| `composer test:frontend` | The frontend tests |
| `composer test:lint` | Pint + Rector + frontend lint (verification mode) |
| `composer test:types` | PHPStan + frontend type checking |
| `composer test:ci` | What CI runs, fastest gates first (lint, types, then coverage), TIA off — the "would CI pass?" check |
| `composer test:retry` | Only the tests that failed: `pest --parallel --toolkit-retry`, then the Vitest retry |
| `composer preflight` | `vendor/bin/preflight` with the timeout disabled |
| `composer optimize` | Cache config, events, routes, and views |
| `composer cloud:setup` | Provision the Cloud stack (create-only, re-runnable) |
| `composer cloud:build` | Run cloud build script |
| `composer cloud:deploy` | Run cloud deploy script |

Scripts are added only if the key doesn't already exist in your `composer.json`. Standardizing the test scripts
overwrites `test` and `test:*` with the ones above, and removes a script that still calls a pre-v1 artisan test
command (`toolkit:report`, `toolkit:retry`, `toolkit:preflight`). It never adds `--env=testing`: nothing boots
Laravel, so there is no `.env` to keep out. It also removes the retired test-command guard hook from
`.claude/settings.json`.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
