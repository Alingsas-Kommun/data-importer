# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin ("Data Importer & Visualizer"). It receives JSON over a per-source REST endpoint, stores records in custom DB tables, and renders them on the front end via PHP templates invoked through a `[data_importer]` shortcode. PHP namespace root is `DataImporter\`, mapped via Composer PSR-4 from `src/php/`.

Full behavior and the shortcode/filter reference are documented in `README.md` and `SECURITY.md` — read those for feature-level and security-model detail rather than re-deriving it from code.

## Commands

Install dependencies:
```bash
composer install
npm install
```

Run tests (from the plugin root):
```bash
composer test            # vendor/bin/phpunit
composer test:verbose    # --testdox output
composer test:list       # list discovered tests without running
```

Run a single test file or filter:
```bash
vendor/bin/phpunit --testdox tests/Cases/RestGuardrailsTest.php
vendor/bin/phpunit --filter test_method_name
```

Frontend assets (Vite, builds `src/js` + `src/scss` into `dist/`):
```bash
npm run build   # one-off production build
npm run dev      # rebuild on change
```

i18n (requires WP-CLI, run from the plugin dir inside a WP install):
```bash
npm run i18n     # regenerates .pot, updates .po, compiles .mo under languages/
```

Release build (creates a versioned `release/X.Y.Z` branch with compiled assets + prod deps committed, dev files stripped, and a git tag):
```bash
php build-release.php --dry-run   # preview only
php build-release.php             # actually cuts the release branch/tag
```
`build-release.php` reads the version from `data-importer.php` but does not bump it, maintain a
changelog, or publish anything. For the full release process (version bump across `data-importer.php`
+ `composer.json`, `CHANGES.md` entry, running this script, tagging, and `gh release create`), use
the `release` skill (`.claude/skills/release/SKILL.md`) rather than doing these steps ad hoc.

## Test environment requirement

**Tests only run inside a real WordPress install**, not standalone. `tests/bootstrap.php` resolves the WP root as three directories up from the plugin root (`dirname(dirname(dirname($plugin_root)))`) and requires its `wp-load.php` — i.e. the plugin must live at `wp-content/plugins/data-importer/` inside an actual WordPress checkout with a working DB connection. The bootstrap then activates and boots the plugin and initializes the real REST server (`rest_get_server()` + `rest_api_init`), so tests exercise real REST routing, real DB tables, and real WP user/capability state — there is no mocking layer. If tests fail with "cannot find wp-load.php" or DB connection errors, the issue is the environment/location, not the test code.

`tests/Support/PluginIntegrationTestCase.php` is the shared base class — it has helpers for creating sources/templates/admin users, dispatching REST import and `/fields` requests, importing records directly, and reading back records/logs. Reuse these helpers rather than hand-rolling WP REST requests in new tests.

See `TESTS.md` for what each test file covers and the reasoning behind the suite's structure (why it favors integration tests over isolated units, and what's intentionally still uncovered — admin JS, admin routing/redirects, schema migration/uninstall).

## Architecture

Everything under `src/php/` is one of five layers, wired together in `Plugin::boot()`:

- **`Api/RestController.php`** — registers `POST /wp-json/data-importer/v1/import/{slug}` and `GET /wp-json/data-importer/v1/fields/{slug}`. Owns all import-time enforcement: API key auth (hashed at rest, verified via `Database::verify_source_api_key`), per-key IP/CIDR allowlists (`Support/IpRules.php`), content-type check, payload-size and record-count guardrails, rate limiting (WP transients), and security/import audit logging. `import_source_records()` is the shared entry point for turning a decoded payload into DB writes (replace/append/upsert) — both the REST handler and the admin manual-import path (`Admin/ManualImportProcessor.php`) call into it, so import-mode logic lives in one place.
- **`Frontend/Display.php`** (largest file, ~1600 lines) — owns the `[data_importer]` shortcode end to end: resolving source/template, decoding stored records, building/applying shortcode filters (`where_*`) and sort rules (`sort`, `sort_key`/`sort_type`/`sort_order`/`sort_empty`), enqueueing per-template CSS/JS assets exactly once per handle, and executing the PHP template itself. Template execution (`eval_php`, `dry_run_template`, `evaluate_fragment`) is sandboxed by `validate_template_code()`, which regex-blocks a configurable function list (`data_importer_blocked_template_functions`) and a construct list covering backticks/`include`/`require` (`data_importer_blocked_template_constructs`) — see `SECURITY.md` before touching this path. Safe mode (`Plugin::is_safe_mode_active()`) short-circuits all template execution here.
- **`Admin/`** — wp-admin UI: `AdminPage.php` is the top-level page/menu controller, `Tabs/*.php` render each tab (General/API/Data/Manual/Template/Log/About) for a source, `Views/*.php` render list/edit/new-source screens, and `SourceFormHandler.php` / `TemplateFormHandler.php` process the corresponding admin form submissions (including template dry-run validation before persisting).
- **`Infrastructure/Database.php`** (largest single responsibility surface, ~1500 lines) — the only place that touches the plugin's custom tables (sources, source API keys, templates, records). Table name getters (`get_*_table()`) are the canonical way to reference them; don't hardcode `$wpdb->prefix . 'data_importer_...'` elsewhere. Holds `create_table()`/`maybe_upgrade()` (schema versioned via `DATAIMPORTER_DB_VERSION` in `data-importer.php`), the three import strategies (`replace_all_records`, `append_records`, `upsert_records`), API key hashing/rotation, and `get_fields()` (flattened field-name discovery used by the `/fields` endpoint).
- **`Support/`** — small stateless helpers shared across layers: `Request.php` (client IP resolution, honoring `X-Forwarded-For` only behind the `data_importer_trust_proxy` filter), `IpRules.php` (IPv4/IPv6 CIDR matching), `Assets.php` (deterministic enqueue-handle generation so duplicate template asset URLs across shortcode instances don't double-enqueue or collide).

`Plugin.php` is the composition root: `boot()` wires `RestController`, `Display`, and (when `is_admin()`) `AdminPage` as singletons, and registers the shortcode. It also owns safe-mode resolution (`DATA_IMPORTER_SAFE_MODE` constant → `data_importer_safe_mode` option → `data_importer_safe_mode_active` filter, in that precedence) and the `data_importer_capability` filter gate used throughout the admin and REST layers.

Almost everything in this plugin is tunable via WordPress filters rather than config files — payload/record/rate limits, blocked template functions/constructs, capability required, proxy trust, safe mode. When adding a new limit or policy knob, follow that existing pattern (a filter with a sane default) instead of a hardcoded constant.

## Key behaviors to preserve when changing import/template code

- Import mode logic (`replace`/`append`/`upsert`) is shared between REST and manual import via `RestController::import_source_records()` — don't fork it per entry point.
- API key secrets are never stored in plaintext; only hashes persist (`Database::build_source_api_key_storage_payload` / `rehash_source_api_key`).
- Template saves go through a dry-run (`Display::dry_run_template`) before persisting — a template that fails dry-run must not be written to the DB.
- IP allowlist rules are scoped per API key, not per source.
