# Changelog

## 0.4.0 - 2026-11-02 (alpha), version 2026110200

Issues #1 to #9 of the development branch. Upgrade step 2026110200 from 0.3.0 (2026102700): three new tables, new
settings with the grade replay switched off; nothing existing changes.

* **#3 Grade diagnosis (read only).** New tab "Grades (AGS)" per tool side connection: core grade sync prerequisites
  (auth/enrol LTI, scheduled and ad hoc tasks, task log), per consumer resource link and learner the prediction of
  core's next run, the source grade, the AGS user id core would send and its source, the last evidence and risks.
  Findings with severity, certainty, limit and responsible side, among them `gr_lastgrade_blocks` (lastgrade shared by
  all consumers of a publication), `core_sends_other_subject` (the first consumer's `sourceid` sent to another one),
  `gr_zero_never_sent`, `gr_no_service`, `gr_no_scope`, `gr_unconfirmed`. Constant query count for 300+ learners.
* **#4 Grade attempt journal.** Table `tool_ltidiagnostics_gatt`: core's sends imported from the task log of
  `sync_tool_grades` (bounded, idempotent, classified error classes, exception text never kept) and every state change
  of an own replay, never mixed. Keyset paging, retention `retentiongradedays`.
* **#9 Replay security.** Capability `tool/ltidiagnostics:repairgrades` (no archetype), site setting `graderepair`
  (off), opt-in per connection (audited), sesskey, locks, one open attempt per target, rate limit per operator and
  hour, batch limit, SSRF guard with address pin and no redirects, endpoint host bound to the issuer or token host,
  no token, body or subject stored, redacted reason, privacy provider and tool_mergeusers declarations.
* **#5 Grade replay.** Unchanged grade to exactly one consumer, line item and learner after a dry run and a
  confirmation; ad hoc task `grade_repair_task`; never a lastgrade reset, never a new line item; AGS score state with a
  monotonic version and strictly increasing whole-second timestamps (table `tool_ltidiagnostics_gstate`, never a future
  timestamp); 429/5xx/network errors retried with backoff, 409 never retried with a newer timestamp.
* **#6 Booking evidence.** AGS result check after a replay and per row (`results_checker`; Moodle's `scoreOf` with
  `?type_id=` is compared by path); signed grade receipt of the consumer
  (`receipt.php` on the platform, request/receipt schemas, JWT verified with the platform key set, table
  `tool_ltidiagnostics_grcpt`).
* **#7 Completion tab.** Separate tab reading local_lticompletionsync (when installed): sender and receiver classes,
  revocations, partner status, links to its actions (check partner, retry now, requeue, full reconcile, force resync)
  with their reach; nothing reimplemented, no grade data used.
* **#8 UX and support package.** Separate grade and completion workflows with captions, row headers and text badges;
  support package `ltidiag-support-2` (JSON, Markdown, CSV) split by provider, consumer, both, unknown, without names,
  user ids, tokens, scores or raw URLs.
* **#1 CI: no PHP 8.1.** The 4.5 cells of the dev and main matrices run PHP 8.2 (PostgreSQL), the coverage job PHP 8.3;
  no job or cell uses PHP 8.1 or older. `version.php` and the declared support are unchanged.
* **#2 CI: no release gate.** Job `maturity-gate` ("Release gate") removed; `ci-complete` aggregates the functional jobs
  (`test`, `release-artefact`, `coverage`, `stale-files`) directly, each with its own result.
* **Fixes found by the two-site E2E run.** `results_checker` compared Moodle's `scoreOf` (with `?type_id=`) with the
  line item URL without its query and reported every real result as a line item mismatch; `package.php` called
  `send_file()` without `lib/filelib.php` and answered every support package download with an error page (the Behat
  scenario now downloads and reads it).
* **Fix found by the load test on 5.2/PostgreSQL.** The latest journal row per link and learner was read with a join on
  an aggregate; right after a bulk insert (statistics not yet updated) PostgreSQL chose a nested loop that repeated the
  aggregate and one page of the diagnosis ran for minutes. `journal::latest()` and the lookup of unattributed core
  sends now read the maximum ids first and then the rows by id (two bounded statements, constant per page).
* **Tests.** PHPUnit for every class above (grade scenario fixture with a control and a BUW-like consumer and core's real
  grade sync; load test with 100 000 journal rows and 1 000 mappings), Behat `grades.feature` (5 scenarios), Playwright
  two-site suites `grades_two_site.spec.js` (11) and `completion_two_site.spec.js` (8) on Moodle 4.5 (tool) and 5.2
  (platform), stack in `tests/playwright/stack/`. The grade scenario overrides core's `get_mock_launch()` with
  `willReturnCallback()` (PHPUnit 11 deprecates `will($this->returnCallback())`).

## 0.3.0 - 2026-10-27 (alpha), version 2026102700

Family audit 2026-10-27 (development branches), items DIA-01 to DIA-05 and GA-03. Replaces the 0.1.0 on the GitHub
`development` branch; upgrade path from the last delivered build 2026102600 (0.2.1) and, through the earlier steps, from 0.1.0.

* **CI (lead, 2026-10-28).** Dev and main workflows run `tools/deprecation_report.php` on the PHPUnit log after every
  PHPUnit job, so PHP deprecations, skips, incomplete and risky tests fail the job with their attributed source
  (actionlint clean; not yet executed on GitHub).
* **Test fix (lead, 2026-10-28).** `family_logstore_test::test_queries_are_bounded` read the clock twice and failed once
  in the full matrix (5.1/MariaDB/PHP 8.2) when a second boundary fell between the two reads; it now reads it once.

* **DIA-01 no hidden deprecation.** Every test class runs inside a deprecation guard (`tests/classes/deprecation_guard.php`,
  trait `deprecation_guarded`): each `E_DEPRECATED`/`E_USER_DEPRECATED` is recorded with its source (plugin code, plugin
  tests, Moodle core, vendor) and the call stack, handed on to the runner, and fails the test with the full report. On
  Moodle 4.5 (PHPUnit 9) a deprecation was only printed output (risky) and was lost completely inside an output buffer.
  Self test `deprecation_guard_test` (including the PHP 8.4 `str_getcsv()` deprecation of the main CI run, attributed to the
  plugin's test code). `tools/deprecation_report.php` classifies the deprecations, PHPUnit runner deprecations (the
  owner's docblock metadata) and skipped/incomplete/risky tests of a PHPUnit output and fails on anything but the
  accepted docblock metadata.
* **DIA-02 message matrix.** New `local\message_matrix`: per protocol message and data direction (OIDC, launch, key sets,
  OAuth token, AGS line items/scores/results, NRPS with paging, deep linking request/response, completion round trip,
  custom and unknown claims, service permissions, key rotation, local effects, group assignment) the separate
  statements configured / observed (level and time) / verified / booked / not observable, and the chains launch,
  grades, roster and completion with a verdict (end_to_end, incomplete, limited, failed, not_configured). An HTTP 200
  of a web server log is "observed (transport only)" and never verifies (observability cells now carry the level of the
  sources that really saw something, `seenlevel`). New `local\field_matrix` of the launch message: platform column
  from the settings, tool column from validated launches, stored resource links and a placeholder check of the
  name/e-mail claims (the observer records only "present"/"absent" markers, never a value). New tab "Messages and
  fields"; JSON/Markdown/CSV exports carry messages, chains, fields and per finding system, certainty and safe check.
* **DIA-02/03 partner diagnostic package** (spec V2 DIA-UX-08): export of the non-sensitive view of a connection (hashes
  and masked forms of iss/client id/deployment id, endpoints, RFC 7638 key thumbprints, field column), import on the
  partner site, comparison with findings `partner_iss_mismatch`, `partner_aud_mismatch`, `partner_deployment_mismatch`,
  `partner_registration_missing`, `partner_registration_incomplete`, `partner_endpoint_mismatch`, `partner_key_stale`,
  `partner_role_conflict`, `partner_ok`; read-only two-system live check of the partner's key set
  (`partner_live_kid_ok` / `partner_live_kid_missing` / `partner_live_unreachable`). Page `partner.php`, command line
  `cli/partner.php`. Nothing in an LTI setting is changed.
* **DIA-03 diagnosis.** Every finding names cause, uncertainty (`certainty`: certain / likely / possible, plus the text of
  what is not visible), responsible system (`this_site`, `partner_site`, `network`, `both_sites`) and a concrete safe
  check (`f_<code>_check` strings for the infrastructure causes). New findings `oauth_scope_not_granted` (tokens of
  mod_lti without the score scope), `bg_cron_gap` (period without any task run, from the new cron windows). Network:
  an exact allow-list of private targets (`privatetargetallowlist`, host:port; narrower than "allow private targets",
  needs `runprivatechecks`, audited); through a Moodle proxy the tunnel is pinned to the validated address with
  `CURLOPT_CONNECT_TO` (no DNS rebinding through the proxy); proxies from the process environment are switched off for
  direct checks (they bypassed the pin); host name mismatch since curl 7.62 (error 60, OpenSSL result 0) is classified
  as `cert_hostname`; a connect timeout of a pinned target is `firewall_drop_suspected` (certainty "possible"); the
  system resolver falls back to the hosts file. Reproducible negative integration tests with a local laboratory (minted
  certificates, proxy 407, dropping listener, NXDOMAIN, redirects to private targets).
* **DIA-04 log sources and performance.** Access log facts come from one aggregated query (before: only the oldest 1 000
  lines of the window were counted); keyset pagination of imported lines (`importer::page()`), the timeline shows the
  newest lines; cron windows from the task log (`task_source::cron_windows()`, bounded, reports truncation); import
  rate limit per person and hour (`importratelimit`, default 10); retention per data class (`retentionaccdays`,
  `retentionauditdays`, `retentionsnapdays`, `retentionpartnerdays`); two new indexes (`acc.category_time`,
  `imp.user_time`). Large-data tests with 100 000 log store rows and a 100 000 line gzip access log (query counts,
  memory, run time).
* **DIA-05 completion sync transfers.** New `local\transfer_report` / page `transfers.php` / `cli/transfers.php`: per
  correlation id the stages source event, outbox, task (time containment in the plugin's task runs, never guessed),
  HTTP/JWT, receiver, native completion (checked against `course_modules_completion`), acknowledgement, retry,
  revocation; partner trace export/import (correlation ids, stages, times, codes only) joins both sites; verdicts
  complete, failed, limited_no_partner_mode, limited_one_side, open; persons masked per connection. The short result code
  of local_lticompletionsync (its `other['detail']`) is now read (it was empty before).
* **GA-03 tool_ltigroupautoenrol.** Optional read-only adapter `local\groupenrol_source`: mapping and failure counter of
  the plugin's table (columns read only when present), its events `group_assignment_completed` / `group_assignment_failed`
  (1.2.1) through the observer and the log stores, fallback to the core group event in the launch request. Message row
  `group_assignment`, findings `ga_failures`, `ga_assignment_missing`, `ga_assigned`. No dependency.
* Upgrade step 2026102700: the two indexes, the new settings with the behaviour of 0.2.1 (imported lines keep the old
  common retention), existing settings untouched; the step 2026102502 now copes with the new index.
* **Merging users (request from local_ltiaccountmerge's merge preview).** The tool_mergeusers callback now declares every
  table with a user column (`merge_users_settings::USER_TABLES`: audit, obs, imp, snap, profile) as an exception with
  string keys. Before, only the audit table was declared and `tool_ltidiagnostics_obs` blocked the preview as undeclared.
  Behaviour change: observations, import jobs, snapshots and profiles no longer follow a merge; they keep the original
  account id as history (reasons per table in DOCUMENTATION). Test: every user column of install.xml is declared, with a
  negative control.
* Fixed (found by the full matrix, 2 of 24 cells): the probe classifier treated any curl error that contained "connect"
  and "407" as a proxy authentication demand. A connect timeout to a port such as 45407 ("Failed to connect to ... port
  45407 after 2001 ms") was reported as `proxy_407` instead of `firewall_drop_suspected`, also without any proxy. Only
  an HTTP 407 or curl's own CONNECT wordings ("CONNECT tunnel failed, response 407", "Received HTTP code 407 from proxy
  after CONNECT") count now (`error_classifier::is_proxy_407_message()`); regression cases in `active_checks_test`.
* Fixed (present since 0.1.0, found by the new stack spec): `export.php` turned an empty from/to filter into the current
  time (`strtotime(' UTC')`), so every exported report evaluated the empty window [now, now] and showed nothing as
  observed. Empty filters now mean "no limit", as on the connection page.
* About 280 new strings in English and German.

## 0.2.1 - 2026-10-26 (alpha), version 2026102600

Gate CS-G6 (found by local_lticompletionsync): the transfer events of LTI family plugins were not visible.

* Log store source: besides the fixed list of core and mod_lti events, a second query reads the events of the LTI family
  by component prefix (`local_lti*`, `ltisource_*`, see `family_events::PREFIXES`). Both queries are bounded: a time
  window (default 7 days before "to" or now, `timecreated` is the indexed column of the standard store) and a row limit
  each; a cut is reported (`familytruncated`) and marks the source coverage as incomplete. From "other" only
  `correlationid`, `connectionid`, `version` and `code` are taken over.
* Correlation: the same non-empty `other['correlationid']` is a `direct` correlation whatever the time difference; timeline
  edges follow it.
* Timeline: family events of the connection (or of no resolvable connection) appear as items, and a new table groups them
  by correlation id into transfers with steps, outcome (acknowledged, applied, failed, open) and retries. Connection ids of
  local_lticompletionsync are resolved through its connection table when that table exists.
* Evidence tab: a table of the family transfer events with correlation id, connection and code; the observability table
  has a new row "Completion sync" (tool: outbound sent/acknowledged; platform: inbound received/applied) with the level
  `recorded` when the plugin is installed and a log store logs or event recording is on, `transport` from an imported web
  server log (new access log category `completion_sync`), otherwise `not_observable` naming the missing plugin.
* Observer: one registration on `\local_lticompletionsync\event\sync_event` (the base class of its transfer events);
  observations keep the correlation id in the new column `tool_ltidiagnostics_obs.correlationid` (indexed, upgrade step
  2026102600). Without the plugin the registration is inert.
* No dependency on local_lticompletionsync: no class of it is loaded by the plugin code, its table is read only after
  `table_exists()`.
* Fixed during the stack run: the transfer table escaped its values twice (`&amp;gt;`).
* Kept: the lead's change to `tests/local/reporting_test.php` (`str_getcsv` with an explicit `$escape`, PHP 8.4).

## 0.2.0 - 2026-10-25 (alpha), build 2026102502 (Gate 2 fixes)

### Post-rename cleanup (2026-10-25, version unchanged)
* Playwright: the specs are independent of their order. `ltidiagnostics.spec.js` removes the stored active-check results and observations of the two connections it asserts on before it starts; `accessibility.spec.js` removes stored probe results before it starts and the ones it created when it ends (it submits the health check). Before, a full-suite run left the accessibility spec's failed probe stored and the main spec saw AGS as `blocked` (9 passed, 1 failed). New helpers in `tests/playwright/lib.js` (`resetConnectionState`, `countProbes`) and `playwright.order.config.js` (reverse order: main -> accessibility -> main).
* CI workflows: removed the template job `ecosystem-lockstep`, which cloned `moodle-{auth,enrol,mod,tool}_<name>` repositories that do not exist and failed every tagged build; `maturity-gate` no longer waits for it. The family plugins are independent and versioned separately.
* Playwright workflow: syntax-checks every file through `npm run check` (it missed `accessibility.spec.js`).
* CI workflows: the reduced and full matrices (4.5 to 5.3, PostgreSQL and MariaDB) are unchanged. The dev pipeline's code checker runs without an installed Moodle and now gets the plugin path (`moodle-plugin-ci codechecker ... plugin`); without it the blocking step stopped with "Not enough arguments (missing: plugin)". Header comments state the real matrix; template wording about sibling plugins was removed. Validated with a YAML parser and a path/name check (see TESTING.md).

* Family overview (README) and documentation updated to the renamed sibling plugins (owner decision 2026-10-25:
  `local_ltiidentity`, `ltisource_ltipseudonym`, `local_ltiaccountmerge`, `local_ltixsembed`). This plugin keeps its name and
  its upgrade path from 0.1.0; it has no dependency on the siblings (they are found by the generic `local_lti`/`ltisource_`
  prefixes only).
* F3 (Medium, present since 0.1.0): importing an access log with an LTI AGS line item request failed with a database
  write error, because the category `service_ags_lineitems` (21 characters) did not fit `tool_ltidiagnostics_acc.category`
  char(20); the import job stayed `running`. The column is now char(40) (install.xml and upgrade step 2026102502), every
  category is declared in `parser::CATEGORIES` and checked against the column length, an unknown or too long category is
  stored as `other`, and any error during an import ends the job as `failed` (`dberror` / `readerror`), never `running`.
  The upgrade step also closes jobs that were left `running` for more than one hour (`interrupted`).
* F5 (Low): tool_mergeusers rewrote the actor of audit rows to the surviving account. The plugin now registers a callback
  for `\tool_mergeusers\hook\add_settings_before_merging` (db/hooks.php) that excludes `tool_ltidiagnostics_audit` from the
  merge, so audit entries keep the account that acted. Observations, imports, snapshots and profiles still follow the
  merge. Without tool_mergeusers the registration is inert (no debugging; tested).

### 0.2.0 build 2026102501

Re-audit rework (audit items FAM-01, DIA-01 to DIA-05, FAM-04). Version 2026102501.

* FAM-01: release 0.2.0 / 2026102501. `db/upgrade.php` has a real, idempotent step from 0.1.0: it creates missing tables,
  repairs invalid retention settings, and writes an audit row when a 0.1.0 site had private targets enabled (the setting
  is kept but now also needs the new capability). Proven with `tests/upgrade_test.php` and by a real upgrade run from the
  installed 0.1.0 state.
* DIA-01: observability levels per transaction type, direction and request/response (`not_observable`, `inferred`,
  `transport`, `recorded`, `payload`). A level is a capability; the time a transaction was last seen is shown separately.
  Shown in the health check, the evidence tab and in all exports.
* DIA-02: one-click health check with a root cause: findings follow the dependency chain configuration, DNS/network,
  firewall, TLS, OIDC/JWT, OAuth2, Advantage services, Moodle core; the first blocking finding is the most likely cause,
  findings below a broken layer are marked as consequence. Each finding names the responsible team, the config location,
  a safe verification and what remains uncertain about the remote side. No cause is invented when nothing is wrong.
* DIA-03: retrospective correlation. All enabled log stores are read (standard, database and others) and merged without
  duplicates; per source coverage (first/last entry, row limit reached, task log retention) is shown in the timeline;
  no correlation from time alone (IP addresses never match); imports can be deleted (sesskey, confirmation, audited).
* DIA-04: SSRF hardening of `url_guard` and `curl_fetcher`: strict URL parsing (userinfo, ports, IDN, trailing dot,
  decimal/octal/hex/short IPv4, IPv6 zones, mapped/NAT64/6to4/Teredo/documentation ranges), the host is resolved once
  and pinned with `CURLOPT_RESOLVE` for every hop, each redirect is validated again, the peer address is compared after
  the transfer, TLS is strict, only https is followed, a configured proxy is reported as a limit. Private targets need
  the new capability `tool/ltidiagnostics:runprivatechecks` and are audited.
* DIA-05: accessibility: table captions and scoped headers, labelled regions, status badges with text and explicit text
  colours, hidden captions for screen readers, `aria-hidden` glyphs. Behat and Playwright accessibility checks.
* FAM-04: production-quality checklist in `docs/ACCEPTANCE.md`.
* New capability `runprivatechecks`, new settings handling, about 140 new strings in English and German.

## 0.1.0 - 2026-10-25 (alpha)

First MVP. Read-only diagnostics built on stable Moodle APIs.

* Connection inventory for both roles (mod_lti tool types, enrol_lti registrations and deployments).
* Capability matrix with the seven-step evidence ladder and a pure status engine. `verified` requires an observed
  transaction or an authorised test; configuration alone never verifies.
* Desired-state profile (JSON) and guided health check with layered findings, responsible party, risk and next safe step.
* Evidence sources: standard log store reader, scheduled and adhoc task inventory with `task_log`, own event observer
  (launch, user binding, enrolment, group membership, grade, view, unknown service call) with trace IDs, web server access
  log importer (nginx/apache, timezone and proxy aware), correlation classes direct / strong / tentative / unmatched.
* Safe active checks (GET only): JWKS, metadata, TLS, with an SSRF guard (https only, no credentials, internal and
  reserved ranges blocked, DNS rebinding protection, redirect re-check).
* Reports as Markdown, JSON and CSV with redaction, time-limited diagnostic mode with audit, Privacy API provider,
  eight capabilities, cleanup scheduled task.
* English and German language packs.
* Tests: PHPUnit unit and integration, Behat feature, Playwright two-site story. See `docs/ACCEPTANCE.md` for the status.

Known limits: no hook for OIDC/JWT/AGS/NRPS calls in Moodle (gates G1, G2), no mutating tests, no partner diagnostic
package, no iss/aud mismatch detection.
