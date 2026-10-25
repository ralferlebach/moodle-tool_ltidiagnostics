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
 * Helpers for the tool_ltidiagnostics browser suite.
 *
 * The suite runs against the two-site stack: site A (LTI platform) launches into site B (LTI tool). The plugin is
 * installed on both. Everything site specific comes from the JSON seed named by LTI_SEED.
 */

const e2e = require(process.env.E2E_LIB || '/home/claude/e2e/lib/e2e');

/**
 * Read the seed file (LTI_SEED, default /home/claude/e2e/state/seed.json).
 *
 * @returns {object} Parsed seed.
 */
function loadSeed() {
  return e2e.loadSeed();
}

/**
 * Find a seed user by key.
 *
 * @param {object} seed The seed.
 * @param {string} key User key, for example studentA1.
 * @returns {object} The user.
 */
function getUser(seed, key) {
  return e2e.getUser(seed, key);
}

/**
 * Log in through the Moodle login form (the stack library retries because a late-initialising password component
 * can reset the field).
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} baseUrl Site URL without trailing slash.
 * @param {{username: string, password: string}} cred Credentials.
 * @returns {Promise<void>}
 */
async function login(page, baseUrl, cred) {
  const seed = e2e.loadSeed();
  const site = baseUrl === seed.urls.a ? 'a' : 'b';
  await e2e.login(page, site, cred, seed);
}

/**
 * URL of a connection page of the plugin.
 *
 * @param {string} baseUrl Site URL.
 * @param {string} key Connection key, for example platform:12 or tool:11:11.
 * @param {string} tab Tab name.
 * @returns {string} URL.
 */
function connectionUrl(baseUrl, key, tab) {
  return `${baseUrl}/admin/tool/ltidiagnostics/connection.php?key=${encodeURIComponent(key)}&tab=${tab}`;
}

module.exports = { loadSeed, getUser, login, connectionUrl };
