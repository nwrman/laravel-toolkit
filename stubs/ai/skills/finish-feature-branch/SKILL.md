---
name: finish-feature-branch
description: Use when implementation is complete on a feature branch and you need to create a pull request. Activates when user says "finish", "create PR", "open pull request", "ready for review", or work on a feature branch is done.
---

# Finish Feature Branch

## Overview

Run tests, push the branch, and create a GitHub PR with an auto-generated description. Blocks if tests fail.

## Workflow

### 1. Guard: Clean Working Tree

```bash
git status --porcelain
```

If output is non-empty, **stop**:

```
Working tree is dirty. Please commit your changes before finishing the branch.
```

### 2. Guard: Not on Main

```bash
git branch --show-current
```

If on `main`, **stop**:

```
You're on main. Switch to the feature branch you want to finish.
```

### 3. Run Tests

```bash
composer test
```

This runs unit, feature, browser, and frontend tests.

**If any test fails, stop:**

```
Tests failing. Fix failures before creating a PR.

[Show failures]
```

**Do not proceed to step 4 until all tests pass.**

### 4. Push Branch

```bash
git push -u origin $(git branch --show-current)
```

### 5. Generate PR Body

Build the PR description from the branch's commit history:

```bash
# Get commits unique to this branch
git log main..HEAD --oneline
```

Structure the PR body:

```markdown
## Summary
<2-3 sentence description of what this branch accomplishes>

## Changes
<list of commits from git log, formatted as bullet points>

## Testing
- [x] `composer test` — all passing

## Related
Closes #<N> (only if commits reference an issue number)
```

### 6. Create PR

```bash
gh pr create \
  --base main \
  --title "<conventional-commit-style title>" \
  --body "<generated body>"
```

The PR title should follow conventional commit format based on the branch prefix:

| Branch prefix | PR title example |
|--------------|-----------------|
| `feature/` | `feat: <description>` |
| `fix/` | `fix: <description>` |
| `docs/` | `docs: <description>` |
| `refactor/` | `refactor: <description>` |
| `chore/` | `chore: <description>` |

### 7. Confirm

```
PR created: <url>

Staying on branch <name>. Use land-feature-branch to merge after approval.
```

**Stay on the feature branch** — do not switch to `main`.

## Quick Reference

| Step | Command | Blocks on failure? |
|------|---------|-------------------|
| Clean check | `git status --porcelain` | Yes |
| Branch check | `git branch --show-current` | Yes (if `main`) |
| Tests | `composer test` | Yes |
| Push | `git push -u origin <branch>` | Yes |
| Create PR | `gh pr create` | — |

## Merge Convention

This project uses **merge commits** (not squash) to preserve the full commit history and decision trail. This is configured at the GitHub repo level, not enforced by this skill.

## Common Mistakes

- **Skipping tests** — never create a PR with failing tests.
- **Pushing before testing** — test first, push second.
- **Vague PR titles** — use conventional commit format for the title.

## Integration

**Preceded by:** `create-feature-branch`
**Followed by:** `land-feature-branch` when PR is approved.
