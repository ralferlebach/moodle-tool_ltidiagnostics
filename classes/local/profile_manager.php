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
 * Persistence of desired-state profiles. Every write checks the capability.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_manager {
    /**
     * Save a profile for a connection (replaces an existing one of the same connection).
     *
     * @param string $connkey Connection key.
     * @param string $json Profile JSON.
     * @return array [id|0, errors]
     */
    public static function save(string $connkey, string $json): array {
        global $DB, $USER;
        require_capability('tool/ltidiagnostics:configure', \context_system::instance());
        if (connection::parse_key($connkey) === null) {
            return [0, [['badkey', '']]];
        }
        [$profile, $errors] = desired_profile::from_json($json);
        if ($profile === null) {
            return [0, $errors];
        }
        $now = time();
        $existing = $DB->get_record('tool_ltidiagnostics_profile', ['connkey' => $connkey], 'id', IGNORE_MULTIPLE);
        $row = (object) [
            'name' => $profile->name,
            'connkey' => $connkey,
            'profilejson' => $profile->to_json(),
            'usermodified' => (int) $USER->id,
            'timemodified' => $now,
        ];
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('tool_ltidiagnostics_profile', $row);
            return [(int) $existing->id, []];
        }
        $row->timecreated = $now;
        return [(int) $DB->insert_record('tool_ltidiagnostics_profile', $row), []];
    }

    /**
     * Load the profile of a connection.
     *
     * @param string $connkey Connection key.
     * @return desired_profile|null
     */
    public static function get(string $connkey): ?desired_profile {
        global $DB;
        $json = $DB->get_field('tool_ltidiagnostics_profile', 'profilejson', ['connkey' => $connkey], IGNORE_MULTIPLE);
        if ($json === false) {
            return null;
        }
        [$profile] = desired_profile::from_json($json);
        return $profile;
    }

    /**
     * Delete the profile of a connection.
     *
     * @param string $connkey Connection key.
     */
    public static function delete(string $connkey): void {
        global $DB;
        require_capability('tool/ltidiagnostics:configure', \context_system::instance());
        $DB->delete_records('tool_ltidiagnostics_profile', ['connkey' => $connkey]);
    }
}
