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
 * Library callbacks of tool_ltidiagnostics.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the aggregate diagnostics page to the course navigation for users who may see it.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context $context The course context.
 */
function tool_ltidiagnostics_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('tool/ltidiagnostics:viewcourse', $context)) {
        return;
    }
    $navigation->add(
        get_string('courseview', 'tool_ltidiagnostics'),
        new moodle_url('/admin/tool/ltidiagnostics/course.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'tool_ltidiagnostics_course'
    );
}
