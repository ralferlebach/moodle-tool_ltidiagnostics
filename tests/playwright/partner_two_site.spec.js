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
 * Two-system health check on the stack (audit items DIA-02, DIA-03): the partner diagnostic package of B (5.3 tool) is
 * exported in B's browser and imported in A's browser (4.5 platform); A compares issuer, client id, deployment,
 * endpoints and keys, and checks live, read only, that B serves the key it declares. A tampered package (other client
 * id) is diagnosed as an aud mismatch with "one of the two sites" responsible. Nothing in the LTI configuration of
 * either site changes (fingerprint before and after).
 *
 * Own scenario "dgp" (scenario fixture of local_lticompletionsync). Settings changed on A for the live check
 * (activechecks, privatetargetallowlist: exactly b.tool-b.test, because the stack resolves to 127.0.0.1) are restored
 * in afterAll. Runs under /tmp/e2e_stack.lock.
 */

const fs = require('fs');
const crypto = require('crypto');
const { test, expect } = require('@playwright/test');
const lib = require('./lib');

const seed = lib.loadSeed();
const csync = lib.csyncLib();
let sc;
let platformKey;
let toolKey;
let packageJson;
const saved = {};
const fingerprints = {};

test.describe.configure({ mode: 'serial' });

/**
 * Log in as administrator of a site.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} site a or b.
 */
async function admin(page, site) {
  await page.context().clearCookies();
  await lib.login(page, seed.urls[site], seed.admins[site]);
}

/**
 * Import a package on A through the partner page.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} json Package.
 */
async function importOnA(page, json) {
  await admin(page, 'a');
  await page.goto(`${seed.urls.a}/admin/tool/ltidiagnostics/partner.php?key=${encodeURIComponent(platformKey)}`);
  await page.locator('#id_document').fill(json);
  await page.locator('#id_submitbutton').click();
  await page.waitForLoadState('load');
}

test.beforeAll(() => {
  sc = csync.buildScenario(seed, { platform: 'a', tool: 'b', scenario: 'dgp', learner: 'dgpstudent1' });
  platformKey = `platform:${sc.p1.typeid}`;
  toolKey = `tool:${sc.t.regid}:${sc.t.deploymentrow}`;
  for (const name of ['activechecks', 'privatetargetallowlist']) {
    saved[name] = lib.getConfig('a', name);
  }
  fingerprints.a = lib.ltiConfigFingerprint('a');
  fingerprints.b = lib.ltiConfigFingerprint('b');
});

test.afterAll(() => {
  for (const [name, value] of Object.entries(saved)) {
    lib.setConfig('a', name, value === null ? '' : value);
  }
});

test('B exports its partner package in the browser; it holds hashes, no secret', async ({ page }) => {
  await admin(page, 'b');
  await page.goto(`${seed.urls.b}/admin/tool/ltidiagnostics/partner.php?key=${encodeURIComponent(toolKey)}`);
  const [download] = await Promise.all([page.waitForEvent('download'),
    page.locator('form:has(input[name="action"][value="export"]) button, '
      + 'form:has(input[name="action"][value="export"]) input[type=submit]').first().click()]);
  packageJson = fs.readFileSync(await download.path(), 'utf8');
  const p = JSON.parse(packageJson);
  expect(p.schema).toBe('ltidiag-partner-1');
  expect(p.role).toBe('tool');
  expect(p.lti.clientid.hash).toMatch(/^[a-f0-9]{64}$/);
  expect(packageJson).not.toContain(sc.p1.clientid);
  expect(packageJson).not.toContain('PRIVATE KEY');
  expect(p.own.keys[0].thumbprint).not.toBe('');
});

test('A imports it: the two sites agree; the live check finds the declared key at B', async ({ page }) => {
  await importOnA(page, packageJson);
  const findings = page.locator('#tool_ltidiagnostics_partnerfindings');
  await expect(findings.locator('tr[data-code="partner_ok"]')).toHaveCount(1);
  lib.setConfig('a', 'activechecks', '1');
  lib.setConfig('a', 'privatetargetallowlist', new URL(seed.urls.b).host);
  await page.reload();
  await page.locator('form:has(input[name="action"][value="live"]) button, '
    + 'form:has(input[name="action"][value="live"]) input[type=submit]').first().click();
  await page.waitForLoadState('load');
  await expect(page.locator('#tool_ltidiagnostics_partnerlive')).toHaveAttribute('data-findings', 'partner_live_kid_ok');
  // The partner column of A's field matrix is filled from B's package.
  await page.goto(lib.connectionUrl(seed.urls.a, platformKey, 'messages'));
  await expect(page.locator('#tool_ltidiagnostics_partnerinfo')).toBeVisible();
  await expect(page.locator('#tool_ltidiagnostics_messages tr[data-message="key_rotation"]')).toHaveAttribute('data-state',
    'verified');
});

test('a tampered package (other client id) is an aud mismatch of one of the two sites', async ({ page }) => {
  const p = JSON.parse(packageJson);
  p.lti.clientid.hash = crypto.createHash('sha256').update('not-the-client').digest('hex');
  await importOnA(page, JSON.stringify(p));
  const r = page.locator('#tool_ltidiagnostics_partnerfindings tr[data-code="partner_aud_mismatch"]');
  await expect(r).toHaveCount(1);
  await expect(r).toHaveAttribute('data-system', 'both_sites');
  await expect(r).toHaveAttribute('data-certainty', 'certain');
  // The health check of A lists it with cause, responsible system and a safe check.
  await page.goto(lib.connectionUrl(seed.urls.a, platformKey, 'health'));
  const card = page.locator('div.card[data-testid="oidc_jwt.partner_aud_mismatch"]');
  await expect(card).toHaveAttribute('data-system', 'both_sites');
  await expect(card).toHaveAttribute('data-certainty', 'certain');
  // Restore the correct comparison for later specs.
  await importOnA(page, packageJson);
});

test('no diagnostic action changed the LTI configuration of either site', async () => {
  expect(lib.ltiConfigFingerprint('a')).toBe(fingerprints.a);
  expect(lib.ltiConfigFingerprint('b')).toBe(fingerprints.b);
});
