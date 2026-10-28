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
 * tool_ltigroupautoenrol with and without the diagnostics (audit item GA-03) on the stack: B (5.3 tool) maps the
 * published tool of an own scenario course to a group.
 *
 *  1. Without diagnostics: the event recording of tool_ltidiagnostics is switched off on B. A learner launches from A;
 *     tool_ltigroupautoenrol adds the group membership on its own (it has no dependency on the diagnostics); the
 *     diagnostics record nothing.
 *  2. With diagnostics: recording on, a second learner launches; the membership is added and the diagnostics show the
 *     group assignment as booked (observed in the launch request; with tool_ltigroupautoenrol >= 1.2.1 also its own
 *     event group_assignment_completed).
 *
 * Own scenario "dgg" (scenario fixture of local_lticompletionsync); the mapping and the recording switch are restored
 * in afterAll. "Without" means: diagnostics installed but recording nothing. Uninstalling a plugin on the shared stack
 * is not possible; groupautoenrol's own suite runs without tool_ltidiagnostics (docs/TESTING.md). Runs under
 * /tmp/e2e_stack.lock.
 */

const { test, expect } = require('@playwright/test');
const lib = require('./lib');

const seed = lib.loadSeed();
const csync = lib.csyncLib();
let sc;
let toolKey;
let groupid;
let recordevents;
const start = lib.nowSeconds() - 5;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  sc = csync.buildScenario(seed, { platform: 'a', tool: 'b', scenario: 'dgg', learner: 'dggstudent1,dggstudent2' });
  toolKey = `tool:${sc.t.regid}:${sc.t.deploymentrow}`;
  const map = lib.fixture('b', { stage: 'gamap', courseid: sc.t.courseid, toolid: sc.t.toolid });
  expect(map.installed, 'tool_ltigroupautoenrol must be installed on site B').toBe(true);
  groupid = map.groupid;
  recordevents = lib.getConfig('b', 'recordevents');
});

test.afterAll(() => {
  lib.setConfig('b', 'recordevents', recordevents === null ? '1' : recordevents);
  lib.fixture('b', { stage: 'gaunmap', courseid: sc.t.courseid });
});

test('without diagnostics recording the group is assigned by tool_ltigroupautoenrol alone', async ({ page }) => {
  lib.setConfig('b', 'recordevents', '0');
  const learner = sc.p2.learners[0];
  await csync.launchAsLearner(page, seed, { ...sc, learner }, 'a', 'b');
  expect(lib.fixture('b', { stage: 'members', groupid }).count).toBe(1);
  expect(lib.fixture('b', { stage: 'obs', key: 'any', type: 'group', from: start }).count).toBe(0);
  expect(lib.fixture('b', { stage: 'obs', key: 'any', type: 'groupassign', from: start }).count).toBe(0);
});

test('with diagnostics the assignment is shown as booked', async ({ page }) => {
  lib.setConfig('b', 'recordevents', '1');
  const learner = sc.p2.learners[1];
  await page.context().clearCookies();
  await csync.launchAsLearner(page, seed, { ...sc, learner }, 'a', 'b');
  expect(lib.fixture('b', { stage: 'members', groupid }).count).toBe(2);
  await page.context().clearCookies();
  await lib.login(page, seed.urls.b, seed.admins.b);
  await page.goto(lib.connectionUrl(seed.urls.b, toolKey, 'messages'));
  const row = page.locator('#tool_ltidiagnostics_messages tr[data-message="group_assignment"]');
  await expect(row).toHaveAttribute('data-state', 'booked');
  await page.goto(lib.connectionUrl(seed.urls.b, toolKey, 'health'));
  await expect(page.locator('[data-testid="moodle_core.ga_assigned.groups"]')).toHaveCount(1);
  await expect(page.locator('[data-testid="moodle_core.ga_failures.groups"]')).toHaveCount(0);
});
