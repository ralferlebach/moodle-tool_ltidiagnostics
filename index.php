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
$courseid = optional_param('courseid', 0, PARAM_INT);
if (!in_array($role, ['', 'platform', 'tool'], true)) {
    $role = '';
}

admin_externalpage_setup(
    'tool_ltidiagnostics_index',
    '',
    null,
    new moodle_url('/admin/tool/ltidiagnostics/index.php', ['search' => $search, 'role' => $role, 'courseid' => $courseid])
);
require_capability('tool/ltidiagnostics:view', $context);

$reveal = \tool_ltidiagnostics\local\diagnostic_mode::can_reveal();
$form = new \tool_ltidiagnostics\form\filter_form(null, null, 'get');
$form->set_data(['search' => $search, 'role' => $role, 'courseid' => $courseid ?: '']);

/** @var \tool_ltidiagnostics\output\renderer $output */
$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo $output->heading(get_string('connections', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('connections_intro', 'tool_ltidiagnostics'));
if ($reveal) {
    echo $output->notification(get_string('diagmode_active', 'tool_ltidiagnostics', userdate(
        \tool_ltidiagnostics\local\diagnostic_mode::until()
    )), \core\output\notification::NOTIFY_WARNING);
}
$form->display();
$connections = (new \tool_ltidiagnostics\local\inventory())->get_connections($role, $search, $courseid);
echo $output->connection_list($connections, $reveal);

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
