# Changelog

All notable changes to Data Importer & Visualizer are documented in this file.

Format loosely follows [Keep a Changelog](https://keepachangelog.com/). Entries from before this
file existed were reconstructed from git history (tags `1.0.0`–`1.0.4`); entries from here on are
added by the release process (see `.claude/skills/release/`).

## [1.0.4] - 2026-06-15

### Added
- Shortcode sorting: `sort`, `sort_key`, `sort_type`, `sort_order`, and `sort_empty` attributes,
  including multi-field sorts and dot-notation nested keys.

## [1.0.3] - 2026-05-24

### Added
- Free-form custom style/script fields for templates.
- `$record_index`, `$is_first`, and `$is_last` metadata available in per-row template rendering.

### Changed
- Hardened style/script asset handle generation and frontend enqueueing to avoid handle collisions.
- Removed legacy database migration code and refreshed plugin metadata.

### Fixed
- Template validation badge no longer gets stuck instead of clearing.
- Log tab links now route to the correct location.

## [1.0.2] - 2026-05-12

### Added
- Plugin license file (GPL-2.0-or-later).

### Fixed
- Uninstall routine no longer fatals while cleaning up per-source import log options.

## [1.0.1] - 2026-04-23

### Added
- Swedish (sv_SE) translations.

## [1.0.0] - 2026-04-23

Initial release: REST API import per source, PHP template rendering via the `[data_importer]`
shortcode, `replace`/`append`/`upsert` import modes, API key authentication with IP/CIDR
allowlisting, rate limiting, safe mode, and import/security/template-error logging.
