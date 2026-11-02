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
 * Completion channel end to end (issue #7) on two real sites with local_lticompletionsync installed on both: Moodle
 * 4.5 is the tool (sender), Moodle 5.2 the platform (receiver). The learner launched the external tool activity
 * (real OIDC launch), read the published page on the tool (completion on view) and the platform applied the native
 * completion with a signed acknowledgement. The suite checks the separate completion tab of tool_ltidiagnostics for:
 * signed ACK, revocation, retry and "Retry now", force resync (reach: whole connection), conflict, missing partner and
 * the rights; the gradebook of the platform stays unchanged throughout.
 *
 * Setup: stack/README.md (the scenario is created with the fixture of local_lticompletionsync).
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATEFILE = process.env.GRADES_STATE || '/home/claude/e2e/state.json';
const STACK = path.join(__dirname, 'stack', 'grades_stack.php');
const TOOLROOT = process.env.TOOL_ROOT || '/home/claude/moodle';
const PLATFORMROOT = process.env.PLATFORM_ROOT || '/home/claude/moodle52';
const ADMIN = { username: 'admin', password: process.env.E2E_ADMIN_PASSWORD || 'E2eAdmin123!' };
const VIEWER = { username: 'diagviewer', password: 'Viewer123!' };
const st = JSON.parse(fs.readFileSync(STATEFILE, 'utf8'));
const C = st.completion;

/**
 * Last JSON line of a command.
 *
 * @param {string} out Output.
 * @returns {object} JSON.
 */
function lastJson(out) {
  const lines = out.trim().split('\n');
  return lines.length && lines[lines.length - 1].startsWith('{') ? JSON.parse(lines[lines.length - 1]) : {};
}

/**
 * Stage of the completion sync fixture.
 *
 * @param {string} root Moodle tree.
 * @param {string} stage Stage.
 * @param {object} [extra] Options.
 * @returns {object} JSON answer.
 */
function csync(root, stage, extra = {}) {
  const args = [C.fixture, `--root=${root}`, `--stage=${stage}`, `--scenario=${C.scenario}`];
  for (const [k, v] of Object.entries(extra)) {
    args.push(`--${k}=${v}`);
  }
  return lastJson(execFileSync('php', args, { encoding: 'utf8' }));
}

/**
 * Action of the stack fixture.
 *
 * @param {string} root Moodle tree.
 * @param {string} action Action.
 * @param {string} [arg] Argument.
 * @returns {string} Output.
 */
function stack(root, action, arg) {
  const args = [STACK, `--config=${root}/config.php`, `--state=${STATEFILE}`, `--action=${action}`];
  if (arg !== undefined) {
    args.push(`--arg=${arg}`);
  }
  return execFileSync('php', args, { encoding: 'utf8' });
}

/** @returns {object} Platform state of the learner (native completion, grade, grade items, receiver row). */
function platformState() {
  return csync(PLATFORMROOT, 'state', { cmid: C.platformcmid, username: C.learner });
}

/** @returns {object} Outbox row of the learner on the tool. */
function outrow() {
  return csync(TOOLROOT, 'outrows').rows[0];
}

/** Run the plugin's tasks on the tool (dispatch, sweep) and on the platform (reassert). */
function cron() {
  csync(TOOLROOT, 'cron');
  csync(PLATFORMROOT, 'cron');
}

/**
 * Log in.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} base Site URL.
 * @param {{username: string, password: string}} cred Credentials.
 */
async function login(page, base, cred) {
  for (let i = 0; i < 3; i++) {
    await page.goto(`${base}/login/index.php`, { waitUntil: 'networkidle' });
    await page.fill('#username', cred.username);
    await page.fill('#password', cred.password);
    if ((await page.inputValue('#password')) !== cred.password) {
      continue;
    }
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
    if (!/login\/index\.php/.test(page.url())) {
      return;
    }
  }
  throw new Error(`Login of ${cred.username} at ${base} failed`);
}

/**
 * Open the completion tab of the scenario's connection.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openTab(page) {
  await page.goto(`${st.tool.url}/admin/tool/ltidiagnostics/connection.php?key=${encodeURIComponent(C.toolkey)}` +
    '&tab=completion');
  await expect(page.locator('[role=main]')).toContainText('Completion channel: local_lticompletionsync');
}

/**
 * Count of a state class in the states table.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} label Visible label of the class.
 * @returns {Promise<number>} Count.
 */
async function count(page, label) {
  const row = page.locator('table tr', { has: page.locator('th, td', { hasText: new RegExp(`^${label}$`) }) }).first();
  return parseInt((await row.locator('td').nth(0).innerText()).trim(), 10);
}

test.describe.configure({ mode: 'serial' });

test.describe('Completion channel 4.5 tool -> 5.2 platform (local_lticompletionsync on both)', () => {
  let gradesBefore = null;

  test('signed ACK: the platform applied native completion, the tab shows "applied (signed)"', async ({ page }) => {
    const p = platformState();
    expect(p.state).toBe(1);
    expect(p.rcv.status).toBe('applied');
    gradesBefore = { grade: p.grade, gradeitems: p.gradeitems };
    await login(page, st.tool.url, ADMIN);
    await openTab(page);
    expect(await count(page, 'applied \\(signed\\)')).toBe(1);
    expect(await count(page, 'sent \\(tool only\\)')).toBe(0);
    await expect(page.locator('[role=main]')).toContainText('partner: partner plugin answers');
    // Screen reader structure: captions and scoped header cells.
    const tables = page.locator('[role=main] table');
    for (let i = 0; i < await tables.count(); i++) {
      expect(await tables.nth(i).locator('caption').count(), `table ${i} has a caption`).toBe(1);
      expect(await tables.nth(i).locator('th:not([scope])').count(), `table ${i} headers have a scope`).toBe(0);
    }
    // No grade action on this tab: no replay, no score column.
    await expect(page.locator('[role=main]')).not.toContainText('Preview replay');
    await expect(page.locator('[role=main]')).not.toContainText('Source grade');
  });

  test('rights: a read-only viewer sees the states but none of the completion plugin actions', async ({ page }) => {
    await login(page, st.tool.url, VIEWER);
    await openTab(page);
    expect(await count(page, 'applied \\(signed\\)')).toBe(1);
    await expect(page.getByRole('button', { name: /Force resync/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Retry now/ })).toHaveCount(0);
  });

  test('revocation: a withdrawn completion on the tool arrives and is shown as revoked', async ({ page }) => {
    csync(TOOLROOT, 'revoke', { cmid: C.pagecmid });
    cron();
    const r = outrow();
    expect(r.status).toBe('acked');
    expect(r.ackversion).toBe(r.version);
    expect(platformState().state).toBe(0);
    await login(page, st.tool.url, ADMIN);
    await openTab(page);
    expect(await count(page, 'revoked')).toBe(1);
    expect(await count(page, 'applied \\(signed\\)')).toBe(1);
  });

  test('retry: an unreachable platform leads to "retry"; "Retry now" of the completion plugin delivers', async ({ page }) => {
    stack(TOOLROOT, 'csyncendpoint', 'http://127.0.0.1:8059/local/lticompletionsync/endpoint.php');
    stack(TOOLROOT, 'completion', '1');
    csync(TOOLROOT, 'cron');
    expect(outrow().status).toBe('retry');
    expect(platformState().state).toBe(0);
    await login(page, st.tool.url, ADMIN);
    await openTab(page);
    expect(await count(page, 'retry')).toBe(1);
    stack(TOOLROOT, 'csyncendpoint', 'restore');
    await page.getByRole('button', { name: /^Retry now/ }).click();
    await expect(page).toHaveURL(/local\/lticompletionsync\/index\.php/);
    csync(TOOLROOT, 'deliver');
    const r = outrow();
    expect(r.status).toBe('acked');
    expect(platformState().state).toBe(1);
    await openTab(page);
    expect(await count(page, 'retry')).toBe(0);
    expect(await count(page, 'applied \\(signed\\)')).toBe(1);
  });

  test('force resync: whole connection, new version, applied again; no single-learner action exists', async ({ page }) => {
    const before = outrow();
    await login(page, st.tool.url, ADMIN);
    await openTab(page);
    await expect(page.locator('[role=main]')).toContainText('There is no single-learner variant.');
    await page.getByRole('button', { name: /^Force resync/ }).click();
    await expect(page).toHaveURL(/local\/lticompletionsync\/index\.php/);
    // The completion plugin queues its reconcile task (force); cron runs it and delivers.
    csync(TOOLROOT, 'cron');
    csync(TOOLROOT, 'deliver');
    const after = outrow();
    expect(after.version).toBeGreaterThan(before.version);
    expect(after.status).toBe('acked');
    expect(after.ackversion).toBe(after.version);
    expect(platformState().state).toBe(1);
    await openTab(page);
    await expect(page.locator('[role=main]')).toContainText(`version ${after.version}`);
  });

  test('conflict: a local teacher change at the platform wins under policy "local" and is reported', async ({ page }) => {
    stack(PLATFORMROOT, 'csyncreceiver', 'policy:local');
    stack(PLATFORMROOT, 'completion', '0');
    csync(TOOLROOT, 'resync');
    csync(TOOLROOT, 'deliver');
    const p = platformState();
    expect(p.state).toBe(0);
    expect(p.rcv.status).toBe('conflict');
    const r = outrow();
    await login(page, st.tool.url, ADMIN);
    await openTab(page);
    const label = { blocked: 'blocked', dead: 'dead letter', rejected: 'rejected' }[r.status] || 'blocked';
    expect(await count(page, label)).toBe(1);
    await expect(page.locator('[role=main]')).toContainText('conflict');
    stack(PLATFORMROOT, 'csyncreceiver', 'policy:provider');
  });

  test('missing partner: a disabled receiver is shown after "Check partner", nothing is applied', async ({ page }) => {
    stack(PLATFORMROOT, 'csyncreceiver', 'enabled:0');
    await login(page, st.tool.url, ADMIN);
    await openTab(page);
    await page.getByRole('button', { name: /^Check partner/ }).click();
    await expect(page).toHaveURL(/local\/lticompletionsync\/index\.php/);
    await openTab(page);
    await expect(page.locator('[role=main]')).toContainText('partner: partner connection disabled');
    csync(TOOLROOT, 'resync');
    csync(TOOLROOT, 'deliver');
    const r = outrow();
    expect(r.status).not.toBe('acked');
    expect(platformState().state).toBe(0);
    await openTab(page);
    expect(await count(page, 'applied \\(signed\\)')).toBe(0);
    stack(PLATFORMROOT, 'csyncreceiver', 'enabled:1');
  });

  test('the gradebook of the platform is unchanged by every completion transfer', async () => {
    const p = platformState();
    expect({ grade: p.grade, gradeitems: p.gradeitems }).toEqual(gradesBefore);
  });
});
