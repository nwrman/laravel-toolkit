## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `composer test` (or `vendor/bin/pest`); every Pest run writes `storage/logs/test-failures.md` on failure.
- Re-run only previously failed tests: `composer test:retry`.
- Frontend tests: `bun run test:ui` (Vitest).
- Do NOT delete tests without approval.
