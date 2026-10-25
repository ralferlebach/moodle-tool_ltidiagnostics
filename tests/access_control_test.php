<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\local\diagnostic_mode;
use tool_ltidiagnostics\local\profile_manager;
use tool_ltidiagnostics\local\support;

/**
 * Access control: capabilities, roles, pages and the guarded service classes.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\diagnostic_mode
 * @covers     \tool_ltidiagnostics\local\profile_manager
 * @covers     \tool_ltidiagnostics\local\support
 */
final class access_control_test extends \advanced_testcase {
    /** @var string[] All capabilities of the plugin. */
    private const CAPS = ['view', 'viewcourse', 'viewsupport', 'viewprivacy', 'configure', 'runchecks', 'importlogs', 'export'];

    /**
     * Create a user that holds the given capabilities in the system context only.
     *
     * @param string[] $caps Capability short names.
     * @return \stdClass
     */
    private function user_with(array $caps): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($caps as $cap) {
            assign_capability('tool/ltidiagnostics:' . $cap, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    /**
     * All capabilities exist, have a risk where they expose personal data or change configuration, and no archetype
     * except the course summary has a default.
     */
    public function test_capability_definitions(): void {
        global $DB;
        $this->resetAfterTest();
        $caps = $DB->get_records_select(
            'capabilities',
            "name LIKE 'tool/ltidiagnostics:%'",
            [],
            'name',
            'name, captype, contextlevel, riskbitmask'
        );
        $this->assertCount(count(self::CAPS), $caps);
        $expected = [
            'view' => RISK_PERSONAL | RISK_CONFIG, 'viewprivacy' => RISK_PERSONAL, 'configure' => RISK_CONFIG,
            'runchecks' => RISK_CONFIG, 'importlogs' => RISK_PERSONAL | RISK_CONFIG, 'export' => RISK_PERSONAL,
            'viewcourse' => 0, 'viewsupport' => 0,
        ];
        foreach ($expected as $name => $risk) {
            $this->assertSame($risk, (int) $caps["tool/ltidiagnostics:$name"]->riskbitmask, $name);
        }
        $this->assertSame((int) CONTEXT_COURSE, (int) $caps['tool/ltidiagnostics:viewcourse']->contextlevel);
        foreach (['configure', 'runchecks', 'importlogs'] as $writer) {
            $this->assertSame('write', $caps["tool/ltidiagnostics:$writer"]->captype, $writer);
        }
        // Defaults per archetype.
        foreach (self::CAPS as $cap) {
            $archetypes = $DB->get_fieldset_select(
                'role_capabilities',
                'roleid',
                "capability = ? AND contextid = ? AND permission = ?",
                ["tool/ltidiagnostics:$cap", \context_system::instance()->id, CAP_ALLOW]
            );
            $names = [];
            foreach ($archetypes as $roleid) {
                $names[] = $DB->get_field('role', 'archetype', ['id' => $roleid]);
            }
            if ($cap === 'viewcourse') {
                $this->assertEqualsCanonicalizing(['editingteacher', 'manager'], $names);
            } else {
                $this->assertSame([], $names, "$cap must not be granted to any role by default.");
            }
        }
    }

    /**
     * Site administrators hold everything; other standard users hold nothing at system level.
     */
    public function test_default_users(): void {
        $this->resetAfterTest();
        $system = \context_system::instance();
        $this->setAdminUser();
        foreach (self::CAPS as $cap) {
            $this->assertTrue(has_capability("tool/ltidiagnostics:$cap", $system), $cap);
        }
        $course = $this->getDataGenerator()->create_course();
        foreach (['student', 'teacher', 'editingteacher', 'manager', 'coursecreator'] as $role) {
            $user = $this->getDataGenerator()->create_and_enrol($course, $role);
            $this->setUser($user);
            foreach (['view', 'viewsupport', 'viewprivacy', 'configure', 'runchecks', 'importlogs', 'export'] as $cap) {
                $this->assertFalse(has_capability("tool/ltidiagnostics:$cap", $system), "$role $cap");
                $coursecontext = \context_course::instance($course->id);
                $this->assertFalse(has_capability("tool/ltidiagnostics:$cap", $coursecontext), "$role $cap");
            }
        }
        $this->setUser(0);
        $this->assertFalse(has_capability('tool/ltidiagnostics:view', $system));
    }

    /**
     * The course summary is for editing teachers of that course only.
     */
    public function test_course_summary_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $cap = 'tool/ltidiagnostics:viewcourse';
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $nonediting = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertTrue(has_capability($cap, \context_course::instance($course->id), $teacher));
        $this->assertFalse(has_capability($cap, \context_course::instance($other->id), $teacher));
        $this->assertFalse(has_capability($cap, \context_course::instance($course->id), $nonediting));
        $this->assertFalse(has_capability($cap, \context_course::instance($course->id), $student));
        $this->assertFalse(has_capability('tool/ltidiagnostics:view', \context_course::instance($course->id), $teacher));
    }

    /**
     * The diagnostic mode, profile management and log access are guarded in the service classes themselves.
     */
    public function test_services_require_capabilities(): void {
        $this->resetAfterTest();
        $viewer = $this->user_with(['view']);
        $this->setUser($viewer);
        try {
            diagnostic_mode::enable(10);
            $this->fail('enable() must require the configure capability');
        } catch (\required_capability_exception $e) {
            $this->assertFalse(diagnostic_mode::is_active());
        }
        try {
            diagnostic_mode::disable();
            $this->fail('disable() must require the configure capability');
        } catch (\required_capability_exception $e) {
            $this->assertTrue(true);
        }
        try {
            profile_manager::save('platform:1', '{"schema":1,"name":"x"}');
            $this->fail('save() must require the configure capability');
        } catch (\required_capability_exception $e) {
            $this->assertNull(profile_manager::get('platform:1'));
        }
        $this->expectException(\required_capability_exception::class);
        profile_manager::delete('platform:1');
    }

    /**
     * Users with the support capability see the summary only; the summary leaks no connection detail.
     */
    public function test_support_summary_is_pseudonymised(): void {
        $this->resetAfterTest();
        $key = 'tool:12:34';
        $id = support::diagnostic_id($key);
        $this->assertMatchesRegularExpression('/^LTD-[0-9A-F]{10}$/', $id);
        $this->assertSame($id, support::diagnostic_id($key));
        $this->assertNotSame($id, support::diagnostic_id('tool:12:35'));
        $this->assertNotSame($id, (function () use ($key) {
            set_config('siteidentifier', 'something-else');
            return support::diagnostic_id($key);
        })(), 'The id depends on the site.');

        $finding = new local\finding('cfg_state', 'configuration', 'blocking', 'admin', ['a' => 'SecretStateText']);
        $ok = new local\finding('cfg_ok', 'configuration', 'ok', 'admin');
        $layers = ['configuration' => ['light' => 'red', 'findings' => [$finding]]];
        $summary = support::summary($key, ['layers' => $layers, 'findings' => [$finding, $ok], 'checkedat' => 1000]);
        $this->assertSame(['diagnosticid', 'role', 'layers', 'findings', 'checkedat'], array_keys($summary));
        $this->assertSame('tool', $summary['role']);
        $this->assertCount(1, $summary['findings'], 'Passed checks are not listed.');
        $this->assertSame(['code', 'layer', 'severity'], array_keys($summary['findings'][0]));
        $this->assertStringNotContainsString('SecretStateText', json_encode($summary));
        $this->assertStringNotContainsString($key, json_encode($summary));
    }

    /**
     * The diagnostic mode can reveal only to those who may view, and only while it is active.
     */
    public function test_reveal_needs_mode_and_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertFalse(diagnostic_mode::can_reveal());
        diagnostic_mode::enable(5);
        $this->assertTrue(diagnostic_mode::can_reveal());
        $nobody = $this->user_with(['viewsupport']);
        $this->setUser($nobody);
        $this->assertFalse(diagnostic_mode::can_reveal(), 'The mode is global, the right to reveal is not.');
        $viewer = $this->user_with(['view']);
        $this->setUser($viewer);
        $this->assertTrue(diagnostic_mode::can_reveal());
    }

    /**
     * Every script of the plugin is guarded; state changing scripts check the session key.
     *
     * This is a contract test over the source files, complementing the Behat and Playwright runs.
     *
     * @dataProvider page_provider
     * @param string $file Script name.
     * @param string $capability Required capability.
     * @param bool $changesstate Whether the script changes state through a plain request.
     */
    public function test_pages_are_guarded(string $file, string $capability, bool $changesstate): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/admin/tool/ltidiagnostics/' . $file);
        $this->assertNotFalse($source);
        $this->assertStringContainsString("tool/ltidiagnostics:$capability", $source);
        $this->assertMatchesRegularExpression('/require_login\(|admin_externalpage_setup\(/', $source);
        $this->assertMatchesRegularExpression('/require_capability\(/', $source);
        if ($changesstate) {
            $this->assertStringContainsString('require_sesskey()', $source);
        }
        $this->assertDoesNotMatchRegularExpression('/\$_(GET|POST|REQUEST)\b/', $source, 'Use required_param/optional_param.');
        $this->assertDoesNotMatchRegularExpression('/\b(eval|exec|shell_exec|system|passthru|unserialize)\s*\(/', $source);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function page_provider(): array {
        return [
            'index' => ['index.php', 'view', false],
            'connection' => ['connection.php', 'view', false],
            'mode' => ['mode.php', 'configure', true],
            'export' => ['export.php', 'export', true],
            'profile' => ['profile.php', 'configure', false],
            'logimport' => ['logimport.php', 'importlogs', false],
            'support' => ['support.php', 'viewsupport', false],
            'course' => ['course.php', 'viewcourse', false],
            'audit' => ['audit.php', 'viewprivacy', false],
        ];
    }

    /**
     * The mode switch is a POST-only action with a fixed set of values.
     */
    public function test_mode_script_accepts_only_known_actions(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/admin/tool/ltidiagnostics/mode.php');
        $this->assertStringContainsString("required_param('action', PARAM_ALPHA)", $source);
        $this->assertStringContainsString("optional_param('minutes', 60, PARAM_INT)", $source);
    }
}
