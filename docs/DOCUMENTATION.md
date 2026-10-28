# tool_ltidiagnostics - Documentation

## 1. Purpose and principles

A read-only diagnostic tool for LTI connections. Four principles drive the design:

1. **Evidence, not opinion.** Every statement carries the kind of evidence it rests on.
2. **`verified` is earned.** Only an *observed transaction* or an *authorised test* can verify a feature. Settings,
   declared metadata, reachable endpoints and green task states cannot.
3. **Unknown stays unknown.** What a remote party does cannot be seen from here; it is reported as `not_observable` or
   `unknown`, never guessed.
4. **No fork.** Only stable Moodle APIs are used. Where Moodle has no extension point the limit is documented (gates).

## 2. Architecture

```
inventory ──► connection ──► fact_collector ──► matrix_builder ──► status_engine ──► status_result
 (mod_lti,       (key:           (observations,     (ladder per         (pure rules)
  enrol_lti)      platform:N      tokens, access      feature)
                  tool:R:D)       log, tasks)
                                       │
                              health_check ◄── desired_profile
                                       │
                         finding ──► report ──► report_exporter (md/json/csv) ── redactor
```

| Area | Classes (namespace `tool_ltidiagnostics\local`) |
|------|--------------------------------------------------|
| Inventory | `inventory`, `connection` (keys `platform:<typeid>` and `tool:<registration>:<deployment row>`) |
| Status model | `status`, `evidence`, `status_engine`, `status_result`, `matrix_builder`, `implementation_detector` |
| Health check | `health_check`, `finding`, `desired_profile`, `profile_manager`, `snapshot` |
| Evidence sources | `logstore_source`, `task_source`, `observation_store` + `observer`, `accesslog\{parser,importer,path_allowlist}`, `fact_collector`, `entrypoint` |
| Correlation | `correlation`, `timeline`, `resolver` |
| Active checks | `probe_runner`, `active\{active_checker,url_guard,curl_fetcher,http_fetcher,dns_resolver,error_classifier,...}` |
| Reports and privacy | `report`, `report_exporter`, `redactor`, `diagnostic_mode`, `audit`, `support`, `privacy\provider` |

### Evidence ladder

Seven steps per feature: `specified`, `implemented`, `enabled`, `declared`, `authorised`, `reachable`, `observed`.
Step states: `yes`, `no`, `unknown`, `not_observable`, `blocked`, `na`.
Evidence kinds: `config`, `code_detection`, `db_record`, `observed_transaction`, `local_effect`, `authorised_test`,
`access_log`, `task_log`, `active_probe`, `none`.

### Status engine (first matching rule wins)

1. incompatible flag set -> `incompatible`
2. `specified` or `implemented` is `no` -> `not_supported`
3. `implemented` is `not_observable` -> `not_observable`
4. `implemented` is not `yes` -> `unknown`
5. enabled and a `no`/`blocked` on declared, authorised, reachable or observed -> `blocked`
6. `observed` is `yes` and enabled and the evidence kind is `observed_transaction` or `authorised_test` -> `verified`
   (otherwise an `evidence_insufficient` note is added)
7. `authorised` yes -> `authorised`; `enabled` yes -> `configured`; otherwise `supported`

`status_engine_test` checks the rules and an exhaustive invariant over about 466 000 combinations: `verified` never
occurs without a verifying evidence kind.

### LTI family events (0.2.1)

`family_events` recognises the plugins of the LTI family by component prefix (`local_lti*`, `ltisource_*`), so a new
member needs no change here. Contract: an event that belongs to a transfer carries `other['correlationid']` (same value
on both sites), optionally `connectionid`, `version` and `code`; nothing else from "other" is taken over. The eleven
transfer events of local_lticompletionsync (spec 08, section 7) are classified into steps, timeline stages and
directions (`COMPLETION_STEPS`). `logstore_source::fetch()` runs two bounded queries per log store (fixed list and family
prefix; time window, default `DEFAULT_WINDOW` = 7 days, and a row limit each). The timeline groups family events into
transfers by correlation id (`family_events::transactions()`), the evidence tab lists them, and the observability table
has the row `completion_sync`. A completion sync connection id is mapped to a connection key through
`local_lticompletionsync_con` only if that table exists (receiver: `platform:<type id>`, sender:
`tool:<registration>:<deployment>`); unresolved events are shown as "not resolvable", events of another connection are
not shown. No class of a family plugin is loaded.

### Observer

`observer` listens to a small set of core and mod_lti events and stores an observation per event: type, direction,
entry point class (`lti_launch`, `lti_services`, `cli`, `ws`, `web`), a trace ID, ids of course, activity and user, the
time. A launch only counts as launch evidence when the entry point is the LTI launch script. Failures inside the
observer never break the request.

### Access log importer

Parser for the common nginx/apache formats (combined, with and without forwarded addresses). Each line gets an HMAC hash
plus an occurrence counter so that importing the same file twice is idempotent. The CLF offset wins over the configured
time zone. Addresses are masked and query values removed before anything is stored. An address is never used alone for
correlation (`correlation::NEVER_REFERENCES`).

### Correlation classes

`direct` (shared transaction reference: the same trace id, or the same `correlationid` of a family transfer, whatever
the time difference), `strong` (at least two shared stable references), `tentative` (one weak
reference or time proximity), `unmatched`.

### Active checks

GET only (`http_fetcher` has one method). The URL guard (`url_guard`): https only, no credentials, strict host syntax
(decimal, octal, hex and short IPv4 are refused; IPv6 zones refused; IPv4-mapped, NAT64, 6to4, Teredo, documentation,
site-local and unique-local IPv6 are classified as internal), internal host names, CGNAT / benchmarking / IETF ranges,
then core `curl_security_helper`. `curl_fetcher` resolves the host **once**, validates **every** answer, and pins the
chosen address with `CURLOPT_RESOLVE` so that the connection cannot use a second, different lookup (DNS rebinding,
TOCTOU). Redirects are followed by the plugin itself (at most 3): each hop is parsed, validated, resolved and pinned
again, a redirect to http is refused. After the transfer the peer address is compared with the pinned one; a mismatch
discards the answer (`pin_mismatch`). TLS is strict (peer 1, host 2). With a configured web proxy the pin cannot take
effect; the result says so (`viaproxy`). The setting `allowprivatetargets` lifts the range rules only, never the scheme,
credentials or TLS rules, and using it needs the extra capability `runprivatechecks`; every private run is audited
(`runchecks_private`).

### Access log categories

`parser::CATEGORIES` lists every category the parser can produce (`service_nrps`, `service_ags_scores`,
`service_ags_results`, `service_ags_lineitems`, `service`, `oauth`, `oidc`, `jwks`, `dynreg`, `deeplinking`, `launch`,
`other`); `parser::CATEGORY_MAXLEN` equals the length of `tool_ltidiagnostics_acc.category` (40). The importer stores an
undeclared or too long category as `other`. An import job ends as `done`, `truncated` or `failed`; a failure while reading
or writing ends the job as `failed` with the reason `readerror` or `dberror` (no log content in the reason).

### Merging users (tool_mergeusers)

When the optional plugin tool_mergeusers is installed, `db/hooks.php` registers
`local\merge_users_settings::add_settings_before_merging` for its hook `add_settings_before_merging`. Since 0.3.0 it
declares every table of this plugin that has a user column (`merge_users_settings::USER_TABLES`), so a merge preview such
as local_ltiaccountmerge's never finds an undeclared table. All of them are exceptions (string keys, so the default
exceptions of tool_mergeusers and of other plugins stay in place) because each user column records history:

| Table | Column | Merge | Reason |
|---|---|---|---|
| `tool_ltidiagnostics_audit` | `userid` | unchanged | who viewed, exported or changed diagnostics (accountable act) |
| `tool_ltidiagnostics_obs` | `userid` | unchanged | the account an LTI event happened to; moving it would forge the surviving account's journey (second `account_created`, launch with another subject) |
| `tool_ltidiagnostics_imp` | `userid` | unchanged | who imported a web server log (provenance, accountable act); the import rate limit of the surviving account starts from its own imports |
| `tool_ltidiagnostics_snap` | `userid` | unchanged | who saved a snapshot (0 = scheduled task) |
| `tool_ltidiagnostics_profile` | `usermodified` | unchanged | who last changed a desired-state profile |
| `tool_ltidiagnostics_acc` | none | - | redacted log lines, no user column |

The rows keep the merged-away account's id. tool_mergeusers suspends that account rather than deleting it; the retention
task deletes the rows on schedule and the privacy provider still finds them under that id. To diagnose the surviving
person's history, look at both accounts. `tests/merge_users_settings_test.php` compares the list with every user column of
`db/install.xml` (tool_mergeusers' default field names plus any `*userid` column; negative control with an added column)
and, when local_ltiaccountmerge is installed, reads the settings with its `merge_preview::declare_tables()`.
Without tool_mergeusers the hook class does not exist, nothing dispatches it and core's hook manager accepts the
registration silently.

### Observability (DIA-01)

`local\observability` lists, for each transaction type (launch, OIDC login, JWKS fetch, OAuth2 token, AGS, NRPS, deep
linking, user binding, enrolment, gradebook update), each direction (this site as platform or tool) and request/response,
the highest level this installation can reach: `not_observable` < `inferred` < `transport` < `recorded` < `payload`.
The level is a capability of the installation (logging enabled, access log imported, own observer); the time the
transaction was last seen is a separate value. A log line with status 200 is `transport` only; `payload` exists only
for the plugin's own successful JWKS check.

### Health check and root cause (DIA-02)

`health_check` collects findings with layer, severity, responsible team, config location, safe verification and remote
uncertainty. The layers are ordered config, dns_network, firewall, tls, oidc_jwt, oauth2, advantage, moodle_core
(background tasks are independent). The first blocking finding along that chain is the root cause; findings in higher
layers are marked as consequence. "Configured, not yet observed" notes are never a cause. When nothing is wrong the
result has no cause and says that this is not proof that everything works.

### Retrospective correlation (DIA-03)

`logstore_source` reads every enabled and logging reader (`\core\log\sql_reader`), merges equal events (same name,
second, context, object, user; the n-th equal event of one store matches the n-th of another) and reports per reader
status. `task_source::coverage()` reports the task log retention (`task_logretainruns`). `timeline` shows per source the
first and last entry and whether the picture is known to be incomplete (row limit reached or retention). Correlation uses
trace ids, user/course/activity ids and time windows; an address never matches by itself.

### Pages

| Page | Capability | State changing |
|------|------------|----------------|
| `index.php` | view | no |
| `connection.php` (tabs matrix, health, timeline, evidence, history) | view (+ runchecks for the check form) | active checks via Form API (sesskey) |
| `profile.php` | configure | form |
| `logimport.php` | importlogs | form; delete an import (POST-like link with sesskey and confirmation) |
| `mode.php` | configure | POST, `require_sesskey` |
| `export.php` | export | POST, `require_sesskey` |
| `support.php` | viewsupport | no |
| `course.php` | viewcourse (course context) | no |
| `audit.php` | viewprivacy | no |

## 3. Feasibility gates and evidence

| Gate | Question | Result | Evidence test (`tests/gates_test.php` unless noted) |
|------|----------|--------|-------------------------------------------------------|
| G1 | Does Moodle expose a hook for the OIDC login / JWT steps? | **No.** The plugin uses launch-side events and, optionally, the imported access log. OIDC/JWT steps are `not_observable` otherwise. | `test_g1_no_core_extension_point_for_oidc_or_jwt_steps`, `test_g1_events_used_for_launch_evidence_exist`, `observer_test` |
| G2 | Are AGS/NRPS/Deep Linking calls observable? | **Only indirectly.** The only dedicated event is `unknown_service_api_called`. A grade written while the service script runs is seen as a gradebook event from that entry point. | `test_g2_no_event_for_service_calls`, `test_g2_service_entry_points_are_distinguishable`, `journey_test` |
| G3 | Is the log store readable through a generic API? | **Yes** (`\core\log\sql_reader`), other stores are different classes, so the adapter does not assume the standard table. | `test_g3_logstore_reader_interface`, `sources_test` |
| G4 | Is there an API to read the task log? | **No.** Scheduler and adhoc queue have APIs; `task_log` is read from its table. | `test_g4_no_task_log_reader_api`, `sources_test` |
| G5 | May the plugin read web server logs? | Only from an admin-configured allow-list (empty by default) or an upload. | `test_g5_access_logs_need_an_allow_list`, `accesslog/importer_test` |
| G6 | Can remote settings be queried? | **No.** Local settings yes; remote ones (`declared` for AGS/NRPS/DL on the platform) are `not_observable`. | `test_g6_local_versus_remote_settings`, `test_g6_implementation_detection` |
| G7 | Can active checks be done safely, and mutating tests? | GET-only checks with SSRF guard: yes. **Mutating tests are not implemented.** | `test_g7_active_checks_are_read_only`, `test_g7_probe_runner_rules`, `test_g7_no_mutating_test_context_exists`, `active/*_test` |

Honest limits: iss/aud mismatches are detected only by comparing the partner package (0.3.0; a single site cannot see
the other's values); a platform-side `lti_core` can never be `verified` (a view is only a local effect);
tool-side AGS has no authorised test and so stops at `authorised` or `configured`.

## 4. Configuration

| Setting (`tool_ltidiagnostics`) | Default | Meaning |
|-----------------------------------|---------|---------|
| `recordevents` | 1 | Record LTI related events (observer) |
| `retentiondays` | 30 | Delete recorded data after this time |
| `logallowlist` | empty | One directory or file per line; only these can be read |
| `maxlinesperimport` | 50000 | Upper bound per import |
| `activechecks` | 0 | Master switch for active network checks (off by default) |
| `allowprivatetargets` | 0 | Allow private address ranges for active checks (needs capability runprivatechecks, audited) |
| `diagmodemaxminutes` | 240 | Upper bound for the diagnostic mode |
| `privatetargetallowlist` | empty | Exactly allowed private targets, `host` or `host:port` per line (0.3.0) |
| `importratelimit` | 10 | Log imports per person and hour, 0 = no limit (0.3.0) |
| `retentionaccdays`, `retentionauditdays`, `retentionsnapdays`, `retentionpartnerdays` | 30, 365, 365, 30 | Retention per data class (0.3.0), see section 6.7 |

Internal defaults without a settings page (set with `set_config` / `admin/cli/cfg.php`): `maxfilesize` 52428800 bytes
(upload limit for log imports), `clockwindow` 120 seconds (tolerated clock difference).

Database tables (all `tool_ltidiagnostics_*`): `obs`, `audit`, `profile`, `snap`, `imp`, `acc`.

## 5. Language packs

`lang/en` and `lang/de` are checked for equal keys, order and placeholders by `lang_test`; every finding code has
title, detail and fix strings in both languages.

## 6. Release 0.3.0: messages, partner package, transfers, diagnosis parts

### 6.1 Message matrix (DIA-02)

`local\message_matrix::build()` is a pure function of the connection, the facts, the observability rows and the stored
probes. Per message and direction (seen from this site) it keeps five statements apart:

| Statement | Meaning | Source |
|-----------|---------|--------|
| configured | the local settings ask for the message (`yes`/`no`), or no setting decides (`na`) | connection facts |
| observed | the highest level of the sources that **really saw** the message (`seenlevel` of the observability cell) and the time | observer, log stores, imported web server log, task log, own probe, family events |
| verified | a successful exchange at level `recorded` or `payload` (request, response or both, per message) | see below |
| booked | the business effect is recorded here (`yes`/`no`), happens at the partner (`remote`) or does not exist (`na`) | observations of enrolment, account, grade, completion, group |
| not observable | no source of this installation can see the message (`potential` = `not_observable`) | observability specification |

State, strongest first: `failed` (the newest evidence is a failure: 4xx/5xx after the last success, dead letter, failed
probe, missing kid, missing scope) > `booked` > `verified` > `observed` > `configured` / `not_configured`; `not_observable`
when no source exists. **An HTTP 200 of a web server log is level `transport`, the row says "transport only" and is never
verified** (property tested over all combinations in `message_matrix_test::test_http_200_alone_never_verifies` and
`test_invariants_over_combinations`).

What verifies: tool side, a login recorded by the launch script (enrol_lti validated signature, iss, aud, nonce before);
local effects, an enrolment or account creation of the launch; platform side, an issued access token (core validated the
client assertion), a gradebook write from the service script (also books the score), a received and applied completion;
own probe of a key set with the expected kid. What never verifies: OIDC redirects, deep linking, NRPS, AGS line items and
results (no hook, web server log only), a grade or member sync task that ran (it does not prove acceptance).

Chains: platform `launch` = OIDC authentication request, launch; `grades` = token, score; `roster` = token, NRPS;
`completion` = completion round trip. Tool `launch` = OIDC initiation, launch, local effects; `grades`, `roster`,
`completion` alike. Verdict `end_to_end` only when every leg is verified and the last one is booked (or has no effect);
`limited` when a leg cannot be proven from this site (transport only, not observable, booked at the partner); `incomplete`
when a provable leg is not proven yet; `failed`; `not_configured`.

Field matrix (`local\field_matrix`): claims of the resource link launch with category (core, roles, context, identity, LIS,
AGS, NRPS, presentation, platform, custom, unknown). Platform column from the type settings (`always`, `yes`, `no`,
`per_activity`, custom parameter names). Tool column: `validated` for the core claims after a recorded launch;
`present`/`absent` for name and e-mail from the placeholder check of the observer (auth_lti stores sub, iss and a generated
example.com address when a claim is missing; identical in 4.5 and 5.3; only the markers `claim.<name>=present|absent` are
stored in `obs.fieldnames`); `present`/`absent` for resource link, context, custom id, AGS and NRPS claims from
`enrol_lti_resource_link`; roles, LIS, presentation, other custom and unknown claims are `not_observable` (no hook,
not persisted by core). Limit: local_ltiidentity may rewrite names; the marker then reflects the stored result.

### 6.2 Partner diagnostic package (DIA-02, DIA-03, spec V2 DIA-UX-08)

Schema `ltidiag-partner-1` (`local\partner_package`), at most 64 KiB, parsed strictly (unknown members dropped, every string
cleaned): role, pseudonymous diagnostic id, Moodle branch and plugin release, LTI version and state; `lti.issuer`,
`lti.clientid`, `lti.deploymentid` as SHA-256 of the normalised value plus a masked display form; `own` endpoints of the
site (platform: auth, token, key set; tool: login with registration id, launch, deep linking, key set) and its signing
key(s) as kid and RFC 7638 thumbprint; `expects`: the endpoints this site has configured for the partner; `statickey`:
thumbprint of a static public key of a platform type; `fields`: the field column. No secret, private key, token, user,
log line or payload.

`compare()` checks: role conflict; registration incomplete or missing deployment; iss (platform wwwroot against the
issuer registered on the tool), aud (client id), deployment id; endpoints each side expects of the other; static key
against the partner's current key. A mismatch of values held on both sides names `both_sites`; an endpoint configured on
one side names that side. Findings are `certain` (configuration of both sites). `live_check()` fetches the key set this
site has configured for the partner through the SSRF guard (GET, read only) and checks the declared kid and thumbprint.
Imported packages are stored as snapshot kind `partner` (retention `retentionpartnerdays`) and feed the health check, the
message matrix (key rotation) and the partner column of the field matrix.

### 6.3 Completion sync transfers (DIA-05)

`local\transfer_report` reads the transfer events (contract of local_lticompletionsync, spec 08 section 7: the eleven
events, `other` with correlationid, connectionid, version and the short code `detail`) from the log stores and the own
observations, and the plugin's task runs from `task_log`. Stages: source event (source_detected), outbox (queued), task
(the run of a task of the component whose time span contains the first send: `strong`; none: the stage stays missing),
HTTP/JWT (sent / http_received), receiver (identity_resolved, reconciled), native completion (completion_applied and the
completion record of the activity and user in `course_modules_completion`: `match`/`mismatch`), acknowledgement
(acknowledged / dead_letter), retry, revocation (completion_revoked, the native record must be "not complete").

Verdicts: `failed` (dead letter, rejected or conflict code, native mismatch), `limited_no_partner_mode` (acknowledged
with `tool_only_no_confirmation`: AGS progress only, native completion not confirmed by anybody), `open` (a required
stage of this side is missing), `limited_one_side` (no partner trace), `complete` (both sides' required stages present).
The partner trace (schema `ltidiag-trace-1`, at most 512 KiB / 500 transactions) holds correlation ids, stages, times,
codes and the native check only. The clock difference of the sites is estimated from the HTTP stage (receiver minus
sender). Persons: `P-` + 8 hex of HMAC(site identifier and connection key, user id); not part of the trace.

### 6.4 tool_ltigroupautoenrol adapter (GA-03)

`local\groupenrol_source`: reads `tool_ltigroupautoenrol` (after `table_exists()`; optional columns `event_errors`,
`event_errortime`, `event_errorlast`, `backfill_time` only when present) for the courses of the connection's published
tools: enabled mapping of the connection's tools, failure counter. Events of 1.2.1 (`group_assignment_completed`,
`group_assignment_failed`, objectid = enrol instance) are registered in `db/events.php` (inert without them) and listed in
`logstore_source::EVENTS`. Older versions: the core `group_member_added` in the launch request. Findings `ga_failures`
(certain, from the plugin's counter or failed events), `ga_assignment_missing` (an LTI enrolment of a mapped tool without
a group membership in the launch request; likely), `ga_assigned`. Without the plugin nothing is read and the message row is
`not_observable` with the limit "plugin absent".

### 6.5 Diagnosis parts (DIA-03)

`finding::diagnosis()` returns cause (title, detail), certainty (`certain`: read from data of this site; `likely`: the
symptom was observed by a probe, an access log or a transaction, the named cause is the most probable; `possible`: only an
indication, e.g. a connect timeout), uncertainty (what cannot be seen, `uncertain_<layer>`, and the documented
alternative cause), responsible system (`this_site`, `partner_site`, `network`, `both_sites`) and responsible team, a
concrete safe check (`f_<code>_check` or the layer's verification), the fix and where the setting lives. Shown in the
health check, the partner page and every export.

### 6.6 Network laboratory and SSRF rules added in 0.3.0

* Exact allow-list `privatetargetallowlist` (`host` or `host:port`, port 443 by default): only that host and port may
  resolve to private addresses; https stays mandatory; the address is pinned; a redirect to another private target is
  refused; using it needs `runprivatechecks` and is audited (`runchecks_private`, `partner_live_private`).
* Through a Moodle proxy: `CURLOPT_CONNECT_TO` host:port:validated-address:port, so the proxy opens the tunnel to the
  validated address (`pinmode` = `connect_to`); without a Moodle proxy: `CURLOPT_PROXY` = '' switches off proxies of the
  process environment (`https_proxy`), which would otherwise resolve the name themselves.
* Classification: curl >= 7.62 reports a host name mismatch as error 60 with OpenSSL result 0: `cert_hostname`; a connect
  timeout without a connection (also when the address was pinned and the lookup time is about 0): `firewall_drop_suspected`.
* `system_dns_resolver` falls back to `gethostbynamel()` (hosts file), like curl.

### 6.7 Retention and limits (DIA-04)

| Data class | Setting | Default |
|------------|---------|---------|
| Observations | `retentiondays` | 30 days |
| Imported web server lines and import jobs | `retentionaccdays` | 30 days (an upgraded site starts with its `retentiondays`) |
| Audit of views and exports | `retentionauditdays` | 365 days |
| Snapshots, stored checks | `retentionsnapdays` | 365 days |
| Imported partner packages and traces | `retentionpartnerdays` | 30 days |

Limits: log store pages of at most 500 rows per query with a time window (indexed `timecreated`) and offset paging;
imported lines by keyset pages (`logtime`, `id`) of at most 500 and aggregated counts in one GROUP BY; cron windows from
at most 20 000 task log rows (the result says when it was cut and from when it is complete); imports per person and hour
(`importratelimit`), lines and bytes per import (`maxlinesperimport`, `maxfilesize`). Correlation stays `unmatched` when only
time or an address would link two rows.

### 6.8 Gates after 0.3.0

G1/G2 are unchanged: Moodle has no hook for OIDC/JWT and AGS/NRPS/DL calls. The partner package and the partner trace
are the documented, non-instrumenting way to see the other half; the message matrix names every message that stays
`not_observable` or "transport only".
