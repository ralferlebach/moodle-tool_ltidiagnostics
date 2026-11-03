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
 * Command line fixture of the two-site grade stack (issues #3 to #9): Moodle 4.5 as LTI tool (provider), Moodle 5.2 as
 * LTI platform (consumer) with two tool types, the control consumer and the "BUW" consumer.
 *
 * Every step uses core APIs only; the stack is described by a JSON state file. Run with the PHP of the site:
 *   php grades_stack.php --config=/path/to/config.php --state=/path/state.json --action=<action> [--arg=...]
 *
 * Actions on the tool (4.5): tool1 (draft registrations, published assign, learner provisioning), tool2 (complete
 * registrations and deployments from the platform's values), grade (teacher grade of the learner), sync (run core's
 * scheduled and ad-hoc grade sync with the task log), toolstate (diagnosis facts). Actions on the platform (5.2):
 * platform (courses, learner, two LTI 1.3 tool types and activities), platformstate (gradebook of the learner in both
 * courses), futuregrade (move the consumer grade into the future to force a 409), deleteitem (delete the BUW grade item).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState
// Standalone CLI script of the test stack: it loads the config.php of the site given with --config.

define('CLI_SCRIPT', true);

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}
// Moodle's setup may change the working directory: resolve the state file first.
$statefile = $options['state'][0] === '/' ? $options['state'] : getcwd() . '/' . $options['state'];
require($options['config']);
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
if (is_file($CFG->dirroot . '/auth/lti/auth.php')) {
    require_once($CFG->dirroot . '/auth/lti/auth.php');
}

$state = is_file($statefile) ? json_decode(file_get_contents($statefile), true) : [];
$action = $options['action'];
\core\session\manager::set_user(get_admin());

/**
 * Save the state file.
 *
 * @param array $state State.
 * @param string $file File.
 */
function stack_save(array $state, string $file): void {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

switch ($action) {
    case 'tool1':
        // Tool side, phase 1: plugins, a course with a graded assignment published as LTI 1.3 tool, two draft
        // registrations (their unique ids go into the platform's initiate login URLs).
        $auth = get_config('core', 'auth');
        if (strpos((string) $auth, 'lti') === false) {
            set_config('auth', trim($auth . ',lti', ','));
        }
        \core\plugininfo\enrol::enable_plugin('lti', 1);
        set_config('allowprivatetargets', 1, 'tool_ltidiagnostics');
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        set_config('task_logmode', 1);
        $gen = new \testing_data_generator();
        if ($old = $DB->get_record('course', ['shortname' => 'toolcourse'])) {
            delete_course($old, false);
        }
        $course = $gen->create_course(['fullname' => 'Tool course', 'shortname' => 'toolcourse', 'enablecompletion' => 1]);
        $assign = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100, 'name' => 'Graded task']);
        $tool = $gen->create_lti_tool((object) ['cmid' => $assign->cmid, 'courseid' => $course->id, 'gradesync' => 1,
            'ltiversion' => 'LTI-1p3', 'provisioningmodelearner' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY,
            'provisioningmodeinstructor' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY, 'membersync' => 0]);
        $tool = \enrol_lti\helper::get_lti_tool($tool->id);
        $repo = new \enrol_lti\local\ltiadvantage\repository\application_registration_repository();
        $state['tool'] = ['courseid' => (int) $course->id, 'cmid' => (int) $assign->cmid, 'assignid' => (int) $assign->id,
            'toolid' => (int) $tool->id, 'uuid' => $tool->uuid, 'url' => $CFG->wwwroot, 'registrations' => []];
        foreach (['control' => 'Control consumer', 'buw' => 'BUW consumer'] as $k => $name) {
            $reg = $repo->save(\enrol_lti\local\ltiadvantage\entity\application_registration::create_draft(
                $name,
                bin2hex(random_bytes(10))
            ));
            $state['tool']['registrations'][$k] = ['id' => (int) $reg->get_id(), 'uniqueid' => $reg->get_uniqueid(),
                'name' => $name];
        }
        $teacher = $DB->get_record('user', ['username' => 'toolteacher']) ?:
            $gen->create_user(['username' => 'toolteacher', 'password' => 'Teacher123!']);
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        stack_save($state, $statefile);
        break;

    case 'platform':
        // Platform side: two courses, one learner in both, two LTI 1.3 tool types (control, BUW) with grade services,
        // an LTI activity of each type with 100 points.
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $gen = new \testing_data_generator();
        $toolurl = $state['tool']['url'];
        foreach (['controlcourse', 'buwcourse'] as $short) {
            if ($old = $DB->get_record('course', ['shortname' => $short])) {
                delete_course($old, false);
            }
        }
        $DB->delete_records_select('lti_types', "name IN ('Control tool', 'BUW tool')");
        $learner = $DB->get_record('user', ['username' => 'learner1']) ?: $gen->create_user([
            'username' => 'learner1', 'password' => 'Learner123!', 'firstname' => 'Lena', 'lastname' => 'Learner',
            'email' => 'learner1@example.org',
        ]);
        $state['platform'] = ['url' => $CFG->wwwroot, 'issuer' => $CFG->wwwroot, 'learnerid' => (int) $learner->id,
            'types' => []];
        foreach (['control' => 'Control', 'buw' => 'BUW'] as $k => $label) {
            $course = $gen->create_course(['fullname' => "$label course", 'shortname' => strtolower($label) . 'course']);
            $gen->enrol_user($learner->id, $course->id, 'student');
            $uniqueid = $state['tool']['registrations'][$k]['uniqueid'];
            $type = new \stdClass();
            $type->state = LTI_TOOL_STATE_CONFIGURED;
            $type->course = SITEID;
            $type->coursevisible = LTI_COURSEVISIBLE_ACTIVITYCHOOSER;
            $config = new \stdClass();
            $config->lti_typename = "$label tool";
            $config->lti_toolurl = $toolurl . '/enrol/lti/launch.php';
            $config->lti_ltiversion = LTI_VERSION_1P3;
            $config->lti_keytype = LTI_JWK_KEYSET;
            $config->lti_publickeyset = $toolurl . '/enrol/lti/jwks.php';
            $config->lti_initiatelogin = $toolurl . '/enrol/lti/login.php?id=' . $uniqueid;
            $config->lti_redirectionuris = $toolurl . '/enrol/lti/launch.php';
            $config->lti_customparameters = 'id=' . $state['tool']['uuid'];
            $config->lti_coursevisible = LTI_COURSEVISIBLE_ACTIVITYCHOOSER;
            $config->lti_launchcontainer = LTI_LAUNCH_CONTAINER_WINDOW;
            $config->lti_sendname = LTI_SETTING_ALWAYS;
            $config->lti_sendemailaddr = LTI_SETTING_ALWAYS;
            $config->lti_acceptgrades = LTI_SETTING_ALWAYS;
            $config->ltiservice_gradesynchronization = 2;
            $config->ltiservice_memberships = 0;
            $config->ltiservice_toolsettings = 0;
            $typeid = lti_add_type($type, $config);
            $clientid = $DB->get_field('lti_types', 'clientid', ['id' => $typeid]);
            $lti = $gen->create_module('lti', ['course' => $course->id, 'typeid' => $typeid, 'name' => "$label activity",
                'grade' => 100, 'instructorchoiceacceptgrades' => 1, 'resourcekey' => '', 'password' => '',
                'toolurl' => $toolurl . '/enrol/lti/launch.php']);
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'lti', 'iteminstance' => $lti->id,
                'courseid' => $course->id]);
            $state['platform']['types'][$k] = ['typeid' => (int) $typeid, 'clientid' => $clientid,
                'courseid' => (int) $course->id, 'cmid' => (int) $lti->cmid, 'ltiid' => (int) $lti->id,
                'itemid' => $item ? (int) $item->id : 0];
        }
        stack_save($state, $statefile);
        break;

    case 'tool2':
        // Tool side, phase 2: complete the registrations with the platform's values and add the deployments.
        $repo = new \enrol_lti\local\ltiadvantage\repository\application_registration_repository();
        $deprepo = new \enrol_lti\local\ltiadvantage\repository\deployment_repository();
        $p = $state['platform'];
        foreach ($state['tool']['registrations'] as $k => $r) {
            $reg = $repo->find($r['id']);
            $reg->set_platformid(new \moodle_url($p['issuer']));
            $reg->set_clientid($p['types'][$k]['clientid']);
            $reg->set_authenticationrequesturl(new \moodle_url($p['url'] . '/mod/lti/auth.php'));
            $reg->set_jwksurl(new \moodle_url($p['url'] . '/mod/lti/certs.php'));
            $reg->set_accesstokenurl(new \moodle_url($p['url'] . '/mod/lti/token.php'));
            $reg->complete_registration();
            $reg = $repo->save($reg);
            $dep = $deprepo->save($reg->add_tool_deployment($r['name'] . ' deployment', (string) $p['types'][$k]['typeid']));
            $state['tool']['registrations'][$k]['deploymentrowid'] = (int) $dep->get_id();
            $state['tool']['registrations'][$k]['key'] = 'tool:' . $r['id'] . ':' . $dep->get_id();
        }
        stack_save($state, $statefile);
        break;

    case 'grade':
        // The teacher grades the learner on the tool (value from --arg, of 100).
        $userid = (int) $DB->get_field('enrol_lti_users', 'userid', ['toolid' => $state['tool']['toolid']], IGNORE_MULTIPLE);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $state['tool']['assignid'],
            'courseid' => $state['tool']['courseid']]);
        $item->update_final_grade($userid, (float) $options['arg'], 'stack');
        $state['tool']['learnerid'] = $userid;
        stack_save($state, $statefile);
        echo json_encode(['userid' => $userid, 'grade' => (float) $options['arg']]) . "\n";
        break;

    case 'sync':
        // Core's grade sync exactly as cron runs it: the scheduled task queues one ad-hoc task per resource, the ad-hoc
        // runner sends; both are written to the task log.
        $task = \core\task\manager::get_scheduled_task('\\enrol_lti\\local\\ltiadvantage\\task\\sync_grades');
        $task->execute();
        while ($adhoc = \core\task\manager::get_next_adhoc_task(time())) {
            \core\task\manager::adhoc_task_starting($adhoc);
            $logger = \core\task\logmanager::start_logging($adhoc);
            try {
                $adhoc->execute();
                \core\task\logmanager::finalise_log(false);
                \core\task\manager::adhoc_task_complete($adhoc);
            } catch (\Throwable $e) {
                \core\task\logmanager::finalise_log(true);
                \core\task\manager::adhoc_task_failed($adhoc);
            }
        }
        break;

    case 'replaytask':
        // Run the queued grade replay tasks of tool_ltidiagnostics (what cron does).
        $n = 0;
        foreach ($DB->get_records('task_adhoc', ['classname' => '\\tool_ltidiagnostics\\task\\grade_repair_task']) as $r) {
            $DB->set_field('task_adhoc', 'nextruntime', time() - 1, ['id' => $r->id]);
        }
        while ($adhoc = \core\task\manager::get_next_adhoc_task(time())) {
            \core\task\manager::adhoc_task_starting($adhoc);
            try {
                $adhoc->execute();
                \core\task\manager::adhoc_task_complete($adhoc);
            } catch (\Throwable $e) {
                \core\task\manager::adhoc_task_failed($adhoc);
            }
            $n++;
        }
        echo json_encode(['ran' => $n]) . "\n";
        break;

    case 'manylearners':
        // Paging fixture (issues #21, #22): --arg more learners mapped to the Control resource link, as enrol_lti stores
        // them after launches (LTI user, user resource link, auth_lti binding), with grades 65 (every third), 0 (every
        // third) and none, and core's lastgrade at 0.
        $n = (int) ($options['arg'] ?? 60);
        $gen = new \testing_data_generator();
        $control = $state['tool']['registrations']['control'];
        $linkid = (int) $DB->get_field(
            'enrol_lti_resource_link',
            'id',
            ['ltideploymentid' => $control['deploymentrowid']],
            IGNORE_MULTIPLE
        );
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $state['tool']['assignid'],
            'courseid' => $state['tool']['courseid']]);
        $issuer = (string) $DB->get_field('enrol_lti_app_registration', 'platformid', ['id' => $control['id']]);
        $made = 0;
        for ($i = 1; $i <= $n; $i++) {
            $username = 'pager' . $i;
            if ($DB->record_exists('user', ['username' => $username])) {
                continue;
            }
            $u = $gen->create_user(['username' => $username, 'firstname' => 'Paula', 'lastname' => sprintf('Pager %03d', $i),
                'auth' => 'lti']);
            $luid = $DB->insert_record('enrol_lti_users', (object) ['userid' => $u->id, 'toolid' => $state['tool']['toolid'],
                'ltideploymentid' => $control['deploymentrowid'], 'sourceid' => 'pager-' . $i, 'lastgrade' => 0,
                'timecreated' => time(), 'lastaccess' => time()]);
            $DB->insert_record('enrol_lti_user_resource_link', (object) ['ltiuserid' => $luid, 'resourcelinkid' => $linkid]);
            if (class_exists('\\auth_plugin_lti')) {
                get_auth_plugin('lti')->create_user_binding($issuer, 'pager-' . $i, (int) $u->id);
            }
            if ($i % 3 === 1) {
                $item->update_final_grade($u->id, 65, 'stack');
            } else if ($i % 3 === 2) {
                $item->update_final_grade($u->id, 0, 'stack');
            }
            $made++;
        }
        echo json_encode(['added' => $made, 'link' => $linkid]) . "\n";
        break;

    case 'toolstate':
        $l = [];
        foreach ($DB->get_records('enrol_lti_users', ['toolid' => $state['tool']['toolid']]) as $u) {
            $l[] = ['userid' => (int) $u->userid, 'lastgrade' => $u->lastgrade, 'deployment' => (int) $u->ltideploymentid];
        }
        $journal = array_values($DB->get_records('tool_ltidiagnostics_gatt', null, 'id', 'id, attemptid, origin, source,
            connkey, resourcelinkrowid, state, httpstatus, errorclass, bookingstate, agstimestamp, stateversion'));
        echo json_encode(['ltiusers' => $l, 'links' => array_values($DB->get_records(
            'enrol_lti_resource_link',
            null,
            'id',
            'id, resourcelinkid, ltideploymentid, lineitemservice, scorescope, resultscope'
        )), 'journal' => $journal]) . "\n";
        break;

    case 'platformstate':
        $out = [];
        foreach ($state['platform']['types'] as $k => $t) {
            $g = $DB->get_record('grade_grades', ['itemid' => $t['itemid'], 'userid' => $state['platform']['learnerid']]);
            $out[$k] = $g ? ['finalgrade' => $g->finalgrade === null ? null : (float) $g->finalgrade,
                'timemodified' => (int) $g->timemodified] : null;
        }
        echo json_encode($out) . "\n";
        break;

    case 'viewer':
        // Tool side: a read-only diagnostics viewer (view and export, no configure, no repair).
        $gen = new \testing_data_generator();
        $viewer = $DB->get_record('user', ['username' => 'diagviewer']) ?:
            $gen->create_user(['username' => 'diagviewer', 'password' => 'Viewer123!', 'firstname' => 'Vera',
                'lastname' => 'Viewer']);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'diagviewer']) ?:
            create_role('Diagnostics viewer', 'diagviewer', '');
        $system = \context_system::instance();
        assign_capability('tool/ltidiagnostics:view', CAP_ALLOW, $roleid, $system->id, true);
        assign_capability('tool/ltidiagnostics:export', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $viewer->id, $system->id);
        break;

    case 'platformgrade':
        // Consumer side: a teacher changes the grade of the learner in an activity (--arg=<type>:<value>).
        [$k, $value] = explode(':', $options['arg']);
        $t = $state['platform']['types'][$k];
        $item = \grade_item::fetch(['id' => $t['itemid']]);
        $item->update_final_grade($state['platform']['learnerid'], (float) $value, 'stack');
        break;

    case 'csyncendpoint':
        // Tool side: point the completion sender of the scenario at another endpoint (--arg=URL), e.g. a closed port.
        $con = $DB->get_record('local_lticompletionsync_con', ['role' => 'sender',
            'deploymentid' => (int) explode(':', $state['completion']['toolkey'])[2]], '*', MUST_EXIST);
        $state['completion']['endpoint'] = $state['completion']['endpoint'] ?? $con->endpointurl;
        $DB->set_field('local_lticompletionsync_con', 'endpointurl', $options['arg'] === 'restore' ?
            $state['completion']['endpoint'] : $options['arg'], ['id' => $con->id]);
        stack_save($state, $statefile);
        break;

    case 'csyncreceiver':
        // Platform side: receiver connection of the scenario (--arg=enabled:0|1 or policy:local|provider).
        [$field, $value] = explode(':', $options['arg']);
        $DB->set_field(
            'local_lticompletionsync_con',
            $field === 'policy' ? 'conflictpolicy' : 'enabled',
            $value,
            ['role' => 'receiver', 'ltitypeid' => $state['completion']['typeid']]
        );
        break;

    case 'completion':
        // Set the completion of the learner (--arg=0|1) on this site's activity of the scenario (tool: published page,
        // platform: external tool activity, as a teacher's override).
        $cmid = (int) ($CFG->wwwroot === $state['tool']['url'] ? $state['completion']['pagecmid'] :
            $state['completion']['platformcmid']);
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        $user = $DB->get_record('user', ['username' => $state['completion']['learner']]);
        if ($CFG->wwwroot === $state['tool']['url']) {
            $user = $DB->get_record_sql("SELECT u.* FROM {user} u JOIN {local_lticompletionsync_out} o ON o.userid = u.id
                WHERE o.sourceid = ?", [$cmid], MUST_EXIST);
        }
        (new \completion_info(get_course($courseid)))->update_state($cm, (int) $options['arg'] ? COMPLETION_COMPLETE :
            COMPLETION_INCOMPLETE, $user->id, true);
        break;

    case 'noags':
        // Second defective BUW constellation: a third consumer (tool type "BUW without AGS") that launches the same
        // published resource without the grade service (gradesynchronization 0). --arg=tool1 (tool: draft
        // registration), platform (platform: type, course, activity), tool2 (tool: complete registration).
        $step = $options['arg'];
        if ($step === 'tool1') {
            $repo = new \enrol_lti\local\ltiadvantage\repository\application_registration_repository();
            $reg = $repo->save(\enrol_lti\local\ltiadvantage\entity\application_registration::create_draft(
                'BUW without AGS',
                bin2hex(random_bytes(10))
            ));
            $state['tool']['registrations']['noags'] = ['id' => (int) $reg->get_id(), 'uniqueid' => $reg->get_uniqueid(),
                'name' => 'BUW without AGS'];
        } else if ($step === 'platform') {
            require_once($CFG->dirroot . '/mod/lti/locallib.php');
            $gen = new \testing_data_generator();
            $toolurl = $state['tool']['url'];
            $course = $gen->create_course(['fullname' => 'BUW no AGS course', 'shortname' => 'buwnoagscourse']);
            $gen->enrol_user($state['platform']['learnerid'], $course->id, 'student');
            $type = (object) ['state' => LTI_TOOL_STATE_CONFIGURED, 'course' => SITEID,
                'coursevisible' => LTI_COURSEVISIBLE_ACTIVITYCHOOSER];
            $config = (object) ['lti_typename' => 'BUW tool without AGS', 'lti_toolurl' => $toolurl . '/enrol/lti/launch.php',
                'lti_ltiversion' => LTI_VERSION_1P3, 'lti_keytype' => LTI_JWK_KEYSET,
                'lti_publickeyset' => $toolurl . '/enrol/lti/jwks.php',
                'lti_initiatelogin' => $toolurl . '/enrol/lti/login.php?id=' . $state['tool']['registrations']['noags']['uniqueid'],
                'lti_redirectionuris' => $toolurl . '/enrol/lti/launch.php',
                'lti_customparameters' => 'id=' . $state['tool']['uuid'],
                'lti_coursevisible' => LTI_COURSEVISIBLE_ACTIVITYCHOOSER, 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_WINDOW,
                'lti_sendname' => LTI_SETTING_ALWAYS, 'lti_sendemailaddr' => LTI_SETTING_ALWAYS,
                'lti_acceptgrades' => LTI_SETTING_NEVER, 'ltiservice_gradesynchronization' => 0,
                'ltiservice_memberships' => 0, 'ltiservice_toolsettings' => 0];
            $typeid = lti_add_type($type, $config);
            $lti = $gen->create_module('lti', ['course' => $course->id, 'typeid' => $typeid, 'name' => 'BUW no AGS activity',
                'grade' => 0, 'resourcekey' => '', 'password' => '', 'toolurl' => $toolurl . '/enrol/lti/launch.php']);
            $state['platform']['types']['noags'] = ['typeid' => (int) $typeid,
                'clientid' => $DB->get_field('lti_types', 'clientid', ['id' => $typeid]), 'courseid' => (int) $course->id,
                'cmid' => (int) $lti->cmid, 'ltiid' => (int) $lti->id, 'itemid' => 0];
        } else {
            $repo = new \enrol_lti\local\ltiadvantage\repository\application_registration_repository();
            $deprepo = new \enrol_lti\local\ltiadvantage\repository\deployment_repository();
            $p = $state['platform'];
            $r = $state['tool']['registrations']['noags'];
            $reg = $repo->find($r['id']);
            $reg->set_platformid(new \moodle_url($p['issuer']));
            $reg->set_clientid($p['types']['noags']['clientid']);
            $reg->set_authenticationrequesturl(new \moodle_url($p['url'] . '/mod/lti/auth.php'));
            $reg->set_jwksurl(new \moodle_url($p['url'] . '/mod/lti/certs.php'));
            $reg->set_accesstokenurl(new \moodle_url($p['url'] . '/mod/lti/token.php'));
            $reg->complete_registration();
            $reg = $repo->save($reg);
            $dep = $deprepo->save($reg->add_tool_deployment($r['name'] . ' deployment', (string) $p['types']['noags']['typeid']));
            $state['tool']['registrations']['noags']['deploymentrowid'] = (int) $dep->get_id();
            $state['tool']['registrations']['noags']['key'] = 'tool:' . $r['id'] . ':' . $dep->get_id();
        }
        stack_save($state, $statefile);
        break;

    case 'futuregrade':
        // Consumer side: the grade of the BUW activity carries a newer timestamp than any replay can issue (409 case).
        $t = $state['platform']['types'][$options['arg']];
        $DB->set_field('grade_grades', 'timemodified', time() + DAYSECS, ['itemid' => $t['itemid'],
            'userid' => $state['platform']['learnerid']]);
        break;

    default:
        throw new \coding_exception('Unknown action ' . $action);
}
