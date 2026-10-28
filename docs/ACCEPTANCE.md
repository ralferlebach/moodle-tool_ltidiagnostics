# Acceptance

Status classes (COMMON brief): **CP** code present / **TL** tests executed locally / **CI** CI passed / **FA** functionally
accepted / **NV** not verified. Nothing is marked CI or FA yet: no CI run happened, and the owner has not accepted anything.
"TL" means the named test passed on all four tree/database combinations listed below unless stated otherwise.

## Release 0.3.0 / 2026102700 (family audit 2026-10-27, development branches)

Evidence classes per the round brief: **code present** / **automated tests (local)** / **CI passed (not executed by us)** /
**functionally accepted (owner)** / **not verified** / **core gate open**. Local evidence (ltirun on this machine, two-site
stack) is kept apart from the owner's GitHub CI, which we did not run. No skip is counted as green (`--fail-on-skipped`,
`tools/deprecation_report.php`), fixtures or mocks alone never count for an E2E item.

### Audit items

| Item | Status | Code | Tests | Result (2026-10-27) | Honest boundary |
|------|--------|------|-------|---------------------|-----------------|
| DIA-01 deprecations attributed, none hidden | automated tests (local); CI wiring: **CI agent** | `tests/classes/deprecation_guard.php`, `tests/classes/deprecation_guarded.php` (trait in every test class), `tools/deprecation_report.php` | `deprecation_guard_test` (user and native deprecation with trace, `str_getcsv()` PHP 8.4 attributed to the test code, hand-on to the runner, nesting, classification) | 0 PHP deprecations, 0 guard failures, 0 skipped/incomplete/risky in all 6 local cells below (`tools/deprecation_report.php`); mutation control fails as required (below) | The 10 CI cells, the release gate and the 5.3 container are the CI agent's; "all 10 matrix cells green" is not claimed. The 53 PHPUnit runner deprecations are the docblock metadata the owner prescribes (PHPUnit 9 style), listed per class by the report tool |
| DIA-02 configured / observed / verified / booked / not observable per message, field matrix, no "end to end" from HTTP 200 | automated tests (local); Playwright on the stack `messages_two_site.spec.js` 5/5 (29/29 passed, 2026-10-27 23:15, stack A 4.5 + B 5.3, `flock /tmp/e2e_stack.lock`) | `classes/local/message_matrix.php`, `field_matrix.php`, `observability.php` (`seenlevel`), `observer.php::with_claims()`, `report.php`, `report_exporter.php`, `classes/output/renderer.php::messages_tab()`, tab "messages" | `local/message_matrix_test` (HTTP 200 never verifies for all messages and both roles; invariants over 2 000+ combinations; chains; NRPS paging; key rotation; completion; group row), `local/field_matrix_test` (real login event in the launch script), `integration/service_negative_test::test_gradebook_booking`, `tests/playwright/messages_two_site.spec.js` | see runner table | OIDC, deep linking, NRPS, AGS line items/results: only "observed (transport only)" from an imported web server log, never verified (no core hook, gates G1/G2); custom/unknown claims and roles are "not observable" on the tool (core does not persist them); the platform never sees the id_token |
| DIA-02 redacted evidence/trace export | automated tests (local); Playwright | report export (JSON/MD/CSV with messages, chains, fields, diagnosis parts), partner package, partner trace | `reporting_test`, `role_audit_test::test_audit_and_data_minimisation`, `messages_two_site.spec.js` (JSON export without learner data) | | |
| DIA-02/03 two-system check (partner package, live key check) | automated tests (local); Playwright on the stack `partner_two_site.spec.js` 4/4 (29/29 passed, 2026-10-27 23:15, stack A 4.5 + B 5.3, `flock /tmp/e2e_stack.lock`) | `classes/local/partner_package.php`, `partner_service.php`, `partner.php`, `cli/partner.php` | `integration/service_negative_test` (matching pair, iss/aud, registration, stale key, role conflict, import/audit), `integration/network_negative_test::test_partner_live_key_check`, `tests/playwright/partner_two_site.spec.js` (browser export on B 5.3, import on A 4.5, live check through the exact allow-list, tampered package, LTI fingerprint of both sites unchanged) | | The comparison needs the partner's cooperation (package export); a single site cannot see the other's values |
| DIA-03 negative integration tests with cause, uncertainty, responsible system, safe check | automated tests (local) | `finding.php` (`system`, `certainty`, `diagnosis()`), `health_check.php` (`token_scope_findings`, `bg_cron_gap`, certainty of probe findings), `active/*` (exact allow-list, CONNECT_TO, environment proxy off, host name and firewall classification, hosts file) | `integration/network_negative_test` (laboratory: valid control, expired certificate, missing intermediate (with control), wrong host and untrusted root, unknown kid, NXDOMAIN (system resolver), proxy 407 with the pinned tunnel target in the proxy log, firewall drop, redirects to private targets, clock skew, live key check), `integration/service_negative_test` (wrong iss/aud, missing registration, stale key, missing AGS scope with real `lti_new_access_token`, cron outage, gradebook booking, each with a positive control), `local/active/active_checks_test` | | No real rebinding DNS server (resolver replaced, pin proven with real connections); "firewall drop" is classified "possible" only; the remote cause of a missing scope is not visible |
| DIA-03 no SSRF via redirect, DNS rebinding, proxy | automated tests (local) | `active/url_guard.php`, `active/curl_fetcher.php` | `network_negative_test::test_redirects_to_private_targets_are_refused`, `test_proxy_407_and_pinned_tunnel`, `curl_fetcher_test` | | With a Moodle proxy the peer address cannot be compared after the transfer |
| DIA-03 no productive mutation | automated tests (local); Playwright | every diagnostic action is read only towards LTI | LTI table fingerprint in `network_negative_test`, `service_negative_test::test_import_through_service`, `partner_two_site.spec.js` (both sites) | | |
| DIA-04 log stores, task logs, cron windows, web logs, rotation/gzip/timezone/drift, unmatched, paginated/limited queries, minimisation, retention | automated tests (local) | `accesslog/importer.php` (`summary`, `page`, rate limit), `task_source.php::cron_windows()`, `timeline.php`, `fact_collector.php`, `task/cleanup_task.php`, install.xml indexes, upgrade 2026102700 | `integration/large_data_test` (100 000 log store rows, 100 000 line gzip log, keyset pages, cron windows over 20 000 rows, rate limit, retention per class), `role_audit_test`, earlier `retrospective_test`, `accesslog/*`, `correlation_test` (unchanged, still green) | measured 2026-10-28, 4.5/PostgreSQL, PHP 8.3 (`LTIDIAG_PERF`): log store fetch of a 100 000 row store 7 reads / 0.05 s; import of a 100 000 line gzip access log 104 reads / 102 writes / 2.95 s / peak growth 7.7 MiB (50 000 LTI lines stored); summary 6 reads; 25 keyset pages 25 reads / 0.15 s; cron windows over 20 000 task log rows 1 read. The test asserts the bounds, not these times | Other log store plugins than standard/database not exercised; measured bounds are ceilings for a shared machine |
| DIA-05 completion sync path with the same correlation id on both sites, masked persons, failed/successful reports 4.5 <-> 5.3, no-partner mode limited | automated tests (local); Playwright on the stack `transfers_two_site.spec.js` 3/3 (29/29 passed, 2026-10-27 23:15, stack A 4.5 + B 5.3, `flock /tmp/e2e_stack.lock`) | `classes/local/transfer_report.php`, `transfer_service.php`, `transfers.php`, `cli/transfers.php`, `family_events::sanitise_other()` (reads `detail`) | `local/transfer_report_test` (one-sided limited, two-sided complete, task by time containment, native match/mismatch, dead letter, rejection, no-partner mode, masking, trace validation, real log store rows), `tests/playwright/transfers_two_site.spec.js` | | The no-partner mode (AGS progress only) is proven by unit tests, not on the stack; the task stage is linked by time containment (strong), not by an id |
| GA-03 groupautoenrol events and failures read optionally; E2E with and without diagnostics | automated tests (local); Playwright `groups_two_site.spec.js` 2/2 (29/29 passed, 2026-10-27 23:15, stack A 4.5 + B 5.3, `flock /tmp/e2e_stack.lock`) | `classes/local/groupenrol_source.php`, `observer.php`, `db/events.php`, `logstore_source::EVENTS`, message row `group_assignment` | `local/groupenrol_source_test`, `tests/playwright/groups_two_site.spec.js` | | "Without diagnostics" on the shared stack means recording switched off (a plugin cannot be uninstalled there); the runtime code of tool_ltigroupautoenrol only mentions this plugin in a comment (`classes/local/event_reporter.php`); its E2E fixture reads our observation table only when it exists |
| Merge declaration (coordinator request, local_ltiaccountmerge preview) | automated tests (local) with stand-ins; real tool_mergeusers / local_ltiaccountmerge assertions run only where installed | `classes/local/merge_users_settings.php` (`USER_TABLES`, `settings`), `db/hooks.php` | `tests/merge_users_settings_test.php` (`test_every_user_table_is_declared` incl. negative control, merge-order tests) | green in the 4 local cells of 2026-10-28 (603 tests each); first attempt failed on schema order and on an assertion about another plugin's list (fixed) | Rows of a merged-away account stay under its id; diagnosing a merged person means looking at both accounts. Not run against the real tool_mergeusers here. |
| Upgrade 2026102600 -> 2026102700 | automated tests (local); real upgrade on the stack sites A (4.5/PostgreSQL) and B (5.3/PostgreSQL); the partner sites a2/b2 were not installed after the re-seed and are not claimed | `db/upgrade.php` | `upgrade_test::test_upgrade_from_021`, all earlier upgrade tests | `upgrade_log` of A: 2026102600 -> 2026102700 at 2026-10-27 14:10 CET, B: 14:45 CET | |
| FAM-00 branch policy | code present (README) | `README.md` "Branch policy" | | | Rulesets on GitHub are the owner's |

### Local runner results (not CI)

| Date | Cell | Command | Result |
|---|---|---|---|
| 2026-10-28 | 4.5 PostgreSQL, PHP 8.3 | `ltirun 45 pgsql tool_ltidiagnostics all` | static OK, `OK (603 tests)` |
| 2026-10-28 | 4.5 MariaDB, PHP 8.3 | `ltirun 45 mariadb tool_ltidiagnostics all` | static OK, `OK (603 tests)` |
| 2026-10-28 | 5.3 PostgreSQL, PHP 8.3 | `ltirun 53 pgsql tool_ltidiagnostics all` | static OK, 603 tests, 0 failures, 53 PHPUnit runner deprecations (docblock metadata) |
| 2026-10-28 | 5.3 MariaDB, PHP 8.3 | `ltirun 53 mariadb tool_ltidiagnostics all` | static OK, 603 tests, 0 failures, 53 PHPUnit runner deprecations (docblock metadata) |
| 2026-10-27 | 5.0 PostgreSQL, PHP 8.4 | `LTI_PHP=8.4 ltirun 50 pgsql ... static` and `phpunit --display-all-issues` | static OK, 602 tests, 0 failures, 0 PHP deprecations, 53 runner deprecations |
| 2026-10-27 | 5.3 MariaDB, PHP 8.4 | `LTI_PHP=8.4 ltirun 53 mariadb ... phpunit --display-all-issues` | 602 tests, 0 failures, 0 PHP deprecations, 53 runner deprecations |
| 2026-10-27 | Playwright, stack A 4.5 + B 5.3 | full suite of `tests/playwright` under `flock /tmp/e2e_stack.lock` | 29/29 passed (6.3 min) |

The 2026-10-27 cells ran before the merge declaration change (602 tests); the 2026-10-28 cells include it (603). Every
PHPUnit log was read with `tools/deprecation_report.php`: 0 PHP deprecations, 0 guard failures, 0 skipped, incomplete or
risky tests. ltifullmatrix and the CI matrix are not claimed here (lead / CI agent).

Full matrix finding (2026-10-28, 2 of 24 cells): `network_negative_test::test_firewall_drop_timeout` got `proxy_407`. Root
cause: a product bug in `error_classifier::classify()`. Any curl error containing "connect" and "407" was read as a proxy
answer, and curl's real timeout text "Failed to connect to lab.diag.test port 45407 after 1001 ms: Timeout was reached"
(reproduced with curl 8.5.0) matched whenever the ephemeral port contained 407. Fixed with
`is_proxy_407_message()` (curl's own CONNECT wordings only), plus regression cases in `active_checks_test` (port 45407,
port 4070, 1407 ms, 403 tunnel). Local evidence: 20 consecutive runs of `network_negative_test|active_checks_test` on
5.3/PostgreSQL with PHP 8.4, all green (59 tests each); `ltirun all` on 4.5 and 5.3 PostgreSQL green (608 tests).

### DIA-01 negative control (mutation), local, 2026-10-27

`strlen(null)` was injected into `classes/local/redactor.php` (`redactor::text()`) of the mirrored plugin on 4.5/PostgreSQL
(PHP 8.3) and `redactor_test` was run. Result: `Tests: 16, Assertions: 60, Failures: 12`; every failure is the guard's,
reporting `[plugin_code] E_DEPRECATED: strlen(): Passing null to parameter #1 ($string) of type string is deprecated at
admin/tool/ltidiagnostics/classes/local/redactor.php:45` with the call stack (`redactor::text` <- `redactor::data`). The file
was restored afterwards (no mutant left; re-checked 2026-10-28). Without the guard the same deprecation is only printed
output on 4.5 (PHPUnit 9) and the run stays green, which is what DIA-01 forbids.

## Release 0.2.1 / 2026102600 (gate CS-G6, 2026-10-26)

| Item | Status | Evidence | Limits |
|------|--------|----------|--------|
| Family events read by component prefix, bounded | TL | `family_logstore_test::test_logstore_reads_family_events` (real standard log store rows from fixture events shaped like completion sync's, plus an `ltisource_` event and a non-family control), `test_queries_are_bounded` (past window finds nothing, default window, limit, both queries carry the time condition and the limit, escaped prefixes) | The standard store indexes `timecreated` only; other stores were not measured |
| Correlation by `other['correlationid']` | TL | `family_events_test::test_correlation_by_correlation_id`, `test_edges_follow_the_correlation_id`, `family_logstore_test::test_timeline_shows_transfers` | |
| Timeline and evidence show completion sync transfers | TL; Playwright on the stack | `family_logstore_test::test_timeline_shows_transfers`, `test_facts_and_evidence`; `tests/playwright/family.spec.js` (real completion sync events triggered on B and A with one correlation id: B shows queued > sent > acknowledged, outcome acknowledged, connection resolved to the seeded tool key, three evidence rows, observability `recorded`; A shows the receiving side, outcome applied) | |
| Observability level | TL | `family_events_test::test_observability_of_completion_sync` | |
| Observer keeps the correlation id; registration inert without the plugin | TL | `family_logstore_test::test_observer_records_correlation_id`, `test_observer_registration_is_inert`, `observer_test::test_registered_events` | The observer covers completion sync only; other family plugins are read from the log stores |
| No dependency on completion sync | TL | fixture component `local_ltidiagfixture` / `ltisource_diagfixture`; `family_events_test::test_connection_key_without_table`, `test_connection_key` (stub lookup) | |
| Upgrade 2026102502 -> 2026102600 | TL; real upgrade on the stack sites A and B (e2e-mirror) and in every `ltirun` combination | `upgrade_test::test_upgrade_adds_correlation_id` | |

Mutation check: with the family query switched off in `logstore_source::fetch()`, the correlation id rule removed from
`correlation::classify()` and the 0.2.0 `db/events.php` in the mirrored 4.5 tree, 8 of 53 tests in
`family_events_test`, `family_logstore_test` and `observer_test` failed (7 failures, 1 error); the code was restored afterwards.

Runner results (`ltirun ... all`, 2026-10-26): phplint, validate, savepoints, mustache, codechecker (0 warnings) and
phpdoc (0 warnings) OK on all four combinations; PHPUnit 4.5 PostgreSQL / MariaDB OK, 536 tests, 940 558 assertions;
5.3 PostgreSQL / MariaDB OK, 536 tests, 940 563 assertions, 43 accepted PHPUnit 11 deprecations (docblock metadata).
Behat not re-run (no feature affected).

Playwright (Chromium, two-site stack, after `e2e-mirror`): full suite **15/15 passed** (accessibility 9, family 2, main 4).
The first run found two problems: the double escaping above (fixed) and `ltidiagnostics.spec.js` expecting AGS/NRPS
"configured" although other suites on the shared stack had stored service tokens for the type; the spec now expects
"authorised" exactly when such a token exists (the same rule as the matrix), and still never "verified".

Note for local_lticompletionsync: its `pseudonym_diagnostics.spec.js` asserts the 0.2.0 gap (`correlation_found` false);
with 0.2.1 that assertion is expected to fail and should be inverted by its owner.

CI: **not executed**. The full PHP matrix (`ltifullmatrix`) is run by the lead.

## Post-rename cleanup re-run (2026-10-25, version 2026102502 unchanged)

| Run | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
|---|---|---|---|---|
| `ltirun ... tool_ltidiagnostics all` (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings, PHPUnit) | OK, 507 tests, 940281 assertions | OK, 507 tests, 940281 assertions | OK, 507 tests, 940286 assertions; 40 deprecations (accepted) | OK, 507 tests, 940286 assertions; 40 deprecations (accepted) |
| `ltibehat ... all` (non-JS) | 8 scenarios / 71 steps passed | 8 scenarios / 71 steps passed | 8 scenarios / 71 steps passed | 8 scenarios / 71 steps passed |

* Playwright order dependency fixed (see below).
* Uninstall: no gap. Own tables, settings and the scheduled task are removed by core; uploaded access logs are read from the user's draft area and never stored in a plugin file area. Uninstall leftovers, checked for real: on separate scratch databases (`moodle_upg` 4.5/PostgreSQL and `moodle53_upg` 5.3/PostgreSQL, gate data plus seeded plugin state) all five family plugins were uninstalled with `admin/cli/uninstall_plugins.php --run` and the core tables that can hold plugin state were searched for rows naming the component (config, user preferences, files, scheduled and ad hoc tasks, capabilities and role capabilities, message providers, external functions, events, tables, cache definitions); then reinstalled. Result on both: nothing left except `local_ltixsembed/consumed` in the cache configuration, which core rebuilds from the `db/caches.php` files on disk at the next upgrade (its data is purged by the uninstall).
* CI workflows: validated locally (YAML parser, job graph, matrix 4.5 to 5.3 x PostgreSQL/MariaDB, names and referenced paths; see
  TESTING.md "CI workflows"). CI itself: **not executed**.
* Playwright (Chromium, two-site stack): full suite twice **13/13 passed** each; reverse order `playwright.order.config.js` (main -> accessibility -> main) **17/17 passed**. Before the fix the full suite was 9 passed, 1 failed, 3 not run (reproduced again today). Each spec now removes the state it asserts on before it starts; the accessibility spec also removes the probe results it stored when it ends and asserts that none remain.

## Gate 2 fixes, build 2026102502 (2026-10-25)

| Finding | Status | What exists | Limits |
|---------|--------|-------------|--------|
| F3 AGS line item import (Medium) | automated tests (local); mutation-checked | column char(40) + upgrade step 2026102502 (also closes stuck `running` jobs); `parser::CATEGORIES` / `CATEGORY_MAXLEN`; `importer::storable_category()`; failed status on any error. Tests: `importer_test::test_every_category_fits_the_column` (install.xml and installed DB), `test_ags_lineitems_lines_are_imported` (real AGS line item lines), `test_database_error_ends_the_job_as_failed` (old char(20) column recreated in the test), `test_overlong_category_is_guarded`, `parser_test::test_categorise` (every category declared), `upgrade_test::test_upgrade_widens_category_and_closes_stuck_jobs`. Real upgrade 2026102501 -> 2026102502 run on the 4.5/pgsql site: column length 40 | The lead's seed probe has not been re-run |
| F5 merge rewrites audit actor (Low) | automated tests (local) with stand-ins; **not verified with the real tool_mergeusers** | `local\merge_users_settings` + `db/hooks.php`: the audit table is an exception for tool_mergeusers, listed under a string key so that tool_mergeusers' `array_replace_recursive` layering keeps its default exceptions (a plain list entry would replace `user_preferences`). Tests: `merge_users_settings_test` (callback with stand-in hook, merge order reproduced as arrays incl. the counter-example, registration accepted by core's hook manager without debugging, real classes used when present) | tool_mergeusers is not installed in the test trees; the real merge must be re-run by the lead (Gate 2) |

Runner results for build 2026102502 (`ltirun ... all`, 2026-10-25): all six static checks OK on all four combinations;
PHPUnit 4.5 pgsql/mariadb OK, 507 tests, 940 281 assertions; 5.3 pgsql/mariadb OK, 507 tests, 940 286 assertions,
40 accepted PHPUnit 11 deprecations. Behat was not re-run (no feature affected). CI not executed.

Mutation check for the Gate 2 fixes: with the 0.1.0 `importer.php` and without `db/hooks.php` in the mirrored tree,
`test_database_error_ends_the_job_as_failed`, `test_overlong_category_is_guarded` and
`test_registration_without_tool_mergeusers_is_silent` failed (3 of 18 tests); the code was restored afterwards.

## Release 0.2.0 / 2026102501 (re-audit rework, 2026-10-25)

CI has **not been executed**. No item below is "CI passed" or "functionally accepted". Mutation check (a test goes RED when the old
fragile code is restored): done for DIA-04 - the 0.1.0 `url_guard.php` and `curl_fetcher.php` from the 0.1.0 package were put
back into the mirrored Moodle tree and `url_guard_test` plus `curl_fetcher_test` were run: 56 of 159 tests failed or errored
(13 errors, 43 failures); then the new code was restored. The other fixed findings have regression tests that assert the
corrected behaviour (listed per item); only DIA-04 was mutation-checked against the old code.

| Item | Status | What exists | Limits |
|------|--------|-------------|--------|
| FAM-01 upgrade | automated tests (local) | `db/upgrade.php` step `< 2026102501`: creates missing tables, repairs retention settings, audits kept private targets; `upgrade_test` (6 tests: data kept, idempotent, missing table recreated, settings, release information, schema equals install.xml). A real upgrade was also run on the 4.5/pgsql site: installed version reset to 2026102401, retention 0 and private targets 1 seeded, `admin/cli/upgrade.php` executed: version 2026102501, retention 30, audit row `upgrade_allowprivate`, existing audit rows kept | Real-site proof on one combination only; no data of a productive 0.1.0 site |
| DIA-01 observability | automated tests (local) | `local\observability`, tests `observability_test` (9), shown in health check, evidence tab, JSON/CSV/Markdown | Levels describe what this installation can see; `payload` only for the plugin's own successful JWKS check; no hook in Moodle core for OIDC/AGS/NRPS (gates G1, G2 open) |
| DIA-02 health check | automated tests (local) | root cause along the dependency chain, team, config location, safe verification, remote uncertainty; `diagnosis_scenarios_test` (8: broken-then-fixed pairs per layer, consequence marking, no invented cause), `matrix_health_test` | The cause is the most likely one, never proven; remote side only reachable through own probes |
| DIA-03 retrospective correlation | automated tests (local) | multi-logstore reading (standard + `logstore_database` pointed at the same table, the technique core uses), coverage per source, task log retention, import deletion; `retrospective_test` (9), `sources_test`, `accesslog/importer_test` | Logstore plugins other than standard/database not exercised; a second store in a real external database not exercised |
| DIA-04 SSRF | automated tests (local); mutation-checked | pinned resolve-once fetch, per-hop validation, peer check, IPv4/IPv6 forms; `url_guard_test`, `curl_fetcher_test` (17, with a scripted transfer layer), `probe_runner_test` (5), `active_checks_test` | No real network was used for the pin; with a proxy the pin is not effective (reported); no test against a real rebinding DNS server |
| DIA-05 accessibility | automated tests (local) for structure; Playwright `accessibility.spec.js` 9/9 passed 2026-10-25 (Chromium, two-site stack, lead run; table checks scoped to direct children after a spec bug counted nested captions) | `accessibility_test` (6: captions, scoped headers, badge text, ids/labels, heading order), 3 new non-JS Behat scenarios, `tests/playwright/accessibility.spec.js` (5 tests, only `node --check` validated) | No screen reader test, no automated contrast measurement (contrast is by explicit text colours on both Bootstrap generations), keyboard operation only in the unexecuted Playwright spec |
| FAM-04 checklist | code present | see below | |

### FAM-04 production-quality checklist (this plugin)

| Area | State |
|------|-------|
| Version, release, changelog consistent | TL: `upgrade_test::test_release_information_is_consistent` |
| Upgrade path from 0.1.0 | TL, real run on 4.5/pgsql |
| Static analysis | phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings on 4.5 and 5.3 |
| Capabilities with risk bits, no default for lay users | TL: `access_control_test` |
| Privacy API, retention, deletion | TL: `privacy/provider_test`; import deletion |
| SSRF defence | TL, see DIA-04 |
| No secrets in reports, logs | TL: `redactor_test`, `reporting_test`, `journey_test` |
| Language packs en and de, key parity, placeholders | TL: `lang_test` |
| Accessibility | partly TL, see DIA-05 |
| Tests: no skipped, no risky, warnings fail the run | TL |
| Behat non-JS | TL (8 scenarios) |
| Browser E2E | 0.1.0 specs executed 2026-10-25; 0.2.0 accessibility spec executed 2026-10-25 by the lead: 9/9 passed (Chromium only) |
| CI | **not executed** |
| Performance with large logs | NV: row limits and coverage notes exist, no load test |
| Moodle 4.5 and 5.3, PostgreSQL and MariaDB | TL, see table |
| Functional acceptance by the owner | not done |

## Runner results (`ltirun ... all`, 2026-10-25, version 0.2.0 / 2026102501)

| Tree | DB | phplint | validate | savepoints | mustache | codechecker (0 warnings) | phpdoc (0 warnings) | PHPUnit | Behat (non-JS) |
|------|----|---------|----------|------------|----------|---------------------------|----------------------|---------|----------------|
| 4.5 | pgsql | OK | OK | OK | OK | OK | OK | OK, 498 tests, 940 189 assertions | 8/8 scenarios, 71 steps |
| 4.5 | mariadb | OK | OK | OK | OK | OK | OK | OK, 498 tests, 940 189 assertions | 8/8, 71 steps |
| 5.3 | pgsql | OK | OK | OK | OK | OK | OK | OK, 498 tests, 940 194 assertions, 39 PHPUnit deprecations | 8/8, 71 steps |
| 5.3 | mariadb | OK | OK | OK | OK | OK | OK | OK, 498 tests, 940 194 assertions, 39 PHPUnit deprecations | 8/8, 71 steps |

The PHPUnit 11 deprecations on 5.3 are the accepted ones (docblock `@covers` / `@dataProvider` metadata; the brief fixes
PHPUnit 9 style). No test was skipped or risky (`--fail-on-skipped`, `--fail-on-risky`).

### Tests per layer (test methods; 498 executed tests include data provider sets)

| Layer | Methods | Files |
|-------|---------|-------|
| PHPUnit unit (pure logic, fakes) | about 170 | status_engine, redactor, parser, url_guard, curl_fetcher (scripted transfer), probe_runner, correlation, active_checks, observability, diagnosis_scenarios, matrix_health, importer, lang |
| PHPUnit integration (real core flows and APIs) | about 110 | observer, journey, inventory, sources, retrospective (two real log stores), reporting, privacy provider, access_control, accessibility (rendered output), upgrade |
| Gate evidence | 12 | gates_test (G1 to G7) |
| Behat (non-JS) | 8 scenarios | `tests/behat/ltidiagnostics.feature` - **executed 2026-10-25, all passed** on 4.5 and 5.3, PostgreSQL and MariaDB (local, not CI) |
| Playwright E2E | 4 tests (story) + 9 accessibility tests, executed 2026-10-25 (Chromium): 13/13 twice, order check 17/17 | `tests/playwright/` |

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
| Behat | TL (non-JS, 8 scenarios) | `tests/behat/ltidiagnostics.feature` |
| German and English language packs | TL | `lang_test` |
| CI | NV | The workflows are adapted from the template and have not run |

## Open gates

G1 (no OIDC/JWT hook) and G2 (no AGS/NRPS/DL service hook) are open: the plugin reports `not_observable` unless the access log is
imported. Tool-side AGS cannot reach `verified` (no authorised test). Not implemented: iss/aud mismatch detection, claim recording, partner diagnostic package (DIA-UX-08), mutating tests.

## Re-run after the rename of the four sibling plugins (2026-10-25)

This plugin kept its name and version (`2026102502`); only README/CHANGELOG text changed. Re-run on the switched test sites
(old sibling components uninstalled, renamed ones installed fresh):

* `ltirun <45|53> <pgsql|mariadb> tool_ltidiagnostics all`: static checks OK, PHPUnit 507 tests OK on all four combinations
  (5.3: 40 PHPUnit deprecations, accepted). `ltifamily`: 1369 tests OK on all four.
* Behat (non-JS, `ltibehat`): 8 scenarios / 71 steps passed on all four combinations.
* Playwright (Chromium, two-site stack): **red when the whole suite runs in one go**: 9 passed, 1 failed -
  `ltidiagnostics.spec.js` "platform matrix shows AGS and NRPS configured" saw `blocked`. Reproduced twice. Cause: test order
  dependency - `accessibility.spec.js` (runs first) submits the health check with the keyboard; the stored result of its active
  probe marks the AGS row of the same connection as blocked. After `e2e-reset`, `ltidiagnostics.spec.js` alone: 4/4 passed. Not
  caused by the rename (no code of this plugin changed). **Resolved** in the post-rename cleanup: the specs now create/clean their own state (see "Post-rename cleanup re-run").
* Release gates (`/home/claude/gates`, rename runs): Gate 1 for this plugin is still the 0.1.0 ZIP -> 0.2.0 upgrade.
