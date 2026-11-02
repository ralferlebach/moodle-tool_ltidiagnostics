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

namespace tool_ltidiagnostics\tests;

use enrol_lti\local\ltiadvantage\entity\application_registration;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use enrol_lti\local\ltiadvantage\task\sync_tool_grades;
use Packback\Lti1p3\LtiAssignmentsGradesService;
use Packback\Lti1p3\LtiMessageLaunch;

/**
 * Real enrol_lti grade scenario with a control consumer and two defective "BUW" link constellations.
 *
 * Built only through core: registrations and deployments of enrol_lti, launches through enrol_lti's tool launch
 * service (which stores users, resource links and the AGS claims), auth_lti bindings, the gradebook, and core's
 * sync_tool_grades task with a mocked AGS service; the task output is stored in the task log exactly as the database
 * logger does.
 *
 *  - Control consumer A (https://lms.example.org, client 123): learners L1 and L2, full AGS claim.
 *  - BUW consumer B (https://buw.example.org, client buw-client):
 *    defect 1: L1 launches later through B (link rl-b-1, AGS claim without result scope). Core already sent L1's grade
 *    to A, so lastgrade equals the grade and core skips B forever; core also keeps B's subject as the only sourceid.
 *    defect 2: L3 launches through B with an old claim without the score scope (link rl-b-2); core's send fails 401.
 *  - L2 has no grade (missing). L4 (consumer A) has 0 points: core never sends it, because enrol_lti starts every
 *    learner with lastgrade 0.0 and skips a grade that equals lastgrade.
 *
 * Used by test classes that extend \lti_advantage_testcase.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait grade_scenario {
    /**
     * Get a mock LtiMessageLaunch object, as if a user had launched from a resource link in the platform.
     *
     * Same launch data as core's helper in enrol/lti/tests/local/ltiadvantage/lti_advantage_testcase.php (identical in
     * Moodle 4.5 and 5.2), but with willReturnCallback(): PHPUnit 11 deprecates will($this->returnCallback()), and the
     * deprecation report of this plugin counts every runner deprecation that is not the owner's docblock metadata.
     *
     * @param \stdClass $resource the resource record, allowing the mock to generate a link to this.
     * @param array $mockuser the user on the platform who is performing the launch.
     * @param string|null $resourcelinkid the id of resource link in the platform, if desired.
     * @param array|null $ags array representing the lti-ags claim info. Pass null to omit, empty array to use a default.
     * @param bool $nrps whether to include a mock NRPS claim or not.
     * @param array|null $migrationclaiminfo contains consumer key, secret and any fields which are sent in the claim.
     * @param array|null $customparams an array of custom params to send, or null to just use defaults.
     * @param mixed $aud the array or string value of aud to use in the mock launch data.
     * @return \Packback\Lti1p3\LtiMessageLaunch the mock launch object with test launch data.
     */
    protected function get_mock_launch(
        \stdClass $resource,
        array $mockuser,
        ?string $resourcelinkid = null,
        ?array $ags = [],
        bool $nrps = true,
        ?array $migrationclaiminfo = null,
        ?array $customparams = null,
        $aud = '123'
    ): LtiMessageLaunch {

        $mocklaunch = $this->getMockBuilder(LtiMessageLaunch::class)
            ->onlyMethods(['getLaunchData', 'getLaunchId'])
            ->disableOriginalConstructor()
            ->getMock();
        $mocklaunch->expects($this->any())
            ->method('getLaunchData')
            ->willReturnCallback(
                function () use ($resource, $mockuser, $resourcelinkid, $migrationclaiminfo, $ags, $nrps, $customparams, $aud) {
                    // This simulates the data in the jwt['body'] of a real resource link launch.
                    // Real launches would of course have this data and authenticity of the user verified.
                    $rltitle = $resourcelinkid ? "Resource link $resourcelinkid in platform" : "Resource link in platform";
                    $rlid = $resourcelinkid ?: '12345';
                    $data = [
                        'iss' => $this->issuer, // Must match registration in create_test_environment.
                        'aud' => $aud, // Must match registration in create_test_environment.
                        'sub' => $mockuser['user_id'], // User id on the platform site.
                        'exp' => time() + 60,
                        'nonce' => 'some-nonce-value-123',
                        'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => '1', // Must match registration.
                        'https://purl.imsglobal.org/spec/lti/claim/roles' =>
                            $mockuser['roles'] ?? ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
                        'https://purl.imsglobal.org/spec/lti/claim/resource_link' => [
                            'title' => $rltitle,
                            'id' => $rlid, // Arbitrary, will be mapped to the user during resource link launch.
                        ],
                        "https://purl.imsglobal.org/spec/lti/claim/context" => [
                            "id" => "context-id-12345",
                            "label" => "ITS 123",
                            "title" => "ITS 123 Machine Learning",
                            "type" => ["http://purl.imsglobal.org/vocab/lis/v2/course#CourseOffering"],
                        ],
                        'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' =>
                            'https://this-moodle-tool.example.org/context/24/resource/14',
                        'given_name' => $mockuser['given_name'],
                        'family_name' => $mockuser['family_name'],
                        'email' => $mockuser['email'],
                    ];

                    if (!is_null($customparams)) {
                        $data['https://purl.imsglobal.org/spec/lti/claim/custom'] = $customparams;
                    } else {
                        $data['https://purl.imsglobal.org/spec/lti/claim/custom'] = [
                            'id' => $resource->uuid,
                        ];
                    }

                    if (is_array($ags)) {
                        if (empty($ags)) {
                            $agsclaim = [
                                "scope" => [
                                    "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem",
                                    "https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly",
                                    "https://purl.imsglobal.org/spec/lti-ags/scope/score",
                                ],
                                "lineitems" => "https://platform.example.com/10/lineitems/",
                                "lineitem" => "https://platform.example.com/10/lineitems/45/lineitem",
                            ];
                        } else {
                            $agsclaim = $ags;
                        }
                        $data["https://purl.imsglobal.org/spec/lti-ags/claim/endpoint"] = $agsclaim;
                    }

                    if ($nrps) {
                        $data['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice'] = [
                            'context_memberships_url' => 'https://lms.example.org/context/24/memberships',
                            'service_versions' => ['2.0'],
                        ];
                    }

                    if (!empty($mockuser['picture'])) {
                        $data['picture'] = $mockuser['picture'];
                    }

                    if ($migrationclaiminfo) {
                        if (isset($migrationclaiminfo['consumer_key'])) {
                            $base = [
                                $migrationclaiminfo['consumer_key'],
                                $data['https://purl.imsglobal.org/spec/lti/claim/deployment_id'],
                                $data['iss'],
                                $data['aud'],
                                $data['exp'],
                                $data['nonce'],
                            ];
                            $basestring = implode('&', $base);

                            $data['https://purl.imsglobal.org/spec/lti/claim/lti1p1'] = [
                                'oauth_consumer_key' => $migrationclaiminfo['consumer_key'],
                            ];

                            if (isset($migrationclaiminfo['signing_secret'])) {
                                $sig = base64_encode(hash_hmac('sha256', $basestring, $migrationclaiminfo['signing_secret']));
                                $data['https://purl.imsglobal.org/spec/lti/claim/lti1p1']['oauth_consumer_key_sign'] = $sig;
                            }
                        }

                        $claimprops = ['user_id', 'context_id', 'tool_consumer_instance_guid', 'resource_link_id'];
                        foreach ($claimprops as $prop) {
                            if (!empty($migrationclaiminfo[$prop])) {
                                $data['https://purl.imsglobal.org/spec/lti/claim/lti1p1'][$prop] =
                                    $migrationclaiminfo[$prop];
                            }
                        }
                    }
                    return $data;
                }
            );

        $mocklaunch->expects($this->any())
            ->method('getLaunchId')
            ->willReturnCallback(function () {
                return uniqid('lti1p3_launch_', true);
            });

        return $mocklaunch;
    }

    /** @var string Issuer of the control consumer (core's test environment). */
    protected static $issuera = 'https://lms.example.org';
    /** @var string Issuer of the BUW consumer. */
    protected static $issuerb = 'https://buw.example.org';

    /**
     * AGS claim.
     *
     * @param string $host Host URL of the platform.
     * @param string[] $scopes Short scope names (score, lineitem, lineitem.readonly, result.readonly).
     * @param bool $coupled Include the coupled line item.
     * @param int $item Line item number.
     * @return array
     */
    protected static function ags_claim(string $host, array $scopes, bool $coupled = true, int $item = 45): array {
        $claim = [
            'scope' => array_map(fn($s) => 'https://purl.imsglobal.org/spec/lti-ags/scope/' . $s, $scopes),
            'lineitems' => $host . '/mod/lti/services.php/2/lineitems?type_id=1',
        ];
        if ($coupled) {
            $claim['lineitem'] = $host . '/mod/lti/services.php/2/lineitems/' . $item . '/lineitem?type_id=1';
        }
        return $claim;
    }

    /**
     * A learner launches the resource from a platform.
     *
     * @param \stdClass $resource Published resource.
     * @param \stdClass $user Local user.
     * @param string $issuer Issuer.
     * @param string $aud Client id.
     * @param string $sub Subject.
     * @param string $rlid Platform resource link id.
     * @param array|null $ags AGS claim (null: none).
     * @return int Local user id.
     */
    protected function learner_launch(
        \stdClass $resource,
        \stdClass $user,
        string $issuer,
        string $aud,
        string $sub,
        string $rlid,
        ?array $ags
    ): int {
        $this->issuer = $issuer;
        $mockuser = self::get_mock_launch_users_with_ids(
            [$sub],
            false,
            'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'
        )[0];
        $launch = $this->get_mock_launch($resource, $mockuser, $rlid, $ags, false, null, null, $aud);
        [$userid] = $this->get_tool_launch_service()->user_launches_tool($user, $launch);
        get_auth_plugin('lti')->create_user_binding($issuer, $sub, (int) $userid);
        $this->issuer = self::$issuera;
        return (int) $userid;
    }

    /**
     * Run core's sync_tool_grades for a resource with a mocked AGS service and store the output in the task log.
     *
     * @param \stdClass $resource Published resource (helper::get_lti_tool()).
     * @param int $status HTTP status the mocked service answers.
     * @param int $time Start time of the run.
     * @return string The output.
     */
    protected function run_core_grade_sync(\stdClass $resource, int $status, int $time): string {
        global $DB;
        $ags = $this->getMockBuilder(LtiAssignmentsGradesService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['putGrade'])
            ->getMock();
        $ags->method('putGrade')->willReturnCallback(fn() => ['headers' => ['httpstatus' => "HTTP/2 $status X"],
            'body' => '', 'status' => $status]);
        $task = $this->getMockBuilder(sync_tool_grades::class)->onlyMethods(['get_ags'])->getMock();
        $task->method('get_ags')->willReturn($ags);
        $task->set_custom_data($resource);
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $DB->insert_record('task_log', (object) [
            'type' => 1, 'component' => 'enrol_lti', 'classname' => get_parent_class($task) ?: get_class($task),
            'userid' => 0, 'timestart' => $time, 'timeend' => $time + 1, 'dbreads' => 0, 'dbwrites' => 0,
            'result' => 0, 'output' => $output, 'hostname' => 'test', 'pid' => 1,
        ]);
        return $output;
    }

    /**
     * Build the scenario.
     *
     * @param bool $gradesynccompletion Publish with completion gating.
     * @return \stdClass course, tool, tool2, tool3, rega, depa, regb, depb, keya, keyb, users (l1, l2, l3), links.
     */
    protected function build_grade_scenario(bool $gradesynccompletion = false): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        [$course, $tool, $tool2, $tool3, $rega, $depa] = $this->create_test_environment(
            true,
            true,
            false,
            \enrol_lti\helper::MEMBER_SYNC_ENROL_AND_UNENROL,
            true,
            $gradesynccompletion
        );
        $regrepo = new application_registration_repository();
        $regb = $regrepo->save(application_registration::create(
            'BUW consumer',
            'buw-unique',
            new \moodle_url(self::$issuerb),
            'buw-client',
            new \moodle_url(self::$issuerb . '/mod/lti/auth.php'),
            new \moodle_url(self::$issuerb . '/mod/lti/certs.php'),
            new \moodle_url(self::$issuerb . '/mod/lti/token.php')
        ));
        $depb = (new deployment_repository())->save($regb->add_tool_deployment('BUW deployment', '1'));

        $gen = $this->getDataGenerator();
        $l1 = $gen->create_user(['username' => 'l1']);
        $l2 = $gen->create_user(['username' => 'l2']);
        $l3 = $gen->create_user(['username' => 'l3']);
        $l4 = $gen->create_user(['username' => 'l4']);
        $agsa = self::ags_claim(self::$issuera, ['lineitem', 'result.readonly', 'score']);
        $this->learner_launch($tool, $l1, self::$issuera, '123', 'a-l1', 'rl-a-1', $agsa);
        $this->learner_launch($tool, $l2, self::$issuera, '123', 'a-l2', 'rl-a-1', $agsa);
        $this->set_user_grade_for_resource_local((int) $l1->id, 65, $tool);
        $this->run_core_grade_sync($tool, 200, time() - 3600);

        // Later: L1 also comes through the BUW consumer, L3 through a second BUW link with an old claim.
        $this->learner_launch(
            $tool,
            $l1,
            self::$issuerb,
            'buw-client',
            'b-l1',
            'rl-b-1',
            self::ags_claim(self::$issuerb, ['lineitem.readonly', 'score'], true, 7)
        );
        $this->learner_launch(
            $tool,
            $l3,
            self::$issuerb,
            'buw-client',
            'b-l3',
            'rl-b-2',
            self::ags_claim(self::$issuerb, ['lineitem.readonly'], true, 8)
        );
        $this->set_user_grade_for_resource_local((int) $l3->id, 40, $tool);
        $this->learner_launch($tool, $l4, self::$issuera, '123', 'a-l4', 'rl-a-1', $agsa);
        $this->set_user_grade_for_resource_local((int) $l4->id, 0, $tool);
        $this->run_core_grade_sync($tool, 401, time() - 1800);

        $links = [];
        foreach ($DB->get_records('enrol_lti_resource_link', ['resourceid' => $tool->id]) as $r) {
            $links[$r->resourcelinkid . ':' . $r->ltideploymentid] = (int) $r->id;
        }
        return (object) [
            'course' => $course, 'tool' => $tool, 'tool2' => $tool2, 'tool3' => $tool3,
            'rega' => $rega, 'depa' => $depa, 'regb' => $regb, 'depb' => $depb,
            'keya' => 'tool:' . $rega->get_id() . ':' . $depa->get_id(),
            'keyb' => 'tool:' . $regb->get_id() . ':' . $depb->get_id(),
            'users' => (object) ['l1' => $l1, 'l2' => $l2, 'l3' => $l3, 'l4' => $l4],
            'linka' => $links['rl-a-1:' . $depa->get_id()],
            'linkb1' => $links['rl-b-1:' . $depb->get_id()],
            'linkb2' => $links['rl-b-2:' . $depb->get_id()],
        ];
    }

    /**
     * Grade a user on a published activity or course (as core's own tests do).
     *
     * @param int $userid User.
     * @param float $grade Grade (of 100).
     * @param \stdClass $resource Published resource.
     * @return \grade_grade
     */
    protected function set_user_grade_for_resource_local(int $userid, float $grade, \stdClass $resource): \grade_grade {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $context = \context::instance_by_id($resource->contextid);
        if ($context->contextlevel == CONTEXT_COURSE) {
            $gi = \grade_item::fetch_course_item($resource->courseid);
        } else {
            $cm = get_coursemodule_from_id('assign', $context->instanceid);
            $gi = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $cm->instance,
                'courseid' => $resource->courseid]);
        }
        $g = \grade_grade::fetch(['itemid' => $gi->id, 'userid' => $userid]);
        if ($g) {
            $g->finalgrade = $grade;
            $g->rawgrade = $grade;
            $g->timemodified = time() - 7200;
            $g->update();
        } else {
            $g = new \grade_grade();
            $g->itemid = $gi->id;
            $g->userid = $userid;
            $g->rawgrade = $grade;
            $g->finalgrade = $grade;
            $g->rawgrademax = 100;
            $g->rawgrademin = 0;
            $g->timecreated = time() - 7200;
            $g->timemodified = time() - 7200;
            $g->insert();
        }
        return $g;
    }
}
