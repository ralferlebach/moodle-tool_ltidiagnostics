<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Deprecation and skip report of a PHPUnit run of this plugin (audit item DIA-01): nothing may be hidden.
 *
 * Reads the text output of PHPUnit and classifies every reported issue:
 *  - PHP deprecations (PHPUnit 11 with --display-deprecations or --display-all-issues: "triggered by" blocks with file
 *    and line; PHPUnit 9 on Moodle 4.5: "Deprecated:" lines, which appear only as printed output and make the test
 *    risky) by source: plugin code, plugin tests, Moodle core, vendor;
 *  - the failure messages of the plugin's deprecation guard (tests/classes/deprecation_guard.php), which carry the full
 *    stack trace;
 *  - PHPUnit deprecations (runner level, --display-phpunit-deprecations): the docblock metadata ("covers",
 *    "dataProvider") that the owner's PHPUnit 9 style requires; counted and listed per test class;
 *  - skipped, incomplete and risky tests.
 *
 * Exit code 0 only when there is no PHP deprecation, no guard failure, no skipped, incomplete or risky test. PHPUnit
 * deprecations of the docblock metadata are reported, not counted as failures (accepted by the owner, documented).
 *
 * Usage: php tools/deprecation_report.php <phpunit output file> [--json]
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- standalone CLI tool without Moodle.

if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_readable($argv[1])) {
    fwrite(STDERR, "Usage: php tools/deprecation_report.php <phpunit output file> [--json]\n");
    exit(2);
}
$text = (string) file_get_contents($argv[1]);
$lines = preg_split('/\R/', $text);

/**
 * Source class of a file path.
 *
 * @param string $file Path.
 * @return string
 */
function tool_ltidiagnostics_deprecation_source(string $file): string {
    if (preg_match('#admin/tool/ltidiagnostics/tests/#', $file)) {
        return 'plugin_tests';
    }
    if (preg_match('#admin/tool/ltidiagnostics/#', $file)) {
        return 'plugin_code';
    }
    if (strpos($file, '/vendor/') !== false) {
        return 'vendor';
    }
    return 'moodle_core';
}

$report = ['php_deprecations' => [], 'guard_failures' => 0, 'phpunit_deprecations' => ['total' => 0, 'kinds' => []],
    'skipped' => 0, 'incomplete' => 0, 'risky' => 0, 'tests' => 0];
foreach ($lines as $i => $line) {
    // PHP deprecations printed by PHP itself (PHPUnit 9) or listed by PHPUnit 11.
    if (preg_match('/^(?:PHP )?Deprecated:\s+(.*) in (\S+) on line (\d+)/', $line, $m)) {
        $report['php_deprecations'][] = ['message' => $m[1], 'file' => $m[2], 'line' => (int) $m[3],
            'source' => tool_ltidiagnostics_deprecation_source($m[2])];
    }
    if (
        preg_match('/^\d+\) (\S+\.php):(\d+)$/', $line, $m) && isset($lines[$i - 1])
            && preg_match('/deprecat/i', implode(' ', array_slice($lines, max(0, $i - 6), 6)))
    ) {
        $report['php_deprecations'][] = ['message' => trim((string) ($lines[$i + 1] ?? '')), 'file' => $m[1],
            'line' => (int) $m[2], 'source' => tool_ltidiagnostics_deprecation_source($m[1])];
    }
    if (strpos($line, 'deprecation guard, DIA-01') !== false) {
        $report['guard_failures']++;
    }
    if (
        preg_match(
            '/Metadata found in doc-comment for (?:class|method) ([^ ]+)\.? Metadata in doc-comments is deprecated/',
            $line,
            $m
        )
    ) {
        $report['phpunit_deprecations']['kinds']['docblock_metadata'][] = $m[1];
    }
    if (preg_match('/^(?:OK, but .*|Tests: (\d+).*)$/', $line)) {
        if (preg_match('/Tests: (\d+)/', $line, $m)) {
            $report['tests'] = (int) $m[1];
        }
    }
    // PHPUnit 9 summary of a clean run: "OK (602 tests, 943747 assertions)".
    if (preg_match('/^OK \((\d+) tests?, /', $line, $m)) {
        $report['tests'] = (int) $m[1];
    }
    if (preg_match('/^(?:OK, but .*|Tests: (\d+).*)$/', $line)) {
        foreach (['Skipped' => 'skipped', 'Incomplete' => 'incomplete', 'Risky' => 'risky'] as $label => $key) {
            if (preg_match('/' . $label . ': (\d+)/', $line, $mm)) {
                $report[$key] = (int) $mm[1];
            }
        }
        if (preg_match('/PHPUnit Deprecations: (\d+)/', $line, $mm)) {
            $report['phpunit_deprecations']['total'] = (int) $mm[1];
        }
    }
}
foreach ($report['phpunit_deprecations']['kinds'] as $kind => $list) {
    $report['phpunit_deprecations']['kinds'][$kind] = array_count_values($list);
}
$bad = count($report['php_deprecations']) + $report['guard_failures'] + $report['skipped'] + $report['incomplete']
    + $report['risky'];
if (in_array('--json', $argv, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "Tests: {$report['tests']}\n";
    echo 'PHP deprecations: ' . count($report['php_deprecations']) . "\n";
    foreach ($report['php_deprecations'] as $d) {
        echo "  [{$d['source']}] {$d['file']}:{$d['line']} {$d['message']}\n";
    }
    echo "Deprecation guard failures (with stack trace in the PHPUnit output): {$report['guard_failures']}\n";
    echo "Skipped: {$report['skipped']}, incomplete: {$report['incomplete']}, risky: {$report['risky']}\n";
    echo "PHPUnit deprecations (runner level): {$report['phpunit_deprecations']['total']}\n";
    foreach ($report['phpunit_deprecations']['kinds'] as $kind => $where) {
        echo "  $kind: " . array_sum($where) . ' in ' . count($where) . " test classes/methods (owner rule: PHPUnit 9 docblocks)\n";
    }
}
exit($bad > 0 ? 1 : 0);
