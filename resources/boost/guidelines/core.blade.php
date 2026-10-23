# Running Tests

This project runs tests through `composer` scripts that wrap `nwrman/laravel-toolkit`. They write a structured failure
report you read instead of scrolling terminal output. Do **not** shell out to `pest` / `phpunit` / `php artisan test` for
full or per-suite runs; a guard hook blocks them.

## The order: narrowest check first, the full gate once

Every change gets a test. Then check it in this order, and stop climbing as soon as a step fails — fix, and re-run *that
step*, not the one above it.

1. **The test you just wrote or touched**: `php artisan test --filter='name or file'`. Seconds.
2. **Everything your change can reach**: `composer test`. With Test Impact Analysis on (see below) it replays only the
   backend tests the working tree can affect, and writes the failure report. Without TIA, `composer test -- --filter=X`
   narrows it by hand.
3. **The ship gate, once, at the end**: `composer preflight` — coverage, lint and types in parallel, with a summary
   table.
4. **After a gate fails: re-run only what failed.** `php artisan toolkit:preflight --retry` re-runs the failed gates;
   `--gate=lint` (or `types`, `pest-coverage`, `frontend-coverage`) runs one. A lint or type fix never needs the test
   suite again.

**Never re-run the full gate to confirm a narrow fix.** An ArchTest edit is confirmed by that one test, a Pint or
Rector fix by `--gate=lint`, one failing browser spec by that spec. Re-running the full gate after each of them is the
single biggest waste of time this setup sees: minutes per run, several runs per task.

### `composer test:ci` — the "would CI pass?" check

`test:ci` runs exactly what CI runs, serially, with Test Impact Analysis off — every test, from scratch. Use it **once**, when you need that exact
answer — before pushing a branch whose state you have not seen CI judge, or on a freshly provisioned checkout to prove
a clean install works. It is not a fix loop: it is the slowest way to learn about a lint error. For a truly clean
install (fresh `vendor/`, fresh database, no local caches), the honest checks are CI on the PR, or `test:ci` in a newly
provisioned worktree.

## Commands

Each wrapper writes these **only when something fails**; a green run prints `✓ All tests passed!` and deletes them.

- `storage/logs/test-failures.md` — markdown report: class, file, line, error, and a `--filter` re-run hint per failure.
- `storage/logs/test-failures.json` — the same, structured (feeds `composer test:retry`).
- `storage/logs/test-results.xml` — JUnit XML.

| Command | Runs |
| --- | --- |
| `composer test` | Every suite; with TIA, only the affected backend tests. The default. |
| `composer test -- --filter=X` | Only the backend tests matching `X`, with the report — and the frontend build check first when a browser spec could match. |
| `composer test:unit` / `test:feature` / `test:browser` | One suite, in full. For one suite's report — not a way to go faster (it switches TIA off). |
| `composer test:frontend` | The Vitest suite, in full (Vitest is not TIA-aware). `bunx vitest run path/to/file.test.tsx` for one file. |
| `composer test:fast` | The backend through TIA, without the report. The tightest loop. |
| `composer test:retry` | Only the tests that failed last run. |
| `composer test:report` | Interactive suite picker. |
| `composer preflight` | The ship gate: all gates in parallel, summary table. `--retry`, `--gate=<name>`. |
| `composer test:ci` | What CI runs, serially, TIA off. Once, not in a loop (above). |

### Browser specs

Browser specs render the frontend from the built assets in `public/build`, not from the dev server. Run one through the
wrapper — `composer test -- --filter='GalleryTest'` — which rebuilds the assets first when `resources/` is newer than the
last build. `php artisan test --filter` on a browser spec skips that check and can silently test yesterday's bundle.

## Test Impact Analysis

When the project enables it (`pest()->tia()` in `tests/Pest.php`, `toolkit.tia.enabled`), Pest records which test
touches which file and replays only the tests a change can reach. One edited file typically runs a fraction of the
suite; an unchanged tree runs nothing; a comment-only edit or a formatter pass runs zero tests.

- **Prefer a full run over guessing a suite.** TIA selects by real dependency, so it catches the feature and browser
  tests a "this is just a unit change" guess would miss. Selecting a suite by hand switches TIA off and can under-test.
- **Coverage works through TIA.** A replayed run stores what each test covered, so `composer preflight`'s coverage
  gate reports the same numbers as a full run — just faster. `composer test:ci` runs with `--no-tia`, because it
  answers "would CI pass?" and CI runs every test.
- **Preflight keeps the coverage cache honest.** Before the coverage gate it moves Pest's commit back to the one the
  coverage cache matches (a plain `composer test` moves it on without updating coverage), moves a cache recorded in
  another checkout to this one, and deletes a cache it cannot place so the run records coverage afresh. It prints a
  `TIA:` line when it does any of these. Running `composer test` or `test:fast` never repairs coverage — only a
  coverage run writes it.
- **Keep the coverage gate unfiltered.** `--exclude-testsuite`, `--group`, `--filter` and the other selection flags make
  Pest switch TIA off, so every preflight would re-run the whole suite. `toolkit:preflight` refuses such a gate; with
  TIA on, Browser specs only run when a change can reach them, so there is nothing to gain by excluding them.
- The graph lives outside the repo in `~/.pest/tia/` and rebuilds itself when `composer.lock`, `phpunit.xml`, the vite
  config or the node lockfile change. Nothing to gitignore.
- If a coverage number still looks wrong — a percentage that will not move, or a file you never touched reported as
  uncovered — check it with `composer test:ci` before writing a single test. If the two disagree, the replay is
  wrong: that is a toolkit bug. Report it to `nwrman/laravel-toolkit` with both outputs and the `TIA:` line, then
  delete the coverage cache (`vendor/bin/pest --baseline` prints its directory; remove `coverage.bin.gz` there) and
  re-run the coverage gate.

## After a failure

**Read `storage/logs/test-failures.md` instead of re-running to scroll output.** It has each failure's class, file,
line, error and a ready-to-paste filter. Iterate on one failing test with `php artisan test --filter='TestName'` — the
readable test name, not the `__pest_evaluable_` symbol.

## Test-database isolation (non-sqlite projects)

`composer test:*` runs `php artisan toolkit:report`, an artisan parent that loads `.env`, so a non-sqlite project must
isolate its test database or **tests run against the dev database**. Either:

- a committed **`.env.testing`** with the test database, and `--env=testing` on the scripts (the toolkit installer wires
  this when `.env.testing` exists), **or**
- **force-pinned** `DB_*` in `phpunit.xml`, e.g. `<env name="DB_DATABASE" value="..." force="true"/>`.

Sqlite-in-memory projects (`DB_DATABASE=:memory:` forced in `phpunit.xml`) are already isolated.

## Guard hook

A `PreToolUse` hook (`vendor/nwrman/laravel-toolkit/bin/enforce-test-command.php`, wired in `.claude/settings.json`)
blocks raw `pest` / `phpunit` / `php artisan test` runs and points back to the wrappers. Single-test runs with
`--filter` are allowed.

## Push permutations down the stack

Feature stays the default suite for behaviour, but a matrix of input cases is not "behaviour × N". Test the matrix at
the **cheapest level that can falsify the logic** — a validation rule set through a direct `Validator`, a Pest dataset
over the rule object, an expiry calculation over timestamps — and write **one feature test per wiring path** (the
endpoint validates and returns 422; the controller invokes the action). Twenty feature tests for twenty inputs is the
smell; one wiring test plus a twenty-case dataset is the fix.

## Test path convention

Tests mirror `app/`. The test for `app/Foo/Bar/Baz.php` lives at `tests/{Unit|Feature}/Foo/Bar/BazTest.php`:

- `app/Actions/CreateUserPassword.php` → `tests/Unit/Actions/CreateUserPasswordTest.php`
- `app/Http/Controllers/SessionController.php` → `tests/Feature/Controllers/SessionControllerTest.php`
- `app/Models/User.php` → `tests/Unit/Models/UserTest.php`
