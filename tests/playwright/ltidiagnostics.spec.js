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
 * End-to-end story for tool_ltidiagnostics on the two-site stack.
 *
 * 1. Admin A sees the platform connection; AGS and NRPS are "configured" but not "verified" (nothing was observed).
 * 2. A student launches from site A into site B (real LTI Advantage launch, auto provisioning and enrolment).
 * 3. Admin B opens the tool connection: the launch and the local enrolment are listed as separate evidence rows.
 * 4. Admin A's matrix still does not show AGS or NRPS as "verified" (no service call was observed).
 * 5. Access control: a student cannot open the diagnostics page.
 *
 * The test reads LTI_SEED (default /home/claude/e2e/state/seed.json). Set LTI_IGNORE_TLS=1 when the CA is not trusted
 * by the browser.
 */

const { test, expect } = require('@playwright/test');
const { loadSeed, getUser, login, connectionUrl } = require('./lib');

const seed = loadSeed();
const siteA = seed.urls.a;
const siteB = seed.urls.b;
const platformKey = `platform:${seed.lti.platform.type_id}`;
const toolKey = `tool:${seed.lti.registration_on_b.id}:${seed.lti.registration_on_b.deployment_row_id}`;

test.describe.configure({ mode: 'serial' });

test('platform matrix shows AGS and NRPS configured, not verified, before any launch', async ({ page }) => {
  await login(page, siteA, seed.admins.a);
  await page.goto(connectionUrl(siteA, platformKey, 'matrix'));
  const matrix = page.locator('#tool_ltidiagnostics_matrix');
  await expect(matrix).toBeVisible();
  for (const feature of ['ags', 'nrps']) {
    const row = matrix.locator(`tr[data-feature="${feature}"]`);
    await expect(row).toHaveCount(1);
    await expect(row).not.toHaveAttribute('data-status', 'verified');
    await expect(row).toHaveAttribute('data-status', 'configured');
  }
});

test('a real launch from A into B appears as separate evidence rows on B', async ({ browser }) => {
  const student = await browser.newContext();
  const spage = await student.newPage();
  const user = getUser(seed, 'studentA1');
  await login(spage, siteA, user);
  await spage.goto(seed.lti_activity.view_url);
  // The launch ends on site B inside the course (iframe or full page, depending on the launch container).
  await spage.waitForURL((u) => u.href.startsWith(siteB), { timeout: 90000 }).catch(() => {});
  await student.close();

  const admin = await browser.newContext();
  const page = await admin.newPage();
  await login(page, siteB, seed.admins.b);
  await page.goto(connectionUrl(siteB, toolKey, 'timeline'));
  const table = page.locator('#tool_ltidiagnostics_timeline');
  await expect(table).toBeVisible();
  const launches = table.locator('tr[data-stage="launch"]');
  const followups = table.locator('tr[data-stage="followup"]');
  expect(await launches.count()).toBeGreaterThan(0);
  expect(await followups.count()).toBeGreaterThan(0);
  // The two are different rows (a launch is not an enrolment).
  const launchText = await launches.first().innerText();
  const followText = await followups.first().innerText();
  expect(launchText).not.toEqual(followText);

  // The health check page renders layers and findings.
  await page.goto(connectionUrl(siteB, toolKey, 'health'));
  await expect(page.locator('#tool_ltidiagnostics_layers')).toBeVisible();
  await admin.close();
});

test('after the launch the platform side still does not claim AGS/NRPS as verified', async ({ page }) => {
  await login(page, siteA, seed.admins.a);
  await page.goto(connectionUrl(siteA, platformKey, 'matrix'));
  for (const feature of ['ags', 'nrps']) {
    await expect(page.locator(`#tool_ltidiagnostics_matrix tr[data-feature="${feature}"]`))
      .not.toHaveAttribute('data-status', 'verified');
  }
});

test('a student cannot open the diagnostics', async ({ page }) => {
  await login(page, siteA, getUser(seed, 'studentA1'));
  const response = await page.goto(connectionUrl(siteA, platformKey, 'matrix'));
  expect(response.status()).toBeGreaterThanOrEqual(400);
  await expect(page.locator('#tool_ltidiagnostics_matrix')).toHaveCount(0);
});
