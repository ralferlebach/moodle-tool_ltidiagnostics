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
| Behat (non-JS) | `tests/behat/ltidiagnostics.feature` | Run `ltirun <tree> <db> tool_ltidiagnostics mirror`, then `ltibehat <45|53> <pgsql|mariadb> tool_ltidiagnostics`. Executed 2026-10-25: 5 scenarios, 38 steps, all four combos green. |
| Playwright E2E | `tests/playwright/ltidiagnostics.spec.js` (reads `LTI_SEED`, default `/home/claude/e2e/state/seed.json`) | `NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js` from the plugin directory. **Executed 2026-10-25**, browsers = Chromium, two-site stack: 4 passed, 0 failed. `lib.js` delegates login/seed loading to the stack library (`E2E_LIB`). |

Quick local lint: `/tmp/moodlecs/vendor/bin/phpcs --standard=moodle <path>` and `phpcbf`.

## Mirror of the E2E story in PHPUnit

`tests/journey_test.php` reproduces the core of the E2E story without a browser: a tool-side launch and the enrolment appear
as separate evidence rows; a platform-side AGS stays `configured` until a grade arrives through the service entry point;
a stored token means `authorised` only.

## Results

See the table of runner results in `docs/ACCEPTANCE.md` (date, tree and database combinations, counts).
