---
name: run-preflight
description: Run the preflight command to verify all CI gates pass before pushing. Use when the user asks to verify everything is passing, run CI, run preflight, or check before shipping.
---

# Run Preflight

## Overview

`composer preflight` runs all CI gates in parallel (~40s vs ~140s sequential). This is the primary pre-push verification command. Run it before pushing code. On failure, fix the root cause and use `--retry` to re-run only the failed gates.

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

**If all gates pass** → safe to push.

**If any gate fails** → proceed to Step 2.

### Step 2 — Fix and Retry

1. Read the failure output — it shows exactly which gate failed and the last 40 lines of output.
2. Fix the root cause in the source files.
3. Re-run only the failed gates:

```bash
composer preflight -- --retry
```

This reads the saved state file and only re-runs gates that failed last time. Repeat until all gates pass.

### Targeting Specific Gates

When iterating on a fix, run only the relevant gate to get faster feedback:

```bash
composer preflight -- --gate lint              # Just lint
composer preflight -- --gate pest-coverage     # Just PHP tests + coverage
composer preflight -- --gate lint --gate types # Multiple specific gates
```

Then run the full `composer preflight` once the targeted gate passes.

## Quick Reference

| Command | Purpose |
|---------|---------|
| `composer preflight` | Run all 4 gates in parallel |
| `composer preflight -- --retry` | Re-run only previously failed gates |
| `composer preflight -- --gate <name>` | Run specific gate(s) |
| `composer preflight -- --force-build` | Force frontend rebuild before running |
| `composer preflight -- --no-notify` | Suppress macOS notification |
| `composer test:ci` | Sequential equivalent (mirrors CI exactly) |

## Common Mistakes

- **Skipping preflight before pushing** — CI will catch the same issues but slower. Always run `composer preflight` first.
- **Running `composer test:ci` locally instead of preflight** — `test:ci` runs sequentially (~140s). Preflight runs the same checks in parallel (~40s).
- **Only re-running the failed gate** — after fixing, use `composer preflight -- --retry` rather than `--gate`. Retry re-runs only the failures from the last run, which is what you want. Follow up with a full `composer preflight` if you changed code that could affect multiple gates.
- **Not reading the failure output** — each failed gate prints actionable details. Read them before editing files.
