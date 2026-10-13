---
name: run-preflight
description: Run the preflight command to verify all CI gates pass before pushing. Use when the user asks to verify everything is passing, run CI, run preflight, or check before shipping.
---

# Run Preflight

## Overview

`composer preflight` runs every CI gate in parallel and prints a summary table. It is the ship gate: run it **once**,
when the work is done — not after every fix. On failure, fix the root cause and re-run only what failed.

## Workflow

### Step 1 — Run Preflight

```bash
composer preflight
```

This runs 4 gates in parallel:

| Gate | What it checks | Underlying commands |
|------|---------------|---------------------|
| `pest-coverage` | PHP type coverage (100%) + test coverage (100%) | `pest --type-coverage --min=100 && pest --parallel --coverage --exactly=100.0` |
| `frontend-coverage` | Vitest coverage (85% threshold) | `bun run test:coverage` |
| `lint` | Code formatting + style | `pint --parallel --test && rector --dry-run && bun run test:lint` |
| `types` | Static analysis + TypeScript | `phpstan && bun run test:types` |

The command also checks if frontend assets need rebuilding before running tests. Use `--force-build` to force a rebuild.

**If all gates pass** → safe to push. Keep the summary table; it is the evidence.

**If any gate fails** → proceed to Step 2.

### Step 2 — Fix, then re-run only what failed

1. Read the failure output — it names the gate that failed and prints the end of its output.
2. Fix the root cause in the source files.
3. Re-run the failed gates only:

```bash
php artisan toolkit:preflight --retry
```

`--retry` reads the saved state and re-runs only the gates that failed last time. Repeat until they pass.

To check one gate while you are still fixing it, name it: `php artisan toolkit:preflight --gate=lint` (or `types`,
`pest-coverage`, `frontend-coverage`). A lint or type fix is confirmed by its own gate; it never needs the test suite.

Run the full `composer preflight` again only if the fix touched code another gate checks — a refactor that moved
logic, not a formatting or type annotation fix.

## Quick Reference

| Command | Purpose |
|---------|---------|
| `composer preflight` | All gates in parallel — once, at the end |
| `php artisan toolkit:preflight --retry` | Only the gates that failed last run |
| `php artisan toolkit:preflight --gate=<name>` | One gate (repeat the flag for more) |
| `composer preflight -- --force-build` | Force a frontend rebuild first |
| `composer preflight -- --no-notify` | Suppress the macOS notification |
| `composer test:ci` | What CI runs, serially, every test from scratch — the "would CI pass?" check |

## Common Mistakes

- **Re-running the whole gate to confirm a narrow fix** — the most expensive habit there is: minutes per run, several
  runs per task. Re-run the failed gate, or the one failing test with `php artisan test --filter`.
- **Using `composer test:ci` as the fix loop** — it runs serially and measures every test, so it is the slowest way to
  learn about a lint error. It is for one "would CI pass?" check, not for iterating.
- **Skipping preflight before pushing** — CI will catch the same issues, but later.
- **Not reading the failure output** — each failed gate prints actionable details. Read them before editing files.
