---
name: land-feature-branch
description: Use when a pull request has been approved and needs to be merged into main. Activates when user says "land", "merge PR", "merge pull request", "land the branch", or wants to complete the PR lifecycle and clean up.
---

# Land Feature Branch

## Overview

Merge an approved PR into `main` via GitHub, then clean up local branches and remote refs.

## Workflow

### 1. Determine Which PR to Land

**If on a feature branch:**

```bash
branch=$(git branch --show-current)
```

Use the current branch's PR.

**If on `main` or another branch:**

List open PRs and ask the user:

```bash
gh pr list --state open
```

```
Which PR would you like to land? (enter PR number)
```

### 2. Verify PR is Mergeable

```bash
gh pr view <number> --json state,mergeable,mergeStateStatus,reviewDecision
```

Check:
- `state` is `OPEN`
- `mergeable` is `MERGEABLE`

**If not mergeable, stop:**

```
PR #<N> is not mergeable. Resolve conflicts or get approval first.
```

### 3. Merge the PR

```bash
gh pr merge <number> --merge --delete-branch
```

- `--merge` — merge commit to preserve full commit history.
- `--delete-branch` — deletes the remote branch on GitHub.

### 4. Switch to Main and Pull

```bash
git checkout main
git pull origin main
```

### 5. Clean Up Local Branch

```bash
git branch -d <feature-branch>
```

If the branch can't be deleted (not fully merged), report the issue and stop — don't force delete.

### 6. Prune Stale Remote Refs

```bash
git remote prune origin
```

### 7. Confirm

```
PR #<N> merged into main.
Local branch <name> deleted.
Remote refs pruned.

You're on main, up to date.
```

## Quick Reference

| Step | Command | Blocks on failure? |
|------|---------|-------------------|
| Find PR | `gh pr list` or current branch | — |
| Verify mergeable | `gh pr view --json` | Yes |
| Merge | `gh pr merge --merge --delete-branch` | Yes |
| Switch | `git checkout main && git pull` | Yes |
| Local cleanup | `git branch -d <branch>` | Report only |
| Remote cleanup | `git remote prune origin` | — |

## Common Mistakes

- **Force-deleting branches** — use `-d` not `-D`. If it fails, something is wrong.
- **Forgetting to pull after merge** — local `main` won't have the merged commits.
- **Leaving stale remote refs** — `git remote prune origin` keeps things tidy.
- **Merging without checking status** — always verify the PR is mergeable first.

## Integration

**Preceded by:** `finish-feature-branch` (creates the PR)
**Completes the lifecycle:** `create-feature-branch` → `finish-feature-branch` → `land-feature-branch`
