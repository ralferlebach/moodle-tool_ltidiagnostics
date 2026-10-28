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
 * Historised snapshots of the capability matrix and comparison of two snapshots.
 *
 * A snapshot contains statuses and ladder states only, no identifiers and no secrets.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot {
    /**
     * Condense a matrix into the data that is stored.
     *
     * @param array $matrix Matrix rows.
     * @return array
     */
    public static function condense(array $matrix): array {
        $out = [];
        foreach ($matrix as $feature => $row) {
            $steps = [];
            foreach ($row['ladder'] as $step => $ev) {
                $steps[$step] = $ev->state . '/' . $ev->kind;
            }
            $out[$feature] = ['status' => $row['status'], 'reason' => $row['reason'], 'steps' => $steps];
        }
        return $out;
    }

    /**
     * Store a snapshot unless the newest one for the connection is identical.
     *
     * @param string $connkey Connection key.
     * @param array $matrix Matrix rows.
     * @param int $now Time.
     * @return int Record id of the new snapshot, 0 when nothing changed.
     */
    public static function take(string $connkey, array $matrix, int $now): int {
        global $DB, $USER;
        $json = json_encode(self::condense($matrix));
        $hash = hash('sha256', $json);
        $last = $DB->get_records(
            'tool_ltidiagnostics_snap',
            ['connkey' => $connkey, 'kind' => 'matrix'],
            'timecreated DESC, id DESC',
            'id, contenthash',
            0,
            1
        );
        if ($last && reset($last)->contenthash === $hash) {
            return 0;
        }
        return (int) $DB->insert_record('tool_ltidiagnostics_snap', (object) [
            'connkey' => $connkey,
            'kind' => 'matrix',
            'contenthash' => $hash,
            'snapjson' => $json,
            'userid' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
        ]);
    }

    /**
     * Compare two condensed snapshots.
     *
     * @param array $old Older snapshot.
     * @param array $new Newer snapshot.
     * @return array[] Each with feature, from (status), to (status), changedsteps (list of steps whose state changed).
     */
    public static function compare(array $old, array $new): array {
        $out = [];
        foreach ($new as $feature => $row) {
            $before = $old[$feature] ?? null;
            $steps = [];
            foreach ($row['steps'] as $step => $state) {
                if (($before['steps'][$step] ?? null) !== $state) {
                    $steps[] = $step;
                }
            }
            if ($before === null || $before['status'] !== $row['status'] || $steps) {
                $out[] = ['feature' => $feature, 'from' => $before['status'] ?? '', 'to' => $row['status'],
                    'changedsteps' => $steps];
            }
        }
        return $out;
    }

    /**
     * Load the snapshots of a connection, newest first.
     *
     * @param string $connkey Connection key.
     * @param int $limit Maximum number.
     * @return \stdClass[]
     */
    public static function get_list(string $connkey, int $limit = 20): array {
        global $DB;
        return array_values($DB->get_records(
            'tool_ltidiagnostics_snap',
            ['connkey' => $connkey, 'kind' => 'matrix'],
            'timecreated DESC, id DESC',
            'id, timecreated, contenthash',
            0,
            $limit
        ));
    }

    /**
     * Store the latest active check results of a connection. Addresses are dropped, only outcomes are kept.
     *
     * @param string $connkey Connection key.
     * @param array $probes Results keyed by purpose.
     * @param int $now Time.
     * @return int Record id.
     */
    public static function save_probes(string $connkey, array $probes, int $now): int {
        global $DB, $USER;
        $clean = [];
        foreach ($probes as $purpose => $p) {
            unset($p['dns'], $p['url']);
            $clean[$purpose] = $p;
        }
        $json = json_encode($clean);
        return (int) $DB->insert_record('tool_ltidiagnostics_snap', (object) [
            'connkey' => $connkey,
            'kind' => 'probe',
            'contenthash' => hash('sha256', $json),
            'snapjson' => $json,
            'userid' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
        ]);
    }

    /**
     * Latest stored active check results.
     *
     * @param string $connkey Connection key.
     * @return array Results keyed by purpose, empty if none.
     */
    public static function latest_probes(string $connkey): array {
        global $DB;
        $rows = $DB->get_records(
            'tool_ltidiagnostics_snap',
            ['connkey' => $connkey, 'kind' => 'probe'],
            'timecreated DESC, id DESC',
            'id, snapjson',
            0,
            1
        );
        if (!$rows) {
            return [];
        }
        $data = json_decode(reset($rows)->snapjson, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Store a document of a given kind for a connection (partner package, partner trace). The caller redacts it.
     *
     * @param string $connkey Connection key.
     * @param string $kind Kind (at most 20 characters): partner, partnertrace.
     * @param array $data Data.
     * @param int $now Time.
     * @return int Record id.
     */
    public static function save_kind(string $connkey, string $kind, array $data, int $now): int {
        global $DB, $USER;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        return (int) $DB->insert_record('tool_ltidiagnostics_snap', (object) [
            'connkey' => $connkey,
            'kind' => \core_text::substr($kind, 0, 20),
            'contenthash' => hash('sha256', (string) $json),
            'snapjson' => $json,
            'userid' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
        ]);
    }

    /**
     * Newest stored document of a kind.
     *
     * @param string $connkey Connection key.
     * @param string $kind Kind.
     * @return array|null Data plus "_time" (storage time), or null.
     */
    public static function latest_kind(string $connkey, string $kind): ?array {
        global $DB;
        $rows = $DB->get_records(
            'tool_ltidiagnostics_snap',
            ['connkey' => $connkey, 'kind' => $kind],
            'timecreated DESC, id DESC',
            'id, snapjson, timecreated',
            0,
            1
        );
        if (!$rows) {
            return null;
        }
        $row = reset($rows);
        $data = json_decode($row->snapjson, true);
        return is_array($data) ? $data + ['_time' => (int) $row->timecreated] : null;
    }
}
