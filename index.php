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
 * Connection selection: search and choose a platform tool type or a tool registration.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$context = context_system::instance();
require_login(null, false);
if (!has_capability('tool/ltidiagnostics:view', $context) && has_capability('tool/ltidiagnostics:viewsupport', $context)) {
    redirect(new moodle_url('/admin/tool/ltidiagnostics/support.php'));
}
$search = optional_param('search', '', PARAM_TEXT);
$role = optional_param('role', '', PARAM_ALPHA);
$courseid = max(0, optional_param('courseid', 0, PARAM_INT));
$cmid = max(0, optional_param('cmid', 0, PARAM_INT));
if (!in_array($role, ['', 'platform', 'tool'], true)) {
    $role = '';
}
$params = array_filter(['search' => $search, 'role' => $role, 'courseid' => $courseid, 'cmid' => $cmid]);

admin_externalpage_setup(
    'tool_ltidiagnostics_index',
    '',
    null,
    new moodle_url('/admin/tool/ltidiagnostics/index.php', $params)
);
require_capability('tool/ltidiagnostics:view', $context);

$reveal = \tool_ltidiagnostics\local\diagnostic_mode::can_reveal();
// The detail pages lead back here with the same filters (issue #12).
\tool_ltidiagnostics\local\navigation::remember_overview($params);
// Course and activity filter (issue #29): only connections with a proven use in that course or activity. An unknown
// or contradictory course or activity shows nothing (fail closed), never the unfiltered list.
$scope = \tool_ltidiagnostics\local\scope::make($courseid, $cmid);
$inventory = new \tool_ltidiagnostics\local\inventory();
$candidates = $inventory->get_connections($role, $search);

/** @var \tool_ltidiagnostics\output\renderer $output */
$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo $output->heading(get_string('connections', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('connections_intro', 'tool_ltidiagnostics'));
$quick = [];
if (has_capability('tool/ltidiagnostics:viewsupport', $context)) {
    $label = get_string('support', 'tool_ltidiagnostics');
    $quick[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/support.php'), $label);
}
if (has_capability('tool/ltidiagnostics:viewprivacy', $context)) {
    $label = get_string('auditlog', 'tool_ltidiagnostics');
    $quick[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/audit.php'), $label);
}
if (has_capability('tool/ltidiagnostics:importlogs', $context)) {
    $label = get_string('logimport', 'tool_ltidiagnostics');
    $quick[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/logimport.php'), $label);
}
if ($quick) {
    echo html_writer::tag('p', implode(' · ', $quick), ['class' => 'small', 'id' => 'tool_ltidiagnostics_quicklinks']);
}
if ($reveal) {
    echo $output->notification(get_string('diagmode_active', 'tool_ltidiagnostics', userdate(
        \tool_ltidiagnostics\local\diagnostic_mode::until()
    )), \core\output\notification::NOTIFY_WARNING);
}
echo $output->overview_filter($candidates, $params, $scope);
if ($scope->invalid) {
    // The filter shows why nothing is listed.
    echo html_writer::tag('p', get_string('noconnections_invalidscope', 'tool_ltidiagnostics'), [
        'class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_noconnections']);
} else {
    $connections = ($scope->courseid || $scope->cmid)
        ? \tool_ltidiagnostics\local\inventory::filter_by_scope($candidates, (int) $scope->courseid, (int) $scope->cmid)
        : $candidates;
    if (!$connections && ($scope->courseid || $scope->cmid)) {
        echo html_writer::tag('p', get_string('noconnections_scope', 'tool_ltidiagnostics'), [
            'class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_noconnections']);
    } else {
        echo $output->connection_list($connections, $reveal, (int) $scope->courseid, (int) $scope->cmid);
    }
}

// Diagnostic mode switch.
if (has_capability('tool/ltidiagnostics:configure', $context)) {
    echo $output->heading(get_string('diagmode', 'tool_ltidiagnostics'), 3);
    echo html_writer::tag('p', get_string('diagmode_desc', 'tool_ltidiagnostics'));
    $modeurl = new moodle_url('/admin/tool/ltidiagnostics/mode.php');
    if ($reveal) {
        echo $output->single_button(
            $modeurl,
            get_string('diagmode_disable', 'tool_ltidiagnostics'),
            'post',
            ['params' => ['action' => 'disable']]
        );
    } else {
        echo $output->single_button(
            $modeurl,
            get_string('diagmode_enable', 'tool_ltidiagnostics'),
            'post',
            ['params' => ['action' => 'enable', 'minutes' => 60]]
        );
    }
}
echo $output->footer();
