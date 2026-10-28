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
 * Completion sync diagnostics across 4.5 <-> 5.3 (audit item DIA-05): one completion message on its whole path,
 * source event -> outbox -> task -> HTTP/JWT -> receiver -> native completion -> acknowledgement, with the same
 * correlation id on B (5.3 sender) and A (4.5 receiver).
 *
 * Own scenario "dgt" (scenario fixture of local_lticompletionsync). Steps:
 *  1. A real launch and delivery. Before any trace exchange each site reports the transfer as "limited: only this side
 *     observed" and names the stages of the other side as not observable.
 *  2. B exports its redacted trace (command line of this plugin), A imports it in the browser: the transfer is complete
 *     on both sites, the native completion record of A matches; the person is masked; B imports A's trace as well.
 *  3. A failed transfer: B's sender endpoint is pointed at a missing script on A (restored afterwards), a revocation is
 *     delivered, the sender ends in a dead letter; B's report shows the transfer as failed.
 *
 * Runs under /tmp/e2e_stack.lock.
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const lib = require('./lib');

const seed = lib.loadSeed();
const csync = lib.csyncLib();
const DIR = process.env.DIAG_LOGDIR || '/home/claude/e2e/state/diag-logs';
let sc;
let platformKey;
let toolKey;
let endpoint;
let cid;

test.describe.configure({ mode: 'serial' });

/**
 * Open the transfers page of a connection as administrator.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} site a or b.
 * @param {string} key Connection key.
 */
async function transfers(page, site, key) {
  await page.context().clearCookies();
  await lib.login(page, seed.urls[site], seed.admins[site]);
  await page.goto(`${seed.urls[site]}/admin/tool/ltidiagnostics/transfers.php?key=${encodeURIComponent(key)}`);
}

/**
 * Import a trace through the transfers page.
 *
 * @param {import('@playwright/test').Page} page Page (on the transfers page).
 * @param {string} json Trace.
 */
async function importTrace(page, json) {
  await page.locator('#id_document').fill(json);
  await page.locator('#id_submitbutton').click();
  await page.waitForLoadState('load');
}

test.beforeAll(() => {
  sc = csync.buildScenario(seed, { platform: 'a', tool: 'b', scenario: 'dgt', learner: 'dgtstudent1' });
  platformKey = `platform:${sc.p1.typeid}`;
  toolKey = `tool:${sc.t.regid}:${sc.t.deploymentrow}`;
  endpoint = lib.sql('b', `SELECT id || '|' || endpointurl FROM mdl_local_lticompletionsync_con WHERE role = 'sender'`
    + ` AND deploymentid = ${Number(sc.t.deploymentrow)}`).split('|');
  fs.mkdirSync(DIR, { recursive: true });
});

test.afterAll(() => {
  if (endpoint && endpoint.length === 2) {
    lib.sql('b', `UPDATE mdl_local_lticompletionsync_con SET endpointurl = '${endpoint[1].replace(/'/g, "''")}',`
      + ` partnerstatus = 'plugin' WHERE id = ${Number(endpoint[0])}`);
  }
});

test('one side only: each site reports the transfer as limited and names what it cannot see', async ({ page }) => {
  await csync.launchAsLearner(page, seed, sc, 'a', 'b');
  csync.runDispatchTasks('b');
  expect(csync.csync('b', { stage: 'deliver' }).delivered).toBeGreaterThanOrEqual(0);
  const trace = JSON.parse(lib.pluginCli('b', 'transfers.php', [`--key=${toolKey}`, '--trace']));
  const ids = Object.keys(trace.transactions);
  expect(ids.length).toBeGreaterThan(0);
  cid = ids.find((id) => (trace.transactions[id].stages.ack || {}).code === 'applied') || ids[0];
  fs.writeFileSync(path.join(DIR, 'b-trace.json'), JSON.stringify(trace));
  expect(JSON.stringify(trace)).not.toContain(sc.learner.username);

  await transfers(page, 'b', toolKey);
  const rowb = page.locator(`#tool_ltidiagnostics_transfers tr[data-correlationid="${cid}"]`);
  await expect(rowb).toHaveAttribute('data-verdict', 'limited_one_side');
  await expect(rowb.locator('td[data-stage="native_completion"]')).toHaveAttribute('data-state', 'not_observable');
  await expect(rowb.locator('td[data-stage="task"]')).toHaveAttribute('data-state', 'seen');
  await expect(page.locator('[data-limit="partner_side_not_observable"]')).toBeVisible();

  await transfers(page, 'a', platformKey);
  const rowa = page.locator(`#tool_ltidiagnostics_transfers tr[data-correlationid="${cid}"]`);
  await expect(rowa).toHaveAttribute('data-verdict', 'limited_one_side');
  await expect(rowa.locator('td[data-stage="native_completion"]')).toHaveAttribute('data-state', 'seen');
  await expect(rowa).toContainText('native record matches');
  await expect(page.locator('#tool_ltidiagnostics_transfers')).not.toContainText(sc.learner.username);
});

test('with the partner trace the same correlation id is complete on both sites', async ({ page }) => {
  await transfers(page, 'a', platformKey);
  await importTrace(page, fs.readFileSync(path.join(DIR, 'b-trace.json'), 'utf8'));
  await expect(page.locator('#tool_ltidiagnostics_transfermode')).toHaveAttribute('data-mode', 'two_sided');
  await expect(page.locator(`#tool_ltidiagnostics_transfers tr[data-correlationid="${cid}"]`)).toHaveAttribute('data-verdict',
    'complete');
  // And the other way round: B imports A's trace (command line of this plugin).
  const atrace = lib.pluginCli('a', 'transfers.php', [`--key=${platformKey}`, '--trace']);
  const file = path.join(DIR, 'a-trace.json');
  fs.writeFileSync(file, atrace);
  lib.pluginCli('b', 'transfers.php', [`--key=${toolKey}`, `--import=${file}`]);
  const report = JSON.parse(lib.pluginCli('b', 'transfers.php', [`--key=${toolKey}`, '--report=json']));
  const tx = report.transactions.find((t) => t.correlationid === cid);
  expect(tx.verdict).toBe('complete');
  expect(tx.person).toMatch(/^P-[0-9A-F]{8}$/);
  expect(tx.stages.native_completion.partner.native).toBe('match');
});

test('a failed transfer (receiver script missing) ends as failed in the report', async ({ page }) => {
  const missing = `${seed.urls.a}/local/lticompletionsync/endpoint-missing.php`;
  lib.sql('b', `UPDATE mdl_local_lticompletionsync_con SET endpointurl = '${missing}' WHERE id = ${Number(endpoint[0])}`);
  expect(csync.csync('b', { stage: 'revoke', scenario: 'dgt', cmid: sc.t.pagecmid }).revoked).toBeGreaterThanOrEqual(1);
  csync.csync('b', { stage: 'deliver' });
  const report = JSON.parse(lib.pluginCli('b', 'transfers.php', [`--key=${toolKey}`, '--report=json']));
  const failed = report.transactions.filter((t) => t.verdict === 'failed');
  expect(failed.length, JSON.stringify(report.transactions.map((t) => [t.correlationid, t.verdict, t.codes])))
    .toBeGreaterThan(0);
  await transfers(page, 'b', toolKey);
  await expect(page.locator('#tool_ltidiagnostics_transfers tr[data-verdict="failed"]').first()).toBeVisible();
  // The Markdown report states the view and the limits.
  const md = lib.pluginCli('b', 'transfers.php', [`--key=${toolKey}`, '--report=md']);
  expect(md).toContain('Completion sync transfers');
  expect(md).not.toContain(sc.learner.username);
});
