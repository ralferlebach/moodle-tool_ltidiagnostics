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
 * Storage of the plugin's own minimal observations.
 *
 * An observation records that an event happened, where and in which request. It never holds
 * values: no names, no e-mail addresses, no claims, no tokens. Only key names of the event data.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observation_store {
    /** @var string Table name. */
    private const TABLE = 'tool_ltidiagnostics_obs';
    /** @var string|null Trace id of the current request. */
    private static $traceid = null;

    /**
     * Trace id of the current request: a sanitised X-Request-ID when the web server provides one, else random.
     *
     * @return string
     */
    public static function current_trace_id(): string {
        if (self::$traceid === null) {
            $header = isset($_SERVER['HTTP_X_REQUEST_ID']) ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : '';
            $header = substr(clean_param($header, PARAM_ALPHANUMEXT), 0, 64);
            self::$traceid = $header !== '' ? $header : 't' . bin2hex(random_bytes(8));
        }
        return self::$traceid;
    }

    /**
     * Forget the trace id (new request in long running processes and in tests).
     */
    public static function reset_trace(): void {
        self::$traceid = null;
    }

    /**
     * Store one observation.
     *
     * @param array $data Fields of tool_ltidiagnostics_obs. Missing ones get neutral defaults.
     * @return int Record id.
     */
    public static function record(array $data): int {
        global $DB;
        $row = (object) ($data + [
            'traceid' => self::current_trace_id(),
            'eventtype' => 'other',
            'direction' => 'local',
            'eventname' => '',
            'component' => '',
            'connkey' => '',
            'issuerhash' => '',
            'courseid' => 0,
            'cmid' => 0,
            'contextid' => 0,
            'userid' => 0,
            'objectid' => 0,
            'entrypoint' => entrypoint::WEB,
            'fieldnames' => null,
            'timecreated' => time(),
        ]);
        $row->eventname = \core_text::substr((string) $row->eventname, 0, 255);
        return (int) $DB->insert_record(self::TABLE, $row);
    }

    /**
     * Observations of a connection.
     *
     * @param connection $c The connection.
     * @param array $filters Optional: from, to (unix), courseid, cmid, eventtype (string|array), limit.
     * @return \stdClass[] Newest first.
     */
    public static function get_for_connection(connection $c, array $filters = []): array {
        global $DB;
        $params = ['key' => $c->key];
        $where = '(connkey = :key';
        if (!empty($c->facts['issuerhash'])) {
            $where .= ' OR (connkey = :empty AND issuerhash = :ih)';
            $params['empty'] = '';
            $params['ih'] = $c->facts['issuerhash'];
        }
        $where .= ')';
        [$extra, $eparams] = self::filter_sql($filters);
        $limit = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 200;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        return array_values($DB->get_records_select(
            self::TABLE,
            $where . $extra,
            $params + $eparams,
            'timecreated DESC, id DESC',
            '*',
            $offset,
            $limit
        ));
    }

    /**
     * Observations of a course (aggregate use by teachers).
     *
     * @param int $courseid Course id.
     * @param array $filters Optional filters, see get_for_connection().
     * @return \stdClass[]
     */
    public static function get_for_course(int $courseid, array $filters = []): array {
        global $DB;
        [$extra, $eparams] = self::filter_sql($filters);
        $limit = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 200;
        return array_values($DB->get_records_select(
            self::TABLE,
            'courseid = :cid' . $extra,
            ['cid' => $courseid] + $eparams,
            'timecreated DESC, id DESC',
            '*',
            0,
            $limit
        ));
    }

    /**
     * Count observations per event type.
     *
     * @param \stdClass[] $rows Rows from get_*().
     * @return int[] Event type => count.
     */
    public static function count_by_type(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[$r->eventtype] = ($out[$r->eventtype] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }

    /**
     * Delete observations older than a time.
     *
     * @param int $before Unix time.
     * @return int Number of deleted rows.
     */
    public static function purge_older_than(int $before): int {
        global $DB;
        $n = $DB->count_records_select(self::TABLE, 'timecreated < ?', [$before]);
        $DB->delete_records_select(self::TABLE, 'timecreated < ?', [$before]);
        return $n;
    }

    /**
     * Build the SQL fragment for the filters.
     *
     * @param array $filters Filters.
     * @return array [sql starting with " AND", params]
     */
    private static function filter_sql(array $filters): array {
        global $DB;
        $sql = '';
        $params = [];
        if (!empty($filters['from'])) {
            $sql .= ' AND timecreated >= :tfrom';
            $params['tfrom'] = (int) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND timecreated <= :tto';
            $params['tto'] = (int) $filters['to'];
        }
        if (!empty($filters['courseid'])) {
            $sql .= ' AND courseid = :fcourse';
            $params['fcourse'] = (int) $filters['courseid'];
        }
        if (!empty($filters['cmid'])) {
            $sql .= ' AND cmid = :fcm';
            $params['fcm'] = (int) $filters['cmid'];
        }
        if (!empty($filters['eventtype'])) {
            [$insql, $inparams] = $DB->get_in_or_equal((array) $filters['eventtype'], SQL_PARAMS_NAMED, 'et');
            $sql .= ' AND eventtype ' . $insql;
            $params += $inparams;
        }
        return [$sql, $params];
    }
}
