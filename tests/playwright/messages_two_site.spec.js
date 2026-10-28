/*
 * This file is part of Moodle - https://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * Audit item DIA-02 on the two-site stack (A = Moodle 4.5 platform, B = Moodle 5.3 tool): the message matrix keeps
 * configured / observed / verified / booked / not observable apart, on real data of both sites.
 *
 * Own scenario "dgm" (built with the scenario fixture of local_lticompletionsync: own LTI 1.3 type on A, own
 * registration on B, own courses and learner). Steps:
 *  1. Before any launch nothing of the launch is verified on either site.
 *  2. A real launch A -> B: on B the launch message is verified (enrol_lti validated the id_token before the login was
 *     recorded) and its local effects (account, enrolment) are booked; the field matrix shows which claims arrived
 *     (name and e-mail present, values never shown); custom claims are "not observable". On A the launch leaves through
 *     the browser and is never "verified".
 *  3. The real web server log of the stack is imported on both sites (the lines of each site's own scripts): the OIDC
 *     messages become "observed (transport only)", never "verified", and no chain turns end to end because of an HTTP 200.
 *  4. After a completion delivery the receiver A books the completion; the sender B sees it verified but booked at the
 *     partner (limited).
 *  5. The JSON export of B contains the messages, chains and fields and no learner name.
 *
 * Shared stack: the spec runs under /tmp/e2e_stack.lock (see docs/TESTING.md); the settings it changes on A and B
 * (log allow-list) are restored in afterAll.
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const lib = require('./lib');

const seed = lib.loadSeed();
const csync = lib.csyncLib();
const start = lib.nowSeconds() - 5;
const LOGDIR = process.env.DIAG_LOGDIR || '/home/claude/e2e/state/diag-logs';
let sc;
let platformKey;
let toolKey;
const saved = {};

test.describe.configure({ mode: 'serial' });

/**
 * Open the messages tab of a connection as the site administrator.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} site a or b.
 * @param {string} key Connection key.
 */
async function messages(page, site, key) {
  await page.context().clearCookies();
  await lib.login(page, seed.urls[site], seed.admins[site]);
  await page.goto(lib.connectionUrl(seed.urls[site], key, 'messages'));
  await expect(page.locator('#tool_ltidiagnostics_messages')).toBeVisible();
}

/**
 * Row of a message.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} id Message id.
 * @returns {import('@playwright/test').Locator} Row.
 */
function row(page, id) {
  return page.locator(`#tool_ltidiagnostics_messages tr[data-message="${id}"]`);
}

test.beforeAll(() => {
  sc = csync.buildScenario(seed, { platform: 'a', tool: 'b', scenario: 'dgm', learner: 'dgmstudent1' });
  platformKey = `platform:${sc.p1.typeid}`;
  toolKey = `tool:${sc.t.regid}:${sc.t.deploymentrow}`;
  for (const site of ['a', 'b']) {
    saved[site] = lib.getConfig(site, 'logallowlist');
  }
  fs.mkdirSync(LOGDIR, { recursive: true });
});

test.afterAll(() => {
  for (const site of ['a', 'b']) {
    lib.setConfig(site, 'logallowlist', saved[site] === null ? '' : saved[site]);
  }
});

test('before a launch nothing of the launch is verified on either site', async ({ page }) => {
  await messages(page, 'a', platformKey);
  await expect(row(page, 'id_token_launch')).toHaveAttribute('data-verified', '0');
  await expect(row(page, 'custom_claims')).toHaveAttribute('data-state', 'not_observable');
  await messages(page, 'b', toolKey);
  await expect(row(page, 'local_effects')).not.toHaveAttribute('data-state', 'booked');
});

test('a real launch is verified and booked on the tool, never verified on the platform', async ({ page }) => {
  await csync.launchAsLearner(page, seed, sc, 'a', 'b');
  await messages(page, 'b', toolKey);
  await expect(row(page, 'id_token_launch')).toHaveAttribute('data-state', 'verified');
  await expect(row(page, 'local_effects')).toHaveAttribute('data-state', 'booked');
  await expect(row(page, 'custom_claims')).toHaveAttribute('data-state', 'not_observable');
  // Field matrix: the type sends name and e-mail; the tool received them (placeholder check), validated the core claims.
  const fields = page.locator('#tool_ltidiagnostics_fields');
  await expect(fields.locator('tr[data-field="iss"]')).toHaveAttribute('data-local', 'validated');
  await expect(fields.locator('tr[data-field="given_name"]')).toHaveAttribute('data-local', 'present');
  await expect(fields.locator('tr[data-field="email"]')).toHaveAttribute('data-local', 'present');
  await expect(fields.locator('tr[data-field="roles"]')).toHaveAttribute('data-local', 'not_observable');
  await expect(page.locator('#tool_ltidiagnostics_fields')).not.toContainText(sc.learner.username);
  // Without the web server log the OIDC initiation is unknown: the launch chain is limited, not end to end.
  await expect(page.locator('#tool_ltidiagnostics_chains li[data-chain="launch"]')).toHaveAttribute('data-verdict', 'limited');

  await messages(page, 'a', platformKey);
  const launch = row(page, 'id_token_launch');
  await expect(launch).toHaveAttribute('data-verified', '0');
  await expect(launch).toHaveAttribute('data-booked', 'remote');
});

test('imported web server lines make OIDC observed (transport only), never verified', async ({ page }) => {
  const slices = { a: /^\/mod\/lti\//, b: /^\/(enrol|auth)\/lti\// };
  for (const site of ['a', 'b']) {
    const file = path.join(LOGDIR, `${site}-access-${start}.log`);
    const n = lib.logSlice(start, slices[site], file);
    expect(n, `lines of site ${site} in the stack log`).toBeGreaterThan(0);
    lib.setConfig(site, 'logallowlist', LOGDIR);
    await page.context().clearCookies();
    await lib.login(page, seed.urls[site], seed.admins[site]);
    const text = await lib.importLog(page, seed.urls[site], file);
    expect(text).not.toContain('failed');
  }
  await messages(page, 'b', toolKey);
  const init = row(page, 'oidc_login_init');
  await expect(init).toHaveAttribute('data-state', 'observed');
  await expect(init).toHaveAttribute('data-verified', '0');
  await expect(page.locator('#tool_ltidiagnostics_chains li[data-chain="launch"]')).not.toHaveAttribute('data-verdict',
    'end_to_end');
  await messages(page, 'a', platformKey);
  const auth = row(page, 'oidc_auth_request');
  await expect(auth).toHaveAttribute('data-state', 'observed');
  await expect(auth).toHaveAttribute('data-verified', '0');
  for (const id of ['oidc_auth_request', 'jwks_served']) {
    const r = row(page, id);
    if ((await r.getAttribute('data-state')) === 'observed') {
      await expect(r).toContainText('transport only');
    }
  }
});

test('a delivered completion is booked on the receiver and limited on the sender', async ({ page }) => {
  expect(csync.csync('b', { stage: 'deliver' }).delivered).toBeGreaterThanOrEqual(1);
  await messages(page, 'a', platformKey);
  await expect(row(page, 'completion_roundtrip')).toHaveAttribute('data-state', 'booked');
  await expect(page.locator('#tool_ltidiagnostics_chains li[data-chain="completion"]')).toHaveAttribute('data-verdict',
    'end_to_end');
  await messages(page, 'b', toolKey);
  const send = row(page, 'completion_roundtrip');
  await expect(send).toHaveAttribute('data-verified', '1');
  await expect(send).toHaveAttribute('data-booked', 'remote');
  await expect(page.locator('#tool_ltidiagnostics_chains li[data-chain="completion"]')).toHaveAttribute('data-verdict',
    'limited');
});

test('the redacted JSON export of the tool carries messages, chains and fields and no learner data', async ({ page }) => {
  await messages(page, 'b', toolKey);
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('a[href*="export.php"][href*="format=json"]').first().click(),
  ]);
  const json = fs.readFileSync(await download.path(), 'utf8');
  const report = JSON.parse(json);
  expect(report.messages.find((m) => m.id === 'id_token_launch').state).toBe('verified');
  expect(report.chains.map((c) => c.id)).toContain('launch');
  expect(report.fields.find((f) => f.field === 'email').local).toBe('present');
  expect(report.findings[0]).toHaveProperty('system');
  expect(report.findings[0]).toHaveProperty('certainty');
  expect(report.findings[0]).toHaveProperty('check');
  expect(json).not.toContain(sc.learner.username);
  expect(json).not.toMatch(/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\./);
});
