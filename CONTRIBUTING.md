# Contributing

## Release ritual — Filament snapshot

The canonical Filament admin shape is developed in the [`nwrman/nwrman-filament-reference`](https://github.com/nwrman/nwrman-filament-reference) repo (a real Laravel app) and snapshotted into this package's `resources/filament-snapshot/` directory. The `starter:install-filament` command ships those snapshot files to consumer apps.

### When to re-snapshot

Any time the canonical Filament shape changes:

- New or removed resource, page, or widget
- Changed `AdminPanelProvider` configuration
- Updated migration for admin-only columns
- Filament version bump (in the reference app's `composer.json`)

### How to re-snapshot

#### Option 1 — Locally (faster inner loop)

```bash
# From this repo root
composer snapshot:filament -- --from=../nwrman-filament-reference

# Review the diff
git diff resources/filament-snapshot/

# Commit + push + tag if ready
git checkout -b snapshot/filament-$(date +%Y%m%d)
git add resources/filament-snapshot/
git commit -m "chore: snapshot filament from reference@<ref>"
git push -u origin HEAD
```

The local and CI snapshot invocations run the same script (`bin/snapshot-filament.php`) with the same include list (`FilamentSnapshotter::INCLUDED_PATHS`), so output is byte-identical across machines.

#### Option 2 — CI (audit trail, automated PR)

1. Ensure the reference app has a tag you want to snapshot (e.g., `v0.2.0`).
2. Go to **Actions** → **snapshot-filament** → **Run workflow**.
3. Inputs:
   - `reference_ref`: the reference app tag/branch/SHA (e.g., `v0.2.0`, `main`, `abc1234`)
   - `reference_repo`: `nwrman/nwrman-filament-reference` (default)
4. The workflow opens a PR in this repo with the snapshot diff. Review, merge, tag a new toolkit release.

### Release flow end-to-end

1. Iterate Filament shape in `nwrman-filament-reference`. Commit. Tag (e.g., `v0.2.0`).
2. Trigger `snapshot-filament` workflow in this repo with `reference_ref=v0.2.0`.
3. Review the generated PR. Merge.
4. Tag this repo (e.g., `v1.5.0`) — packagist picks it up automatically.
5. Consumers who `composer update nwrman/laravel-toolkit` get the new installer capabilities.
6. Consumers who already ran `starter:install-filament` are unaffected — they own their `app/Filament/` files and can evolve them independently.

### Rebaselining the reference app

When this toolkit's base starter evolves (new Laravel version, new starter package, new middleware), the reference app drifts behind. To re-baseline:

```bash
# Blow away the local directory
rm -rf ~/Herd/nwrman-filament-reference

# Recreate from the current starter
composer create-project nwrman/laravel-starter-kit ~/Herd/nwrman-filament-reference --stability=dev
cd ~/Herd/nwrman-filament-reference

# Re-apply Filament
composer require filament/filament
php artisan filament:install --panels
php artisan make:filament-resource User --generate

# Re-apply the shape customizations (see the reference app's git log for a checklist):
#   - Add is_admin migration
#   - Update User model: implement FilamentUser, add canAccessPanel, add is_admin cast
#   - Replace DatabaseSeeder with AdminUserSeeder
```

The `resources/filament-snapshot/` in this repo becomes the source of truth for which files need to exist in the reference app — you can compare `find app/Filament -type f` after rebasing against what the snapshot contains.

## Testing

```bash
composer test       # Full pest suite (73 tests)
composer analyse    # PHPStan level max
composer format     # Pint
```

The snapshotter (`tests/Snapshot/`) and installer (`tests/Installer/`) tests are pure PHP — they don't need the Orchestra Testbench app. Command tests (`tests/Commands/`) use Testbench via `tests/Pest.php`.
