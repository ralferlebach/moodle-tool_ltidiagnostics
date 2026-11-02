# tool_ltidiagnostics - Playwright end-to-end tests

Two-site story (site A = LTI platform, site B = LTI tool, plugin installed on both). The test reads the seed file
named by `LTI_SEED` (default `/home/claude/e2e/state/seed.json`); `lib.js` reuses the stack library `E2E_LIB`
(default `/home/claude/e2e/lib/e2e`) for login. The stack's CA must be in Chromium's NSS db (`e2e-up` does that);
`playwright.config.js` bypasses the sandbox proxy for the test hosts.

```bash
# stack up and seeded; from the plugin directory
NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js
```

Order check (main -> accessibility -> main): `-c tests/playwright/playwright.order.config.js`. The specs are independent:
each removes the plugin state it asserts on before it starts (`lib.js` `resetConnectionState`), and the accessibility spec
removes the health check results it stored when it ends.

`npm run check` runs `node --check` on all files.

`family.spec.js` (0.2.1, gate CS-G6) triggers real local_lticompletionsync transfer events on both sites through
`fixtures/family_events.php` (run with the stack's `moodle_cli`) and checks the timeline transfer table, the evidence
rows and the observability level.

Status: executed 2026-10-26 (0.2.1), Chromium only: full suite 15 passed. Earlier: 2026-10-25, 13 passed twice, order
config 17 passed. Not part of CI.

## Grade and completion channels (0.4.0)

`grades_two_site.spec.js` (issues #3, #5, #6, #8, #9) and `completion_two_site.spec.js` (issue #7) run on their own
two-site stack (Moodle 4.5 tool on `http://localhost`, Moodle 5.2 platform on `http://127.0.0.1:8052`), described and
built by `stack/` (`stack/README.md`, `stack/setup.sh`), with `playwright.channels.config.js`. The main config ignores
them. Status: executed 2026-11-02 on a freshly installed stack, Chromium (Google Chrome): see `docs/ACCEPTANCE.md`.
