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
 * Teacher view: anonymous aggregate of LTI related activity in one course. No users, no configuration.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_ltidiagnostics\local\observation_store;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('tool/ltidiagnostics:viewcourse', $context);

$url = new moodle_url('/admin/tool/ltidiagnostics/course.php', ['id' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('courseview', 'tool_ltidiagnostics'));
$PAGE->set_heading($course->fullname);

\tool_ltidiagnostics\local\audit::log('view_course', '', 'course=' . $courseid);
$since = time() - 30 * DAYSECS;
$rows = observation_store::get_for_course($courseid, ['from' => $since, 'limit' => 1000]);
$counts = observation_store::count_by_type($rows);

$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo $output->heading(get_string('courseview', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('courseview_desc', 'tool_ltidiagnostics'));
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->caption = get_string('courseview', 'tool_ltidiagnostics');
$table->head = [get_string('eventtype', 'tool_ltidiagnostics'), get_string('count', 'tool_ltidiagnostics')];
foreach ($counts as $type => $n) {
    $table->data[] = [get_string('eventtype_' . $type, 'tool_ltidiagnostics'), $n];
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', get_string('norows', 'tool_ltidiagnostics'));
}
echo html_writer::tag('p', get_string('limit_absencenotevidence', 'tool_ltidiagnostics'), ['class' => 'text-muted']);
echo $output->footer();
