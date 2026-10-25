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
 * Audit log of views and exports of diagnostic data (privacy officers).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_ltidiagnostics_audit', '', null, new moodle_url('/admin/tool/ltidiagnostics/audit.php'));
require_capability('tool/ltidiagnostics:viewprivacy', context_system::instance());

$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo $output->heading(get_string('auditlog', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('auditlog_desc', 'tool_ltidiagnostics', (int) \tool_ltidiagnostics\local\config::get(
    'retentiondays'
)));
$entries = \tool_ltidiagnostics\local\audit::recent(200);
$userids = array_unique(array_map(function ($e) {
    return (int) $e->userid;
}, $entries));
$users = $userids ? $DB->get_records_list('user', 'id', $userids, '', implode(',', \core_user\fields::get_name_fields())
    . ',id') : [];
$table = new html_table();
$table->attributes['class'] = 'generaltable table-sm';
$table->caption = get_string('auditlog', 'tool_ltidiagnostics');
$table->head = [get_string('time'), get_string('user'), get_string('action', 'tool_ltidiagnostics'),
    get_string('connection', 'tool_ltidiagnostics'), get_string('detail', 'tool_ltidiagnostics')];
foreach ($entries as $e) {
    $table->data[] = [userdate($e->timecreated), isset($users[$e->userid]) ? fullname($users[$e->userid]) : (int) $e->userid,
        s($e->action), s($e->connkey), s($e->detail)];
}
echo $table->data ? html_writer::table($table) : html_writer::tag('p', get_string('norows', 'tool_ltidiagnostics'));
echo $output->footer();
