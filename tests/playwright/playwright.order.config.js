/**
 * Order-independence check for the tool_ltidiagnostics browser suite.
 *
 * Runs the spec files in the reverse of the default (alphabetical) order and repeats the main spec after the
 * accessibility spec: main -> accessibility -> main. Project dependencies force the order. Every run must be green;
 * a red third project means the accessibility spec leaked state (for example a stored health check probe).
 *
 * @module     tool_ltidiagnostics/playwright.order.config
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const base = require('./playwright.config');

module.exports = {
  ...base,
  testDir: __dirname,
  projects: [
    { name: '1-main', testMatch: /ltidiagnostics\.spec\.js/ },
    { name: '2-accessibility', testMatch: /accessibility\.spec\.js/, dependencies: ['1-main'] },
    { name: '3-main-again', testMatch: /ltidiagnostics\.spec\.js/, dependencies: ['2-accessibility'] },
  ],
};
