/**
 * Playwright configuration of the grade and completion channel suites (issues #3, #5 to #9) and the UX suite
 * (#10 to #28): Moodle 4.5 tool on http://localhost,
 * Moodle 5.2 platform on http://127.0.0.1:8052, set up by stack/grades_stack.php (see stack/README.md).
 *
 * @module     tool_ltidiagnostics/playwright.channels.config
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { defineConfig } = require('@playwright/test');

// The sandbox exports https_proxy; the two loopback sites must not go through it.
process.env.NO_PROXY = ['localhost', '127.0.0.1', process.env.NO_PROXY || ''].filter(Boolean).join(',');
process.env.no_proxy = process.env.NO_PROXY;

module.exports = defineConfig({
  testDir: '.',
  testMatch: /(grades|completion|ux)_two_site\.spec\.js/,
  timeout: 240000,
  expect: { timeout: 20000 },
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  outputDir: process.env.PW_OUTPUT_DIR || './test-results',
  use: {
    launchOptions: {
      executablePath: process.env.CHROME_PATH || undefined,
      args: ['--no-proxy-server'],
    },
    viewport: { width: 1280, height: 900 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
