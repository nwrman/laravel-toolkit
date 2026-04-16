## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests with failure tracking: `composer test:report -- --suite=unit,feature` (comma-separated suites: unit, feature, browser).
- Re-run only previously failed tests: `composer test:retry`.
- Frontend tests: `bun run test:ui` (Vitest).
- Do NOT delete tests without approval.
