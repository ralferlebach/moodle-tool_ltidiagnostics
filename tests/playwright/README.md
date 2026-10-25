# tool_ltidiagnostics - Playwright end-to-end tests

Two-site story (site A = LTI platform, site B = LTI tool, plugin installed on both). The test reads the seed file
named by `LTI_SEED` (default `/home/claude/e2e/state/seed.json`); `lib.js` reuses the stack library `E2E_LIB`
(default `/home/claude/e2e/lib/e2e`) for login. The stack's CA must be in Chromium's NSS db (`e2e-up` does that);
`playwright.config.js` bypasses the sandbox proxy for the test hosts.

```bash
# stack up and seeded; from the plugin directory
NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js
```

`npm run check` runs `node --check` on all files.

Status: executed 2026-10-25, Chromium only: 4 passed, 0 failed. Not part of CI.
