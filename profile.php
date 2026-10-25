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
 * Desired-state profile of a connection: edit it, or import and export it as JSON.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\profile_manager;

$key = required_param('key', PARAM_RAW);
$url = new moodle_url('/admin/tool/ltidiagnostics/profile.php', ['key' => $key]);
admin_externalpage_setup('tool_ltidiagnostics_index', '', null, $url);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:configure', $context);
if (connection::parse_key($key) === null || (new inventory())->get_connection($key) === null) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$back = new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => 'health']);

$form = new \tool_ltidiagnostics\form\profile_form($url, ['key' => $key]);
if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    [$id, $errors] = profile_manager::save($key, \tool_ltidiagnostics\form\profile_form::data_to_json($data));
    if ($id) {
        audit::log('profilesave', $key);
        redirect($back, get_string('profilesaved', 'tool_ltidiagnostics'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($url, get_string('profilenotsaved', 'tool_ltidiagnostics'), null, \core\output\notification::NOTIFY_ERROR);
}
$existing = profile_manager::get($key);
$form->set_data(\tool_ltidiagnostics\form\profile_form::profile_to_defaults($existing));

$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo $output->heading(get_string('desiredprofile', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('desiredprofile_desc', 'tool_ltidiagnostics'));
$form->display();
if ($existing) {
    echo $output->heading(get_string('profilejson', 'tool_ltidiagnostics'), 3);
    echo html_writer::tag('pre', s($existing->to_json()), ['id' => 'tool_ltidiagnostics_profilejson']);
}
echo $output->footer();
