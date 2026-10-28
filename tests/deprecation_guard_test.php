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

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\tests\deprecation_guard;

/**
 * Self test of the deprecation guard (audit item DIA-01): deprecations are recorded with source and stack trace,
 * handed on to the runner, and nothing is swallowed or left behind.
 *
 * The guard frames opened here run in "capture" mode, so the deliberately triggered deprecations never reach the test
 * runner and the run stays green; the normal mode is what every other test of this plugin runs in.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\tests\deprecation_guard
 * @covers     \tool_ltidiagnostics\tests\deprecation_guarded
 */
final class deprecation_guard_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * Helper that raises a user deprecation one call deeper (the trace must show both frames).
     */
    private function raise_user_deprecation(): void {
        trigger_error('diagnostics guard probe', E_USER_DEPRECATED);
    }

    /**
     * A user deprecation is recorded with type, message, location, source and the calling frames.
     */
    public function test_user_deprecation_is_recorded_with_trace(): void {
        $depth = deprecation_guard::depth();
        deprecation_guard::start(false);
        $this->raise_user_deprecation();
        $records = deprecation_guard::stop();
        $this->assertSame($depth, deprecation_guard::depth());
        $this->assertCount(1, $records);
        $r = $records[0];
        $this->assertSame('E_USER_DEPRECATED', $r['type']);
        $this->assertSame('diagnostics guard probe', $r['message']);
        $this->assertSame(__FILE__, $r['file']);
        $this->assertSame(deprecation_guard::SRC_TESTS, $r['source']);
        $trace = implode("\n", $r['trace']);
        $this->assertStringContainsString('raise_user_deprecation', $trace);
        $this->assertStringContainsString('test_user_deprecation_is_recorded_with_trace', $trace);
        $this->assertStringContainsString('admin/tool/ltidiagnostics/tests/deprecation_guard_test.php:', $trace);
        $report = deprecation_guard::format($records);
        $this->assertStringContainsString('[plugin_tests] E_USER_DEPRECATED: diagnostics guard probe', $report);
        $this->assertStringContainsString('raise_user_deprecation', $report);
    }

    /**
     * A deprecation raised by PHP itself (null to a non-nullable internal parameter, deprecated since PHP 8.1) is
     * recorded as well.
     */
    public function test_native_deprecation_is_recorded(): void {
        $value = null;
        deprecation_guard::start(false);
        $length = strlen($value);
        $records = deprecation_guard::stop();
        $this->assertSame(0, $length);
        $this->assertCount(1, $records);
        $this->assertSame('E_DEPRECATED', $records[0]['type']);
        $this->assertStringContainsString('strlen()', $records[0]['message']);
        $this->assertSame(deprecation_guard::SRC_TESTS, $records[0]['source']);
    }

    /**
     * The str_getcsv() deprecation of the main CI run (PHP 8.4, missing $escape) is attributed to its source; the form
     * used by this plugin since 0.2.1 raises nothing on any PHP version.
     */
    public function test_str_getcsv_escape_deprecation(): void {
        deprecation_guard::start(false);
        $old = str_getcsv('a,"b c",d');
        $records = deprecation_guard::stop();
        $this->assertSame(['a', 'b c', 'd'], $old);
        if (PHP_VERSION_ID >= 80400) {
            $this->assertCount(1, $records);
            $this->assertStringContainsString('$escape', $records[0]['message']);
            $this->assertSame(deprecation_guard::SRC_TESTS, $records[0]['source']);
        } else {
            $this->assertSame([], $records);
        }
        deprecation_guard::start(false);
        $fixed = str_getcsv('a,"b c",d', ',', '"', '\\');
        $this->assertSame([], deprecation_guard::stop());
        $this->assertSame(['a', 'b c', 'd'], $fixed);
    }

    /**
     * In the normal mode the message is handed on to the handler below (the runner keeps counting it), and other
     * errors are handed on untouched.
     */
    public function test_messages_are_handed_on(): void {
        $seen = [];
        set_error_handler(function (int $errno, string $errstr) use (&$seen) {
            $seen[] = [$errno, $errstr];
            return true;
        });
        try {
            deprecation_guard::start(true);
            trigger_error('handed on', E_USER_DEPRECATED);
            trigger_error('a notice', E_USER_NOTICE);
            $records = deprecation_guard::stop();
        } finally {
            restore_error_handler();
        }
        $this->assertCount(1, $records);
        $this->assertSame([[E_USER_DEPRECATED, 'handed on'], [E_USER_NOTICE, 'a notice']], $seen);
    }

    /**
     * Frames nest; stopping without an open frame is harmless.
     */
    public function test_frames_nest(): void {
        $depth = deprecation_guard::depth();
        deprecation_guard::start(false);
        deprecation_guard::start(false);
        trigger_error('inner', E_USER_DEPRECATED);
        $inner = deprecation_guard::stop();
        trigger_error('outer', E_USER_DEPRECATED);
        $outer = deprecation_guard::stop();
        $this->assertSame(['inner'], array_column($inner, 'message'));
        $this->assertSame(['outer'], array_column($outer, 'message'));
        $this->assertSame($depth, deprecation_guard::depth());
    }

    /**
     * Source classification.
     *
     * @dataProvider classify_provider
     * @param string $file File that raised the message.
     * @param string[] $trace Stack.
     * @param string $expected Source class.
     */
    public function test_classify(string $file, array $trace, string $expected): void {
        $this->assertSame($expected, deprecation_guard::classify($file, $trace));
    }

    /**
     * Cases for the classification.
     *
     * @return array[]
     */
    public static function classify_provider(): array {
        return [
            'plugin class' => ['/srv/moodle/admin/tool/ltidiagnostics/classes/local/report.php', [], 'plugin_code'],
            'plugin test' => ['/srv/moodle/admin/tool/ltidiagnostics/tests/local/reporting_test.php', [], 'plugin_tests'],
            'core lib' => ['/srv/moodle/public/lib/weblib.php', [], 'moodle_core'],
            'vendor alone' => ['/srv/moodle/vendor/guzzle/src/Client.php', ['/srv/moodle/vendor/x.php:1 f'], 'vendor'],
            'vendor for plugin' => ['/srv/moodle/vendor/guzzle/src/Client.php',
                ['/srv/moodle/vendor/a.php:3 f', '/srv/moodle/admin/tool/ltidiagnostics/classes/local/x.php:9 g'], 'plugin_code'],
            'vendor for core' => ['/srv/moodle/vendor/guzzle/src/Client.php', ['/srv/moodle/lib/filelib.php:3 f'], 'moodle_core'],
            'eval' => ['/srv/moodle/x.php(12) : eval()\'d code', [], 'other'],
        ];
    }
}
