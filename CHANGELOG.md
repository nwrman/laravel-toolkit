# Changelog

All notable changes to `laravel-toolkit` will be documented in this file.

## Unreleased

### BREAKING

- The package is now **dev-only**. Install with `composer require --dev nwrman/laravel-toolkit`. The service provider is fully inert outside `runningInConsole()` context — `register()` and `boot()` short-circuit on HTTP requests and queue workers, so the package adds zero overhead to application runtime.
- `DeployNotifyTelegramCommand` has been removed from the package and is now published into the consumer app as a stub at `app/Console/Commands/DeployNotifyTelegramCommand.php` (with its Pest feature test at `tests/Feature/Console/Commands/DeployNotifyTelegramCommandTest.php`, to preserve 100% test coverage). This means the command remains available in production (where `composer install --no-dev` omits this package) because it lives in the application's own code.
- The command's artisan signature has been renamed from `toolkit:deploy-notify` to `deploy:notify-telegram` — matching the invocation already present in `stubs/scripts/cloud-build.sh` and `stubs/scripts/cloud-deploy.sh` (which was previously broken because of the signature mismatch).
- `toolkit:install` now includes two new prompted steps:
  - Moves `nwrman/laravel-toolkit` from `require` to `require-dev` in the host `composer.json` (if the package is currently in `require`).
  - Publishes the deploy notification command and its test via the new `toolkit-commands` tag.

### Upgrade path

With the package still installed in `require`, run:

```bash
php artisan toolkit:install --force
composer update
```

This will migrate the package to `require-dev`, publish the `DeployNotifyTelegramCommand` stub + its test into your app, and refresh the lock file. Commit the new files under `app/Console/Commands/` and `tests/Feature/Console/Commands/`.
