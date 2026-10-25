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

namespace tool_ltidiagnostics\tests;

/**
 * Helpers that create real LTI data with the core generators.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait lti_data {
    /**
     * Create a platform-side tool type (Moodle as platform) with LTI 1.3 settings.
     *
     * @param array $override Values that replace the defaults (type fields, lti_* and ltiservice_* config).
     * @return int The type id.
     */
    protected function create_platform_type(array $override = []): int {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $data = $override + [
            'name' => 'Diagnostics test tool',
            'baseurl' => 'https://tool.example.org/launch',
            'state' => LTI_TOOL_STATE_CONFIGURED,
            'lti_ltiversion' => LTI_VERSION_1P3,
            'lti_clientid' => 'client-' . random_string(8),
            'lti_keytype' => LTI_JWK_KEYSET,
            'lti_publickeyset' => 'https://tool.example.org/jwks.json',
            'lti_initiatelogin' => 'https://tool.example.org/login',
            'lti_redirectionuris' => 'https://tool.example.org/launch',
            'ltiservice_gradesynchronization' => 2,
            'ltiservice_memberships' => 1,
            'ltiservice_toolsettings' => 0,
        ];
        /** @var \mod_lti_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lti');
        return $generator->create_tool_types($data);
    }

    /**
     * Create a tool-side platform registration with one deployment (Moodle as tool).
     *
     * @param array $override Values that replace the defaults.
     * @return \stdClass Properties: regid, depid (row id), deploymentid, platformid.
     */
    protected function create_registration(array $override = []): \stdClass {
        global $DB;
        $data = $override + [
            'name' => 'Platform X',
            'platformid' => 'https://platform.example.org',
            'clientid' => 'tool-client-1',
            'authrequesturl' => 'https://platform.example.org/auth',
            'jwksurl' => 'https://platform.example.org/jwks',
            'accesstokenurl' => 'https://platform.example.org/token',
            'deploymentname' => 'Deployment 1',
            'deploymentid' => 'dep-' . random_string(6),
        ];
        /** @var \enrol_lti_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('enrol_lti');
        $registration = $generator->create_application_registration($data);
        $out = (object) ['regid' => (int) $registration->get_id(), 'platformid' => $data['platformid'],
            'deploymentid' => $data['deploymentid'], 'depid' => 0];
        if (isset($data['deploymentname'])) {
            $out->depid = (int) $DB->get_field('enrol_lti_deployment', 'id', [
                'platformid' => $out->regid, 'deploymentid' => $data['deploymentid'],
            ]);
        }
        return $out;
    }

    /**
     * Publish a course resource as an LTI 1.3 tool.
     *
     * @param \stdClass $course Course.
     * @param array $override Tool column overrides (gradesync, membersync, ...).
     * @return \stdClass The enrol_lti_tools record, with the enrol instance in property "instance".
     */
    protected function create_published_tool(\stdClass $course, array $override = []): \stdClass {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        /** @var \enrol_lti_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('enrol_lti');
        $tool = $generator->create_published_resource([
            'name' => 'Published page', 'courseid' => $course->id, 'activityid' => $page->cmid, 'ltiversion' => 'LTI-1p3',
        ]);
        if ($override) {
            $DB->update_record('enrol_lti_tools', (object) (['id' => $tool->id] + $override));
        }
        $tool = $DB->get_record('enrol_lti_tools', ['id' => $tool->id], '*', MUST_EXIST);
        $tool->instance = $DB->get_record('enrol', ['id' => $tool->enrolid], '*', MUST_EXIST);
        return $tool;
    }

    /**
     * Link a published tool to a deployment through a resource link row.
     *
     * @param int $depid Deployment row id.
     * @param int $toolid Published tool id.
     * @param array $override Resource link columns.
     * @return int The resource link row id.
     */
    protected function create_resource_link(int $depid, int $toolid, array $override = []): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('enrol_lti_resource_link', (object) ($override + [
            'resourcelinkid' => 'rl-' . random_string(6),
            'ltideploymentid' => $depid,
            'resourceid' => $toolid,
            'lticontextid' => null,
            'lineitemsservice' => null,
            'lineitemservice' => null,
            'lineitemscope' => null,
            'resultscope' => null,
            'scorescope' => null,
            'contextmembershipsurl' => null,
            'nrpsserviceversions' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]));
    }
}
