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
 * Gate CS-G6 (release 0.2.1): transfer events of the LTI family plugin local_lticompletionsync appear in the timeline and
 * the evidence of the connection, grouped by their correlation id, with an observability level.
 *
 * The events are triggered on the sites by fixtures/family_events.php through the plugin's own event classes (the
 * stack has local_lticompletionsync installed). Tool side B: queued, sent, acknowledged; platform side A: received,
 * identity resolved, applied - all with the same correlation id.
 */

const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loadSeed, login, connectionUrl } = require('./lib');

const E2E_HOME = process.env.E2E_HOME || '/home/claude/e2e';
const ROOTS = { a: process.env.A_ROOT || '/home/claude/moodle', b: process.env.B_ROOT || '/home/claude/moodle53' };
const SCRIPT = path.join(__dirname, 'fixtures', 'family_events.php');

/**
 * Trigger transfer events on a site.
 *
 * @param {string} site a or b.
 * @param {object} args Script options without dashes.
 * @returns {object} Parsed JSON output.
 */
function trigger(site, args) {
  const opts = Object.entries(args).map(([k, v]) => `--${k}=${v}`);
  const cmd = `. ${E2E_HOME}/bin/e2e-env.sh; moodle_cli ${site} "$@"`;
  const out = execFileSync('bash', ['-c', cmd, 'family', SCRIPT, `--root=${ROOTS[site]}`, ...opts],
    { encoding: 'utf8', timeout: 120000 });
  const lines = out.trim().split('\n');
  return JSON.parse(lines[lines.length - 1]);
}

const seed = loadSeed();
const corr = crypto.randomUUID();
const toolKey = `tool:${seed.lti.registration_on_b.id}:${seed.lti.registration_on_b.deployment_row_id}`;
const platformKey = `platform:${seed.lti.platform.type_id}`;

test.describe.configure({ mode: 'serial' });

test('tool B: the transfer is listed with its correlation id, outcome and observability level', async ({ page }) => {
  const res = trigger('b', { corr, role: 'sender', registration: seed.lti.registration_on_b.id,
    deployment: seed.lti.registration_on_b.deployment_row_id });
  expect(res.installed).toBe(true);
  expect(res.events).toEqual(['message_queued', 'message_sent', 'message_acknowledged']);

  await login(page, seed.urls.b, seed.admins.b);
  await page.goto(connectionUrl(seed.urls.b, toolKey, 'timeline'));
  const tx = page.locator(`#tool_ltidiagnostics_transactions tr[data-correlationid="${corr}"]`);
  await expect(tx).toHaveCount(1);
  await expect(tx).toHaveAttribute('data-outcome', 'acknowledged');
  await expect(tx).toContainText('queued > sent > acknowledged');
  await expect(tx).toContainText('local_lticompletionsync');

  await page.goto(connectionUrl(seed.urls.b, toolKey, 'evidence'));
  const events = page.locator('#tool_ltidiagnostics_familyevents tbody tr', { hasText: corr });
  await expect(events).toHaveCount(3);
  const row = page.locator('tr[data-transaction="completion_sync"]').first();
  await expect(row).toBeVisible();
  await expect(row.locator('td[data-level="recorded"]').first()).toBeVisible();
});

test('platform A: the same correlation id shows the receiving side', async ({ page }) => {
  const res = trigger('a', { corr, role: 'receiver', typeid: seed.lti.platform.type_id });
  expect(res.installed).toBe(true);
  await login(page, seed.urls.a, seed.admins.a);
  await page.goto(connectionUrl(seed.urls.a, platformKey, 'timeline'));
  const tx = page.locator(`#tool_ltidiagnostics_transactions tr[data-correlationid="${corr}"]`);
  await expect(tx).toHaveCount(1);
  await expect(tx).toHaveAttribute('data-outcome', 'applied');
  await expect(tx).toContainText('http_received > identity_resolved > completion_applied');
});
