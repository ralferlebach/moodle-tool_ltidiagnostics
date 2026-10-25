# Acceptance

Status classes (COMMON brief): **CP** code present / **TL** tests executed locally / **CI** CI passed / **FA** functionally
accepted / **NV** not verified. Nothing is marked CI or FA yet: no CI run happened, and the owner has not accepted anything.
"TL" means the named test passed on all four tree/database combinations listed below unless stated otherwise.

## Runner results (`ltirun ... all`, 2026-10-24, version 0.1.0 / 2026102401)

| Tree | DB | phplint | validate | savepoints | mustache | codechecker (0 warnings) | phpdoc (0 warnings) | PHPUnit |
|------|----|---------|----------|------------|----------|---------------------------|----------------------|---------|
| 4.5 | pgsql | OK | OK | OK | OK | OK | OK | OK, 336 tests, 937 974 assertions |
| 4.5 | mariadb | OK | OK | OK | OK | OK | OK | OK, 336 tests, 937 974 assertions |
| 5.3 | pgsql | OK | OK | OK | OK | OK | OK | OK, 336 tests, 937 979 assertions, 28 PHPUnit deprecations |
| 5.3 | mariadb | OK | OK | OK | OK | OK | OK | OK, 336 tests, 937 979 assertions, 28 PHPUnit deprecations |

The 28 PHPUnit 11 deprecations on 5.3 are the accepted ones (docblock `@covers` / `@dataProvider` metadata; the brief fixes
PHPUnit 9 style). No test was skipped or risky (`--fail-on-skipped`, `--fail-on-risky`). One earlier full run showed a single transient "Problem calling HTML validator" from the Mustache lint on 4.5/pgsql;
the immediate rerun and this final matrix were green.

### Tests per layer (test methods; 336 executed tests include data provider sets)

| Layer | Methods | Files |
|-------|---------|-------|
| PHPUnit unit (pure logic, fakes) | 101 | status_engine, redactor, parser, url_guard, correlation, active_checks, matrix_health, importer, lang |
| PHPUnit integration (real core flows and APIs) | 69 | observer (real events), journey, inventory (real mod_lti / enrol_lti data), sources (real log store, tasks, task_log), reporting, privacy provider, access_control |
| Gate evidence | 12 | gates_test (G1 to G7) |
| Behat (non-JS) | 5 scenarios, 38 steps | `tests/behat/ltidiagnostics.feature` - **executed 2026-10-25, all passed** on 4.5 and 5.3, PostgreSQL and MariaDB (local, not CI). Exception pages are not asserted; denial is covered by `access_control_test`. Revealed and fixed a real bug: on Moodle 4.5 the `tools` admin category exists only for `moodle/site:config`, so a delegated viewer got Access denied (settings.php now falls back to `root`) |
| Playwright E2E | 4 tests | `tests/playwright/ltidiagnostics.spec.js` - **executed 2026-10-25, Chromium, two-site stack: 4 passed, 0 failed** |

## Acceptance criteria (spec 06, B.3 and the V2 supplement)

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| B3-1 | Site, tool, deployment, course and activity contexts are separated | TL | `inventory_test::test_keys`, `test_platform_connection_from_real_type`, `test_tool_connection_from_real_registration`, `test_course_filter`, `test_ambiguous_issuer_is_not_resolved` |
| B3-2 | Matrix separates local capability, local enablement, remote declaration, scopes, reachability and observation | TL | `matrix_health_test::test_platform_ags_configured_is_not_verified`, `test_platform_token_authorises_but_does_not_verify`, `status_engine_test` |
| B3-3 | AGS off, NRPS on: difference detected, no automatic reconfiguration proposed | TL | `matrix_health_test::test_ags_disabled_but_nrps_active` (findings only describe, the plugin has no write path) |
| B3-4 | AGS enabled but token without scope is distinguishable from a network outage and a missing endpoint | TL | `matrix_health_test::test_spec_example_scope_missing`, `active_checks_test::test_classify` |
| B3-5 | A sent score and the later gradebook update are shown separately with sources | TL | `matrix_health_test::test_score_request_without_gradebook_change`, `test_spec_example_score_observed`, `journey_test::test_platform_side_services_are_configured_until_observed` |
| B3-6 | NRPS members are not called Moodle enrolments | TL | `matrix_health_test::test_tool_enrolment_and_launch_are_separate`, `journey_test::test_tool_side_launch_and_enrolment_are_separate_evidence`, `lang_test` |
| B3-7 | Missing data and missing observability are different in all reports | TL | `reporting_test::test_report_metadata`, `matrix_health_test::test_observability_findings`, `status_engine_test` |
| B3-8 | Standard log store, other stores and missing readers do not crash or give false findings | TL (standard store only; the alternative store is not exercised, only its reader interface) | `sources_test::test_logstore_degrades_without_reader`, `test_logstore_present_but_not_logging`, `gates_test::test_g3_*` |
| B3-9 | Scheduled and adhoc queue and task logs are shown singly or with time context | TL | `sources_test::test_scheduled_inventory_from_real_tasks`, `test_adhoc_queue`, `test_task_log`, `test_cron_status` |
| B3-10 | Historical access/error logs are imported read-only; time shift, rotation and NAT/proxy considered | TL (nginx and apache access and error lines, time zone and offsets, gzip rotation and overlap, forwarded addresses) | `accesslog/parser_test`, `accesslog/importer_test::test_import_gz_and_overlap`, `test_import_skew`, `test_import_error_log`, `correlation_test::test_ip_alone_is_not_a_reference` |
| B3-11 | Default reports contain no raw tokens, sessions, cookies or private keys | TL | `redactor_test`, `reporting_test::test_exports_mask_and_redact`, `journey_test::test_report_of_a_real_run_is_redacted` |
| B3-12 | Unknown or custom claims are recorded structurally | NV | Claims are not recorded at all in 0.1.0 (no hook, privacy); not implemented |
| B3-13 | Tests cover every supported Moodle version and both LTI roles; skipped tests do not count | TL | Four runner combinations, both roles in `inventory_test`, `matrix_health_test`, `journey_test`; no skipped test |
| V2-1 | A wrong AGS scope looks different from a TLS error, each with evidence and next safe step | TL | `matrix_health_test::test_spec_example_scope_missing`, `test_probe_findings`, lang test for fix texts |
| V2-2 | Test matrix: expired certificate, wrong host name, missing intermediate, JWKS kid mismatch, iss/aud error, clock skew, proxy 407, DNS NXDOMAIN, firewall drop, cron stall, adhoc retry, missing log source | TL for all except iss/aud (NV: not implemented) | `active_checks_test::test_classify`, `test_cert_expiry`, `test_clock_skew`, `test_jwks_analyser`, `matrix_health_test::test_jwks_findings`, `test_skew_and_expiry_findings`, `test_background_findings`, `sources_test::test_health_check_degrades_without_any_source` |
| V2-3 | No remote feature is called active/working by a configured switch alone | TL | `status_engine_test` exhaustive invariant (about 466 000 combinations), `matrix_health_test::test_platform_ags_configured_is_not_verified` |
| V2-4 | Non-instrumentable remote flows show "not observable", no invented request or payload | TL | `gates_test::test_g6_local_versus_remote_settings`, `status_engine_test` |
| V2-5 | Diagnostics without task, log store or web log access degrade safely | TL | `sources_test::test_logstore_degrades_without_reader`, `test_health_check_degrades_without_any_source` |
| V2-6 | E2E over different permissions and versions; SSRF and unauthorised log/token access proven | SSRF and access control: TL (`active/url_guard_test`, `active_checks_test`, `access_control_test`); browser E2E on the stack (A 4.5, B 5.3; admin and student permissions) executed 2026-10-25, Chromium, 4/4 passed; other browsers not run | `tests/playwright/ltidiagnostics.spec.js` |
| V2-7 | No core modification; otherwise the no-fork gate is "open" | TL | No core change. Gates G1 and G2 are open as documented (`gates_test`, `docs/DOCUMENTATION.md`) |

## P6 MVP scope

| Item | Status | Evidence |
|------|--------|----------|
| Connection inventory, both roles | TL | `inventory_test` |
| Capability matrix, seven-step ladder, pure engine, `verified` only with real evidence | TL | `status_engine_test`, `matrix_health_test`, `journey_test` |
| Desired-state profile and guided health check, spec A.6 examples | TL | `matrix_health_test::test_spec_example_*`, `test_profile_parsing` |
| Logstore reader | TL | `sources_test::test_logstore_*` |
| Task inventory and task_log | TL | `sources_test::test_task_*`, `gates_test::test_g4_*` |
| Own observer with trace id, no PII values | TL | `observer_test` |
| Access log importer and parser | TL | `accesslog/*_test` |
| Correlation classes | TL | `correlation_test` |
| Safe active checks with SSRF guard (GET only) | TL | `active/*_test`, `gates_test::test_g7_*` |
| Mutating tests | NV (not implemented, by design for the MVP) | `gates_test::test_g7_no_mutating_test_context_exists` |
| Reports MD/JSON/CSV, redaction, diagnostic mode with TTL and audit | TL | `reporting_test` |
| Privacy API | TL | `privacy/provider_test` |
| Capabilities (admin, privacy officer, teacher aggregate, support pseudonymised) | TL | `access_control_test` |
| E2E: launch A->B listed as separate rows on B; A's AGS/NRPS configured, not verified | PHPUnit mirror: TL (`journey_test`); browser: TL (Playwright 2026-10-25, Chromium, 4/4 passed) | `tests/playwright/ltidiagnostics.spec.js` |
| Behat | CP, NV | `tests/behat/ltidiagnostics.feature` |
| German and English language packs | TL | `lang_test` |
| CI | NV | The workflows are adapted from the template and have not run |

## Open gates

G1 (no OIDC/JWT hook) and G2 (no AGS/NRPS/DL service hook) are open: the plugin reports `not_observable` unless the access log is
imported. Tool-side AGS cannot reach `verified` (no authorised test). Not implemented: iss/aud mismatch detection, claim recording, partner diagnostic package (DIA-UX-08), mutating tests.
