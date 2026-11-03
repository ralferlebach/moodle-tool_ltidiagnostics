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

namespace tool_ltidiagnostics\local;

/**
 * Who gets the plugin's entries in the site administration (issue #10).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_access {
    /** @var string[] Capabilities that open a page of the plugin, each with its admin tree node. */
    public const PAGES = [
        'tool/ltidiagnostics:view' => 'tool_ltidiagnostics_index',
        'tool/ltidiagnostics:viewsupport' => 'tool_ltidiagnostics_support',
        'tool/ltidiagnostics:importlogs' => 'tool_ltidiagnostics_logimport',
        'tool/ltidiagnostics:viewprivacy' => 'tool_ltidiagnostics_audit',
    ];

    /**
     * True when the user holds at least one capability that opens a page of the plugin.
     *
     * Capabilities that are not installed yet (pending upgrade) are skipped instead of asked, so building the admin tree
     * never raises "capability does not exist" while an upgrade is due.
     *
     * @param int|null $userid User (default: current user).
     * @return bool
     */
    public static function any(?int $userid = null): bool {
        $context = \context_system::instance();
        foreach (array_keys(self::PAGES) as $cap) {
            if (get_capability_info($cap) && has_capability($cap, $context, $userid)) {
                return true;
            }
        }
        return false;
    }
}
