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
 * Accessibility checks for tool_ltidiagnostics (audit item DIA-05).
 *
 * Structural and keyboard checks only; they do not replace a manual review with a screen reader. Runs on the
 * two-site stack.
 *
 * Independence: the keyboard test submits the health check, which stores the probe results of the platform
 * connection. The spec removes stored probe results before it starts and removes the ones it created afterwards,
 * so no other spec sees them, whatever the order of the spec files.
 *
 * - Every data table has a caption (visible or hidden) and column headers with scope.
 * - A status never relies on colour alone: each badge carries text.
 * - The tabs and the health check form are reachable and operable with the keyboard.
 * - The root cause card is a labelled region.
 */

const { test, expect } = require('@playwright/test');
const { loadSeed, login, connectionUrl, resetConnectionState, countProbes, nowSeconds } = require('./lib');

const seed = loadSeed();
const siteA = seed.urls.a;
const platformKey = `platform:${seed.lti.platform.type_id}`;

test.describe.configure({ mode: 'serial' });

let startedat = 0;

test.beforeAll(() => {
  startedat = nowSeconds();
  resetConnectionState('a', platformKey, { probes: true });
});

test.afterAll(() => {
  resetConnectionState('a', platformKey, { probes: true, since: startedat });
  expect(countProbes('a', platformKey)).toBe(0);
});

for (const tab of ['matrix', 'health', 'timeline', 'evidence', 'history']) {
  test(`tab ${tab}: tables have captions and scoped headers`, async ({ page }) => {
    await login(page, siteA, seed.admins.a);
    await page.goto(connectionUrl(siteA, platformKey, tab));
    const tables = page.locator('#region-main table');
    const count = await tables.count();
    for (let i = 0; i < count; i++) {
      const table = tables.nth(i);
      await expect(table.locator(':scope > caption')).toHaveCount(1);
      const headers = table.locator(':scope > thead th, :scope > tbody > tr > th');
      if (await headers.count()) {
        const unscoped = await table.locator(':scope > thead th:not([scope])').count();
        expect(unscoped).toBe(0);
      }
    }
  });
}

test('status badges carry text, not only colour', async ({ page }) => {
  await login(page, siteA, seed.admins.a);
  await page.goto(connectionUrl(siteA, platformKey, 'matrix'));
  const badges = page.locator('#tool_ltidiagnostics_matrix .badge');
  const count = await badges.count();
  for (let i = 0; i < count; i++) {
    const text = (await badges.nth(i).innerText()).trim();
    expect(text.length).toBeGreaterThan(0);
  }
});

test('the health check can be reached and submitted with the keyboard only', async ({ page }) => {
  await login(page, siteA, seed.admins.a);
  await page.goto(connectionUrl(siteA, platformKey, 'health'));
  const button = page.getByRole('button', { name: /run|check/i }).first();
  await button.focus();
  await expect(button).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByText('The checks were run and saved.')).toBeVisible();
  await expect(page.locator('[aria-labelledby="ltidiag-rootcause"], #tool_ltidiagnostics_norootcause').first()).toBeVisible();
  // The submission really stored probe results (cleaned up in afterAll).
  expect(countProbes('a', platformKey)).toBeGreaterThan(0);
});

test('the tab bar is operable with Tab and Enter', async ({ page }) => {
  await login(page, siteA, seed.admins.a);
  await page.goto(connectionUrl(siteA, platformKey, 'matrix'));
  const link = page.getByRole('link', { name: 'Health check' }).first();
  await link.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/tab=health/);
});

test('focus is visible on interactive elements', async ({ page }) => {
  await login(page, siteA, seed.admins.a);
  await page.goto(connectionUrl(siteA, platformKey, 'health'));
  const link = page.getByRole('link', { name: 'Timeline' }).first();
  await link.focus();
  const outline = await link.evaluate((el) => {
    const s = getComputedStyle(el);
    return s.outlineStyle !== 'none' || s.boxShadow !== 'none';
  });
  expect(outline).toBe(true);
});
