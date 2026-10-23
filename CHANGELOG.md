# Changelog

All notable changes to `laravel-toolkit` will be documented in this file.

## v0.4.2 — 2026-10-22

### Changed files no longer leave phantom gaps in preflight coverage

Fixes #16. After a merge brought in new versions of several files, preflight's coverage gate kept failing on lines
that every test runs (a `);`, the first line of a method), while `composer test:ci` read 100 %.

Pest merges each run's coverage into its cache line by line. When a file changed, the cache still holds the old
version's line numbers. A line that was code then and is a comment or a closing bracket now stays "executable, not
covered": the tests that ran it are re-run and their old hits removed, but the new run never writes that line again.
With a gate at exactly 100 % it fails on code that is fully tested; with a lower minimum the number just drifts down.
Reproduced in the starter by adding one comment line above a covered statement.

- **Before the coverage gate, preflight takes every file changed since the stamp out of the coverage cache**: files
  changed in commits since, uncommitted now, or uncommitted when the stamp was taken. TIA replays every test that
  touches a changed file, so the run fills each one in whole. Preflight prints `TIA: re-covering N changed files.`
- **A failed coverage gate's cache is not kept.** Preflight sets the stamp aside while the gate runs and stamps the
  cache again only when the gate passes. When the gate fails after writing coverage, the cache is deleted and the next
  run records afresh; when it fails before writing any, the old stamp comes back. An interrupted run leaves no stamp.
  The cost: while coverage is really too low, each retry is a full coverage run.
- **The Running Tests guideline** describes both.

## v0.4.1 — 2026-10-22

### The coverage gate stops reading a stale or foreign coverage cache

Agents in three apps kept seeing a preflight coverage number that `composer test:ci` contradicted, and wiped
`~/.pest/tia/` by hand to get past it: 13 sessions, 5 of them in one day. Two causes, both in how the coverage cache
was trusted:

- **A cache recorded in one checkout was read in another.** Pest names every source file in its coverage cache by its
  absolute path, and `toolkit:tia publish` / `inherit` copied that cache between worktrees as-is. The receiving
  checkout listed the first checkout's files beside its own, so files were counted twice and the total dropped
  (82.6 % against a real 100 %). Copying a baseline into a checkout now rewrites the paths to that checkout.
- **A plain run moved Pest's commit past the coverage cache.** Pest keeps one commit per branch for two jobs: picking
  the tests a change reaches, and marking what its coverage cache is current to. `composer test` and `test:fast` run
  with a coverage driver loaded, so they move that commit, but only a coverage run updates the cache. The next
  preflight skipped the tests for the commits in between and read their lines from a cache that predates them. That
  failed good work (classes added since then dropped out of the report) and passed bad work (a line added since was
  missing from the cache, so 100 % held with the line never run).

What changes:

- **Preflight stamps the coverage cache** (`toolkit-coverage.json`, beside Pest's cache) with the checkout, commit and
  uncommitted files each coverage run covered.
- **Before the coverage gate, preflight lines the cache up**: it moves Pest's commit back to the stamped one, so the
  run replays everything since; moves a cache from another checkout to this one; and deletes a cache it cannot place
  (no stamp, or a commit outside the branch's history) so the run records coverage afresh. It prints a `TIA:` line
  when it does any of this.
- **A published baseline carries its stamp**, and its commit is the stamp's. A cache no toolkit preflight stamped is
  not published or inherited.
- **The "Running Tests" guideline** says what preflight now does, that `composer test` never repairs coverage, and to
  report a remaining mismatch instead of wiping the cache.

On upgrade: every existing coverage cache is unstamped, so each checkout's first preflight records coverage once, in
full. Baselines already published are ignored until the next ticket lands and publishes a stamped one.

## v0.4.0 — 2026-10-19

### New worktrees inherit a TIA baseline instead of re-recording one

Pest keys each git worktree's TIA cache by its own path, so a ticket's worktree started with no baseline: its first
`composer test` and its final `composer preflight` re-ran everything. Copying the main checkout's cache did not fix it —
that baseline went stale, or recorded no commit at all, whenever main's checkout sat on another branch.

- **New `toolkit:tia` command** (`check`, `inherit`, `publish`). A landed branch publishes its final, complete
  baseline for the branch it merged into; a new checkout cut from that branch inherits it under its own name. Works
  for any base branch, not only `main`. The logic tracks Pest's cache format here, so consuming apps stop carrying
  their own copy of it in scripts.
- **`toolkit:preflight` refuses a coverage gate that switches TIA off.** A selection flag such as
  `--exclude-testsuite=Browser` made every preflight re-run the whole suite and record nothing to hand on. The "Running
  Tests" guideline says why the gate stays unfiltered.
- **Fixed: the guard hook blocking `pest --baseline`**, which prints the cache directory and runs no tests.
- **Fixed: two PHPStan errors.** `toolkit:retry` gains `--no-notify`, like the other commands that notify.

## v0.3.0 — 2026-10-15

### Agents are told what a good test is, not only how to run one

The shipped advice covered running tests and nothing about what to assert. Agent-written tests passed, raised
coverage and proved little: `toBeTruthy()` where a value was known, branches in test bodies, expected values computed
with the production logic, mocks of the app's own Actions.

- **New Boost guideline "What a Good Test Is"** (`resources/boost/guidelines/testing.blade.php`), always in context:
  a test fails when the behavior breaks and survives a refactor; assert through the app's front door; stage only
  states the app can reach; fake only at the system's edges; exact known values; one deterministic path; no
  tautologies or change detectors; cover the edges; repeated setup moves into shared tooling.
- **New Boost skill `test-quality`** (`resources/boost/skills/test-quality/`): the tear-apart checklist for writing
  and reviewing tests, Pest and Vitest, with a findings format for a reviewing agent. It adds to Laravel's
  `testing-best-practices` skill rather than repeating it.

Reaches an app on `composer update` + `php artisan boost:update`.

## v0.2.1 — 2026-10-13

- **Fixed: the guard hook blocking a commit whose message only mentioned Pest.** Quoted text was stripped in two
  regex passes, single quotes first, so an apostrophe inside a double-quoted message (`"Laravel's …"`) paired with a
  later single quote, unbalanced the double quotes, and exposed a message line starting with "Pest" as a command. It
  is now one left-to-right scan, the way the shell reads quotes. Found by the hook blocking its own release commit.

## v0.2.0 — 2026-10-13

### Agents check a change in the right order

Measured in a consuming app over three days: 14 full `composer test:ci` runs, 100–230s each, most of them confirming
a fix that one test or one gate would have confirmed in seconds. The agent advice pointed at hand-picked suites and
listed `test:ci` beside `preflight` as equals.

- **The "Running Tests" guideline ships through Boost** (`resources/boost/guidelines/core.blade.php`) instead of being
  published. `composer update` + `php artisan boost:update` now reach every app. It replaces the old
  "run the narrowest suite" advice with a checking order — touched test, `composer test`, one `composer preflight`,
  `--retry` for what failed — and reserves `composer test:ci` for the "would CI pass?" check.
- **The guard hook runs from `vendor/`** (`bin/enforce-test-command.php`). `toolkit:install` points
  `.claude/settings.json` at it and repoints entries that still run the old copy in `.claude/hooks/`. Its block
  message now gives the checking order instead of recommending per-suite runs, it allows the bare `composer test`
  (Test Impact Analysis made it the fast default), and it ignores quoted strings and heredoc bodies, so a commit
  message or a script written to disk that only mentions a runner is no longer blocked.
- **`toolkit:report --filter=<pattern>`** (`composer test -- --filter=X`): one test or file through the wrapper, with
  the failure report and the frontend build check. `php artisan test --filter` on a browser spec skips that check and
  can test a stale bundle.
- **`test:ci` fails fastest first and measures everything**: lint and types before the coverage run, and `--no-tia`
  on the coverage run wherever Pest has TIA — CI runs every test, so the check that predicts it must too. Merged by
  `toolkit:install` into apps that have no `test:ci` yet; existing ones keep theirs.
- **Fixed: `toolkit:preflight` exhausting memory** once a gate ran for minutes (#8). Under `--env=testing`,
  `nunomaduro/essentials` fakes `Sleep` in the parent process, and the poll loop used `Sleep::usleep()` — so it
  busy-spun, recording every interval, at roughly 200 MB/s. It now uses PHP's own `usleep()`.

### Upgrade path

```bash
composer update nwrman/laravel-toolkit
php artisan toolkit:install            # answer yes to "AI skills & guidelines" and "guard hook"
rm .ai/guidelines/enforce-tests.blade.php .claude/hooks/enforce-test-command.php   # the old copies
php artisan boost:update
```

Reorder your own `test:ci` by hand if you want the fast-fail order; the installer never overwrites it.

## v0.1.x

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

### Changed

- `shipfastlabs/agent-detector` is replaced by `laravel/agent-detector` (`^2.0`). The original was marked abandoned by its author, who names this as the successor; it is the same package under the Laravel vendor. Every app installing this toolkit was getting an abandonment warning on `composer install` that it could not fix on its own, because the requirement lives here.
- The API is unchanged — `AgentDetector::detect()->isAgent` — so the only source change is the import namespace, `AgentDetector\AgentDetector` to `Laravel\AgentDetector\AgentDetector`. Nothing in a consuming app needs to change beyond `composer update`.
