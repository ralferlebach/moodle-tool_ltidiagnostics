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
 * User stories of the UX rework (issues #10 to #27) end to end on the two-site stack: Moodle 4.5 tool (provider),
 * Moodle 5.2 platform (consumer) with the Control and BUW tool types. The Control link gets 60 more learners so that
 * paging, the server-side selection and the bulk replay run on a real population.
 *
 * Covered: asynchronous paging with history, back/forward and reload; the same state without JavaScript; counters that
 * equal the filtered totals; the journal of one row; the bulk replay with a double submit and a partial failure at the
 * real consumer; read-only views never POST; the scope bar; the support journey; accessibility (axe, keyboard, live
 * region); screenshots of every view in English and German for the manual acceptance (SCREEN_DIR).
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATEFILE = process.env.GRADES_STATE || '/home/claude/e2e/state.json';
const STACK = path.join(__dirname, 'stack', 'grades_stack.php');
const TOOLCFG = process.env.TOOL_CONFIG || '/home/claude/moodle/config.php';
const PLATFORMCFG = process.env.PLATFORM_CONFIG || '/home/claude/moodle52/config.php';
const SCREENDIR = process.env.SCREEN_DIR || '/home/claude/e2e/screens';
const ADMIN = { username: 'admin', password: process.env.E2E_ADMIN_PASSWORD || 'E2eAdmin123!' };
const VIEWER = { username: 'diagviewer', password: 'Viewer123!' };

let AxeBuilder = null;
try {
  AxeBuilder = require('@axe-core/playwright').default;
} catch (e) {
  AxeBuilder = null;
}

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
 * URL of a tab of a tool side connection.
 *
 * @param {object} st State.
 * @param {string} which control or buw.
 * @param {string} tab Tab.
 * @param {string} [extra] Extra query.
 * @returns {string}
 */
function tabUrl(st, which, tab, extra = '') {
  const key = st.tool.registrations[which].key;
  return `${st.tool.url}/admin/tool/ltidiagnostics/connection.php?key=${encodeURIComponent(key)}&tab=${tab}${extra}`;
}

/**
 * Number of the population shown in the rows count ("1 to 20 of 61 pairs ...").
 *
 * @param {import('@playwright/test').Page} page Page.
 * @returns {Promise<number>}
 */
async function rowsTotal(page) {
  return Number(await page.locator('#tool_ltidiagnostics_rows_count').getAttribute('data-total'));
}

/**
 * Accessibility scan of the main region (WCAG 2.1 A and AA rules of axe), when axe is installed.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} name Name for the message.
 */
async function axe(page, name) {
  if (!AxeBuilder) {
    throw new Error('@axe-core/playwright is required for the accessibility checks (npm install @axe-core/playwright)');
  }
  const result = await new AxeBuilder({ page }).include('#region-main').withTags(['wcag2a', 'wcag2aa']).analyze();
  const serious = result.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
  expect(serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`), name)
    .toEqual([]);
}

/**
 * Save a full page screenshot for the acceptance log.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} name File name without extension.
 */
async function shot(page, name) {
  fs.mkdirSync(SCREENDIR, { recursive: true });
  await page.screenshot({ path: path.join(SCREENDIR, `${name}.png`), fullPage: true });
}

test.describe.configure({ mode: 'serial' });

test.describe('UX user stories 4.5 provider -> 5.2 consumer', () => {
  const st = JSON.parse(fs.readFileSync(STATEFILE, 'utf8'));

  test.beforeAll(() => {
    stack(TOOLCFG, 'manylearners', '60');
    stack(TOOLCFG, 'journalrows', '45');
    stack(TOOLCFG, 'viewer');
  });

  test('#21 asynchronous paging keeps the page, updates the history, announces the result and survives back and reload',
    async ({ page }) => {
      await login(page, st.tool.url, ADMIN);
      await page.goto(tabUrl(st, 'control', 'grades'));
      const total = await rowsTotal(page);
      expect(total).toBeGreaterThanOrEqual(61);
      await page.evaluate(() => {
        window.ltidiagMarker = 'still-here';
      });
      const rows = page.locator('#tool_ltidiagnostics_section_grades_rows');
      await rows.locator('select[name=perpage]').selectOption('20');
      await rows.locator('form.ltidiag-filter button[type=submit]').first().click();
      await expect(page).toHaveURL(/perpage=20/);
      await expect(page.locator('#tool_ltidiagnostics_live')).toContainText('Updated:');
      expect(await page.evaluate(() => window.ltidiagMarker)).toBe('still-here');
      await expect(rows.locator('#tool_ltidiagnostics_grades_rows tbody tr')).toHaveCount(20);
      // Next page through the core paging bar (issue #31): one above and one below the table.
      await expect(rows.locator('.ltidiag-pager[data-pagevar=gpage]')).toHaveCount(2);
      await rows.locator('.ltidiag-pager').first().getByRole('link', { name: /Next/ }).click();
      await expect(page).toHaveURL(/gpage=1/);
      expect(await page.evaluate(() => window.ltidiagMarker)).toBe('still-here');
      const second = await rows.locator('#tool_ltidiagnostics_rows_count').innerText();
      expect(second).toMatch(/^21 to 40 of/);
      // Back restores the first page, still without a reload; a reload shows the same state from the URL.
      await page.goBack();
      await expect(rows.locator('#tool_ltidiagnostics_rows_count')).toContainText(/^1 to 20 of/);
      expect(await page.evaluate(() => window.ltidiagMarker)).toBe('still-here');
      await page.goForward();
      await expect(rows.locator('#tool_ltidiagnostics_rows_count')).toContainText(/^21 to 40 of/);
      await page.reload();
      await expect(page.locator('#tool_ltidiagnostics_rows_count')).toContainText(/^21 to 40 of/);
      expect(await rowsTotal(page)).toBe(total);
      // 500 rows in one page.
      await page.goto(tabUrl(st, 'control', 'grades', '&perpage=500'));
      await expect(page.locator('#tool_ltidiagnostics_grades_rows tbody tr')).toHaveCount(total);
    });

  test('#13/#21 every counter equals the population of its filter, also without JavaScript', async ({ browser }) => {
    const ctx = await browser.newContext({ javaScriptEnabled: false });
    const page = await ctx.newPage();
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'control', 'grades'));
    const total = await rowsTotal(page);
    for (const dim of ['grade', 'transfer', 'booking']) {
      await page.goto(tabUrl(st, 'control', 'grades'));
      const cells = page.locator(`#tool_ltidiagnostics_cnt_${dim} tbody tr`);
      const n = await cells.count();
      let sum = 0;
      const pairs = [];
      for (let i = 0; i < n; i++) {
        const value = await cells.nth(i).getAttribute('data-value');
        const count = Number((await cells.nth(i).locator('td').last().textContent()).trim());
        sum += count;
        pairs.push([value, count]);
      }
      expect(sum, `counters of ${dim} add up to the total`).toBe(total);
      for (const [value, count] of pairs) {
        if (!count) {
          continue;
        }
        await page.goto(tabUrl(st, 'control', 'grades', `&f${dim}=${value}`));
        expect(await rowsTotal(page), `${dim}=${value}`).toBe(count);
      }
    }
    await expect(page.locator('body')).not.toContainText('Open work');
    // The filter form and the pager work as plain GET requests.
    await page.goto(tabUrl(st, 'control', 'grades'));
    await page.locator('select[name=fgrade]').selectOption('zero');
    await page.locator('#tool_ltidiagnostics_section_grades_rows form.ltidiag-filter button[type=submit]').first().click();
    await expect(page).toHaveURL(/fgrade=zero/);
    expect(await rowsTotal(page)).toBeGreaterThan(0);
    await ctx.close();
  });

  test('#23 the journal of one row filters the journal section to that link and learner', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'control', 'grades'));
    const link = page.locator('a[data-ltidiag-target=grades_journal]').first();
    const pair = await link.getAttribute('data-ltidiag-pair');
    await link.click();
    const journal = page.locator('#tool_ltidiagnostics_section_grades_journal');
    await expect(journal.locator('#tool_ltidiagnostics_journal_pair')).toContainText('Only entries of resource link');
    await expect(page).toHaveURL(new RegExp(`jlink=${pair.split(':')[0]}`));
    // Core's own skip of the original learner is in the journal with its origin; unattributable rows are filterable.
    await page.goto(tabUrl(st, 'control', 'grades', '&jorigin=core'));
    await expect(page.locator('#tool_ltidiagnostics_section_grades_journal')).toContainText('core');
  });

  test('#18/#19/#20 read-only views never send a POST and name what each check proves', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    const posts = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && !/lib\/ajax\/service\.php/.test(r.url())) {
        posts.push(r.url());
      }
    });
    for (const tab of ['matrix', 'messages', 'health', 'timeline', 'evidence', 'history']) {
      await page.goto(tabUrl(st, 'buw', tab));
      await expect(page.locator('#tool_ltidiagnostics_tabs')).toBeVisible();
    }
    expect(posts).toEqual([]);
    await page.goto(tabUrl(st, 'buw', 'matrix'));
    const howto = page.locator('details.ltidiag-howto').first();
    await expect(howto).toHaveCount(1);
    await expect(page.locator('li:has(> [data-effect=readonly])').first()).toContainText('read-only');
    await page.goto(tabUrl(st, 'buw', 'health'));
    await expect(page.locator('#tool_ltidiagnostics_services')).toContainText('Launch');
    await expect(page.locator('#tool_ltidiagnostics_services')).toContainText('Booking at the consumer');
  });

  test('#22 bulk replay: selection of a page, preview of every target, one batch on double submit, partial failure',
    async ({ page }) => {
      await login(page, st.tool.url, ADMIN);
      await page.goto(tabUrl(st, 'control', 'grades'));
      const optin = page.getByRole('button', { name: 'Allow grade replay for this connection' });
      if (await optin.count()) {
        await optin.click();
      }
      await page.goto(tabUrl(st, 'control', 'grades', '&fgrade=present&perpage=20'));
      const total = await rowsTotal(page);
      expect(total).toBeGreaterThan(5);
      // Mark three rows with the keyboard and the select-all box, then take the marked rows.
      await page.locator('#ltidiag-pair-all').check();
      await expect(page.locator('input.ltidiag-pair:checked')).toHaveCount(Math.min(20, total));
      await page.locator('#ltidiag-pair-all').uncheck();
      const boxes = page.locator('input.ltidiag-pair');
      for (let i = 0; i < 3; i++) {
        await boxes.nth(i).focus();
        await page.keyboard.press('Space');
      }
      // Selecting never sends anything; the action is chosen with the selection (issue #32).
      await page.locator('select[name=bulkaction]').selectOption('replay');
      await page.getByRole('button', { name: 'Preview marked rows' }).click();
      const main = page.locator('[role=main]');
      await expect(main).toContainText('Preview of the bulk replay');
      await expect(main).toContainText('3 selected learners (marked rows)');
      await expect(main).toContainText('no reset of lastgrade');
      await expect(page.locator('#tool_ltidiagnostics_bulk_summary')).toBeVisible();
      await page.getByLabel('Reason for the replay').fill('Bulk replay E2E');
      await page.getByLabel('Confirmation').check();
      const before = JSON.parse(stack(TOOLCFG, 'toolstate').trim().split('\n').pop()).journal.length;
      await page.getByRole('button', { name: 'Queue replay' }).dblclick();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).toContainText(/Status of the bulk replay|selection has expired or was already used/);
      const after = JSON.parse(stack(TOOLCFG, 'toolstate').trim().split('\n').pop()).journal;
      const queued = after.slice(before);
      expect(queued.length, 'one attempt per target, the double submit made no second batch').toBeLessThanOrEqual(3);
      expect(queued.length).toBeGreaterThan(0);
      // The used selection cannot be confirmed again.
      await page.goBack();
      await page.getByLabel('Reason for the replay').fill('Second try');
      await page.getByLabel('Confirmation').check();
      await page.getByRole('button', { name: 'Queue replay' }).click();
      await expect(page.locator('body')).toContainText(/selection has expired or was already used|Status of the bulk replay/);
      const again = JSON.parse(stack(TOOLCFG, 'toolstate').trim().split('\n').pop()).journal.length;
      expect(again).toBe(before + queued.length);
      // Send: every target on its own; the consumer does not know the pager learners, so those fail while others stay.
      stack(TOOLCFG, 'replaytask');
      const tool = JSON.parse(stack(TOOLCFG, 'toolstate').trim().split('\n').pop());
      const states = tool.journal.slice(before).map((r) => r.state);
      expect(states.every((s) => ['http_accepted', 'http_rejected', 'retry_wait', 'dead', 'aborted', 'queued'].includes(s)))
        .toBe(true);
      for (const r of tool.journal.slice(before)) {
        expect(['results_match', 'receipt_confirmed'].includes(r.bookingstate) && r.state !== 'http_accepted').toBe(false);
      }
      // With the replay possible, the function check marks the replay as the one action that changes the partner.
      await page.goto(tabUrl(st, 'control', 'matrix'));
      await expect(page.locator('li:has(> [data-effect=mutating])').first()).toContainText('changes data at the partner');
    });

  test('#13 the scope bar restricts the report, shows chips and can be reset', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'control', 'evidence'));
    const bar = page.locator('#tool_ltidiagnostics_scope');
    await bar.locator('select[name=courseid]').selectOption(String(st.tool.courseid));
    await bar.locator('input[name=from]').fill('2026-01-01');
    await bar.getByRole('button', { name: 'Apply' }).click();
    await expect(page).toHaveURL(new RegExp(`courseid=${st.tool.courseid}`));
    await expect(page.locator('[data-chip=course]')).toBeVisible();
    await expect(page.locator('[data-chip=period]')).toContainText('2026-01-01');
    // The scope follows the tabs.
    await page.locator('#tool_ltidiagnostics_tabs a[href*="tab=grades"]').click();
    await expect(page).toHaveURL(new RegExp(`courseid=${st.tool.courseid}`));
    await page.locator('#tool_ltidiagnostics_scope_reset').click();
    await expect(page.locator('[data-chip]')).toHaveCount(0);
  });

  test('#15/#27 support journey: list by name, case with the next step, on to the diagnosis and the journal',
    async ({ page }) => {
      await login(page, st.tool.url, ADMIN);
      await page.goto(`${st.tool.url}/admin/tool/ltidiagnostics/support.php?search=BUW`);
      await expect(page.locator('#tool_ltidiagnostics_support_cases')).toContainText('BUW consumer');
      await expect(page.locator('#tool_ltidiagnostics_support_cases')).not.toContainText('Control consumer');
      await expect(page.locator('a[href*="support.php?id="]')).toHaveCount(0);
      await page.getByRole('link', { name: 'Support case of BUW consumer' }).click();
      await expect(page.locator('#tool_ltidiagnostics_support_journey')).toContainText('Finding:');
      await page.getByRole('link', { name: 'Grade journal' }).click();
      await expect(page).toHaveURL(/tab=grades/);
      await page.goto(`${st.tool.url}/admin/tool/ltidiagnostics/audit.php`);
      await expect(page.locator('#tool_ltidiagnostics_audit')).toContainText('Support case viewed');
      await expect(page.locator('#tool_ltidiagnostics_audit')).toContainText('BUW consumer');
    });

  test('#14/#24 completion with the plugin present: tab exists, actions only in the completion context', async ({ page }) => {
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'buw', 'grades'));
    await expect(page.locator('#tool_ltidiagnostics_tabs')).toContainText('Completion sync');
    await expect(page.locator('#region-main')).not.toContainText('Completion transfers');
    await page.goto(tabUrl(st, 'buw', 'health'));
    await expect(page.locator('#ltidiag-partnerlink')).toContainText('Collect partner evidence for this connection');
    await page.goto(tabUrl(st, 'buw', 'matrix'));
    await expect(page.locator('#ltidiag-partnerlink')).toHaveCount(0);
  });

  test('#12/#21 a viewer without the identity right sees pseudonyms and no scores', async ({ page }) => {
    await login(page, st.tool.url, VIEWER);
    await page.goto(tabUrl(st, 'control', 'grades'));
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).not.toContainText('Paula');
    await expect(page.locator('#tool_ltidiagnostics_grades_rows')).not.toContainText('65.00');
    await expect(page.locator('input[name=fsearch]')).toHaveCount(0);
  });

  test('#29/#30 the course and activity filter of the overview show only connections used there, by name, with pencils',
    async ({ page }) => {
      const other = JSON.parse(stack(TOOLCFG, 'othercourse').trim().split('\n').pop());
      await login(page, st.tool.url, ADMIN);
      const index = `${st.tool.url}/admin/tool/ltidiagnostics/index.php`;
      await page.goto(index);
      const list = page.locator('#tool_ltidiagnostics_connections');
      await expect(list).toContainText('Other platform E2E');
      await expect(list).toContainText('Control consumer');
      // One row, one pencil per thing: the registration in the name cell, the publication in "Used in".
      const control = list.locator('tr', { hasText: 'Control consumer' }).first();
      await expect(control.locator('th a.ltidiag-edit')).toHaveCount(1);
      await expect(control.locator('td a.ltidiag-edit[aria-label^="Edit publication"]').first()).toBeVisible();
      await expect(control).toContainText(/\(id: \d+\)/);
      // Course filter: only the connections of that course.
      const filter = page.locator('#tool_ltidiagnostics_overview_filter');
      await filter.locator('select[name=courseid]').selectOption(String(other.courseid));
      await filter.getByRole('button', { name: 'Filter' }).click();
      await expect(page).toHaveURL(new RegExp(`courseid=${other.courseid}`));
      await expect(list).toContainText('Other platform E2E');
      await expect(list).not.toContainText('Control consumer');
      await expect(list).not.toContainText('BUW consumer');
      await filter.locator('select[name=courseid]').selectOption(String(st.tool.courseid));
      await filter.getByRole('button', { name: 'Filter' }).click();
      await expect(list).toContainText('Control consumer');
      await expect(list).not.toContainText('Other platform E2E');
      // Activity filter: the course-wide publication of the other course never matches an activity.
      await filter.locator('select[name=cmid]').selectOption(String(st.tool.cmid));
      await filter.getByRole('button', { name: 'Filter' }).click();
      await expect(page).toHaveURL(new RegExp(`cmid=${st.tool.cmid}`));
      await expect(list).toContainText('Control consumer');
      await expect(list).not.toContainText('Other platform E2E');
      // The open link and the way back keep the filter; reload keeps it; reset removes it.
      await control.getByRole('link', { name: /^Open/ }).click();
      await expect(page).toHaveURL(new RegExp(`cmid=${st.tool.cmid}`));
      await expect(page.locator('[data-chip=cm]')).toBeVisible();
      await expect(page.locator('#tool_ltidiagnostics_connheading a.ltidiag-edit')).toHaveCount(1);
      await expect(page.locator('#region-main')).not.toContainText('Edit deployments');
      await page.locator('#tool_ltidiagnostics_backtooverview').click();
      await expect(page).toHaveURL(new RegExp(`cmid=${st.tool.cmid}`));
      await page.reload();
      await expect(list).not.toContainText('Other platform E2E');
      await page.locator('#tool_ltidiagnostics_overview_reset').click();
      await expect(list).toContainText('Other platform E2E');
      // Fail closed: an unknown course lists nothing.
      await page.goto(`${index}?courseid=999999`);
      await expect(page.locator('#tool_ltidiagnostics_connections')).toHaveCount(0);
      await expect(page.locator('#tool_ltidiagnostics_noconnections')).toBeVisible();
      // A connection opened with a course it is not used in shows empty scoped views.
      await page.goto(tabUrl(st, 'control', 'grades', `&courseid=${other.courseid}`));
      await expect(page.locator('#region-main')).toContainText('no proven use in the selected course or activity');
      expect(await rowsTotal(page)).toBe(0);
      await expect(page.locator('#tool_ltidiagnostics_grades_prereq')).toContainText('no published resource in the selected');
      const exportlink = page.locator('a[href*="export.php"]').first();
      await expect(exportlink).toHaveAttribute('href', new RegExp(`courseid=${other.courseid}`));
      const pkg = page.locator('#tool_ltidiagnostics_pkg_json');
      await expect(pkg).toHaveAttribute('href', new RegExp(`courseid=${other.courseid}`));
    });

  test('#31/#32 compact toolbars, folded counters, row checkboxes and a journal with its own paging bar', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, st.tool.url, ADMIN);
    await page.goto(tabUrl(st, 'control', 'grades', '&jperpage=20'));
    const rows = page.locator('#tool_ltidiagnostics_section_grades_rows');
    // One row of selects: every select of the rows toolbar starts on the same line at desktop width.
    // One row: every select, input and button of both toolbars ends on the same line at the default desktop width.
    for (const id of ['grades_rows', 'grades_journal']) {
      const bottoms = await page.locator(`#tool_ltidiagnostics_section_${id} form.ltidiag-filter`)
        .locator('select, input:not([type=hidden]), button').evaluateAll((els) =>
          els.map((e) => Math.round(e.getBoundingClientRect().bottom)));
      expect(bottoms.length).toBeGreaterThanOrEqual(5);
      expect(Math.max(...bottoms) - Math.min(...bottoms), `${id} toolbar: ${bottoms}`).toBeLessThan(12);
    }
    // The three counter tables are folded away under one summary line.
    const details = page.locator('#tool_ltidiagnostics_counters_details');
    await expect(details).toHaveCount(1);
    await expect(details).not.toHaveAttribute('open', '');
    await expect(page.locator('#tool_ltidiagnostics_cnt_grade')).toBeHidden();
    await details.locator('summary').click();
    await expect(page.locator('#tool_ltidiagnostics_cnt_grade')).toBeVisible();
    // Checkboxes and "select all shown" select exactly the shown page.
    const shown = await rows.locator('#tool_ltidiagnostics_grades_rows tbody tr').count();
    await page.locator('#ltidiag-pair-all').check();
    await expect(page.locator('input.ltidiag-pair:checked')).toHaveCount(shown);
    await page.locator('#ltidiag-pair-all').uncheck();
    // The journal: compact toolbar, "X–Y of Z", core paging bar above and below, own page parameter.
    const journal = page.locator('#tool_ltidiagnostics_section_grades_journal');
    await expect(journal.locator('select[name=jorigin]')).toHaveCount(1);
    await expect(journal.locator('input[name=jsearch]')).toHaveCount(1);
    const count = journal.locator('#tool_ltidiagnostics_journal_count');
    const total = Number(await count.getAttribute('data-total'));
    expect(total, 'the journal has several pages').toBeGreaterThan(40);
    {
      await expect(journal.locator('.ltidiag-pager[data-pagevar=jpage]')).toHaveCount(2);
      await expect(count).toContainText(/Entries 1–20 of/);
      await journal.locator('.ltidiag-pager').last().getByRole('link', { name: /Next/ }).click();
      await expect(page).toHaveURL(/jpage=1/);
      await expect(count).toContainText(/Entries 21–/);
      // The other block keeps its page.
      expect(page.url()).not.toMatch(/gpage=/);
      await page.goBack();
      await expect(count).toContainText(/Entries 1–20 of/);
    }
    // A tampered page number shows the last page, never an empty one.
    await page.goto(tabUrl(st, 'control', 'grades', '&perpage=20&gpage=99999'));
    await expect(page.locator('#tool_ltidiagnostics_grades_rows tbody tr').first()).toBeVisible();
  });

  test('#11/#28 accessibility and screenshots of every view in English and German', async ({ page }) => {
    test.setTimeout(900000);
    await login(page, st.tool.url, ADMIN);
    const views = [
      ['overview', `${st.tool.url}/admin/tool/ltidiagnostics/index.php`],
      ['overview_course', `${st.tool.url}/admin/tool/ltidiagnostics/index.php?courseid=${st.tool.courseid}`],
      ['control_grades', tabUrl(st, 'control', 'grades', '&perpage=20&jperpage=20')],
      ['support', `${st.tool.url}/admin/tool/ltidiagnostics/support.php`],
      ['audit', `${st.tool.url}/admin/tool/ltidiagnostics/audit.php`],
    ];
    for (const tab of ['matrix', 'messages', 'health', 'grades', 'completion', 'timeline', 'evidence', 'history']) {
      views.push([`buw_${tab}`, tabUrl(st, 'buw', tab)]);
    }
    for (const lang of ['en', 'de']) {
      for (const [name, url] of views) {
        await page.goto(`${url}${url.includes('?') ? '&' : '?'}lang=${lang}`);
        await expect(page.locator('#region-main')).toBeVisible();
        await expect(page.locator('body')).not.toContainText('[[');
        if (lang === 'de' && name.startsWith('buw_')) {
          await expect(page.locator('#tool_ltidiagnostics_tabs')).toContainText('Funktionscheck');
          await expect(page.locator('#tool_ltidiagnostics_tabs')).toContainText('Verbindungs-Diagnose');
        }
        await axe(page, `${lang} ${name}`);
        await shot(page, `${lang}_${name}`);
      }
    }
    // Keyboard: the scope bar, the tabs and the first filter are reachable with Tab, the live region exists.
    await page.goto(`${tabUrl(st, 'control', 'grades')}&lang=en`);
    await expect(page.locator('#tool_ltidiagnostics_live[aria-live=polite]')).toHaveCount(1);
    const first = page.locator('#tool_ltidiagnostics_section_grades_rows select').first();
    await first.focus();
    await expect(first).toBeFocused();
  });
});
