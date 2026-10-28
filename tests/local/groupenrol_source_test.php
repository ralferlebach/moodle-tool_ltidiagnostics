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

namespace tool_ltidiagnostics\local;

use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/lti_data.php');

/**
 * Optional read-only adapter for tool_ltigroupautoenrol (audit item GA-03): absent plugin, an old table without the
 * failure columns, the 1.2.x table with failures, and the observation of group assignments in the launch request.
 *
 * The plugin's table is reproduced as a test table with the same columns, dropped after each test (the real plugin is
 * neither required nor touched); the same code reads the real table when it exists.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\groupenrol_source
 * @covers     \tool_ltidiagnostics\observer
 */
final class groupenrol_source_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use lti_data;

    /** @var string Temporary copy of the plugin table. */
    private const TABLE = 'tool_ltidiagnostics_gafix';

    /**
     * Create the temporary table.
     *
     * @param bool $failurecolumns With the failure columns of 1.2.0.
     */
    private function table(bool $failurecolumns): void {
        global $DB;
        $t = new \xmldb_table(self::TABLE);
        $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $t->add_field('courseid', XMLDB_TYPE_INTEGER, '11', null, XMLDB_NOTNULL, null, '0');
        $t->add_field('enable_enrol', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $t->add_field('settings', XMLDB_TYPE_TEXT);
        if ($failurecolumns) {
            $t->add_field('backfill_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('event_errors', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('event_errortime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $t->add_field('event_errorlast', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman = $DB->get_manager();
        if ($dbman->table_exists($t)) {
            $dbman->drop_table($t);
        }
        $dbman->create_table($t);
    }

    /**
     * Drop the copy of the table (the schema is not reset between tests).
     */
    protected function tearDown(): void {
        global $DB;
        $t = new \xmldb_table(self::TABLE);
        if ($DB->get_manager()->table_exists($t)) {
            $DB->get_manager()->drop_table($t);
        }
        parent::tearDown();
        $this->check_deprecation_guard();
    }

    /**
     * Open the guard frame (this class has its own tearDown).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->start_deprecation_guard();
    }

    /**
     * A tool connection with one published tool in a course.
     *
     * @return array [connection, tool record, course]
     */
    private function connection(): array {
        $reg = $this->create_registration();
        $course = $this->getDataGenerator()->create_course();
        $tool = $this->create_published_tool($course);
        $this->create_resource_link($reg->depid, (int) $tool->id);
        $c = (new inventory())->get_connection(connection::make_key(connection::ROLE_TOOL, $reg->regid, $reg->depid));
        return [$c, $tool, $course];
    }

    /**
     * Without the plugin nothing is read, no finding appears and the message is "not observable".
     */
    public function test_without_plugin(): void {
        $this->resetAfterTest();
        [$c] = $this->connection();
        $g = groupenrol_source::facts($c, ['enrolment' => time()], 'tool_ltidiagnostics_gaabsent');
        $this->assertFalse($g['installed']);
        $this->assertSame([], groupenrol_source::findings($g, ['enrolment' => time()], time()));
    }

    /**
     * An older table without the failure columns is read without error.
     */
    public function test_old_table_without_failure_columns(): void {
        global $DB;
        $this->resetAfterTest();
        [$c, $tool, $course] = $this->connection();
        $this->table(false);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $DB->insert_record(self::TABLE, (object) ['courseid' => $course->id, 'enable_enrol' => 1,
            'settings' => json_encode([(string) $tool->id => [(string) $group->id]])]);
        $g = groupenrol_source::facts($c, [], self::TABLE);
        $this->assertTrue($g['installed']);
        $this->assertSame(1, $g['mappedtools']);
        $this->assertSame(1, $g['mappedgroups']);
        $this->assertSame(0, $g['failures']);
    }

    /**
     * Failures recorded by the plugin, a missing assignment after an LTI enrolment, and an observed assignment.
     */
    public function test_failures_missing_and_assigned(): void {
        global $DB;
        $this->resetAfterTest();
        [$c, $tool, $course] = $this->connection();
        $this->table(true);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $now = time();
        $DB->insert_record(self::TABLE, (object) ['courseid' => $course->id, 'enable_enrol' => 1,
            'settings' => json_encode([(string) $tool->id => [(string) $group->id]]), 'backfill_time' => 0, 'event_errors' => 2,
            'event_errortime' => $now - 100, 'event_errorlast' => $now - 50]);
        $g = groupenrol_source::facts($c, [], self::TABLE);
        $this->assertSame(2, $g['failures']);
        $codes = array_map(function ($f) {
            return $f->code;
        }, groupenrol_source::findings($g, ['enrolment' => $now - 60], $now));
        $this->assertSame(['ga_failures', 'ga_assignment_missing'], $codes);
        $DB->set_field(self::TABLE, 'event_errors', 0, ['courseid' => $course->id]);
        $DB->set_field(self::TABLE, 'event_errortime', 0, ['courseid' => $course->id]);
        $DB->set_field(self::TABLE, 'event_errorlast', 0, ['courseid' => $course->id]);
        $ok = groupenrol_source::facts($c, ['group' => $now - 10], self::TABLE);
        $findings = groupenrol_source::findings($ok, ['enrolment' => $now - 11, 'group' => $now - 10], $now);
        $this->assertSame(['ga_assigned'], array_map(function ($f) {
            return $f->code;
        }, $findings));
        foreach ($findings as $f) {
            $this->assertNotSame('', $f->diagnosis()['check']);
        }
        // A disabled course configuration counts as not mapped.
        $DB->set_field(self::TABLE, 'enable_enrol', 0, ['courseid' => $course->id]);
        $this->assertSame(0, groupenrol_source::facts($c, [], self::TABLE)['mappedtools']);
    }

    /**
     * The group membership added during the launch request is observed with the trace id of the enrolment (fallback
     * signal for versions without own events); with the events of 1.2.1 the assignment is observed directly.
     */
    public function test_assignment_is_observed_in_the_launch_request(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $user = $this->getDataGenerator()->create_user(['auth' => 'lti']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $script = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/enrol/lti/launch.php';
        try {
            observation_store::reset_trace();
            groups_add_member($group, $user);
            $obs = $DB->get_records('tool_ltidiagnostics_obs', ['eventtype' => 'group', 'userid' => $user->id]);
            $this->assertCount(1, $obs);
            if (groupenrol_source::has_events()) {
                $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
                $class = groupenrol_source::EVENT_COMPLETED;
                $class::create(['context' => \context_course::instance($course->id), 'objectid' => $instance->id,
                    'relateduserid' => $user->id, 'other' => ['source' => 'event', 'added' => 1, 'existing' => 0, 'errors' => 0,
                    'toolid' => 0]])->trigger();
                $this->assertTrue($DB->record_exists('tool_ltidiagnostics_obs', ['eventtype' => 'groupassign',
                    'userid' => $user->id]));
            } else {
                // The registrations in db/events.php for the events of 1.2.1 are inert without them.
                $this->assertFalse(class_exists(groupenrol_source::EVENT_COMPLETED));
            }
        } finally {
            if ($script === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $script;
            }
            observation_store::reset_trace();
        }
    }
}
