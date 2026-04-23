# Tests

This plugin uses PHPUnit integration tests to verify the parts of the system
that matter most: importing data, protecting endpoints, rendering shortcode
output, preserving audit logs, and preventing destructive admin mistakes.

Most of the plugin logic is tightly coupled to WordPress state, database
tables, REST routing, current users, options, and transients. Because of that,
the suite intentionally leans toward integration tests instead of very isolated
unit tests. The goal is to test the plugin the way it actually runs.

## What the suite is trying to protect

The test suite is designed around a few core risks:

1. Data loss or incorrect imports.
2. Unauthorized or abusive access to the REST API.
3. Broken frontend rendering or filtering behavior.
4. Missing or incorrect audit/security logs.
5. Admin actions deleting or mutating the wrong records.

If a change can break one of those areas, it should usually have a test.

## How the tests run

The bootstrap in [tests/bootstrap.php](/Users/michaelclaesson/Sites/wordpress.local/wp-content/plugins/data-importer/tests/bootstrap.php) does four important things:

1. Loads Composer autoloading.
2. Loads WordPress through `wp-load.php`.
3. Activates and boots the plugin.
4. Initializes the REST server and registered routes.

That means the tests run against the real plugin code inside a real WordPress
runtime, backed by the local WordPress database.

## Shared test harness

[tests/Support/PluginIntegrationTestCase.php](/Users/michaelclaesson/Sites/wordpress.local/wp-content/plugins/data-importer/tests/Support/PluginIntegrationTestCase.php)
is the base class for the suite.

It provides helpers for:

- Creating data sources and templates.
- Creating administrator users.
- Dispatching REST import requests.
- Dispatching raw-body REST requests for malformed JSON cases.
- Dispatching `/fields` REST requests.
- Importing records directly through shared plugin logic.
- Reading decoded records from the plugin tables.
- Reading import, security, and template-error logs.

Why this matters:

- It keeps test setup consistent.
- It reduces copy/paste across test files.
- It makes the tests read like behavior specifications instead of low-level
  WordPress plumbing.

## Current test files

### `ManualImportProcessorTest.php`

This file covers the admin-side manual JSON import workflow.

What it tests:

- Manual JSON import writes records successfully.
- Manual imports persist the chosen import mode and update key.
- Manual imports store user and IP context in the import log.
- Invalid JSON is rejected and does not insert records.

Why this matters:

- The manual import path is an admin-facing fallback when REST is not the
  desired entry point.
- It shares import logic with the REST controller, but still has its own input
  validation and audit behavior.
- If this breaks, admins can import bad data or lose traceability about who ran
  an import.

### `RestImportTest.php`

This file covers the normal success paths for REST imports.

What it tests:

- `append` mode adds records without removing existing ones.
- `replace` mode removes old records and inserts the new set.
- Request-time `mode` overrides the source's configured mode.
- `upsert` mode updates matching records and inserts new ones.

Why this matters:

- These are the plugin's core business rules.
- A regression here can silently duplicate, overwrite, or fail to update data.
- Import mode bugs are especially dangerous because they can look successful
  while corrupting the stored dataset.

### `RestSecurityAndLoggingTest.php`

This file covers REST authentication, source-level IP restrictions, and audit
logging.

What it tests:

- Source-specific allowed IP lists are enforced.
- CIDR notation (IPv4 and IPv6) is supported in allowed IP lists.
- IP rules are scoped per API key, not per source — a secondary key can allow a different IP range than the primary key.
- Requests with the wrong API key are rejected.
- API keys are stored as hashes, not plaintext — the plain secret is never persisted.
- Regenerating an API key invalidates the previous secret.
- Successful imports are logged.
- Failed security checks are logged with the correct event, status, and IP.

Why this matters:

- The import endpoint is intentionally open to machines, not logged-in users.
- That makes API-key and IP enforcement the main security boundary.
- Per-key IP scoping allows deployments to grant multiple callers different network trust levels.
- Hashing prevents secret exposure if the database is compromised.
- Logging matters because operations and abuse investigations depend on it.

### `RestGuardrailsTest.php`

This file covers the defensive branches around the REST/import pipeline.

What it tests:

- Invalid JSON request bodies are rejected.
- Payload-size limits are enforced.
- Record-count limits are enforced.
- Rate limiting works.
- Scalar payloads are rejected by the shared import routine.
- Non-object array items are rejected by the REST path.
- `upsert` mode requires an update key.
- The `/fields` endpoint requires management capability.
- The `/fields` endpoint returns flattened field names for administrators.

Why this matters:

- These are the "don't let bad input or abusive traffic through" protections.
- They tend to be easy to regress because they live in edge-path code rather
  than happy-path code.
- Without these tests, a change can accidentally remove a safeguard while all
  normal import tests still pass.

### `ShortcodeDisplayTest.php`

This file covers baseline shortcode rendering.

What it tests:

- The shortcode can render a specific template by slug.
- The shortcode falls back to the default template when no template is given.

Why this matters:

- This is the simplest proof that imported data can actually be rendered for
  site visitors.
- Template selection is a fundamental feature of the plugin's display layer.

### `ShortcodeFilteringTest.php`

This file covers shortcode filtering and query-like behavior.

What it tests:

- Filter operators (including default `eq` when no operator is specified):
  - `eq`
  - `neq`
  - `contains`
  - `ncontains`
  - `gt`
  - `gte`
  - `lt`
  - `lte`
  - `in`
  - `nin`
  - `starts_with`
  - `ends_with`
  - `empty`
  - `not_empty`
- Compact `where` syntax.
- Nested key filtering such as `address.city`.
- Ordering, pagination, and record-id selection through shortcode attributes.

Why this matters:

- These are user-facing behaviors that site builders depend on in content.
- Filtering bugs can be subtle: the shortcode may still render, but show the
  wrong records.
- This area is particularly valuable to lock down because it contains many
  operator branches and input combinations.

### `SafeModeAndTemplateLogTest.php`

This file covers safe mode and template runtime error logging.

What it tests:

- Global safe mode disables PHP template execution.
- Template runtime failures are written to the template error log.

Why this matters:

- PHP templates are powerful, but they are also the riskiest runtime feature in
  the plugin.
- Safe mode is the emergency brake.
- Template error logs are the main observability tool when a template breaks.

### `TemplateDeletionAndSourceCleanupTest.php`

This file covers destructive admin/data cleanup behavior.

What it tests:

- A template delete request cannot delete a template that belongs to another
  source.
- Deleting a source removes its records, templates, and all per-source logs.

Why this matters:

- These are high-risk operations because mistakes here can destroy data.
- The tampered template delete case protects against deleting the wrong
  template via a manipulated request.
- Source cleanup matters because orphaned logs and child records create messy
  state and misleading admin history.

### `TemplateAssetsAndValidationTest.php`

This file covers template-specific asset handling and template validation
guardrails.

What it tests:

- Style/script asset sanitization generates handles from source URLs.
- Duplicate requested handles are made unique instead of colliding.
- Template saves reject dry-run runtime failures and do not persist broken
  template changes.
- Template validation rejects oversized templates.
- Template validation rejects null-byte input.
- Template validation respects security-policy rejections.
- Repeated shortcode usage does not enqueue the same template asset multiple
  times.
- Different templates using the same requested handle get distinct enqueue
  handles when their asset URLs differ.

Why this matters:

- Template assets are one of the easiest places to accidentally introduce
  duplicate CSS/JS or clobber one asset with another.
- The dry-run save path is the last safety net before a broken PHP template is
  stored in the database.
- Validation rules around size, invalid characters, and policy filters are part
  of the plugin's template safety model.
- Pages with multiple shortcodes need deterministic asset behavior so styles and
  scripts load exactly once when they should, and remain isolated when handles
  collide across templates.

## What we are testing at a higher level

If you zoom out, the suite currently verifies these major behaviors:

- Import correctness:
  - replace, append, upsert, manual import
- Import safety:
  - invalid JSON, malformed payloads, missing update keys
- REST security:
  - API key checks, IP allowlists, rate limiting, payload limits
- Observability:
  - import logs, security logs, template error logs
- Frontend output:
  - default template rendering, alternate template rendering, filters
  - template asset enqueue behavior
- Data cleanup safety:
  - source deletion cleanup, template ownership checks
- Template safety:
  - dry-run validation, policy rejection, size/character guards

## Why some tests are written at different layers

The suite uses the lowest layer that gives good confidence without becoming
fragile.

Examples:

- REST route behavior is tested through actual REST requests because we want to
  cover routing, request parsing, auth, and response formatting together.
- Shared import behavior is sometimes tested through
  `RestController::import_source_records()` directly because that is the common
  logic reused by both REST and manual import.
- Destructive admin behavior is tested close to the form handler because that is
  where request tampering and ownership checks matter.

This keeps the suite realistic without turning every test into a full browser
test.

## Current gaps

The suite is much stronger now, but it is not exhaustive.

The biggest remaining gaps are:

1. Browser-side admin JavaScript behavior.
2. Admin page routing and redirect flows.
3. Schema migration and uninstall behavior.
4. Asset behavior when frontend build outputs are missing or vary by
   environment.

These are good candidates for future commits, especially the admin UI path.

## How to run the tests

From the plugin root:

```bash
vendor/bin/phpunit --testdox
```

Run a single file:

```bash
vendor/bin/phpunit --testdox tests/Cases/RestGuardrailsTest.php
```

## When adding new tests

A good rule of thumb:

- If the change affects data correctness, add an integration test.
- If the change affects security, add both success and failure-path coverage.
- If the change affects rendering, assert the exact output or exact records
  included/excluded.
- If the change affects destructive actions, test the tampered/incorrect input
  path, not just the happy path.

The suite is most useful when it explains intent, not just behavior. Each new
test should answer two questions clearly:

1. What could break here?
2. Why would that matter in production?
