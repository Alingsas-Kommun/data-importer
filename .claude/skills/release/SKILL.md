---
name: release
description: Cut a new version of the Data Importer & Visualizer plugin - bump the version, update CHANGES.md, commit, run build-release.php to produce the release/X.Y.Z branch, tag, push, and publish a GitHub release. Use when the user asks to "release", "cut a version", "bump the version", or "tag a release".
---

# Cutting a release

This plugin already has a build mechanism (`build-release.php`) that bakes compiled assets and
production Composer dependencies into a dedicated `release/X.Y.Z` branch and applies a git tag —
run it as-is, don't reimplement it. What it does **not** do is bump the version number, maintain a
changelog, or publish the GitHub release; that's what this skill adds around it.

Version lives in **two** places and must move together:
- `data-importer.php` — both the `* Version: X.Y.Z` header comment and the
  `define( 'DATAIMPORTER_VERSION', 'X.Y.Z' )` constant just below it.
- `composer.json` — `"version": "X.Y.Z"`.

Do not touch:
- `DATAIMPORTER_DB_VERSION` in `data-importer.php` — this tracks the DB schema, not the plugin
  release, and only moves when `Database::create_table()` / `maybe_upgrade()` actually changes the
  table shape. Bump it only if this release includes a schema change, as its own concern.
- `package.json` / `package-lock.json` — these have no `version` field (the package is
  `"private": true`, npm is only used for the Vite asset build), so there is nothing to sync there.
- `languages/data-importer.pot` — regenerated from the plugin header via `npm run i18n`, not hand-edited.

## 1. Figure out the next version

```bash
git tag                                  # existing tags: 1.0.0-1.0.4 so far
git log --oneline <last-content-commit>..HEAD   # unreleased changes
```

Note: each tag points at the `release/X.Y.Z` build commit, which isn't reachable from `main`'s
history in the normal sense (it's a sibling branch built off the source commit). To see unreleased
work, diff against the last commit on `main` that actually bumped the version
(`git log -- data-importer.php` and look for the last `Version:` change), not against the tag
itself.

Pick the bump by semver: bug fixes only → patch, backward-compatible features → minor, breaking
change (renamed filter/hook, changed REST route/response shape, changed shortcode attribute
behavior) → major. When in doubt, ask the user rather than guessing.

## 2. Draft the CHANGES.md entry — confirm before writing

Summarize the unreleased commits into **user-facing** bullets (not implementation detail — e.g.
"Added multi-field sorting to the shortcode", not "refactored apply_record_sort()"). Group under
`### Added` / `### Changed` / `### Fixed` as needed. Show the user the draft entry and the chosen
version number before proceeding — this is the one judgment call in the process worth a pause.

## 3. Apply the version bump

Edit directly (no script does this):
- `data-importer.php`: `* Version: X.Y.Z` and `define( 'DATAIMPORTER_VERSION', 'X.Y.Z' );`
- `composer.json`: `"version": "X.Y.Z"`

Insert the confirmed changelog entry at the top of `CHANGES.md` (after the header, before the
previous latest entry).

## 4. Verify

```bash
php -l data-importer.php
composer test
```

(`build-release.php` runs its own `npm run build` in step 5 below and aborts on failure, so there's
no need to build separately here.)

## 5. Commit

Mirror this repo's existing pattern: actual behavior changes get their own commit(s), committed as
part of the normal course of work *before* starting a release — don't bundle a feature/fix into the
release commit. The release commit touches only the version files and changelog:

```bash
git add data-importer.php composer.json CHANGES.md
git commit -m "Release X.Y.Z"
git push origin main
```

## 6. Build the release branch

```bash
php build-release.php --dry-run   # sanity-check what it will do first
php build-release.php             # builds release/X.Y.Z with assets + prod deps, tags X.Y.Z
```

This reads the version straight from `data-importer.php`, so it must run *after* step 3's commit is
in place. It creates/resets `release/X.Y.Z` off the current branch, installs prod-only Composer
deps, builds and commits the Vite assets (including `dist/`, which is otherwise gitignored on
`main`), strips dev-only files, and creates the annotated tag `X.Y.Z`.

## 7. Push — confirm before this step

This pushes to the shared remote and publishes a public release; confirm with the user before
running it (unless they already explicitly asked for the full release including publish in this
same request).

```bash
git push origin release/X.Y.Z --tags
```

## 8. Publish the GitHub release

```bash
gh release create X.Y.Z --repo Alingsas-Kommun/data-importer \
  --title "vX.Y.Z" \
  --notes "$(cat <<'EOF'
- ...
EOF
)"
```

Matches this repo's established convention (releases `1.0.0`–`1.0.4`): title is `vX.Y.Z` (with the
`v` prefix — note this differs from the bare tag name itself, which has none), notes are a plain
bullet list of the CHANGES.md entry's content without the `## [X.Y.Z] - date` heading, and no
`--target` is needed since the tag from step 6 already exists and points at the `release/X.Y.Z`
commit carrying the built assets.

After publishing, if this isn't the newest version overall (e.g. backfilling an older release),
GitHub's "Latest" marker follows publish date, not version order — check
`gh release list --repo Alingsas-Kommun/data-importer` and if the wrong one is marked Latest, fix it
with `gh release edit <newest-tag> --repo Alingsas-Kommun/data-importer --latest`.
