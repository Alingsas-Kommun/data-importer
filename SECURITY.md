# Data Importer Security Notes

This document summarizes the plugin's current security posture using OWASP Top 10 (2021). It covers both the hardening that is in place and the flaws and pitfalls that remain — operators should read both halves before deploying.

## OWASP Top 10 Mapping

### A01: Broken Access Control
- Admin handlers require capability checks (`data_importer_capability`, default `manage_options`) and nonces.
- REST import endpoint uses hashed API key verification + optional per-key IP/CIDR allowlists.
- Template editing remains admin-only.

Hardening in place:
- Consistent request ID/input normalization (`absint` + `wp_unslash`) in admin handlers.
- Central capability helper (`data_importer_get_capability()` / `data_importer_user_can_manage()`).

### A02: Cryptographic Failures
- API keys are stored as hashes; plaintext is never persisted.
- Candidate-key lookup uses a 12-character non-secret prefix before `password_verify()`.

Pitfalls:
- The 12-character lookup prefix is adequate today but collisions grow with key volume; bumping to 16–20 chars (or splitting into a dedicated lookup id) is a pending low-priority improvement.

Recommended operations:
- Use HTTPS only for all imports.
- Rotate API keys periodically and after suspected exposure.

### A03: Injection
- SQL queries use `$wpdb->prepare` where parameters are dynamic.
- Frontend/admin output escapes values before rendering.

High-risk area — PHP template execution (`eval`):
- Templates are still executed via `eval()` under `manage_options`. This is intentional for functionality.
- A default function blocklist in `Display::validate_template_code()` covers shell execution (`exec`, `system`, `passthru`, `shell_exec`, `proc_*`, `popen`, `pcntl_exec`), code execution (`eval`, `assert`, `preg_replace`, `create_function`), indirect execution via callables (`call_user_func[_array]`, `array_map/filter/walk[_recursive]/reduce`, `usort`/`uasort`/`uksort`, `register_shutdown_function`, `register_tick_function`, `spl_autoload_register`, `set_error_handler`, `set_exception_handler`), destructive filesystem ops (`unlink`, `rmdir`, `file_put_contents`, `fputs`, `fwrite`), and extension loading (`dl`). Extendable via `data_importer_blocked_template_functions`.
- A companion `data_importer_blocked_template_constructs` filter blocks language constructs the function-call regex would miss: the backtick shell-exec operator and `include`/`include_once`/`require`/`require_once` in no-parenthesis form.
- `extract()` on untrusted data is opt-in. Templates receive the record as `$vars` by default; legacy `extract()` is gated behind `data_importer_template_extract_vars` (defaults to `false`).
- Pre-validation hook and size guard run before runtime execution.
- Runtime error handling provides controlled error detail.

Known pitfalls in the eval model:
- The blocklist is a regex-based defense-in-depth measure, not a guarantee. Variable-variable calls (`$$fn()`), dynamic method dispatch on attacker-instantiable classes, and reflection APIs (`ReflectionFunction::invoke`, etc.) are **not** currently caught.
- A compromised admin account is game-over for the server. Treat the blocklist as a moving target and review it when PHP versions change or new callable-accepting APIs appear.

### A04: Insecure Design
- Design supports dynamic PHP templates, which is powerful but inherently high risk.

Compensating controls:
- Admin-only template management.
- Runtime guard filters and optional kill switch.
- Recovery safe mode (UI toggle + `DATA_IMPORTER_SAFE_MODE` constant) to stop template execution quickly.
- Safe mode lock indicator: when safe mode is forced by constant or by a custom `data_importer_safe_mode_active` filter, the admin page shows an explanatory notice and hides the Disable Safe Mode button to avoid confusion over an inert toggle.

Long-term recommendations (not yet implemented):
- Offer a sandboxed template engine (Twig, Blade-like) as an alternative mode for sites that do not need raw PHP.
- Move template execution into a forked worker process with reduced privileges, or behind a capability separate from `manage_options` so template authorship is an explicit, auditable grant.

### A05: Security Misconfiguration
- Import endpoint trusts `REMOTE_ADDR` only by default.
- Proxy trust is opt-in via the `data_importer_trust_proxy` filter.
- When proxy trust is enabled, `Request::get_client_ip()` walks the `X-Forwarded-For` chain from the **rightmost** entry (the IP added by the trusted proxy) rather than the client-supplied leftmost one.
- `RestController` and `ManualImportProcessor` both delegate to `DataImporter\Support\Request::get_client_ip()`, keeping XFF handling consistent in one place.
- `RestController::check_content_type()` rejects import requests without `Content-Type: application/json` (`415 Unsupported Media Type`) and emits a security-log entry. Tunable via `data_importer_require_json_content_type`.

### A06: Vulnerable and Outdated Components
- Plugin uses WordPress core APIs and bundled libraries.

Recommended operations:
- Keep WordPress core, PHP, and all plugins/themes patched.

### A07: Identification and Authentication Failures
- API key required for import.
- Per-key IP/CIDR allowlists supported.
- Uniform rejection messaging for auth-like failures.
- Security logs for blocked import attempts.

### A08: Software and Data Integrity Failures
- No remote executable code loading by plugin.
- Templates are stored in DB and executed locally.
- `uninstall.php` issues `DELETE ... LIKE '_transient_data_importer_%'` (plus matching timeout rows) and removes per-source security and template error logs, so secrets and logs do not linger after removal.

Recommended operations:
- Limit admin access to trusted operators only.

### A09: Security Logging and Monitoring Failures
Hardening in place:
- Security log for blocked import attempts (`data_importer_security_log_{source_id}`).
- Template runtime/validation error log (`data_importer_template_error_log_{source_id}`).
- Logs are shown in the admin API tab and capped to a bounded length.
- Template list shows badges for templates with recent runtime/validation errors.

Pitfalls:
- `Display::eval_php()` prints template errors inline when `WP_DEBUG` is on, gated behind `data_importer_template_error_details` (default `false`). The generic message can still signal plugin presence. Acceptable given the `WP_DEBUG` caveat, but keep in mind when changing error handling.

### A10: Server-Side Request Forgery (SSRF)
- Plugin does not issue user-controlled outbound HTTP requests.

## Known Flaws and Outstanding Risks

| Priority | Issue | Status |
|----------|-------|--------|
| Critical | Raw `eval()` execution model | Mitigated via blocklist, not eliminated |
| High | Transient-based rate limiter | Documented, not replaced |
| Medium | No CORS policy on REST endpoint | Outstanding |
| Low | 12-char API key lookup prefix | Outstanding |
| Low | Error detail leakage with `WP_DEBUG` | Accepted |

### Rate limiting
`check_rate_limit()` is best-effort only: non-atomic, object-cache dependent, resettable on cache flush, and it causes `wp_options` write amplification on sites without a persistent object cache. A race condition between the read and write steps is possible. For public-facing deployments, enforce rate limiting at the WAF or reverse proxy. A DB-backed atomic counter (custom table with `INSERT ... ON DUPLICATE KEY UPDATE`) is a pending improvement.

### CORS
The REST import endpoint emits no explicit CORS headers. The endpoint is designed for server-to-server use. A future change should either emit a restrictive `Access-Control-Allow-Origin` (deny by default, allowlist filter) or document the server-to-server intent prominently.

## Runtime Hardening Filters

Theme/plugin developers can tune policy without patching plugin core:

- `data_importer_enable_php_templates` (bool, default `true`)
- `data_importer_capability` (string, default `manage_options`)
- `data_importer_safe_mode_active` (bool)
- `data_importer_max_template_bytes` (int, default `524288`)
- `data_importer_validate_template_code` (true/WP_Error)
- `data_importer_blocked_template_functions` (array)
- `data_importer_blocked_template_constructs` (array)
- `data_importer_template_extract_vars` (bool, default `false`)
- `data_importer_template_error_details` (bool, default `false`)
- `data_importer_rate_limit_count` (int, default `60`)
- `data_importer_rate_limit_window` (seconds, default `MINUTE_IN_SECONDS`)
- `data_importer_max_payload_bytes` (int, default `1048576`)
- `data_importer_max_records_per_import` (int, default `1000`)
- `data_importer_trust_proxy` (bool, default `false`)
- `data_importer_require_json_content_type` (bool, default `true`)

## Operational Recommendations

- Enforce HTTPS and HSTS.
- Restrict access to `/wp-json/data-importer/v1/import/*` at WAF/reverse proxy where possible.
- Add network-level rate limiting in front of WordPress — do not rely on the built-in limiter alone.
- Protect wp-admin with MFA and least privilege.
- Use least privilege via `data_importer_capability` if non-admin operators should not edit templates.
- Rotate source API keys regularly.
- Monitor both import and security logs.
- If a template breaks rendering, activate safe mode in admin, fix the template, then disable safe mode.
