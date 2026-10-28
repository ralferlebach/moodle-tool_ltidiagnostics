# Security

## Threat model

| Threat | Measure | Evidence test |
|--------|---------|---------------|
| Unauthorised access to diagnostics | Every page calls `require_login` / `admin_externalpage_setup` and `require_capability` with a dedicated capability; capabilities have risk bitmasks and no default archetype except course summaries | `access_control_test` |
| CSRF on state-changing actions | `require_sesskey()` on `mode.php` and `export.php`; Form API (sesskey) for profile, import and checks | `access_control_test::test_pages_are_guarded` |
| SSRF through active checks | https only, no credentials, strict host syntax (decimal/octal/hex IPv4, IPv6 forms, mapped/NAT64/6to4/Teredo), internal names, reserved/CGNAT ranges, core `curl_security_helper`, host resolved once and **pinned with `CURLOPT_RESOLVE` for every hop** (no second lookup: DNS rebinding and TOCTOU), every answer validated, each redirect validated again, peer address compared after the transfer (`pin_mismatch` discards the answer), strict TLS, GET only, master switch off by default, capability `runchecks`; private targets additionally need `runprivatechecks` and are audited | `active/url_guard_test`, `active/curl_fetcher_test`, `active/probe_runner_test`, `active/active_checks_test`, `gates_test::test_g7_*` |
| Leakage of secrets and identifiers | Observer stores ids and event types only; no tokens, claims, names or e-mail; log lines are stored with masked addresses and without query values; reports are redacted by default; support view is pseudonymised | `redactor_test`, `observer_test`, `accesslog/importer_test`, `journey_test` |
| Reading arbitrary files | Log import only from allow-listed paths (empty by default) or an upload, realpath check | `accesslog/importer_test`, `gates_test::test_g5_*` |
| CSV injection in exports | Cells starting with `=`, `+`, `-`, `@` are neutralised | `reporting_test` |
| Over-long retention | Daily cleanup task, diagnostic mode with TTL and maximum | `reporting_test::test_diagnostic_mode_ttl` |
| Injection through parameters | `required_param` / `optional_param` with types; connection keys parsed strictly (`\z`) | `inventory_test`, `access_control_test` |
| Misleading results | `verified` only with verifying evidence (exhaustive invariant); an HTTP 200 of a web server log is "transport only" and never verifies a message or a chain | `status_engine_test`, `message_matrix_test` |
| SSRF through an environment or Moodle proxy (0.3.0) | Direct checks switch off proxies of the process environment (`CURLOPT_PROXY` = ''); through a Moodle proxy the tunnel goes to the validated address (`CURLOPT_CONNECT_TO`), the proxy cannot resolve the name again | `integration/network_negative_test::test_proxy_407_and_pinned_tunnel` (real proxy logs `CONNECT 127.0.0.1:port`), `curl_fetcher_test::test_proxy_path_is_pinned_with_connect_to` |
| Over-broad private exception (0.3.0) | Exact allow-list `privatetargetallowlist` (host:port) instead of all private ranges; https, pin and redirect checks stay; capability `runprivatechecks`, audited | `integration/network_negative_test::test_redirects_to_private_targets_are_refused` |
| Hostile partner documents (0.3.0) | Partner package (64 KiB) and trace (512 KiB, 500 transactions) are parsed against a schema; unknown members dropped; strings cleaned; hashes must be 64 hex; import needs `configure`, export `export` (+ `view` for traces), audited | `integration/service_negative_test::test_import_through_service`, `local/transfer_report_test::test_trace_validation`, `role_audit_test` |
| Mutation by diagnostics (0.3.0) | No diagnostic action writes LTI settings; the integration tests compare a fingerprint of the LTI tables before and after, the stack specs a fingerprint of both sites | `integration/network_negative_test`, `tests/playwright/partner_two_site.spec.js` |

## Limits of the SSRF defence

* With a configured Moodle web proxy the proxy connects; since 0.3.0 the tunnel target is the validated address
  (`CURLOPT_CONNECT_TO`, `pinmode` = `connect_to`), but the peer address cannot be compared after the transfer and the
  proxy's own policy still applies (`viaproxy` is reported).
* No test against a real rebinding DNS server: the resolver is replaced in the tests; the pin is proven with real
  connections (laboratory) and a scripted transfer layer.
* A service that returns different addresses to different callers is checked as seen from this server only.
* The peer comparison happens after the transfer; the answer is discarded, but the request has been sent. The guard before
  the request is the primary defence, the comparison is the second.

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
