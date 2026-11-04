# Running Tests

Tests run through `nwrman/laravel-toolkit`, and no test command boots Laravel. Pest runs from the shell, where
`phpunit.xml`'s `APP_ENV=testing` makes Laravel load `.env.testing`, so every run uses the test database. A Pest plugin
writes the failure report on every run, `vendor/bin/pest` included: read the report instead of scrolling output.

## The order: narrowest check first, the full gate once

Every change gets a test. Then check it in this order, and stop climbing as soon as a step fails — fix, and re-run *that
step*, not the one above it.

1. **The test you just wrote or touched**: `vendor/bin/pest --filter='name or file'`. Seconds.
2. **Everything your change can reach**: `composer test`. With Test Impact Analysis on (see below) it replays only the
   backend tests the working tree can affect, then runs the frontend tests. It leaves browser specs out: after a change
   a page renders, run `composer test:browser` or the specs for that page.
3. **After a failure: only what failed.** `composer test:retry` re-runs exactly the failed tests, by file and name.
4. **The ship gate, once, at the end**: `composer preflight`. It stops at the first failing gate and prints every
   gate's row.
5. **After a gate fails: only what did not pass.** `vendor/bin/preflight --retry`, or `--gate=lint` (or `types`,
   `pest-coverage`, `browser`, `frontend-coverage`) for one. A lint or type fix is confirmed by its own gate.

**Confirm a narrow fix with the narrowest check.** An ArchTest edit is confirmed by that one test, a Pint or Rector fix
by `--gate=lint`, one failing browser spec by that spec. Re-running the full gate after each of them is the single
biggest waste of time this setup sees: minutes per run, several runs per task. The preflight table already shows the
gates that passed earlier (`passed, previous run`), so a re-run for the table is never needed.

### `composer test:ci` — the "would CI pass?" check

`test:ci` runs exactly what CI runs, serially, with Test Impact Analysis off — every test, from scratch. Use it **once**,
when you need that exact answer: before pushing a branch whose state you have not seen CI judge, or on a freshly
provisioned checkout. It is the slowest way to learn about a lint error, so it is never the fix loop.

## The failure report

Every Pest run writes these when something fails, and removes them once it passes:

- `storage/logs/test-failures.md` — each failure's file, class, error, and a command that re-runs exactly that test.
- `storage/logs/test-failures.json` — the same, structured; `composer test:retry` reads it.
- `storage/logs/test-results.xml` — JUnit XML.

Vitest failures land in the same report, under their own heading. Run a printed command exactly as printed: its
`--filter` is anchored and quoted, so it selects that one test and no other whose name contains it.

| Command | Runs |
| --- | --- |
| `composer test` | Pest without browser specs (with TIA, only the affected backend tests), then the frontend tests. The default. |
| `vendor/bin/pest --filter=X` | Only the backend tests matching `X`, with the report and the asset build check. |
| `composer test:retry` | Exactly the tests that failed last run, backend and frontend. |
| `composer test:unit` / `test:feature` / `test:browser` | One suite, in full. For one suite's report; it switches TIA off. |
| `composer test:frontend` | The frontend suite, in full. `bunx vitest run path/to/file.test.tsx` for one file. |
| `composer preflight` | The ship gate: every gate in parallel, fail-fast, summary table. `--retry`, `--gate=<name>`. |
| `composer test:ci` | What CI runs, serially, TIA off. Once, as above. |

### Browser specs

Browser specs render the frontend from the built assets in `public/build`, not from the dev server. Any Pest run that
could select a browser spec rebuilds the assets first when `resources/` is newer than the last build, and keeps the
dev server's `public/hot` file. `TOOLKIT_FORCE_BUILD=1 vendor/bin/pest …` forces the rebuild.

`composer test` leaves browser specs out: under Xdebug, beside every other worker, they time out whenever many of them
run at once. `composer test:browser` and preflight's `browser` gate run them without Xdebug, on fewer workers.

Preflight runs browser specs in their own `browser` gate, after the coverage gate, without coverage. Coverage comes from
unit and feature tests, so cover page props with a feature test (`assertInertia`, partial-reload headers), never with a
browser visit.

## Test Impact Analysis

When the project enables it (`pest()->tia()` in `tests/Pest.php`, `toolkit.tia.enabled`), Pest records which test
touches which file and replays only the tests a change can reach. One edited file typically runs a fraction of the
suite; an unchanged tree runs nothing; a comment-only edit or a formatter pass runs zero tests.

- **Prefer a full run over guessing a suite.** TIA selects by real dependency, so it catches the feature tests a
  "this is just a unit change" guess would miss. Selecting a suite or a filter switches TIA off.
- **Coverage works through TIA.** A replayed run stores what each test covered, so `composer preflight`'s coverage
  gate reports the same numbers as a full run — just faster. It leaves browser specs out with `--toolkit-no-browser`,
  which keeps the run a full one to TIA; `--exclude-testsuite` and the other selection flags would switch TIA off, so
  preflight refuses a coverage gate that passes one.
- **Preflight keeps the coverage cache honest.** Before the coverage gate it moves Pest's commit back to the one the
  coverage cache matches (a plain `composer test` moves it on without updating coverage), moves a cache recorded in
  another checkout to this one, takes every file changed since out of the cache so the run covers it afresh, and
  deletes a cache it cannot place. It prints a `TIA:` line when it does any of these. Only a coverage run writes
  coverage, so `composer test` never repairs it.
- The graph lives outside the repo in `~/.pest/tia/` and rebuilds itself when `composer.lock`, `phpunit.xml`, the vite
  config or the node lockfile change. Nothing to gitignore.
- If a coverage number still looks wrong — a percentage that will not move, or a file you never touched reported as
  uncovered — check it with `composer test:ci` before writing a single test. If the two disagree, the replay is
  wrong: that is a toolkit bug. Report it to `nwrman/laravel-toolkit` with both outputs and the `TIA:` line, then
  delete the coverage cache (`vendor/bin/pest --baseline` prints its directory; remove `coverage.bin.gz` there) and
  re-run the coverage gate.

## Test-database isolation (non-sqlite projects)

Pest runs with `APP_ENV=testing` (forced in `phpunit.xml`), so Laravel loads `.env.testing`. Keep a `.env.testing`
that names the test database — or force-pin `DB_*` in `phpunit.xml`. Without either, Laravel falls back to `.env` and
its database. The scripts carry no `--env=testing`: nothing boots Laravel, so there is no `.env` to keep out.
Sqlite-in-memory projects (`DB_DATABASE=:memory:` forced in `phpunit.xml`) are already isolated.

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
