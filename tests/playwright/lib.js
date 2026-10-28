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

/**
 * Database prefix of the stack sites (both use the same one).
 *
 * @returns {string} Prefix, default mdl_.
 */
function dbPrefix() {
  const prefix = process.env.LTI_DB_PREFIX || 'mdl_';
  if (!/^[a-z0-9_]+$/.test(prefix)) {
    throw new Error(`Invalid LTI_DB_PREFIX: ${prefix}`);
  }
  return prefix;
}

/**
 * Run SQL on one stack site.
 *
 * @param {string} site a or b.
 * @param {string} statement Statement.
 * @returns {string} psql output.
 */
function sql(site, statement) {
  return site === 'a' ? e2e.sqlA(statement) : e2e.sqlB(statement);
}

/**
 * Remove the plugin state of one connection that a spec could otherwise inherit from another spec.
 *
 * Stored active check results (snapshot kind "probe") feed the matrix: a failed probe stored by one spec turns
 * AGS into "blocked" for the next one. Recorded observations decide "observed"/"verified". Each spec calls this
 * for the connections it asserts on, so the result does not depend on the order in which spec files run.
 *
 * @param {string} site a or b.
 * @param {string} key Connection key, for example platform:12.
 * @param {{probes?: boolean, observations?: boolean, since?: number}} what What to remove (default probes only);
 *        since (unix seconds) restricts the removal to rows created at or after that time.
 * @returns {void}
 */
function resetConnectionState(site, key, what = {}) {
  if (!/^[a-z]+(:[0-9]+)+$/.test(key)) {
    throw new Error(`Invalid connection key: ${key}`);
  }
  const p = dbPrefix();
  const since = Number.isInteger(what.since) ? ` AND timecreated >= ${what.since}` : '';
  if (what.probes !== false) {
    sql(site, `DELETE FROM ${p}tool_ltidiagnostics_snap WHERE connkey = '${key}' AND kind = 'probe'${since}`);
  }
  if (what.observations) {
    sql(site, `DELETE FROM ${p}tool_ltidiagnostics_obs WHERE connkey = '${key}'${since}`);
  }
}

/**
 * Count the stored active check results of a connection.
 *
 * @param {string} site a or b.
 * @param {string} key Connection key.
 * @returns {number} Number of probe snapshots.
 */
function countProbes(site, key) {
  if (!/^[a-z]+(:[0-9]+)+$/.test(key)) {
    throw new Error(`Invalid connection key: ${key}`);
  }
  return parseInt(sql(site, `SELECT COUNT(*) FROM ${dbPrefix()}tool_ltidiagnostics_snap WHERE connkey = '${key}' AND kind = 'probe'`),
    10);
}

/**
 * Current time on the test host in unix seconds (the sites run on the same host).
 *
 * @returns {number} Seconds.
 */
function nowSeconds() {
  return Math.floor(Date.now() / 1000);
}

/**
 * Run a command line script of this plugin on a stack site (as the site administrator, see cli/*.php).
 *
 * @param {string} site a or b.
 * @param {string} script Script name in admin/tool/ltidiagnostics/cli, for example partner.php.
 * @param {string[]} args Arguments.
 * @returns {string} Standard output.
 */
function pluginCli(site, script, args) {
  const { execFileSync } = require('child_process');
  if (!/^[a-z]+\.php$/.test(script)) {
    throw new Error(`Invalid script: ${script}`);
  }
  const home = process.env.E2E_HOME || '/home/claude/e2e';
  // Moodle 5.1 and later serve from public/; the 4.5 tree has no public directory.
  const rel = site === 'b' ? `public/admin/tool/ltidiagnostics/cli/${script}` : `admin/tool/ltidiagnostics/cli/${script}`;
  return execFileSync('bash', ['-c', `. ${home}/bin/e2e-env.sh; moodle_cli ${site} "$@"`, 'cli', rel, ...args],
    { encoding: 'utf8', timeout: 240000, maxBuffer: 32 * 1024 * 1024 });
}

/**
 * Set a setting of this plugin on a stack site through Moodle's cfg.php (purges the cache).
 *
 * @param {string} site a or b.
 * @param {string} name Setting name.
 * @param {string} value Value.
 * @returns {void}
 */
function setConfig(site, name, value) {
  const { execFileSync } = require('child_process');
  if (!/^[a-z]+$/.test(name)) {
    throw new Error(`Invalid setting: ${name}`);
  }
  const home = process.env.E2E_HOME || '/home/claude/e2e';
  execFileSync('bash', ['-c', `. ${home}/bin/e2e-env.sh; moodle_cli ${site} admin/cli/cfg.php --component=tool_ltidiagnostics`
    + ` --name=${name} --set="$1"`, 'cfg', String(value)], { encoding: 'utf8', timeout: 120000 });
}

/**
 * Current value of a setting of this plugin (null when unset).
 *
 * @param {string} site a or b.
 * @param {string} name Setting name.
 * @returns {string|null} Value.
 */
function getConfig(site, name) {
  if (!/^[a-z]+$/.test(name)) {
    throw new Error(`Invalid setting: ${name}`);
  }
  const out = sql(site, `SELECT value FROM ${dbPrefix()}config_plugins WHERE plugin = 'tool_ltidiagnostics' AND name = '${name}'`);
  return out === '' ? null : out;
}

/**
 * Fingerprint of the LTI configuration of a site (types, type settings, registrations, deployments): a diagnostic
 * action must never change it.
 *
 * @param {string} site a or b.
 * @returns {string} Fingerprint.
 */
function ltiConfigFingerprint(site) {
  const p = dbPrefix();
  return sql(site, `SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
      SELECT 't' || id || ':' || coalesce(clientid, '') || ':' || state || ':' || baseurl AS x FROM ${p}lti_types
      UNION ALL SELECT 'c' || typeid || ':' || name || ':' || coalesce(value, '') FROM ${p}lti_types_config
      UNION ALL SELECT 'r' || id || ':' || platformid || ':' || clientid || ':' || status FROM ${p}enrol_lti_app_registration
      UNION ALL SELECT 'd' || id || ':' || deploymentid || ':' || platformid FROM ${p}enrol_lti_deployment) q`);
}

/**
 * Write the lines of the shared stack web server log since a time whose path belongs to one site into a file.
 * The stack has one access log for both virtual hosts; a real site has its own, so the spec keeps only the scripts of
 * the site it imports into.
 *
 * @param {number} since Unix seconds.
 * @param {RegExp} paths Paths to keep.
 * @param {string} file Target file.
 * @returns {number} Number of lines written.
 */
function logSlice(since, paths, file) {
  const fs = require('fs');
  const log = process.env.E2E_ACCESS_LOG || '/home/claude/e2e/log/nginx-access.log';
  const size = fs.statSync(log).size;
  const fd = fs.openSync(log, 'r');
  const length = Math.min(size, 8 * 1024 * 1024);
  const buffer = Buffer.alloc(length);
  fs.readSync(fd, buffer, 0, length, size - length);
  fs.closeSync(fd);
  const months = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };
  const keep = buffer.toString('utf8').split('\n').filter((line) => {
    const m = line.match(/\[(\d{2})\/(\w{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2}) ([+-])(\d{2})(\d{2})\] "\w+ (\S+)/);
    if (!m) {
      return false;
    }
    const offset = (m[7] === '-' ? -1 : 1) * (Number(m[8]) * 3600 + Number(m[9]) * 60);
    const t = Date.UTC(Number(m[3]), months[m[2]], Number(m[1]), Number(m[4]), Number(m[5]), Number(m[6])) / 1000 - offset;
    return t >= since && paths.test(m[10]);
  });
  fs.writeFileSync(file, keep.join('\n') + '\n');
  return keep.length;
}

/**
 * Run a stage of the E2E fixture of this suite (fixtures/diag_fixture.php) on a site.
 *
 * @param {string} site a or b.
 * @param {object} args Stage and options.
 * @returns {object} Parsed JSON output.
 */
function fixture(site, args) {
  const { execFileSync } = require('child_process');
  const path = require('path');
  const home = process.env.E2E_HOME || '/home/claude/e2e';
  const roots = { a: process.env.A_ROOT || '/home/claude/moodle', b: process.env.B_ROOT || '/home/claude/moodle53' };
  const opts = Object.entries(args).map(([k, v]) => `--${k}=${v}`);
  const out = execFileSync('bash', ['-c', `. ${home}/bin/e2e-env.sh; moodle_cli ${site} "$@"`, 'fx',
    path.join(__dirname, 'fixtures', 'diag_fixture.php'), `--root=${roots[site]}`, ...opts], { encoding: 'utf8', timeout: 120000 });
  const lines = out.trim().split('\n');
  return JSON.parse(lines[lines.length - 1]);
}

/**
 * The scenario builder of local_lticompletionsync's browser suite (a family test fixture: own courses, learners, LTI 1.3
 * type and registration per scenario name, nothing of the stack's own data is changed). CSYNC_LIB overrides the path.
 *
 * @returns {object} The module (buildScenario, launchAsLearner, csync, runDispatchTasks).
 */
function csyncLib() {
  return require(process.env.CSYNC_LIB || '/home/claude/work/moodle-local_lticompletionsync/tests/playwright/lib.js');
}

/**
 * Import a web server log file through the log import page of this plugin (allow-listed path mode: the directory of
 * the file is put on the allow-list of the site first; the caller restores the setting).
 *
 * @param {import('@playwright/test').Page} page Logged-in page.
 * @param {string} baseUrl Site URL.
 * @param {string} file Absolute path of the log file.
 * @returns {Promise<string>} Text of the import result.
 */
async function importLog(page, baseUrl, file) {
  await page.goto(`${baseUrl}/admin/tool/ltidiagnostics/logimport.php`);
  await page.locator('#id_format').selectOption('access:combined');
  await page.locator('#id_path').selectOption(file);
  await page.locator('#id_submitbutton').click();
  await page.waitForLoadState('load');
  return page.locator('#region-main').innerText();
}

module.exports = { fixture, csyncLib, importLog, loadSeed, getUser, login, connectionUrl, resetConnectionState, countProbes, nowSeconds, pluginCli, setConfig,
  getConfig, ltiConfigFingerprint, logSlice, sql };
