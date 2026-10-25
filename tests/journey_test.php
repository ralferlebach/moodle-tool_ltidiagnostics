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

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\desired_profile;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\observation_store;
use tool_ltidiagnostics\local\report;
use tool_ltidiagnostics\local\report_exporter;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/lti_data.php');

/**
 * Integration journeys over real core flows: the two sides of an LTI connection as the health check sees them.
 *
 * This is the PHPUnit counterpart of the Playwright scenario: after a launch, the tool side lists the observed
 * launch and the local enrolment as separate evidence rows, and the platform side shows the grade services as
 * configured but not verified until a transaction was observed.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\health_check
 * @covers     \tool_ltidiagnostics\local\fact_collector
 * @covers     \tool_ltidiagnostics\local\report
 */
final class journey_test extends \advanced_testcase {
    use lti_data;

    /** @var mixed Saved script name. */
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
        observation_store::reset_trace();
        parent::tearDown();
    }

    /**
     * Codes of the findings of a result.
     *
     * @param array $result Health result.
     * @return string[]
     */
    private function codes(array $result): array {
        return array_map(function ($f) {
            return $f->code;
        }, $result['findings']);
    }

    /**
     * Tool side: a launch and an enrolment appear as two separate evidence items; the grade and member services of
     * the tool stay "configured".
     */
    public function test_tool_side_launch_and_enrolment_are_separate_evidence(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $reg = $this->create_registration();
        $tool = $this->create_published_tool($course, ['gradesync' => 1, 'membersync' => 1]);
        $this->create_resource_link($reg->depid, (int) $tool->id, [
            'lineitemsservice' => 'https://platform.example.org/lineitems',
            'scorescope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'contextmembershipsurl' => 'https://platform.example.org/memberships',
        ]);
        $key = "tool:{$reg->regid}:{$reg->depid}";
        $connection = (new inventory())->get_connection($key);
        $profile = $this->profile(['wanted' => ['lti_core', 'ags', 'nrps'], 'ags_scopes' => ['score'],
            'expect' => ['enrolment' => true, 'gradebook_update' => true]]);

        // Before anything happened.
        $before = (new health_check())->run($connection, time(), ['profile' => $profile]);
        $this->assertSame('configured', $before['matrix']['lti_core']['status']);
        $this->assertNotContains('core_launch_seen', $this->codes($before));
        $this->assertContains('core_enrol_missing', $this->codes($before));

        // A real launch: the LTI user logs in through the launch script, then the enrolment follows.
        $user = $this->getDataGenerator()->create_user(['auth' => 'lti']);
        get_auth_plugin('lti')->create_user_binding($reg->platformid, 'sub-1', (int) $user->id);
        $_SERVER['SCRIPT_NAME'] = '/enrol/lti/launch.php';
        $this->setUser(0);
        @complete_user_login($user);
        $student = $DB->get_field('role', 'id', ['shortname' => 'student']);
        enrol_get_plugin('lti')->enrol_user($tool->instance, $user->id, $student);

        $after = (new health_check())->run($connection, time(), ['profile' => $profile]);
        $codes = $this->codes($after);
        $this->assertContains('core_launch_seen', $codes);
        $this->assertContains('core_enrol_seen', $codes, 'The enrolment is its own evidence row.');
        $this->assertContains('core_enrol_ok', $codes);
        $this->assertNotContains('core_enrol_missing', $codes);
        $this->assertSame('verified', $after['matrix']['lti_core']['status']);
        $this->assertSame('observed_transaction', $after['matrix']['lti_core']['ladder']['observed']->kind);
        $this->assertSame('local_effect', $this->finding($after, 'core_enrol_seen')->evidencekind);
        $this->assertSame('observed_transaction', $this->finding($after, 'core_launch_seen')->evidencekind);

        // The services of the tool are authorised but not verified: no platform transaction is visible here.
        $this->assertSame('authorised', $after['matrix']['ags']['status']);
        $this->assertNotSame('verified', $after['matrix']['nrps']['status']);
        $this->assertSame('configured', $after['matrix']['nrps']['status']);
        $this->assertContains('adv_notverified', $codes);
        $this->assertContains('core_no_score', $codes);
    }

    /**
     * Platform side: AGS and NRPS configured, nothing observed: configured, not verified. A grade through the
     * service entry point verifies AGS only.
     */
    public function test_platform_side_services_are_configured_until_observed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $typeid = $this->create_platform_type();
        $connection = (new inventory())->get_connection("platform:$typeid");
        $profile = $this->profile(['wanted' => ['lti_core', 'ags', 'nrps'], 'expect' => ['gradebook_update' => true]]);

        $before = (new health_check())->run($connection, time(), ['profile' => $profile]);
        $this->assertSame('configured', $before['matrix']['ags']['status']);
        $this->assertSame('configured', $before['matrix']['nrps']['status']);
        $this->assertContains('adv_notverified', $this->codes($before));
        $this->assertContains('core_no_score', $this->codes($before));

        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'typeid' => $typeid]);
        $_SERVER['SCRIPT_NAME'] = '/mod/lti/services.php';
        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $user->id, 'rawgrade' => 80]);

        $after = (new health_check())->run($connection, time(), ['profile' => $profile]);
        $this->assertSame('verified', $after['matrix']['ags']['status']);
        $this->assertSame('configured', $after['matrix']['nrps']['status'], 'NRPS has no observation hook.');
        $this->assertContains('core_grade_ok', $this->codes($after));
        $this->assertNotContains('core_no_score', $this->codes($after));
    }

    /**
     * Platform side: an issued access token authorises a service but proves nothing else.
     */
    public function test_platform_token_row_is_authorisation_only(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $typeid = $this->create_platform_type();
        $DB->insert_record('lti_access_tokens', (object) [
            'typeid' => $typeid, 'scope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'token' => str_repeat('a', 40), 'validuntil' => time() + 3600, 'timecreated' => time(), 'lastaccess' => null,
        ]);
        $connection = (new inventory())->get_connection("platform:$typeid");
        $result = (new health_check())->run($connection, time());
        $this->assertSame('authorised', $result['matrix']['ags']['status']);
        $this->assertSame('configured', $result['matrix']['nrps']['status']);
        $this->assertStringNotContainsString(str_repeat('a', 40), json_encode($result['matrix']), 'Tokens are never copied.');
    }

    /**
     * The exported report of a real run carries no secret and no personal data, and states its limits.
     */
    public function test_report_of_a_real_run_is_redacted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $typeid = $this->create_platform_type([
            'lti_clientid' => 'client-id-visible-only-in-diagnostic-mode', 'name' => 'Tool with name',
        ]);
        $connection = (new inventory())->get_connection("platform:$typeid");
        $result = (new health_check())->run($connection, time());
        $report = (new report())->build($connection, $result, ['viewerrole' => 'admin']);
        foreach (report_exporter::FORMATS as $format) {
            $out = report_exporter::export($report, $format);
            $this->assertNotSame('', $out);
            $this->assertStringNotContainsString('client-id-visible-only-in-diagnostic-mode', $out, $format);
            $this->assertStringNotContainsString('tool.example.org', $out, $format);
        }
        $json = json_decode(report_exporter::export($report, 'json'), true);
        $this->assertSame('excluded', $json['meta']['payloads']);
        $this->assertFalse($json['meta']['identifiersrevealed']);
        $this->assertCount(5, $json['limits']);
        $this->assertCount(8, $json['matrix']);
        $revealed = (new report())->build($connection, $result, ['reveal' => true]);
        $this->assertStringContainsString(
            'client-id-visible-only-in-diagnostic-mode',
            report_exporter::export($revealed, 'json')
        );
    }

    /**
     * Find a finding in a result.
     *
     * @param array $result Health result.
     * @param string $code Code.
     * @return \tool_ltidiagnostics\local\finding
     */
    private function finding(array $result, string $code) {
        foreach ($result['findings'] as $f) {
            if ($f->code === $code) {
                return $f;
            }
        }
        $this->fail("Finding $code not present");
    }

    /**
     * Build a profile.
     *
     * @param array $data Data.
     * @return desired_profile
     */
    private function profile(array $data): desired_profile {
        [$profile, $errors] = desired_profile::from_array($data + ['schema' => 1, 'name' => 'Journey']);
        $this->assertSame([], $errors);
        return $profile;
    }
}
