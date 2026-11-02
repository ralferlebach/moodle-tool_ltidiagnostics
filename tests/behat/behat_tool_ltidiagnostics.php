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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Steps of tool_ltidiagnostics: a tool side grade scenario with a control consumer and a BUW-like consumer.
 *
 * The data is what enrol_lti stores after real launches and two runs of its grade sync task (registrations,
 * deployments, resource links with their AGS claims, the LTI user record with lastgrade and sourceid, auth_lti links,
 * the gradebook and the task log), written with core's repositories and generators.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_ltidiagnostics extends behat_base {
    /**
     * Create the scenario for a learner.
     *
     * @Given /^the LTI grade scenario exists for "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $username Learner.
     */
    public function the_lti_grade_scenario_exists_for(string $username): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $gen = behat_util::get_data_generator();
        $auth = get_config('core', 'auth');
        set_config('auth', $auth ? $auth . ',lti' : 'lti');
        \core_plugin_manager::reset_caches();
        $enrols = explode(',', (string) $CFG->enrol_plugins_enabled);
        if (!in_array('lti', $enrols, true)) {
            $enrols[] = 'lti';
            set_config('enrol_plugins_enabled', implode(',', $enrols));
        }
        $learner = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $course = $gen->create_course(['fullname' => 'LTI course', 'shortname' => 'lticourse']);
        $assign = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100, 'name' => 'Graded task']);
        $tool = $gen->create_lti_tool((object) ['cmid' => $assign->cmid, 'courseid' => $course->id, 'gradesync' => 1,
            'ltiversion' => 'LTI-1p3']);
        /** @var enrol_lti_generator $lti */
        $lti = $gen->get_plugin_generator('enrol_lti');
        $deps = [];
        $consumers = [['Control LMS', 'https://lms.example.org', '123'], ['BUW Moodle', 'https://buw.example.org', 'buw-client']];
        foreach ($consumers as [$name, $issuer, $client]) {
            $reg = $lti->create_application_registration(['name' => $name, 'platformid' => $issuer, 'clientid' => $client,
                'authrequesturl' => $issuer . '/mod/lti/auth.php', 'jwksurl' => $issuer . '/mod/lti/certs.php',
                'accesstokenurl' => $issuer . '/mod/lti/token.php', 'deploymentname' => $name . ' deployment',
                'deploymentid' => '1']);
            $deps[$issuer] = (int) $DB->get_field('enrol_lti_deployment', 'id', ['platformid' => $reg->get_id()]);
            $sub = $issuer === 'https://lms.example.org' ? 'a-l1' : 'b-l1';
            get_auth_plugin('lti')->create_user_binding($issuer, $sub, (int) $learner->id);
        }
        $ltiuserid = $DB->insert_record('enrol_lti_users', (object) ['userid' => $learner->id, 'toolid' => $tool->id,
            'sourceid' => 'a-l1', 'ltideploymentid' => $deps['https://lms.example.org'], 'lastgrade' => 65,
            'lastaccess' => time(), 'timecreated' => time()]);
        $scope = 'https://purl.imsglobal.org/spec/lti-ags/scope/';
        $links = [];
        $rlinks = [['rl-a-1', 'https://lms.example.org', 45, true], ['rl-b-1', 'https://buw.example.org', 7, false]];
        foreach ($rlinks as [$rlid, $issuer, $item, $result]) {
            $links[$rlid] = $DB->insert_record('enrol_lti_resource_link', (object) [
                'resourcelinkid' => $rlid, 'ltideploymentid' => $deps[$issuer], 'resourceid' => $tool->id,
                'lineitemsservice' => $issuer . '/mod/lti/services.php/2/lineitems?type_id=1',
                'lineitemservice' => $issuer . '/mod/lti/services.php/2/lineitems/' . $item . '/lineitem?type_id=1',
                'lineitemscope' => json_encode([$scope . ($result ? 'lineitem' : 'lineitem.readonly')]),
                'resultscope' => $result ? $scope . 'result.readonly' : null,
                'scorescope' => $scope . 'score', 'timecreated' => time(), 'timemodified' => time(),
            ]);
            $DB->insert_record('enrol_lti_user_resource_link', (object) ['ltiuserid' => $ltiuserid,
                'resourcelinkid' => $links[$rlid]]);
        }
        $gi = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $course->id]);
        $gi->update_final_grade((int) $learner->id, 65, 'test');
        $subject = "for the user '{$learner->id}', for the resource '{$tool->id}' and the course '{$course->id}'";
        $runs = [
            time() - 3600 => ["Processing resource link 'rl-a-1'.", "Success - The grade '0.65' $subject was sent."],
            time() - 1800 => ["Processing resource link 'rl-a-1'.",
                "Not sent - The grade $subject was not sent as the grades are the same.",
                "Processing resource link 'rl-b-1'.",
                "Not sent - The grade $subject was not sent as the grades are the same."],
        ];
        foreach ($runs as $time => $lines) {
            $DB->insert_record('task_log', (object) ['type' => 1, 'component' => 'enrol_lti',
                'classname' => 'enrol_lti\\local\\ltiadvantage\\task\\sync_tool_grades', 'userid' => 0, 'timestart' => $time,
                'timeend' => $time + 1, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 0, 'hostname' => 'behat', 'pid' => 1,
                'output' => implode("\n", array_merge(["Starting - LTI Advantage grade sync for shared resource " .
                    "'{$tool->id}' in course '{$course->id}'.", "Found 2 resource link(s) $subject. Attempting to sync " .
                    "grades for all."], $lines))]);
        }
    }

    /**
     * Open a tab of a tool side connection by the name of its registration.
     *
     * @Given /^I open the LTI diagnostics tab "(?P<tab_string>[a-z]+)" of registration "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $tab Tab.
     * @param string $name Registration name.
     */
    public function i_open_the_lti_diagnostics_tab_of_registration(string $tab, string $name): void {
        global $DB;
        $reg = $DB->get_record('enrol_lti_app_registration', ['name' => $name], '*', MUST_EXIST);
        $dep = $DB->get_field('enrol_lti_deployment', 'id', ['platformid' => $reg->id], MUST_EXIST);
        $params = ['key' => 'tool:' . $reg->id . ':' . $dep, 'tab' => $tab];
        $url = new moodle_url('/admin/tool/ltidiagnostics/connection.php', $params);
        $this->execute('behat_general::i_visit', [$url]);
    }

    /**
     * Open the replay preview of a learner at the first resource link of a registration.
     *
     * @Given /^I open the LTI grade replay preview of registration "(?P<name>(?:[^"]|\\")*)" for "(?P<username>(?:[^"]|\\")*)"$/
     * @param string $name Registration name.
     * @param string $username Learner.
     */
    public function i_open_the_lti_grade_replay_preview(string $name, string $username): void {
        global $DB;
        $reg = $DB->get_record('enrol_lti_app_registration', ['name' => $name], '*', MUST_EXIST);
        $dep = $DB->get_field('enrol_lti_deployment', 'id', ['platformid' => $reg->id], MUST_EXIST);
        $link = $DB->get_field('enrol_lti_resource_link', 'id', ['ltideploymentid' => $dep], MUST_EXIST);
        $user = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $params = ['key' => 'tool:' . $reg->id . ':' . $dep, 'link' => $link, 'user' => $user];
        $this->execute('behat_general::i_visit', [new moodle_url('/admin/tool/ltidiagnostics/repair.php', $params)]);
    }

    /**
     * Check the raw body of the current response (a downloaded file such as the support package is not an HTML page).
     *
     * @Then /^the LTI download should( not)? contain "(?P<text>(?:[^"]|\\")*)"$/
     * @param string $not " not" or empty.
     * @param string $text Text.
     */
    public function the_lti_download_should_contain(string $not, string $text): void {
        $content = $this->getSession()->getPage()->getContent();
        $found = strpos($content, $text) !== false;
        if ($found === ($not !== '')) {
            throw new \Behat\Mink\Exception\ExpectationException(
                '"' . $text . '"' . ($not ? ' was found in' : ' was not found in') . ' the download',
                $this->getSession()
            );
        }
    }
}
