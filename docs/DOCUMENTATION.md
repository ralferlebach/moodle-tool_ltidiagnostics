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

`direct` (shared transaction reference), `strong` (at least two shared stable references), `tentative` (one weak
reference or time proximity), `unmatched`.

### Active checks

GET only (`http_fetcher` has one method). The URL guard: https only, no credentials, host syntax, internal host names,
IPv4-mapped IPv6, CGNAT / benchmarking / IETF ranges, then core `curl_security_helper`. `active_checker` refuses
hosts whose DNS answer is internal (`resolved_private`), `curl_fetcher` pins the validated address (CURLOPT_RESOLVE) and
re-checks every redirect hop (at most 3). The setting `allowprivatetargets` lifts the range rules only, never the
scheme or credentials rules.

### Pages

| Page | Capability | State changing |
|------|------------|----------------|
| `index.php` | view | no |
| `connection.php` (tabs matrix, health, timeline, evidence, history) | view (+ runchecks for the check form) | active checks via Form API (sesskey) |
| `profile.php` | configure | form |
| `logimport.php` | importlogs | form |
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

Honest limits: no iss/aud mismatch detection (kid mismatch and clock skew are covered); the partner diagnostic package
(DIA-UX-08) is not implemented; a platform-side `lti_core` can never be `verified` (a view is only a local effect);
tool-side AGS has no authorised test and so stops at `authorised` or `configured`.

## 4. Configuration

| Setting (`tool_ltidiagnostics`) | Default | Meaning |
|-----------------------------------|---------|---------|
| `recordevents` | 1 | Record LTI related events (observer) |
| `retentiondays` | 30 | Delete recorded data after this time |
| `logallowlist` | empty | One directory or file per line; only these can be read |
| `maxlinesperimport` | 50000 | Upper bound per import |
| `activechecks` | 0 | Master switch for active network checks (off by default) |
| `allowprivatetargets` | 0 | Allow private address ranges for active checks |
| `diagmodemaxminutes` | 240 | Upper bound for the diagnostic mode |

Internal defaults without a settings page (set with `set_config` / `admin/cli/cfg.php`): `maxfilesize` 52428800 bytes
(upload limit for log imports), `clockwindow` 120 seconds (tolerated clock difference).

Database tables (all `tool_ltidiagnostics_*`): `obs`, `audit`, `profile`, `snap`, `imp`, `acc`.

## 5. Language packs

`lang/en` and `lang/de` are checked for equal keys, order and placeholders by `lang_test`; every finding code has
title, detail and fix strings in both languages.
