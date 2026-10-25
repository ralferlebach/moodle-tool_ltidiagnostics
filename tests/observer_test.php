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

use tool_ltidiagnostics\local\observation_store;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/lti_data.php');

/**
 * Integration tests of the event observer with real Moodle events and real entry points.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\observer
 * @covers     \tool_ltidiagnostics\local\observation_store
 * @covers     \tool_ltidiagnostics\local\entrypoint
 */
final class observer_test extends \advanced_testcase {
    use lti_data;


    /**
     * Update a user with the API of the running Moodle version (user_update_user is deprecated since 5.3).
     *
     * @param \stdClass $user Update object.
     */
    private function update_user(\stdClass $user): void {
        if (method_exists(\core\user::class, 'update_user')) {
            \core\user::update_user($user);
        } else {
            user_update_user($user);
        }
    }

    /** @var mixed Saved SCRIPT_NAME. */
    private $script;

    /**
     * Remember the script name.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->script = $_SERVER['SCRIPT_NAME'] ?? null;
        observation_store::reset_trace();
    }

    /**
     * Restore the script name.
     */
    protected function tearDown(): void {
        if ($this->script === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $this->script;
        }
        unset($_SERVER['HTTP_X_REQUEST_ID']);
        observation_store::reset_trace();
        parent::tearDown();
    }

    /**
     * Pretend that the request runs in the given script.
     *
     * @param string $script Script name.
     */
    private function in_script(string $script): void {
        $_SERVER['SCRIPT_NAME'] = $script;
    }

    /**
     * Create an LTI authenticated user bound to an issuer.
     *
     * @param string $issuer Issuer.
     * @return \stdClass The user.
     */
    private function lti_user(string $issuer): \stdClass {
        $user = $this->getDataGenerator()->create_user(['auth' => 'lti', 'username' => 'ltiuser' . strtolower(random_string(5)),
            'email' => 'private.person@example.org', 'firstname' => 'Privatefirst', 'lastname' => 'Privatelast']);
        get_auth_plugin('lti')->create_user_binding($issuer, 'sub-' . random_string(6), (int) $user->id);
        return $user;
    }

    /**
     * Events registered by this plugin are exactly the documented ones.
     */
    public function test_registered_events(): void {
        global $CFG;
        $observers = [];
        require($CFG->dirroot . '/admin/tool/ltidiagnostics/db/events.php');
        $names = array_column($observers, 'eventname');
        $this->assertEqualsCanonicalizing(\tool_ltidiagnostics\local\logstore_source::EVENTS, $names);
        foreach ($observers as $o) {
            $this->assertSame('\tool_ltidiagnostics\observer::handle', $o['callback']);
            $this->assertArrayNotHasKey('internal', $o, 'Observers must run after the transaction commits.');
        }
    }

    /**
     * A real login of an LTI user inside the launch script is recorded with the issuer based connection.
     */
    public function test_launch_login_is_recorded(): void {
        global $DB;
        $this->resetAfterTest();
        $reg = $this->create_registration();
        $user = $this->lti_user($reg->platformid);
        $this->setUser(0);
        $this->in_script('/enrol/lti/launch.php');
        @complete_user_login($user);

        $rows = array_values($DB->get_records('tool_ltidiagnostics_obs', ['eventtype' => 'launch']));
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('lti_launch', $row->entrypoint);
        $this->assertSame('in', $row->direction);
        $this->assertSame((int) $user->id, (int) $row->userid);
        $this->assertSame("tool:{$reg->regid}:{$reg->depid}", $row->connkey);
        $this->assertSame(hash('sha256', $reg->platformid), $row->issuerhash);
        $this->assertSame('\core\event\user_loggedin', $row->eventname);
        $this->assertNotSame('', $row->traceid);
    }

    /**
     * The same login through a normal web script is recorded with a different entry point.
     */
    public function test_login_outside_launch_script_is_not_a_launch(): void {
        global $DB;
        $this->resetAfterTest();
        $reg = $this->create_registration();
        $user = $this->lti_user($reg->platformid);
        $this->setUser(0);
        $this->in_script('/login/index.php');
        @complete_user_login($user);
        $row = $DB->get_record('tool_ltidiagnostics_obs', ['eventtype' => 'launch'], '*', MUST_EXIST);
        $this->assertSame('web', $row->entrypoint);
        $connections = (new local\fact_collector())->collect(
            (new local\inventory())->get_connection("tool:{$reg->regid}:{$reg->depid}"),
            time()
        );
        $this->assertSame(0, $connections['obs']['launch'], 'A login outside the launch script is no launch evidence.');
    }

    /**
     * Logins of users that do not use the LTI authentication are ignored.
     */
    public function test_non_lti_login_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $this->setUser(0);
        $this->in_script('/enrol/lti/launch.php');
        @complete_user_login($user);
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_obs'));
    }

    /**
     * Account creation and the per-launch profile update.
     */
    public function test_user_created_and_updated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->in_script('/enrol/lti/launch.php');
        $user = $this->getDataGenerator()->create_user(['auth' => 'lti']);
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_obs', ['eventtype' => 'account_created']));
        $this->update_user((object) ['id' => $user->id, 'firstname' => 'Changed']);
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_obs', ['eventtype' => 'launch_sync']));

        $this->in_script('/user/edit.php');
        $this->update_user((object) ['id' => $user->id, 'firstname' => 'Again']);
        $this->assertSame(
            1,
            $DB->count_records('tool_ltidiagnostics_obs', ['eventtype' => 'launch_sync']),
            'Only inside a launch.'
        );
    }

    /**
     * A real enrolment by the LTI enrolment method is recorded separately from the launch.
     */
    public function test_enrolment_is_recorded_separately(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $reg = $this->create_registration();
        $tool = $this->create_published_tool($course);
        $this->create_resource_link($reg->depid, (int) $tool->id);
        $user = $this->getDataGenerator()->create_user();
        $student = $DB->get_field('role', 'id', ['shortname' => 'student']);

        enrol_get_plugin('lti')->enrol_user($tool->instance, $user->id, $student);

        $rows = array_values($DB->get_records('tool_ltidiagnostics_obs', ['eventtype' => 'enrolment']));
        $this->assertCount(1, $rows);
        $this->assertSame("tool:{$reg->regid}:{$reg->depid}", $rows[0]->connkey);
        $this->assertSame((int) $user->id, (int) $rows[0]->userid);
        $this->assertSame((int) $course->id, (int) $rows[0]->courseid);
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_obs', ['eventtype' => 'launch']), 'No launch was seen.');

        // A manual enrolment is not recorded.
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student', 'manual');
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_obs', ['eventtype' => 'enrolment']));
    }

    /**
     * Group additions count only inside a launch.
     */
    public function test_group_member_added(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->in_script('/course/view.php');
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_obs', ['eventtype' => 'group']));
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->in_script('/enrol/lti/launch.php');
        $this->getDataGenerator()->create_group_member(['groupid' => $group2->id, 'userid' => $user->id]);
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_obs', ['eventtype' => 'group']));
    }

    /**
     * Grades of LTI items: the direction depends on the entry point; other grade items are ignored.
     */
    public function test_grade_events(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $typeid = $this->create_platform_type();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'typeid' => $typeid]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->in_script('/mod/lti/services.php');
        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $user->id, 'rawgrade' => 77]);
        $this->in_script('/grade/report/grader/index.php');
        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $user->id, 'rawgrade' => 55]);
        grade_update('mod/assign', $course->id, 'mod', 'assign', $assign->id, 0, ['userid' => $user->id, 'rawgrade' => 10]);

        $rows = array_values($DB->get_records('tool_ltidiagnostics_obs', ['eventtype' => 'grade'], 'id'));
        $this->assertCount(2, $rows, 'The assignment grade is not an LTI grade.');
        $this->assertSame('in', $rows[0]->direction);
        $this->assertSame('lti_services', $rows[0]->entrypoint);
        $this->assertSame('local', $rows[1]->direction);
        $this->assertSame("platform:$typeid", $rows[0]->connkey);
        $columns = array_keys((array) $rows[0]);
        foreach ($columns as $column) {
            $this->assertStringNotContainsString('grade', $column, 'The table has no column for grade values.');
        }
    }

    /**
     * Viewing an LTI activity (launch rendered by the platform).
     */
    public function test_view_event(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $typeid = $this->create_platform_type();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'typeid' => $typeid]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $this->setUser($user);
        $cm = get_coursemodule_from_instance('lti', $lti->id);
        \mod_lti\event\course_module_viewed::create([
            'objectid' => $lti->id, 'context' => \context_module::instance($cm->id),
        ])->trigger();
        $row = $DB->get_record('tool_ltidiagnostics_obs', ['eventtype' => 'view'], '*', MUST_EXIST);
        $this->assertSame('out', $row->direction);
        $this->assertSame("platform:$typeid", $row->connkey);
        $this->assertSame((int) $cm->id, (int) $row->cmid);
    }

    /**
     * Recording can be switched off.
     */
    public function test_recording_switch(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('recordevents', 0, 'tool_ltidiagnostics');
        $this->in_script('/enrol/lti/launch.php');
        $this->getDataGenerator()->create_user(['auth' => 'lti']);
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_obs'));
    }

    /**
     * Personal values and secrets never reach the table.
     */
    public function test_no_personal_values_are_stored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->in_script('/enrol/lti/launch.php');
        $user = $this->getDataGenerator()->create_user(['auth' => 'lti', 'username' => 'veryprivateusername',
            'email' => 'private.person@example.org', 'firstname' => 'Privatefirst', 'lastname' => 'Privatelast']);
        $this->update_user((object) ['id' => $user->id, 'firstname' => 'Secondprivatefirst', 'email' => 'new.mail@example.org']);
        $rows = array_values($DB->get_records('tool_ltidiagnostics_obs'));
        $this->assertNotEmpty($rows);
        $dump = json_encode($rows);
        $secrets = ['veryprivateusername', 'private.person', 'Privatefirst', 'Privatelast', 'Secondprivatefirst', 'new.mail'];
        foreach ($secrets as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        $sync = $DB->get_record('tool_ltidiagnostics_obs', ['eventtype' => 'launch_sync'], '*', MUST_EXIST);
        if ($sync->fieldnames !== null) {
            $this->assertIsArray(json_decode($sync->fieldnames, true), 'Field names are a list of keys.');
        }
    }

    /**
     * The trace id: random per request, taken from a sanitised request id header when present.
     */
    public function test_trace_id(): void {
        $this->resetAfterTest();
        $a = observation_store::current_trace_id();
        $this->assertSame($a, observation_store::current_trace_id());
        observation_store::reset_trace();
        $b = observation_store::current_trace_id();
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^t[0-9a-f]{16}$/', $b);

        observation_store::reset_trace();
        $_SERVER['HTTP_X_REQUEST_ID'] = 'abc-123_DEF<script>';
        $this->assertSame('abc-123_DEFscript', observation_store::current_trace_id());
        observation_store::reset_trace();
        $_SERVER['HTTP_X_REQUEST_ID'] = str_repeat('a', 200);
        $this->assertSame(64, strlen(observation_store::current_trace_id()));
    }

    /**
     * Entry point classification.
     *
     * @dataProvider entrypoint_provider
     * @param string $script Script name.
     * @param bool $cli Whether it runs from the command line.
     * @param bool $ws Whether it is a web service.
     * @param string $expected Expected class.
     */
    public function test_entrypoint_classification(string $script, bool $cli, bool $ws, string $expected): void {
        $this->assertSame($expected, local\entrypoint::classify($script, $cli, $ws));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function entrypoint_provider(): array {
        return [
            ['/enrol/lti/launch.php', false, false, 'lti_launch'],
            ['/moodle/enrol/lti/launch.php', false, false, 'lti_launch'],
            ['/enrol/lti/launch_deeplink.php', false, false, 'lti_launch'],
            ['/auth/lti/login.php', false, false, 'lti_launch'],
            ['/mod/lti/services.php', false, false, 'lti_services'],
            ['/mod/lti/token.php', false, false, 'lti_services'],
            ['/index.php', false, false, 'web'],
            ['/enrol/lti/launch.php/evil', false, false, 'web'],
            ['/enrol/lti/launch.php', true, false, 'cli'],
            ['/webservice/rest/server.php', false, true, 'ws'],
            ['', false, false, 'web'],
        ];
    }

    /**
     * Retrieval for a course counts events by type.
     */
    public function test_course_aggregate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        observation_store::record(['eventtype' => 'launch', 'courseid' => $course->id, 'eventname' => 'x']);
        observation_store::record(['eventtype' => 'launch', 'courseid' => $course->id, 'eventname' => 'x']);
        observation_store::record(['eventtype' => 'enrolment', 'courseid' => $course->id, 'eventname' => 'x']);
        observation_store::record(['eventtype' => 'enrolment', 'courseid' => $course->id + 1, 'eventname' => 'x']);
        $rows = observation_store::get_for_course((int) $course->id);
        $this->assertSame(['enrolment' => 1, 'launch' => 2], observation_store::count_by_type($rows));
    }

    /**
     * Old observations are purged.
     */
    public function test_purge(): void {
        $this->resetAfterTest();
        observation_store::record(['eventtype' => 'launch', 'eventname' => 'old', 'timecreated' => 1000]);
        observation_store::record(['eventtype' => 'launch', 'eventname' => 'new', 'timecreated' => time()]);
        $this->assertSame(1, observation_store::purge_older_than(5000));
        global $DB;
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_obs'));
    }
}
