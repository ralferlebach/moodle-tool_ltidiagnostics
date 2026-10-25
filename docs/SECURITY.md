# Security

## Threat model

| Threat | Measure | Evidence test |
|--------|---------|---------------|
| Unauthorised access to diagnostics | Every page calls `require_login` / `admin_externalpage_setup` and `require_capability` with a dedicated capability; capabilities have risk bitmasks and no default archetype except course summaries | `access_control_test` |
| CSRF on state-changing actions | `require_sesskey()` on `mode.php` and `export.php`; Form API (sesskey) for profile, import and checks | `access_control_test::test_pages_are_guarded` |
| SSRF through active checks | https only, no credentials, host syntax, internal names, reserved/CGNAT ranges, core `curl_security_helper`, DNS answer check, address pinning, redirect re-check, GET only, master switch off by default, capability `runchecks`, audit | `active/url_guard_test`, `active/active_checks_test`, `gates_test::test_g7_*` |
| Leakage of secrets and identifiers | Observer stores ids and event types only; no tokens, claims, names or e-mail; log lines are stored with masked addresses and without query values; reports are redacted by default; support view is pseudonymised | `redactor_test`, `observer_test`, `accesslog/importer_test`, `journey_test` |
| Reading arbitrary files | Log import only from allow-listed paths (empty by default) or an upload, realpath check | `accesslog/importer_test`, `gates_test::test_g5_*` |
| CSV injection in exports | Cells starting with `=`, `+`, `-`, `@` are neutralised | `reporting_test` |
| Over-long retention | Daily cleanup task, diagnostic mode with TTL and maximum | `reporting_test::test_diagnostic_mode_ttl` |
| Injection through parameters | `required_param` / `optional_param` with types; connection keys parsed strictly (`\z`) | `inventory_test`, `access_control_test` |
| Misleading results | `verified` only with verifying evidence (exhaustive invariant) | `status_engine_test` |

## Logging

No secret, token, claim or identifier is written to the Moodle log or error log by this plugin. Audit entries hold user
id, action and connection key.

## Findings fixed during development

* `"platform:5\n"` was accepted as a connection key (PCRE `$` before a trailing newline): all strict patterns now use `\z`.
* A login outside the LTI launch script was counted as launch evidence: only the launch entry point counts.
* The observer swallowed a TypeError in `observation_store::record` (hidden by its try/catch): fixed, covered by a test.
* The core SSRF helper is inactive on a site with an empty block list: the plugin has its own built-in rules.

## Reporting

Please report vulnerabilities privately to the maintainer (see README).
