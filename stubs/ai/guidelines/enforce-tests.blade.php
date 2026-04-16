# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run only the tests relevant to your changes. Pick the narrowest scope possible for fast feedback:
  - **Backend PHP changes** (models, controllers, actions, migrations, etc.): `composer test:report -- --suite=unit,feature`.
  - **Frontend React/TS changes** (components, hooks, pages): `bun run test:ui` or with filter: `bunx vitest run path/to/file.test.tsx`.
  - **Full-stack flow changes** (routes + pages wired together): `composer test:report -- --suite=browser`.
- If tests fail, fix them and use `composer test:retry` to re-run only the previously failed tests.
- Do NOT run `composer test` (the full suite) unless you've made sweeping cross-cutting changes. Prefer targeted runs.
- **Test path convention**: Tests must mirror the `app/` directory structure. The test file for `app/Foo/Bar/Baz.php` lives at `tests/{Unit|Feature}/Foo/Bar/BazTest.php`. Examples:
  - `app/Actions/CreateUserPassword.php` → `tests/Unit/Actions/CreateUserPasswordTest.php`
  - `app/Http/Controllers/SessionController.php` → `tests/Feature/Controllers/SessionControllerTest.php`
  - `app/Console/Commands/TestReportCommand.php` → `tests/Feature/Console/TestReportCommandTest.php`
  - `app/Models/User.php` → `tests/Unit/Models/UserTest.php`
