# Laravel Toolkit

Reusable developer infrastructure for Laravel: preflight CI gates, test reporting with retry, deploy notifications, and project scaffolding.

## Requirements

- PHP 8.5+
- Laravel 13+

## Installation

```bash
composer require nwrman/laravel-toolkit
```

Run the interactive installer to set up your project:

```bash
php artisan toolkit:install
```

The installer will:
1. Publish the config file
2. Merge recommended composer scripts into your `composer.json`
3. Optionally publish AI skills & guidelines, GitHub Actions workflows, static analysis configs, and deployment scripts

## Commands

| Command | Description |
|---------|-------------|
| `toolkit:preflight` | Run all CI quality gates in parallel |
| `toolkit:report` | Run test suites and generate failure reports |
| `toolkit:retry` | Re-run only previously failed tests |
| `toolkit:deploy-notify` | Send deploy notifications via Telegram |
| `toolkit:install` | Interactive project setup wizard |

### Preflight

Run all configured quality gates in parallel:

```bash
php artisan toolkit:preflight
```

Run specific gates:

```bash
php artisan toolkit:preflight --gate=lint --gate=types
```

Retry only previously failed gates:

```bash
php artisan toolkit:preflight --retry
```

Force a frontend rebuild before running gates:

```bash
php artisan toolkit:preflight --force-build
```

### Test Report

Run test suites and generate a Markdown failure report:

```bash
php artisan toolkit:report
```

Run specific suites:

```bash
php artisan toolkit:report --suite=unit --suite=feature
```

### Test Retry

Re-run only the tests that failed in the last report:

```bash
php artisan toolkit:retry
```

### Deploy Notify

Send a deployment notification to Telegram:

```bash
php artisan toolkit:deploy-notify --status=success
php artisan toolkit:deploy-notify --status=failure --message="Migration failed"
```

Requires `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` in your `config/services.php`.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=toolkit-config
```

### Smart Merge

You only need to override what you want to change. The package deep-merges your config with its defaults:

```php
// config/toolkit.php — only override the lint command
return [
    'gates' => [
        'lint' => [
            'command' => 'vendor/bin/pint --test',
        ],
    ],
];
```

All other gates (pest-coverage, frontend-coverage, types) keep their default configuration.

### Removing a Gate

Set a gate to `null` to remove it entirely:

```php
return [
    'gates' => [
        'frontend-coverage' => null,
    ],
];
```

### Default Gates

| Gate | Label | Description |
|------|-------|-------------|
| `pest-coverage` | Pest Coverage | Runs Pest with parallel execution and 100% coverage |
| `frontend-coverage` | Frontend Coverage | Runs `bun run test:coverage` |
| `lint` | Lint | Pint, Rector, and frontend linting |
| `types` | Types | PHPStan and frontend type checking |

## Publishable Stubs

| Tag | Contents |
|-----|----------|
| `toolkit-config` | `config/toolkit.php` |
| `toolkit-static-analysis` | `pint.json`, `phpstan.neon` |
| `toolkit-ai` | `.ai/skills/` and `.ai/guidelines/` |
| `toolkit-github` | `.github/workflows/tests.yml` |
| `toolkit-scripts` | `scripts/cloud-build.sh`, `scripts/cloud-deploy.sh`, `resources/js/scripts/lint-dirty.ts` |

Publish specific stubs:

```bash
php artisan vendor:publish --tag=toolkit-ai
php artisan vendor:publish --tag=toolkit-github
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
