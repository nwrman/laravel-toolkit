---
name: run-preflight
description: Run the preflight command to verify all CI gates pass before pushing. Use when the user asks to verify everything is passing, run CI, run preflight, or check before shipping.
---

# Run Preflight

## Overview

`composer preflight` runs every CI gate in parallel, stops at the first failure, and prints a summary table. It is the ship gate: run it **once**,
when the work is done — not after every fix. On failure, fix the root cause and re-run only what failed.

## Workflow

### Step 1 — Run Preflight

```bash
composer preflight
```

This runs the gates in parallel and stops at the first failure, with everything that gate's siblings started:

| Gate | What it checks | Underlying commands |
|------|---------------|---------------------|
| `pest-coverage` | PHP type coverage (100%) + unit and feature coverage (100%), through TIA | `pest --type-coverage --min=100 && pest --parallel --coverage --exactly=100.0 --toolkit-no-browser` |
| `browser` | Browser specs, without coverage, after `pest-coverage` passes | `pest --testsuite=Browser --parallel --processes=4` |
| `frontend-coverage` | Vitest coverage | `bun run test:coverage` |
| `lint` | Code formatting + style | `pint --parallel --test && rector --dry-run && bun run test:lint` |
| `types` | Static analysis + TypeScript | `phpstan && bun run test:types` |

The Pest gates rebuild the frontend assets first when a browser spec could run and `resources/` changed. Use
`--force-build` to force a rebuild.

**If all gates pass** → safe to push. Keep the summary table; it is the evidence.

**If any gate fails** → proceed to Step 2.

### Step 2 — Fix, then re-run only what did not pass

1. Read the failure output: it names the gate that failed and prints the end of its output. For a test gate, read
   `storage/logs/test-failures.md`: each failure with a command that re-runs exactly that test.
2. Fix the root cause in the source files. Confirm it with the narrowest check: the printed command for one test,
   `composer test:retry` for every failed test.
3. Re-run the gates that did not pass (failed, stopped, or not run):

```bash
vendor/bin/preflight --retry
```

The table still shows every gate: the ones that passed earlier read `passed, previous run`, so the table after
`--retry` is the full evidence. Repeat until they pass.

To check one gate while you are still fixing it, name it: `vendor/bin/preflight --gate=lint` (or `types`,
`pest-coverage`, `browser`, `frontend-coverage`). A lint or type fix is confirmed by its own gate.

Run the full `composer preflight` again only if the fix touched code another gate checks — a refactor that moved
logic, not a formatting or type annotation fix.

## Quick Reference

| Command | Purpose |
|---------|---------|
| `composer preflight` | All gates in parallel, fail-fast — once, at the end |
| `vendor/bin/preflight --retry` | Only the gates that did not pass last run |
| `vendor/bin/preflight --gate=<name>` | One gate (repeat the flag for more) |
| `composer test:retry` | Only the tests that failed last run |
| `vendor/bin/preflight --force-build` | Force a frontend rebuild first |
| `vendor/bin/preflight --no-notify` | Suppress the macOS notification |
| `composer test:ci` | What CI runs, serially, every test from scratch — the "would CI pass?" check |

## Common Mistakes

- **Re-running the whole gate to confirm a narrow fix** — the most expensive habit there is: minutes per run, several
  runs per task. Re-run the failed gate, or the one failing test with the command the report prints.
- **Re-running preflight to get a complete table** — `--retry` and `--gate=` already print every gate's row.
- **Using `composer test:ci` as the fix loop** — it runs serially and measures every test, so it is the slowest way to
  learn about a lint error. It is for one "would CI pass?" check, not for iterating.
- **Not reading the failure output** — each failed gate prints actionable details, and test gates write the report.
  Read them before editing files.
