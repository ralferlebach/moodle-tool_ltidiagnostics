# Testing

All commands from anywhere; `ltirun` mirrors the plugin into the Moodle tree.

```bash
env -u JAVA_TOOL_OPTIONS /home/claude/bin/ltirun <45|53> <pgsql|mariadb> tool_ltidiagnostics <mirror|upgrade|static|phpunit [args]|all>
```

`env -u JAVA_TOOL_OPTIONS` is needed, otherwise the Mustache lint (Java based validator) fails. The suite is
`tool_ltidiagnostics_testsuite` with `--fail-on-warning --fail-on-risky --fail-on-skipped`. Do not bump `version.php`
unless the schema changes (a bump triggers a slow PHPUnit re-initialisation).

## Layers

| Layer | Where | Run |
|-------|-------|-----|
| PHPUnit unit | `tests/local/*_test.php` (status engine, redactor, parser, URL guard, correlation, profile, matrix) | `ltirun ... phpunit` |
| PHPUnit integration (real core flows) | `tests/observer_test.php`, `tests/journey_test.php`, `tests/local/inventory_test.php`, `tests/local/sources_test.php`, `tests/local/reporting_test.php`, `tests/privacy/provider_test.php`, `tests/access_control_test.php` | same |
| Gate evidence | `tests/gates_test.php` (G1 to G7) | same |
| Language | `tests/lang_test.php` | same |
| Static | phplint, validate, savepoints, mustache, codechecker (0 warnings), phpdoc (0 warnings) | `ltirun ... static` |
| Behat (non-JS) | `tests/behat/ltidiagnostics.feature` | Run `ltirun <tree> <db> tool_ltidiagnostics mirror`, then `ltibehat <45|53> <pgsql|mariadb> tool_ltidiagnostics`. Executed 2026-10-25 (0.2.0): 8 scenarios, see ACCEPTANCE. |
| Playwright accessibility | `tests/playwright/accessibility.spec.js` (captions, scoped headers, badge text, keyboard, focus; 9 tests) | Same command as the E2E row. Removes stored probe results of the platform connection before it starts and the ones it created (keyboard submission of the health check) when it ends. No screen-reader or contrast measurement. |
| Upgrade | `tests/upgrade_test.php` (real upgrade function from the 0.1.0 state, idempotence, kept data) | `ltirun ... phpunit`; the real run: set the installed version back to 2026102401, delete `allversionshash` from config, run `php admin/cli/upgrade.php --non-interactive` |
| Playwright E2E | `tests/playwright/ltidiagnostics.spec.js` (4 tests; reads `LTI_SEED`, default `/home/claude/e2e/state/seed.json`) | `NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js` from the plugin directory (both specs, alphabetical order). Order check: `-c tests/playwright/playwright.order.config.js` runs main -> accessibility -> main. Each spec removes the state it asserts on before it starts (`lib.js` `resetConnectionState`: stored probe results, and for the main spec the observations of the two connections), so no spec depends on another or on the order. **Executed 2026-10-25 (post-rename cleanup)**, Chromium, two-site stack: full suite twice 13/13 passed each, order config 17/17 passed. `lib.js` delegates login/seed loading/SQL to the stack library (`E2E_LIB`). |

### Added in 0.3.0

| Layer | Where | What it proves |
|-------|-------|----------------|
| Deprecation guard (DIA-01) | `tests/classes/deprecation_guard.php`, trait `deprecation_guarded` used by every test class; self test `tests/deprecation_guard_test.php` | every PHP deprecation fails the test with source and stack trace on 4.5 (PHPUnit 9) and 5.x (PHPUnit 11) alike; `str_getcsv()` of PHP 8.4 attributed |
| Deprecation report | `php tools/deprecation_report.php <phpunit output>` (use `ltirun ... phpunit --display-all-issues` on 5.x) | classifies PHP deprecations, guard failures, PHPUnit runner deprecations (docblock metadata), skipped/incomplete/risky; exit 1 on anything but the accepted metadata |
| Message and field matrix (DIA-02) | `tests/local/message_matrix_test.php`, `tests/local/field_matrix_test.php` | HTTP 200 never verifies (all messages, both roles), invariants over 2 000+ combinations, chains, real login event in the launch script for claim markers |
| Negative network integration (DIA-03) | `tests/integration/network_negative_test.php` with `tests/classes/netlab.php` and `tests/fixtures/netlab_server.php` | real TLS servers with certificates minted by the openssl command line (expired, missing intermediate, wrong host, untrusted root), unknown kid, NXDOMAIN through the system resolver, a real proxy answering 407 that logs the pinned tunnel target, a listener that drops connections, redirects to private targets, clock skew, two-system key check; each with cause, uncertainty, responsible system, safe check, and an LTI configuration fingerprint before/after. Needs `openssl` and the PHP binary of the run (`PHP_BINARY`); never skipped |
| Negative service integration (DIA-03) | `tests/integration/service_negative_test.php` | wrong iss/aud, missing registration and deployment, stale static key, missing AGS scope (real `lti_new_access_token`), cron outage (stale cron and task log gap), gradebook booking (imported 200 without and with a gradebook write), import/audit through the service, each with a positive control |
| Large data (DIA-04) | `tests/integration/large_data_test.php` | 100 000 log store rows, a 100 000 line gzip access log, keyset pages, bounded cron windows over 20 000 task log rows, import rate limit, retention per data class; query counts, memory and time (set `LTIDIAG_PERF=<file>` to record the measurements) |
| Merge declarations | `tests/merge_users_settings_test.php` | every user column of `db/install.xml` (tool_mergeusers' default field names plus any `*userid`) is declared to tool_mergeusers as an exception with a string key; negative control with an added column; local_ltiaccountmerge's `declare_tables()` when installed |
| Transfers (DIA-05) | `tests/local/transfer_report_test.php` | one-sided/two-sided, task by time containment, native completion match/mismatch, failed (dead letter, rejection), no-partner mode limited, masking, trace validation, real log store rows |
| GA-03 | `tests/local/groupenrol_source_test.php` | absent plugin, table without failure columns, failures/missing/assigned, observation in the launch request (and the 1.2.1 event when installed) |
| Role and privacy audit | `tests/role_audit_test.php` | capability matrix of the 0.3.0 actions, audit with actor, no names/e-mails/user ids in exported documents |
| Upgrade | `tests/upgrade_test.php::test_upgrade_from_021` | step 2026102700 from 2026102600: indexes, settings with 0.2.1 behaviour, existing settings kept, idempotent |
| Playwright two-site (0.3.0) | `tests/playwright/messages_two_site.spec.js` (DIA-02), `partner_two_site.spec.js` (two-system check, DIA-02/03), `transfers_two_site.spec.js` (DIA-05, 4.5 <-> 5.3), `groups_two_site.spec.js` (GA-03) | real launches A (4.5) -> B (5.3) with own scenarios built by the scenario fixture of local_lticompletionsync (`CSYNC_LIB`), the stack's real nginx log, browser export/import of partner documents, completion delivery and a failed delivery; helpers in `lib.js` (`pluginCli`, `setConfig`, `ltiConfigFingerprint`, `logSlice`, `importLog`, `fixture`) and `fixtures/diag_fixture.php` |

Run the stack specs only under the shared stack lock and after mirroring the plugin into both sites:

```bash
flock /tmp/e2e_stack.lock /home/claude/e2e/bin/e2e-mirror
cd /home/claude/work/moodle-tool_ltidiagnostics/tests/playwright
flock /tmp/e2e_stack.lock env NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test \
    -c playwright.config.js messages_two_site.spec.js partner_two_site.spec.js transfers_two_site.spec.js groups_two_site.spec.js
```

Quick local lint: `/tmp/moodlecs/vendor/bin/phpcs --standard=moodle <path>` and `phpcbf`.

## CI workflows

`.github/workflows/moodle-plugin-ci-dev.yml` (branches except main, reduced matrix 4.5/5.0/5.3), `moodle-plugin-ci-main.yml`
(main, full matrix 4.5 to 5.3 x PostgreSQL/MariaDB, release artefact, coverage floor, stale files) and `playwright.yml`
(`npm run check` = `node --check` of every Playwright file). None has run (no GitHub access). Checked locally 2026-10-25 with
`/home/claude/bin/ltici-check.py` (PyYAML parse, job graph and `needs`, matrix cells, Behat tag, archive prefix, every referenced
plugin path, no clone of plugin repositories): 0 errors for all five family plugins.

## Mirror of the E2E story in PHPUnit

`tests/journey_test.php` reproduces the core of the E2E story without a browser: a tool-side launch and the enrolment appear
as separate evidence rows; a platform-side AGS stays `configured` until a grade arrives through the service entry point;
a stored token means `authorised` only.

## Results

See the table of runner results in `docs/ACCEPTANCE.md` (date, tree and database combinations, counts).

### Measured large data run (2026-10-28, 4.5/PostgreSQL, PHP 8.3)

`LTIDIAG_PERF=<file> vendor/bin/phpunit --filter large_data_test` (5 tests, 11 s including the setup of the rows):

| Measurement | Reads | Writes | Seconds | Peak memory growth |
|---|---|---|---|---|
| log store fetch, 100 000 rows, limit 200 | 7 | 0 | 0.05 | 1.6 MiB |
| import of a 100 000 line gzip access log (50 000 LTI lines stored) | 104 | 102 | 2.95 | 7.7 MiB |
| import summary | 6 | 0 | 0.04 | < 0.1 MiB |
| 25 keyset pages | 25 | 0 | 0.15 | 2.4 MiB |
| cron windows over 20 000 task log rows | 1 | 0 | 0.04 | 1.3 MiB |

The test asserts query and memory bounds, not times. On MariaDB the batched insert takes more write queries (about 1 000
for the import); the write bound allows for that.
