# Changelog

All notable changes to `laravel-toolkit` will be documented in this file.

## v1.1.0 — 2026-11-11

### Breaking

- **Pest 5 only.** The constraints were `^4.0||^5.0`, left over from the first release; every app on the toolkit runs
  Pest 5. The coverage gate's full-run note loses its Pest 4 wording.

### Fixes found benchmarking Minuta's preflight

- **An uncommitted file no longer re-runs its tests on every preflight.** Preflight stamped each uncommitted file with
  an empty hash and handed that to Pest, which matches no file, so every coverage gate re-ran every test an uncommitted
  file reaches until it was committed. On Minuta a comment-only edit to `resources/js/app.tsx`, which Pest maps to all
  284 test files, made each following coverage gate take 190–670 s instead of about 20 s. The stamp now holds a hash of
  each file, and Pest replays a file only when it changed since the last coverage run.
- **A file whose coverage preflight forgets has its tests replayed.** Preflight forgets a changed file's coverage so its
  old line numbers cannot linger, but Pest ignores comment-only edits, so after one no test re-ran and the file dropped
  out of the coverage cache, committed or not. Preflight now makes Pest replay every file it forgets.
- **A coverage cache kept by an older toolkit is recorded afresh, once.** It can already lack files for the reason
  above. The first coverage gate after the upgrade runs every test and prints a `TIA:` line saying why.

### Failure report fixes

- **A renamed or deleted Vitest test leaves the failure report.** The reporter kept an earlier failure whenever its file
  existed and that exact name had not run, so a renamed test's failure stayed forever: `Failed: 1` beside a Vitest run
  with every test passing. A run of whole files now drops an earlier failure from a file it ran, as the backend
  section does.
- **A filtered Vitest re-run keeps the other failures.** Vitest reports the tests a `-t` filter leaves out as skipped,
  and the reporter counted them as run, so re-running one test erased the others' failures while they still failed.
  `composer test:retry`'s frontend half marks its run as a retry, which drops only the earlier failures it did not run.

## v1.0.5 — 2026-11-04

### Fixes found checking v1.0.4 on Minuta

- **Preflight says when the coverage gate will run every test.** A gate without a usable graph, or without a coverage
  cache, ran in full with no `TIA:` line. It now prints one: Pest's graph is missing or out of date (naming the files
  that changed), or there is no coverage cache yet.
- **No "replaying from" on an out-of-date graph.** Preflight moved Pest's commit inside a graph Pest was about to set
  aside, and said it was replaying when the run covered every test.
- **The guideline states the cost of a lock change.** On Pest 5, coverage replays again only after two full runs:
  `composer test` records the graph, and its drift handling drops the coverage cache with the old graph; the next
  preflight records coverage. A preflight before that `composer test` also runs in full, and its coverage is thrown
  away. Minuta measured the sequence: 225s, 148s, 183s, then 18.5s.

## v1.0.4 — 2026-11-04

### Fixes (#26)

- **Preflight no longer records Pest's graph inside the coverage gate.** v1.0.3's recording pass is Pest's TIA
  recorder, which runs Xdebug with no filter: the first preflight after a `composer.lock` change took 722s on the
  software factory instead of about 3.5 minutes, and Minuta's ran past 11 minutes. v1.0.3 said it cost "about one full
  run"; that was never measured. Reverted to v1.0.2's behaviour: with no graph, the coverage gate runs every test,
  filtered by PHPUnit, and the next plain `composer test` records the graph. `tia.record_command` is gone; remove it
  from a published `config/toolkit.php` if you added it.
- **A TIA recording run keeps `vendor/` out of Xdebug.** The Pest plugin sets an Xdebug exclude filter for the
  directories Pest's TIA scope ignores anyway (`vendor`, `node_modules`, `.git`, `storage/framework`,
  `bootstrap/cache`), outside coverage-report runs, where PHPUnit sets its own. The `composer test` that records the
  graph after a lock change gets faster: 13.4s to 6.2s on the starter (plain run 4.8s); 505s to 57s on the software
  factory, as measured in #26. The recorded graph is the same.

## v1.0.3 — 2026-11-04

### Fixes found moving the software factory to v1 (#22)

- **An unattended upgrade publishes no optional files.** `toolkit:install --no-interaction` answered every prompt
  yes, so an app with no deploys or CI got the Telegram deploy command, the deploy scripts and the GitHub workflow.
  With `config/toolkit.php` already in place, those prompts and the static-analysis one default to no.
- **The `phpstan.neon` stub ships.** `.gitignore`'s unanchored `phpstan.neon` also ignored `stubs/phpstan.neon`, so
  `toolkit:install` printed `Can't locate path`. The stub is the starter kit's.
- **`--gate=` no longer says "All gates passed!" beside gates that have not.** A run of some gates names the others
  that have not passed: `✓ Types passed. Not passed yet: Lint, Browser.` The exit code stays 0.
- **The report drops failures of tests that no longer exist.** A run that selects whole files drops an earlier failure
  whose file it ran without that test (a renamed or deleted test); a retry drops one it targeted and did not run. A
  filter, another selection flag, `--bail`, a `--stop-on-*` option or `--shard` keeps them.
- **No false 100% from a v0 coverage cache.** v0's coverage gate counted Browser specs' hits. The coverage stamp now
  records whether its run did, and preflight records coverage afresh when that doesn't match the gate.
- **Preflight records Pest's graph when it has none.** Pest 5 drops its TIA graph whenever `composer.lock` (or
  `phpunit.xml`, the Vite config, the JS lockfile) changes, and a coverage run can't record one, so every preflight
  after an upgrade or a `composer update` ran the whole suite. Preflight now runs `tia.record_command` (default
  `vendor/bin/pest --parallel --toolkit-no-browser`) at the start of the coverage gate when the graph is missing or
  stale; the coverage run then replays from it.

**Upgrading:** nothing to do. The first preflight after the upgrade records coverage and the graph afresh, once.

## v1.0.2 — 2026-11-04

### Fixes found moving Minuta to v1 (Idealogo-dev/minuta-app#210)

- **`--toolkit-retry` works under `--parallel` when the failures span several files.** The retry named one path per
  failed file, and Paratest takes only one, so `composer test:retry` stopped with "Too many arguments" before any
  test ran. It now names one path that holds every failed file: the file itself, or their closest common
  directory. The anchored filter still picks the exact tests, and the asset build check reads the failed files, not
  that directory.
- **`composer test` leaves Browser specs out** (`--toolkit-no-browser`, so TIA stays on). Under Xdebug, beside every
  other worker, they timed out whenever TIA replayed many of them: in Minuta a graph rebuild failed 100 browser specs
  with `Timeout 5000ms exceeded`, while every unit and feature test passed. They run in `composer test:browser` and
  in preflight's `browser` gate, without Xdebug and on fewer workers. **Upgrading:** answer yes to "Standardize
  composer test:* scripts" in `toolkit:install`, or add `--toolkit-no-browser` to the Pest command in your `test`
  script.

### Development

- The package's own suite runs on Pest 5 (`pestphp/pest-plugin-arch` now allows `^5.0`).

## v1.0.1 — 2026-11-04

### Fixes found moving the starter to v1 (nwrman/laravel-starter-kit#37)

- **The workflow stub stamps the downloaded build.** The Pest plugin treats a build without
  `public/build/.buildstamp` as stale and rebuilds it with `vp`, which the stub's PHP job doesn't install, so Pest
  Coverage died before any test ran. Only the toolkit's own build writes the stamp; the artifact is the commit's own
  build, so the job stamps it. An app on an earlier copy of the stub adds the `Stamp Build Artifact` step after its
  download.
- **Load the Vitest reporter only where `vendor/` is installed.** The stub's Frontend Coverage job runs Vitest without
  `composer install`, and Vitest exits before any test when a reporter file is missing. The README and the
  installer's hint now show the `existsSync` guard.
- **`--toolkit-retry` drops failures whose test file is gone.** Naming a deleted file stopped Pest before any test
  ran, and the report then showed a crash notice instead. When none is left, the retry clears them and runs nothing.
- **The `run-preflight` skill** names every watched file (`build.watch_paths`), not only `resources/`.

## v1.0.0 — 2026-11-03

### Test runner v1: nothing that runs tests boots Laravel (#19)

Breaking. Folds in #13, #6 and starter #31.

Every toolkit test command used to run under an artisan parent, which loads `.env`. Its `DB_DATABASE` reached Pest,
and the tests ran against the development database (2,570 failures in one Minuta run, refused only by the starter's
`_test` guard). No toolkit entry point that runs tests boots Laravel any more. Pest runs from the shell, where
`phpunit.xml`'s `APP_ENV=testing` makes Laravel load `.env.testing`.

- **A Pest plugin** (`extra.pest.plugins`) gives every Pest run the failure report, `vendor/bin/pest` included:
  `test-failures.md` / `.json` and the JUnit file, written when the run ends from the JUnit file Paratest merges, the
  coverage gate's run included. A crash or a failure with no failed test says so in the report instead of leaving it
  stale. Each failure gets a command that re-runs exactly that test. A narrow run (one test, a filter) replaces only
  the entries of the tests it ran, so the other failures stay on record. A Pest process that a test starts (the
  starter's guard test runs Pest itself) leaves the report and the JUnit file alone.
- **`--toolkit-retry`** re-runs exactly the failed tests, by file and by a quoted, anchored class-and-name filter.
  The old `toolkit:retry` passed bare names with `addslashes`, so a name ran every test whose name contained it, and
  a `#` or `@` in a name turned into PHPUnit's data-set syntax.
- **`--toolkit-no-browser`** leaves the Browser suite out of a run without making it a partial one, so TIA stays on
  (`--exclude-testsuite` switches it off).
- **The asset build check moved into the plugin.** It runs only when a browser spec could be selected, builds when
  the assets are stale, and puts back `public/hot` when the build deletes it.
- **A Vitest reporter** (`resources/js/vitest-reporter.mjs`) writes frontend failures into the same report, under
  their own heading, and `resources/js/vitest-retry.mjs` re-runs only those.
- **`vendor/bin/preflight`** replaces `php artisan toolkit:preflight`. It never boots Laravel: it reads
  `config/toolkit.php` as plain arrays (the provider's merge, shared) and `.env` only through `Dotenv::parse()` to
  resolve `env()` in that file, never into the environment. Gates inherit the shell's environment.
  - **Fail-fast** (#13): the first failing gate stops the others, with every process they started, all within one
    grace period. Gates that wait for each other in a cycle fail instead of hanging.
  - **A separate `browser` gate**, after the coverage gate, without coverage or Xdebug, on 4 workers. Coverage comes
    from unit and feature tests. Gates take `after`.
  - **`--retry` and `--gate=` print every gate's row**; one this run skipped shows `passed, previous run`.
- **Fewer needless coverage re-records.** Pest writes its coverage cache only when the tests pass (its coverage
  report runs on exit 0 only), so the stamp stays in place while the gate runs (an interrupted run leaves cache and
  stamp as they were), a cache the run rewrote is stamped even when the gate then failed on the coverage minimum, and
  it is stamped with the commit the run started on even if HEAD moved during it.
- **Removed:** `toolkit:preflight`, `toolkit:report` and `toolkit:retry`; the test-command guard hook
  (`bin/enforce-test-command.php`, #6): a raw Pest run now writes the report and uses TIA. `toolkit:install` removes
  the hook from `.claude/settings.json`, never adds `--env=testing`, writes plain `pest` / Vitest test scripts, and
  removes scripts that still call a removed command.
- The "Running Tests" guideline and the `run-preflight` skill are rewritten for the new commands, with a line to cover
  page props with `assertInertia` rather than a browser visit.

**Upgrading an app:** `composer update nwrman/laravel-toolkit`, `php artisan toolkit:install` (standardize the test
scripts), remove `--env=testing` from any script it does not own, add the Vitest reporter to `vitest.config`, and add
feature tests for any lines only browser specs covered.

### A slow or flaky test is a finding, not a timeout to raise

An agent fixed a test that took ~4.5 s under coverage by raising its timeout to 20 s. The testing guideline now says
never to raise a timeout, add a retry, or skip a test to make it pass: find the cause and fix it, or report it. The
`test-quality` checklist gains item 10, so a review catches it too.

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
