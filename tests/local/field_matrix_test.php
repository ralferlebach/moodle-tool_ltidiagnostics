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
 * Field matrix of the launch message (audit item DIA-02): what the platform's settings send, what the tool
 * demonstrably received (including the presence of name and e-mail claims detected from the placeholders of auth_lti
 * through a real login event in the launch script), and the comparison with a partner package.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\field_matrix
 * @covers     \tool_ltidiagnostics\observer
 * @covers     \tool_ltidiagnostics\local\fact_collector
 */
final class field_matrix_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use lti_data;

    /** @var string|null */
    private $script;

    /**
     * Remember the script name.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->start_deprecation_guard();
        $this->script = $_SERVER['SCRIPT_NAME'] ?? null;
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
        observation_store::reset_trace();
        parent::tearDown();
        $this->check_deprecation_guard();
    }

    /**
     * Platform column from real type settings.
     */
    public function test_platform_column_from_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $typeid = $this->create_platform_type(['lti_sendname' => 1, 'lti_sendemailaddr' => 0,
            'lti_customparameters' => "id=abc\nCourse_Code=\$Context.title\nsecret_thing=x", 'ltiservice_memberships' => 0]);
        // The core generator copies the name setting into the e-mail setting; set the e-mail setting as an administrator would.
        $DB->set_field('lti_types_config', 'value', '0', ['typeid' => $typeid, 'name' => 'sendemailaddr']);
        $c = (new inventory())->get_connection("platform:$typeid");
        $this->assertSame(['course_code', 'id', 'secret_thing'], $c->facts['customkeys']);
        $col = field_matrix::platform_column($c);
        $this->assertSame('yes', $col['given_name'][0]);
        $this->assertSame('no', $col['email'][0]);
        $this->assertSame('yes', $col['ags_endpoint'][0]);
        $this->assertSame('no', $col['nrps_endpoint'][0]);
        $this->assertSame('always', $col['iss'][0]);
        $this->assertSame('yes', $col['custom_id'][0]);
        $this->assertArrayHasKey('custom.course_code', $col);
        $this->assertStringNotContainsString('abc', json_encode($col), 'Custom values are never read into the matrix.');
    }

    /**
     * Tool column: a real login event in the launch script records which identity claims arrived (placeholder check),
     * without storing a value; the stored resource link proves the service claims.
     */
    public function test_tool_column_from_real_launch(): void {
        global $DB;
        $this->resetAfterTest();
        $reg = $this->create_registration();
        $course = $this->getDataGenerator()->create_course();
        $tool = $this->create_published_tool($course);
        $this->create_resource_link($reg->depid, (int) $tool->id, ['lineitemsservice' => 'https://p.example/li',
            'scorescope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/score']);
        $iss = $reg->platformid;
        $sub = 'sub-' . random_string(6);
        $placeholder = \core_user::clean_field('enrol_lti_13_' . sha1($iss . '_' . $sub) . '@example.com', 'email');
        $user = $this->getDataGenerator()->create_user(['auth' => 'lti', 'firstname' => 'Ada', 'lastname' => $iss,
            'email' => $placeholder]);
        $DB->insert_record('auth_lti_linked_login', (object) ['userid' => $user->id, 'issuer' => $iss,
            'issuer256' => hash('sha256', $iss), 'sub' => $sub, 'sub256' => hash('sha256', $sub), 'timecreated' => time(),
            'timemodified' => time()]);
        $_SERVER['SCRIPT_NAME'] = '/enrol/lti/launch.php';
        \core\event\user_loggedin::create(['userid' => $user->id, 'objectid' => $user->id,
            'other' => ['username' => $user->username]])->trigger();
        $row = $DB->get_record('tool_ltidiagnostics_obs', ['eventtype' => 'launch', 'userid' => $user->id], '*', MUST_EXIST);
        $names = json_decode($row->fieldnames, true);
        $this->assertContains('claim.given_name=present', $names);
        $this->assertContains('claim.family_name=absent', $names);
        $this->assertContains('claim.email=absent', $names);
        $this->assertStringNotContainsString('Ada', $row->fieldnames);
        $this->assertStringNotContainsString($sub, $row->fieldnames);

        $conn = (new inventory())->get_connection(connection::make_key(connection::ROLE_TOOL, $reg->regid, $reg->depid));
        $facts = (new fact_collector())->collect($conn, time());
        $this->assertSame('present', $facts['claims']['given_name']);
        $col = field_matrix::tool_column($conn, $facts);
        $this->assertSame('validated', $col['iss'][0]);
        $this->assertSame('present', $col['given_name'][0]);
        $this->assertSame('absent', $col['email'][0]);
        $this->assertSame('present', $col['ags_endpoint'][0]);
        $this->assertSame('absent', $col['nrps_endpoint'][0]);
        $this->assertSame('not_observable', $col['roles'][0]);

        // Outside the launch script nothing about claims is recorded.
        $_SERVER['SCRIPT_NAME'] = '/login/index.php';
        observation_store::reset_trace();
        \core\event\user_loggedin::create(['userid' => $user->id, 'objectid' => $user->id,
            'other' => ['username' => $user->username]])->trigger();
        $last = $DB->get_records('tool_ltidiagnostics_obs', ['userid' => $user->id], 'id DESC', '*', 0, 1);
        $this->assertStringNotContainsString('claim.', (string) reset($last)->fieldnames);
    }

    /**
     * Comparison with the partner's column: e-mail sent by the platform but not received by the tool is a mismatch.
     */
    public function test_agreement_with_partner(): void {
        $c = new connection();
        $c->role = connection::ROLE_TOOL;
        $facts = ['obs' => ['launch' => 1], 'claims' => ['given_name' => 'present', 'family_name' => 'present',
            'email' => 'absent'], 'tool' => ['links' => 1, 'agsclaim' => true, 'scorescope' => true, 'nrpsclaim' => false]];
        $partner = ['email' => 'yes', 'given_name' => 'yes', 'nrps_endpoint' => 'no', 'custom.course_code' => 'yes',
            'iss' => 'always'];
        $rows = field_matrix::build($c, $facts, $partner);
        $this->assertSame('mismatch', $rows['email']['agreement']);
        $this->assertSame('match', $rows['given_name']['agreement']);
        $this->assertSame('match', $rows['nrps_endpoint']['agreement']);
        $this->assertSame('match', $rows['iss']['agreement']);
        $this->assertSame('not_observable', $rows['custom.course_code']['local']);
        $this->assertSame('custom', $rows['custom.course_code']['category']);
        $this->assertSame('na', field_matrix::agreement('platform', 'per_activity', 'present'));
        $none = field_matrix::build($c, $facts, null);
        $this->assertSame('', $none['email']['partner']);
        $this->assertSame('na', $none['email']['agreement']);
    }
}
