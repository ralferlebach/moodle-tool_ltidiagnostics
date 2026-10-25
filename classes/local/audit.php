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
 * Audit of views and exports of diagnostic data.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit {
    /**
     * Write an audit entry for the current user.
     *
     * @param string $action Short action name (letters, digits, underscore).
     * @param string $connkey Connection key or empty.
     * @param string $detail Short machine readable detail. Never a secret.
     * @param int|null $userid User id (default: current user).
     * @return int Record id.
     */
    public static function log(string $action, string $connkey = '', string $detail = '', ?int $userid = null): int {
        global $DB, $USER;
        return (int) $DB->insert_record('tool_ltidiagnostics_audit', (object) [
            'userid' => $userid ?? (int) $USER->id,
            'action' => \core_text::substr(clean_param($action, PARAM_ALPHANUMEXT), 0, 40),
            'connkey' => connection::parse_key($connkey) !== null ? $connkey : '',
            'detail' => \core_text::substr(redactor::text($detail), 0, 255),
            'timecreated' => time(),
        ]);
    }

    /**
     * Recent audit entries.
     *
     * @param int $limit Maximum number (max 500).
     * @return \stdClass[] Newest first.
     */
    public static function recent(int $limit = 100): array {
        global $DB;
        return array_values($DB->get_records(
            'tool_ltidiagnostics_audit',
            null,
            'timecreated DESC, id DESC',
            '*',
            0,
            max(1, min(500, $limit))
        ));
    }
}
