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
 * Evidence source: Moodle logstore readers, accessed only through the official reader API.
 *
 * No direct SQL on log tables. Without a usable reader the source reports "unavailable" with a
 * reason and returns no rows; an absent log entry is never read as "did not happen".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logstore_source {
    /** @var string[] Event names that are relevant for LTI flows. */
    public const EVENTS = [
        '\core\event\user_loggedin',
        '\core\event\user_created',
        '\core\event\user_updated',
        '\core\event\user_enrolment_created',
        '\core\event\group_member_added',
        '\core\event\user_graded',
        '\mod_lti\event\course_module_viewed',
        '\mod_lti\event\unknown_service_api_called',
    ];
    /** @var string[] Events that are only relevant for users authenticated by auth_lti. */
    private const USER_EVENTS = ['\core\event\user_loggedin', '\core\event\user_created', '\core\event\user_updated'];

    /** @var \core\log\sql_reader[]|null Cache of readers. */
    private $readers = null;

    /**
     * Available readers implementing the SQL reader contract.
     *
     * @return \core\log\sql_reader[] Keyed by reader class name.
     */
    public function get_readers(): array {
        if ($this->readers === null) {
            $this->readers = [];
            try {
                foreach (get_log_manager()->get_readers('\core\log\sql_reader') as $name => $reader) {
                    $this->readers[$name] = $reader;
                }
            } catch (\Throwable $e) {
                $this->readers = [];
            }
        }
        return $this->readers;
    }

    /**
     * Describe the readers for the observability layer.
     *
     * @return array[] Each with name, class, logging (bool).
     */
    public function describe(): array {
        $out = [];
        foreach ($this->get_readers() as $name => $reader) {
            $logging = false;
            try {
                $logging = (bool) $reader->is_logging();
            } catch (\Throwable $e) {
                $logging = false;
            }
            $out[] = ['name' => $name, 'class' => get_class($reader), 'logging' => $logging];
        }
        return $out;
    }

    /**
     * Fetch relevant events.
     *
     * @param \context $context Context in which the capability is checked.
     * @param array $filters Optional: from, to (unix), courseid, limit (max 500), offset.
     * @return array [available (bool), reason (string), rows (array)]. Rows newest first.
     */
    public function fetch(\context $context, array $filters = []): array {
        global $DB;
        require_capability('tool/ltidiagnostics:view', $context);
        $readers = $this->get_readers();
        if (!$readers) {
            return ['available' => false, 'reason' => 'noreader', 'rows' => []];
        }
        $reader = null;
        foreach ($readers as $candidate) {
            if ($candidate->is_logging()) {
                $reader = $candidate;
                break;
            }
        }
        if ($reader === null) {
            return ['available' => false, 'reason' => 'notlogging', 'rows' => []];
        }
        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : 200;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        [$insql, $params] = $DB->get_in_or_equal(self::EVENTS, SQL_PARAMS_NAMED, 'en');
        $where = 'eventname ' . $insql;
        if (!empty($filters['from'])) {
            $where .= ' AND timecreated >= :tfrom';
            $params['tfrom'] = (int) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where .= ' AND timecreated <= :tto';
            $params['tto'] = (int) $filters['to'];
        }
        if (!empty($filters['courseid'])) {
            $where .= ' AND courseid = :cid';
            $params['cid'] = (int) $filters['courseid'];
        }
        try {
            $events = $reader->get_events_select($where, $params, 'timecreated DESC', $offset, $limit);
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'readererror', 'rows' => []];
        }
        $ltiusers = $this->lti_user_ids($events);
        $rows = [];
        foreach ($events as $event) {
            $data = $event->get_data();
            if (in_array($data['eventname'], self::USER_EVENTS, true) && !isset($ltiusers[(int) $data['objectid']])) {
                continue;
            }
            $rows[] = [
                'source' => 'logstore',
                'time' => (int) $data['timecreated'],
                'eventname' => $data['eventname'],
                'component' => $data['component'],
                'courseid' => (int) $data['courseid'],
                'contextid' => (int) $data['contextid'],
                'objectid' => (int) $data['objectid'],
                'userid' => (int) ($data['relateduserid'] ?? $data['userid']),
            ];
        }
        return ['available' => true, 'reason' => '', 'rows' => $rows];
    }

    /**
     * Which of the users in the events are authenticated by auth_lti.
     *
     * @param \core\event\base[] $events Events.
     * @return bool[] Keyed by user id.
     */
    private function lti_user_ids(array $events): array {
        global $DB;
        $ids = [];
        foreach ($events as $event) {
            if (in_array($event->eventname, self::USER_EVENTS, true)) {
                $ids[(int) $event->objectid] = true;
            }
        }
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($ids), SQL_PARAMS_NAMED, 'u');
        $params['auth'] = 'lti';
        return array_fill_keys($DB->get_fieldset_select('user', 'id', "auth = :auth AND id $insql", $params), true);
    }
}
