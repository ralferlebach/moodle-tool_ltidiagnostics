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
 * Grade channel end to end (issues #3, #5, #6, #8, #9) on two real sites: Moodle 4.5 is the LTI tool (provider),
 * Moodle 5.2 the LTI platform with two tool types: "Control" and "BUW". The learner launched both (real OIDC launches,
 * stack/lti_launch.py), core's grade sync sent 65/100 to Control and skipped BUW because lastgrade was already 65 -
 * the defect seen at BUW. The suite then diagnoses, replays to exactly the BUW line item, proves the booking with the
 * AGS result service and a signed receipt of the consumer, provokes a mismatch and a 409, and checks the rights.
 *
 * The stack is described by GRADES_STATE (default /home/claude/e2e/state.json, written by stack/grades_stack.php);
 * see stack/README.md for the setup. Every assertion about the consumer reads the 5.2 gradebook.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATEFILE = process.env.GRADES_STATE || '/home/claude/e2e/state.json';
const STACK = path.join(__dirname, 'stack', 'grades_stack.php');
const TOOLCFG = process.env.TOOL_CONFIG || '/home/claude/moodle/config.php';
const PLATFORMCFG = process.env.PLATFORM_CONFIG || '/home/claude/moodle52/config.php';
const ADMIN = { username: 'admin', password: process.env.E2E_ADMIN_PASSWORD || 'E2eAdmin123!' };
const VIEWER = { username: 'diagviewer', password: 'Viewer123!' };

/**
 * Run an action of the stack fixture.
 *
 * @param {string} config Moodle config.php of the site.
 * @param {string} action Action.
 * @param {string} [arg] Argument.
 * @returns {string} Output.
 */
function stack(config, action, arg) {
  const args = [STACK, `--config=${config}`, `--state=${STATEFILE}`, `--action=${action}`];
  if (arg !== undefined) {
    args.push(`--arg=${arg}`);
  }
  return execFileSync('php', args, { encoding: 'utf8' });
}

/**
 * Gradebook of the learner at the consumer (both activities).
 *
 * @returns {object} {control: {finalgrade, timemodified}, buw: ...}.
 */
function consumerGrades() {
  return JSON.parse(stack(PLATFORMCFG, 'platformstate').trim().split('\n').pop());
}

/**
 * Log in.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} base Site URL.
 * @param {{username: string, password: string}} cred Credentials.
 */
async function login(page, base, cred) {
  // The password field can be reset by a component that initialises late: wait for the page, check, retry.
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
 * Grades tab of a tool side connection.
 *
 * @param {object} st State.
 * @param {string} which control or buw.
 * @param {string} [tab] Tab.
 * @returns {string} URL.
 */
function tabUrl(st, which, tab = 'grades') {
  const key = st.tool.registrations[which].key;
  return `${st.tool.url}/admin/tool/ltidiagnostics/connection.php?key=${encodeURIComponent(key)}&tab=${tab}`;
}

/**
 * Text of a download.
 *
 * @param {import('@playwright/test').Download} download Download.
 * @returns {Promise<string>} Content.
 */
async function downloadText(download) {
  return fs.readFileSync(await download.path(), 'utf8');
}

/**
 * Structure for screen readers: every table of the main region has a caption and header cells with a scope, every
 * badge has text.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function expectAccessibleTables(page) {
  const tables = page.locator('[role=main] table');
  const n = await tables.count();
  expect(n).toBeGreaterThan(0);
  for (let i = 0; i < n; i++) {
    const t = tables.nth(i);
    expect(await t.locator('caption').count(), `table ${i} has a caption`).toBe(1);
    const ths = t.locator('th');
    const nth = await ths.count();
    expect(nth, `table ${i} has header cells`).toBeGreaterThan(0);
    for (let j = 0; j < nth; j++) {
      expect(await ths.nth(j).getAttribute('scope'), `table ${i} header ${j} has a scope`).toMatch(/^(col|row)$/);
    }
  }
  const badges = page.locator('[role=main] .badge');
  for (let i = 0; i < await badges.count(); i++) {
    expect((await badges.nth(i).innerText()).trim().length, `badge ${i} has text`).toBeGreaterThan(0);
  }
}

test.describe.configure({ mode: 'serial' });

test.describe('Grade channel 4.5 provider -> 5.2 consumer (Control and BUW)', () => {
  const st = JSON.parse(fs.readFileSync(STATEFILE, 'utf8'));
  let attemptUrl = '';
  let controlBefore = null;

  test('starting point: core sent to Control only, BUW never received the grade', async () => {
    const g = consumerGrades();
    expect(g.control.finalgrade).toBe(65);
    expect(g.buw).toBeNull();
    controlBefore = g.control;
  });

  test('#3 read-only diagnosis names lastgrade as the cause at BUW and shows Control as unconfirmed', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw'));
    const main = page.locator('[role=main]');
    await expect(main).toContainText('Grade channel: standard LTI Assignment and Grade Services');
    await expect(page.locator('#tool_ltidiagnostics_grades_findings tr[data-code="gr_lastgrade_blocks"]')).toHaveCount(1);
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('skipped: equals lastgrade');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('auth_lti link of this issuer');
    await expect(main).not.toContainText('Preview replay for');

    await page.goto(tabUrl(st, 'control'));
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('HTTP accepted, booking unknown');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).not.toContainText('booked (signed receipt)');
    await expect(page.locator('#tool_ltidiagnostics_grades_findings tr[data-code="gr_lastgrade_blocks"]')).toHaveCount(0);
    const now = consumerGrades();
    expect({ control: now.control, buw: now.buw }).toEqual({ control: controlBefore, buw: null });
  });

  test('#3 second defective BUW constellation: a consumer without AGS is named as responsible, no replay', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'noags'));
    const row = page.locator('#tool_ltidiagnostics_grades_findings tr[data-code="gr_no_service"]');
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('Consumer (partner)');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('skipped: no AGS service in the claim');
    await expect(page.locator('#tool_ltidiagnostics_grades_findings tr[data-code="gr_lastgrade_blocks"]')).toHaveCount(0);
    // Even with the opt-in of this connection nothing can be replayed: there is no grade service to send to.
    await page.getByRole('button', { name: 'Allow grade replay for this connection' }).click();
    await expect(page.locator('[role=main]')).not.toContainText('Preview replay for');
    await page.getByRole('button', { name: 'Switch grade replay off for this connection' }).click();
  });

  test('#8/#9 a read-only viewer sees the diagnosis but no score, no opt-in and no replay', async ({ page }) => {
    await login(page, st.tool.url, VIEWER);
    await page.goto(tabUrl(st, 'buw'));
    const main = page.locator('[role=main]');
    await expect(main).toContainText('lastgrade may block delivery');
    await expect(main).not.toContainText('Preview replay for');
    await expect(main).not.toContainText('Allow grade replay for this connection');
    await expect(main).not.toContainText('65.00');
    await expect(page.getByRole('link', { name: 'Support package JSON' })).toHaveCount(1);
    // A forged opt-in with a valid session key is refused on the server.
    const sesskey = await page.evaluate(() => M.cfg.sesskey);
    const key = st.tool.registrations.buw.key;
    const resp = await page.request.post(`${st.tool.url}/admin/tool/ltidiagnostics/repair.php`, {
      form: { key, action: 'optin', sesskey },
    });
    expect(await resp.text()).toMatch(/do not currently have permissions|nopermissions|Access denied/i);
    await page.goto(tabUrl(st, 'buw'));
    await expect(page.locator('[role=main]')).toContainText('Opt-in of this connection: no');
  });

  test('#8 accessibility: captions, scoped headers, text badges; channel tabs work with the keyboard', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw'));
    await expectAccessibleTables(page);
    // Keyboard: the result check button takes the focus; the completion tab opens with Enter.
    const check = page.getByRole('button', { name: /Check AGS result for/ });
    await check.focus();
    await expect(check).toBeFocused();
    const tab = page.locator('a[href*="tab=completion"]').first();
    await tab.focus();
    await expect(tab).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/tab=completion/);
    await expect(page.locator('[role=main]')).toContainText('Completion channel: local_lticompletionsync');
    await page.goto(tabUrl(st, 'control'));
    await expectAccessibleTables(page);
  });

  test('#5 replay of the unchanged grade reaches exactly the BUW line item', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw'));
    await page.getByRole('button', { name: 'Allow grade replay for this connection' }).click();
    await expect(page.locator('body')).toContainText('Grade replay is allowed for this connection.');
    await page.getByRole('link', { name: /Preview replay for/ }).click();
    const main = page.locator('[role=main]');
    await expect(main).toContainText('Nothing has been sent.');
    await expect(main).toContainText('65.00000 / 100.00000');
    await expect(main).toContainText(`/mod/lti/services.php/${st.platform.types.buw.courseid}/lineitems/${st.platform.types.buw.itemid}`);
    // The confirmation is required; the browser keeps the form until both fields are set.
    await page.getByRole('button', { name: 'Queue replay' }).click();
    await expect(main).toContainText('Required');
    await page.getByLabel('Reason for the replay').fill('BUW never received the grade (E2E)');
    await page.getByLabel('Confirmation').check();
    await page.getByRole('button', { name: 'Queue replay' }).click();
    await expect(page.locator('body')).toContainText('The replay is queued');
    attemptUrl = page.url();
    expect(attemptUrl).toContain('attempt=');
    // Nothing was sent before cron.
    expect(consumerGrades().buw).toBeNull();

    stack(TOOLCFG, 'replaytask');
    const g = consumerGrades();
    expect(g.buw.finalgrade).toBe(65);
    expect(g.control).toEqual(controlBefore);

    await page.goto(attemptUrl);
    await expect(page.locator('[role=main]')).toContainText('HTTP accepted');
    const tool = JSON.parse(stack(TOOLCFG, 'toolstate').trim().split('\n').pop());
    expect(tool.ltiusers[0].lastgrade).toBe('65.00000');
    const replays = tool.journal.filter((r) => r.source === 'instrumented');
    expect(replays).toHaveLength(1);
    expect(replays[0].state).toBe('http_accepted');
    expect(replays[0].connkey).toBe(st.tool.registrations.buw.key);
    // The result check after the replay (setting "repairresultcheck", on by default) read the consumer's AGS result.
    expect(replays[0].bookingstate).toBe('results_match');
    await page.goto(tabUrl(st, 'buw'));
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('AGS result matches');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).not.toContainText('booked (signed receipt)');
    // Control is untouched: still the accepted, unconfirmed core send.
    await page.goto(tabUrl(st, 'control'));
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('HTTP accepted, booking unknown');
  });

  test('#6 the AGS result of the consumer confirms the value', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw'));
    await page.getByRole('button', { name: /Check AGS result for/ }).click();
    await expect(page.locator('body')).toContainText('AGS result checked: AGS result matches');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('AGS result matches');
  });

  test('#6 signed receipt round trip with the real platform key', async ({ browser }) => {
    const tool = await browser.newContext({ acceptDownloads: true });
    const platform = await browser.newContext({ acceptDownloads: true });
    const tp = await tool.newPage();
    const pp = await platform.newPage();
    await login(tp, st.tool.url, ADMIN);
    await tp.goto(attemptUrl);
    const [reqdl] = await Promise.all([
      tp.waitForEvent('download'),
      tp.getByRole('button', { name: 'Export receipt request' }).click(),
    ]);
    const request = await downloadText(reqdl);
    expect(JSON.parse(request).schema).toBe('ltidiag-grade-request-1');
    expect(request).not.toContain('learner1');

    await login(pp, st.platform.url, ADMIN);
    const pkey = `platform:${st.platform.types.buw.typeid}`;
    await pp.goto(`${st.platform.url}/admin/tool/ltidiagnostics/receipt.php?key=${encodeURIComponent(pkey)}`);
    await pp.locator('textarea[name=document]').fill(request);
    const [rcptdl] = await Promise.all([
      pp.waitForEvent('download'),
      pp.getByRole('button', { name: 'Create signed receipt' }).click(),
    ]);
    const receipt = await downloadText(rcptdl);
    expect(JSON.parse(receipt).schema).toBe('ltidiag-grade-receipt-1');

    await tp.goto(attemptUrl);
    await tp.locator('textarea[name=document]').fill(receipt);
    await tp.getByRole('button', { name: 'Import receipt' }).click();
    await expect(tp.locator('body')).toContainText('Receipt imported: booked (signed receipt)');
    // The same receipt twice is refused.
    await tp.locator('textarea[name=document]').fill(receipt);
    await tp.getByRole('button', { name: 'Import receipt' }).click();
    await expect(tp.locator('body')).toContainText('already_imported');
    await tp.goto(tabUrl(st, 'buw'));
    await expect(tp.locator('#tool_ltidiagnostics_grades_rows')).toContainText('booked (signed receipt)');
    // A receipt with a forged value fails the signature.
    const forged = JSON.parse(receipt);
    if (typeof forged.jwt === 'string') {
      const parts = forged.jwt.split('.');
      const body = JSON.parse(Buffer.from(parts[1], 'base64url').toString());
      body.scoregiven = 1;
      parts[1] = Buffer.from(JSON.stringify(body)).toString('base64url');
      forged.jwt = parts.join('.');
      await tp.goto(attemptUrl);
      await tp.locator('textarea[name=document]').fill(JSON.stringify(forged));
      await tp.getByRole('button', { name: 'Import receipt' }).click();
      await expect(tp.locator('body')).toContainText('signature');
    }
    await tool.close();
    await platform.close();
  });

  test('#6 a teacher change at the consumer is shown as a mismatch, never as booked', async ({ page }) => {
    stack(PLATFORMCFG, 'platformgrade', 'buw:40');
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw'));
    await page.getByRole('button', { name: /Check AGS result for/ }).click();
    await expect(page.locator('body')).toContainText('AGS result checked: AGS result differs');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).toContainText('consumer shows a different value');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).not.toContainText('booked (signed receipt)');
    expect(consumerGrades().control).toEqual(controlBefore);
  });

  test('#5/#6 a newer score at the consumer gives 409, which is never retried automatically', async ({ page }) => {
    stack(PLATFORMCFG, 'futuregrade', 'buw');
    const before = consumerGrades().buw;
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw'));
    await page.getByRole('link', { name: /Preview replay for/ }).click();
    await page.getByLabel('Reason for the replay').fill('Second replay against a newer consumer score (E2E)');
    await page.getByLabel('Confirmation').check();
    await page.getByRole('button', { name: 'Queue replay' }).click();
    await expect(page.locator('body')).toContainText('The replay is queued');
    const url = page.url();
    stack(TOOLCFG, 'replaytask');
    expect(consumerGrades().buw).toEqual(before);
    expect(consumerGrades().control).toEqual(controlBefore);
    await page.goto(url);
    await expect(page.locator('#tool_ltidiagnostics_409')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'Queue replay' })).toHaveCount(0);
    const tool = JSON.parse(stack(TOOLCFG, 'toolstate').trim().split('\n').pop());
    const last = tool.journal.filter((r) => r.source === 'instrumented').pop();
    expect(last.errorclass).toBe('http_409');
    expect(last.state).toBe('http_rejected');
    // Still exactly one queued task run: no automatic retry was scheduled.
    expect(JSON.parse(stack(TOOLCFG, 'replaytask').trim().split('\n').pop()).ran).toBe(0);
  });

  test('#8 the support package matches the attempt journal and holds no personal data', async ({ browser }) => {
    const ctx = await browser.newContext({ acceptDownloads: true });
    const page = await ctx.newPage();
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw'));
    // The link serves the JSON inline; read it with the session of the page.
    const href = await page.getByRole('link', { name: 'Support package JSON' }).getAttribute('href');
    const resp = await page.request.get(new URL(href, page.url()).toString());
    expect(resp.status()).toBe(200);
    const text = await resp.text();
    const pkg = JSON.parse(text);
    expect(pkg.schema).toBe('ltidiag-support-2');
    const tool = JSON.parse(stack(TOOLCFG, 'toolstate').trim().split('\n').pop());
    const mine = tool.journal.filter((r) => r.connkey === st.tool.registrations.buw.key);
    expect(pkg.grades.attempts).toHaveLength(mine.length);
    for (const r of mine) {
      const a = pkg.grades.attempts.find((x) => x.attemptid === r.attemptid);
      expect(a, `attempt ${r.attemptid} is in the package`).toBeTruthy();
      expect(a.state).toBe(r.state);
      expect(a.errorclass).toBe(r.errorclass);
      expect(a.booking).toBe(r.bookingstate);
    }
    for (const k of ['provider', 'consumer', 'both', 'unknown']) {
      expect(Array.isArray(pkg.responsibility[k])).toBe(true);
    }
    // No names, user ids, scores, tokens or raw endpoint URLs.
    expect(text).not.toMatch(/learner1|Lena|Learner|example\.org/);
    expect(text).not.toMatch(/"(userid|scoregiven|scoremax|token|access_token)"/i);
    expect(text).not.toContain('/mod/lti/services.php');
    await ctx.close();
  });
});
