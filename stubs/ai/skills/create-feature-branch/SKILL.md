---
name: create-feature-branch
description: Use when starting new work that needs a feature branch. Activates when user wants to begin a new feature, fix, refactor, or any scoped work item. Also use when user mentions starting a new PRD phase.
---

# Create Feature Branch

## Overview

Create a properly named feature branch from an up-to-date `main`. Enforces naming conventions and a clean working tree.

## Workflow

### 1. Guard: Clean Working Tree

```bash
git status --porcelain
```

If output is non-empty, **stop**:

```
Working tree is dirty. Please commit or stash your changes before creating a new branch.
```

### 2. Update Main

```bash
git checkout main
git pull origin main
```

### 3. Determine Branch Name

Ask the user what they're working on, then construct the branch name:

**Prefix** — match the type of work:

| Prefix | Use for |
|--------|---------|
| `feature/` | New functionality |
| `fix/` | Bug fixes |
| `docs/` | Documentation only |
| `refactor/` | Code restructuring |
| `chore/` | Maintenance, deps, config |

**Name** — kebab-case, descriptive:

- `feature/member-management`
- `fix/login-redirect`
- `docs/api-documentation`

**PRD convention** — when the work relates to a PRD phase, suggest (don't enforce):

- `feature/phase-02-member-management`
- `feature/phase-06-cfdi-signing`

### 4. Create Branch

```bash
git checkout -b <type>/<name>
```

### 5. Confirm

```
Branch <type>/<name> created from main.
Ready to start working.
```

## Quick Reference

| Step | Command | Blocks on failure? |
|------|---------|-------------------|
| Clean check | `git status --porcelain` | Yes |
| Update main | `git checkout main && git pull` | Yes |
| Create branch | `git checkout -b <name>` | — |

## Common Mistakes

- **Branching from a stale `main`** — always pull before branching.
- **Branching from another feature branch** — always go back to `main` first.
- **Vague branch names** — `feature/stuff` tells nothing. Use descriptive names.

## Integration

**Followed by:** `finish-feature-branch` when work is complete.
