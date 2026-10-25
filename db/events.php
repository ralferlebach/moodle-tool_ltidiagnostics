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
 * Event observers of tool_ltidiagnostics.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callback = '\tool_ltidiagnostics\observer::handle';
$events = [
    '\core\event\user_loggedin',
    '\core\event\user_created',
    '\core\event\user_updated',
    '\core\event\user_enrolment_created',
    '\core\event\group_member_added',
    '\core\event\user_graded',
    '\mod_lti\event\course_module_viewed',
    '\mod_lti\event\unknown_service_api_called',
];

$observers = [];
foreach ($events as $eventname) {
    $observers[] = [
        'eventname' => $eventname,
        'callback' => $callback,
    ];
}
