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
        // Optional tool_ltigroupautoenrol (>= 1.2.1): group assignment outcomes of LTI enrolments (GA-03). Absent events
        // simply match nothing.
        '\tool_ltigroupautoenrol\event\group_assignment_completed',
        '\tool_ltigroupautoenrol\event\group_assignment_failed',
    ];
    /** @var int Default look-back when no start time is given: every query is bounded by time (indexed column). */
    public const DEFAULT_WINDOW = 7 * DAYSECS;
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
     * Fetch relevant events from every logging reader.
     *
     * More than one log store can be enabled at the same time (for example the standard store and an external one).
     * Every logging store is read through the reader API, the rows are merged and an event that several stores hold is
     * reported once. A store that fails is reported in "readers" and does not hide the others.
     *
     * Two bounded queries per reader: the fixed list of core and mod_lti events, and the events of the LTI family
     * ({@see family_events::PREFIXES}, matched on the component). Both are limited to a time window (default
     * {@see self::DEFAULT_WINDOW} before "to" or now; timecreated is indexed in the standard store) and to "limit"
     * rows each, so no query scans the whole log.
     *
     * @param \context $context Context in which the capability is checked.
     * @param array $filters Optional: from, to (unix), courseid, limit (max 500, per query), offset, family (bool, default
     *        true).
     * @return array [available (bool), reason (string), rows (array), readers (array), duplicates (int), truncated (bool),
     *         familytruncated (bool), window (array from, to)]. Rows newest first, each with the name of the reader it
     *         came from (a duplicate lists all readers); family rows carry correlationid, step, stage, direction, connkey.
     */
    public function fetch(\context $context, array $filters = []): array {
        global $DB;
        require_capability('tool/ltidiagnostics:view', $context);
        $empty = ['available' => false, 'reason' => '', 'rows' => [], 'readers' => [], 'duplicates' => 0, 'truncated' => false,
            'familytruncated' => false, 'window' => ['from' => 0, 'to' => 0]];
        $readers = $this->get_readers();
        if (!$readers) {
            return ['reason' => 'noreader'] + $empty;
        }
        $logging = [];
        foreach ($readers as $name => $candidate) {
            if ($candidate->is_logging()) {
                $logging[$name] = $candidate;
            }
        }
        if (!$logging) {
            return ['reason' => 'notlogging'] + $empty;
        }
        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : 200;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        $to = !empty($filters['to']) ? (int) $filters['to'] : 0;
        $from = !empty($filters['from']) ? (int) $filters['from'] : ($to ?: time()) - self::DEFAULT_WINDOW;
        $common = ' AND timecreated >= :tfrom';
        $cparams = ['tfrom' => $from];
        if ($to) {
            $common .= ' AND timecreated <= :tto';
            $cparams['tto'] = $to;
        }
        if (!empty($filters['courseid'])) {
            $common .= ' AND courseid = :cid';
            $cparams['cid'] = (int) $filters['courseid'];
        }
        [$insql, $params] = $DB->get_in_or_equal(self::EVENTS, SQL_PARAMS_NAMED, 'en');
        $queries = ['core' => ['eventname ' . $insql . $common, $params + $cparams]];
        if ($filters['family'] ?? true) {
            $likes = [];
            $fparams = [];
            foreach (family_events::PREFIXES as $i => $prefix) {
                $likes[] = $DB->sql_like('component', ':fp' . $i);
                $fparams['fp' . $i] = $DB->sql_like_escape($prefix) . '_%';
            }
            $queries['family'] = ['(' . implode(' OR ', $likes) . ')' . $common, $fparams + $cparams];
        }
        $rows = [];
        $summary = [];
        $duplicates = 0;
        $truncated = false;
        $familytruncated = false;
        foreach ($logging as $name => $reader) {
            $events = [];
            try {
                foreach ($queries as $kind => [$where, $qparams]) {
                    $found = $reader->get_events_select($where, $qparams, 'timecreated DESC', $offset, $limit);
                    if (count($found) >= $limit) {
                        if ($kind === 'family') {
                            $familytruncated = true;
                        } else {
                            $truncated = true;
                        }
                    }
                    $events = array_merge($events, array_values($found));
                }
            } catch (\Throwable $e) {
                $summary[$name] = ['ok' => false, 'rows' => 0, 'reason' => 'readererror'];
                continue;
            }
            $ltiusers = $this->lti_user_ids($events);
            $count = 0;
            $occurrence = [];
            foreach ($events as $event) {
                $data = $event->get_data();
                if (in_array($data['eventname'], self::USER_EVENTS, true) && !isset($ltiusers[(int) $data['objectid']])) {
                    continue;
                }
                $row = [
                    'source' => 'logstore',
                    'reader' => $name,
                    'readers' => [$name],
                    'time' => (int) $data['timecreated'],
                    'eventname' => $data['eventname'],
                    'component' => $data['component'],
                    'courseid' => (int) $data['courseid'],
                    'contextid' => (int) $data['contextid'],
                    'objectid' => (int) $data['objectid'],
                    'userid' => (int) ($data['relateduserid'] ?? $data['userid']),
                    'family' => false,
                    'correlationid' => '',
                ];
                if (family_events::is_family_component((string) $data['component'])) {
                    $row = self::family_row($row, $data);
                }
                $count++;
                // The same event held by two stores has the same name, second, context, object and user. Several equal
                // events inside one store are different events: the n-th occurrence in one store matches the n-th in another.
                $id = implode('|', [$row['eventname'], $row['time'], $row['contextid'], $row['objectid'], $row['userid'],
                    $row['correlationid']]);
                $occurrence[$id] = ($occurrence[$id] ?? 0) + 1;
                $id .= '#' . $occurrence[$id];
                if (isset($rows[$id])) {
                    $rows[$id]['readers'][] = $name;
                    $duplicates++;
                    continue;
                }
                $rows[$id] = $row;
            }
            $summary[$name] = ['ok' => true, 'rows' => $count, 'reason' => ''];
        }
        if (!array_filter(array_column($summary, 'ok'))) {
            return ['reason' => 'readererror', 'readers' => $summary] + $empty;
        }
        $rows = array_values($rows);
        usort($rows, function (array $a, array $b) {
            return $b['time'] <=> $a['time'];
        });
        return ['available' => true, 'reason' => '', 'rows' => $rows, 'readers' => $summary, 'duplicates' => $duplicates,
            'truncated' => $truncated, 'familytruncated' => $familytruncated, 'window' => ['from' => $from, 'to' => $to]];
    }

    /**
     * Add the family fields to a row: only correlation id, connection, version and code are taken from "other".
     *
     * @param array $row Base row.
     * @param array $data Event data.
     * @return array
     */
    private static function family_row(array $row, array $data): array {
        $other = family_events::sanitise_other($data['other'] ?? []);
        $class = family_events::classify((string) $data['eventname']);
        return array_merge($row, [
            'family' => true,
            'correlationid' => $other['correlationid'],
            'connectionid' => $other['connectionid'],
            'version' => $other['version'],
            'code' => $other['code'],
            'step' => $class['step'],
            'stage' => $class['stage'],
            'direction' => $class['direction'],
            'connkey' => family_events::connection_key((string) $data['component'], $other['connectionid']),
        ]);
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
