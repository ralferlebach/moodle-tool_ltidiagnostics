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
 * Switch the time-limited diagnostic mode on or off.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login(null, false);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:configure', $context);
require_sesskey();

$action = required_param('action', PARAM_ALPHA);
$minutes = optional_param('minutes', 60, PARAM_INT);
if ($action === 'enable') {
    \tool_ltidiagnostics\local\diagnostic_mode::enable($minutes);
    $message = get_string('diagmode_enabled', 'tool_ltidiagnostics');
} else if ($action === 'disable') {
    \tool_ltidiagnostics\local\diagnostic_mode::disable();
    $message = get_string('diagmode_disabled', 'tool_ltidiagnostics');
} else {
    throw new \moodle_exception('invalidaction', 'tool_ltidiagnostics');
}
redirect(new moodle_url('/admin/tool/ltidiagnostics/index.php'), $message);
