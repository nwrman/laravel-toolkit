# Running Tests

This project standardises on `composer test:*` script aliases that wrap the `nwrman/laravel-toolkit` package. **Use them.**
They run through the toolkit, which emits a structured failure report you read instead of scrolling terminal output. Do
**not** shell out to `pest` / `phpunit` / `php artisan test` for full or per-suite runs, and avoid the bare `composer test`.

- Every change must be programmatically tested: add or update a test, then run the narrowest relevant suite.
- Pick the smallest scope for fast feedback:
  - **Backend PHP** (models, controllers, actions, migrations…): `composer test:unit` and/or `composer test:feature`.
  - **Frontend React/TS** (components, hooks, pages): `composer test:frontend` (or `bunx vitest run path/to/file.test.tsx`).
  - **Full-stack flows** (routes + pages wired together): `composer test:browser`.

## Commands

Each command writes the same artifacts **only when something fails**. On a green run the toolkit prints `✓ All tests
passed!` and deletes the failure files so stale data never lingers.

- `storage/logs/test-failures.md` — markdown report: class, file, line, error, and a `--filter` re-run hint per failure.
- `storage/logs/test-failures.json` — same data, structured (feeds `composer test:retry`).
- `storage/logs/test-results.xml` — JUnit XML.

| Command | Runs |
| --- | --- |
| `composer test:unit` | The `Unit` suite. |
| `composer test:feature` | The `Feature` suite. |
| `composer test:browser` | The `Browser` suite. |
| `composer test:frontend` | The frontend (Vitest) suite. |
| `composer test` | All suites at once. Prefer a narrower suite above unless you made sweeping changes. |
| `composer test:report` | Interactive picker — prompts which suites to run. |
| `composer test:retry` | Re-runs only the tests that failed last run (reads the JSON above). |
| `composer test:ci` / `composer preflight` | Full gate: coverage + lint + types. The "is this branch ready?" check. |

## After a failure

**Read `storage/logs/test-failures.md` instead of re-running the suite to scroll output.** It already has each failure's
class, file, line, error, and a ready-to-paste filter command. To iterate on one failing test while debugging, a
single-test filter is fine:

```shell
php artisan test --filter='TestName'
```

The `composer test:*` family is for full runs and for re-runs that feed the structured report.

## Test-database isolation (non-sqlite projects)

Because `composer test:*` runs `php artisan toolkit:report` — an artisan parent that loads `.env` — a non-sqlite project
must isolate its test database, or **tests will run against the dev DB**. Use one of:

- A committed **`.env.testing`** with the test DB, and `--env=testing` on the test scripts (the toolkit installer wires
  this automatically when `.env.testing` exists), **or**
- **force-pinned** `DB_*` in `phpunit.xml`, e.g. `<env name="DB_DATABASE" value="..." force="true"/>` (and `DB_CONNECTION`,
  `DB_HOST`, …).

Sqlite-in-memory projects (`DB_DATABASE=:memory:` forced in `phpunit.xml`) are already isolated and need neither.

## Guard hook

A `PreToolUse` guard (`.claude/hooks/enforce-test-command.php`) **blocks** direct `pest` / `phpunit` / `php artisan test`
and the bare `composer test`, redirecting to the wrappers. Single-test debugging with `--filter` is allowed. Reach for the
`composer test:*` wrappers first so you never hit it.

## Test path convention

Tests mirror the `app/` directory structure. The test for `app/Foo/Bar/Baz.php` lives at
`tests/{Unit|Feature}/Foo/Bar/BazTest.php`. Examples:

- `app/Actions/CreateUserPassword.php` → `tests/Unit/Actions/CreateUserPasswordTest.php`
- `app/Http/Controllers/SessionController.php` → `tests/Feature/Controllers/SessionControllerTest.php`
- `app/Models/User.php` → `tests/Unit/Models/UserTest.php`
