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
 * Hook callbacks of tool_ltidiagnostics.
 *
 * The hook belongs to the optional plugin tool_mergeusers. Without that plugin the hook class does not exist and the
 * callback is never called; Moodle's hook manager accepts the registration without a message (see
 * tests/merge_users_settings_test.php).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \tool_mergeusers\hook\add_settings_before_merging::class,
        'callback' => [\tool_ltidiagnostics\local\merge_users_settings::class, 'add_settings_before_merging'],
    ],
];
