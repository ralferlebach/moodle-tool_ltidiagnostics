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
 * Evidence sources of a connection as separate, filterable and paged sub-views (issue #25): observations of this
 * plugin, the Moodle log stores, scheduled tasks and the task log, LTI family transfer events and imported web server
 * logs.
 *
 * Every row carries its source, time (UTC), service, direction, evidence level and its relation to course, activity and
 * person. Rows are related to the connection only through ids (connection key, issuer hash, course of a published
 * resource); a row that cannot be related this way is "not attributable" and shown under its own filter, never counted
 * for a course because of time proximity. System-wide rows (tasks) say so.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence_query {
    /** @var string[] Sub-views. */
    public const VIEWS = ['observer', 'logstore', 'tasks', 'family', 'imports'];

    /** @var string[] Services of the filter. */
    public const SERVICES = ['launch', 'ags', 'nrps', 'groups', 'completion', 'tasks'];

    /** @var array Service => observation event types. */
    private const OBSTYPES = [
        'launch' => ['launch', 'account_created', 'launch_sync', 'view'],
        'ags' => ['grade', 'service_unknown'],
        'nrps' => ['enrolment'],
        'groups' => ['group', 'groupassign', 'groupassign_failed'],
        'completion' => [observation_store::FAMILY_EVENT],
        'tasks' => [],
    ];

    /** @var array Service => log store events. */
    private const LOGEVENTS = [
        'launch' => ['\core\event\user_loggedin', '\core\event\user_created', '\core\event\user_updated',
            '\mod_lti\event\course_module_viewed'],
        'ags' => ['\core\event\user_graded', '\mod_lti\event\unknown_service_api_called'],
        'nrps' => ['\core\event\user_enrolment_created'],
        'groups' => ['\core\event\group_member_added', '\tool_ltigroupautoenrol\event\group_assignment_completed',
            '\tool_ltigroupautoenrol\event\group_assignment_failed'],
        'completion' => [],
        'tasks' => [],
    ];

    /** @var array Service => task class name parts. */
    private const TASKCLASSES = [
        'launch' => [],
        'ags' => ['sync_grades', 'sync_tool_grades', 'grade_repair_task'],
        'nrps' => ['sync_members', 'sync_tool_members'],
        'groups' => ['groupautoenrol'],
        'completion' => ['lticompletionsync'],
        'tasks' => [],
    ];

    /**
     * One page of a sub-view.
     *
     * @param connection $conn Connection.
     * @param scope $scope Scope.
     * @param string $view Sub-view.
     * @param array $f service, text, assign (assigned, unassigned), limit, offset.
     * @param int $now Time.
     * @return array view, rows (normalised), total (int|null: unknown), available (bool), reason (string), scoped (bool:
     *     the scope could be applied), notes (string ids).
     */
    public function page(connection $conn, scope $scope, string $view, array $f, int $now): array {
        $limit = max(1, min(500, (int) ($f['limit'] ?? 50)));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $service = (string) ($f['service'] ?? '');
        $text = (string) ($f['text'] ?? '');
        $out = ['view' => $view, 'rows' => [], 'total' => 0, 'available' => true, 'reason' => '', 'scoped' => true,
            'notes' => [], 'limit' => $limit, 'offset' => $offset];
        switch ($view) {
            case 'observer':
                return $this->observer($conn, $scope, $service, $text, (string) ($f['assign'] ?? ''), $limit, $offset) + $out;
            case 'logstore':
                return $this->logstore($conn, $scope, $service, $text, $limit, $offset) + $out;
            case 'tasks':
                return $this->tasks($scope, $service, $text, $limit, $offset, $now) + $out;
            case 'family':
                return $this->family($conn, $scope, $text, $limit, $offset) + $out;
            default:
                return $this->imports($limit, $offset) + $out;
        }
    }

    /**
     * Observations of this plugin.
     *
     * @param connection $conn Connection.
     * @param scope $scope Scope.
     * @param string $service Service.
     * @param string $text Free text (event name).
     * @param string $assign assigned (by connection key), unassigned (issuer only) or ''.
     * @param int $limit Limit.
     * @param int $offset Offset.
     * @return array
     */
    private function observer(
        connection $conn,
        scope $scope,
        string $service,
        string $text,
        string $assign,
        int $limit,
        int $offset
    ): array {
        global $DB;
        $params = ['key' => $conn->key];
        $conds = ['connkey = :key'];
        if (!empty($conn->facts['issuerhash']) && $assign !== 'assigned') {
            $conds[] = '(connkey = :empty AND issuerhash = :ih)';
            $params['empty'] = '';
            $params['ih'] = $conn->facts['issuerhash'];
        }
        $where = $assign === 'unassigned' && count($conds) > 1 ? $conds[1] : '(' . implode(' OR ', $conds) . ')';
        if ($assign === 'unassigned' && count($conds) === 1) {
            $where = '1 = 0';
        }
        foreach (['from' => '>=', 'to' => '<='] as $name => $op) {
            if ($scope->$name) {
                $where .= " AND timecreated $op :t$name";
                $params['t' . $name] = $scope->$name;
            }
        }
        if ($scope->courseid) {
            $where .= ' AND courseid = :cid';
            $params['cid'] = $scope->courseid;
        }
        if ($scope->cmid) {
            $where .= ' AND cmid = :cm';
            $params['cm'] = $scope->cmid;
        }
        if ($service !== '') {
            $types = self::OBSTYPES[$service] ?? [];
            if (!$types) {
                $where .= ' AND 1 = 0';
            } else {
                [$in, $p] = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'et');
                $where .= " AND eventtype $in";
                $params += $p;
            }
        }
        if ($text !== '') {
            $where .= ' AND (' . $DB->sql_like('eventname', ':tx1', false) . ' OR ' . $DB->sql_like('eventtype', ':tx2', false)
                . ' OR ' . $DB->sql_like('entrypoint', ':tx3', false) . ')';
            $params['tx1'] = $params['tx2'] = $params['tx3'] = '%' . $DB->sql_like_escape($text) . '%';
        }
        $total = (int) $DB->count_records_select(observation_store::TABLE, $where, $params);
        $rows = [];
        foreach (
            $DB->get_records_select(
                observation_store::TABLE,
                $where,
                $params,
                'timecreated DESC, id DESC',
                '*',
                $offset,
                $limit
            ) as $o
        ) {
            $rows[] = [
                'time' => (int) $o->timecreated,
                'source' => 'observer',
                'service' => self::service_of_type((string) $o->eventtype),
                'direction' => (string) $o->direction,
                'label' => (string) $o->eventname,
                'detail' => (string) $o->entrypoint,
                'level' => 'observed',
                'courseid' => (int) $o->courseid,
                'cmid' => (int) $o->cmid,
                'userid' => (int) $o->userid,
                'assigned' => (string) $o->connkey === $conn->key,
                'correlationid' => (string) ($o->correlationid ?? ''),
            ];
        }
        return ['rows' => $rows, 'total' => $total, 'scoped' => true];
    }

    /**
     * Log store rows (read through the reader API).
     *
     * @param connection $conn Connection.
     * @param scope $scope Scope.
     * @param string $service Service.
     * @param string $text Free text.
     * @param int $limit Limit.
     * @param int $offset Offset.
     * @return array
     */
    private function logstore(connection $conn, scope $scope, string $service, string $text, int $limit, int $offset): array {
        $filters = $scope->filters() + ['limit' => $limit, 'offset' => $offset, 'family' => false, 'count' => true,
            'text' => $text];
        if ($service !== '') {
            $filters['events'] = self::LOGEVENTS[$service] ?? [];
        }
        $log = (new logstore_source())->fetch(\context_system::instance(), $filters);
        if (!$log['available']) {
            return ['rows' => [], 'total' => 0, 'available' => false, 'reason' => 'logstore_' . $log['reason']];
        }
        $courses = array_flip(navigation::published_courses($conn));
        $rows = [];
        foreach ($log['rows'] as $r) {
            $rows[] = [
                'time' => (int) $r['time'],
                'source' => 'logstore',
                'service' => self::service_of_event((string) $r['eventname']),
                'direction' => 'local',
                'label' => (string) $r['eventname'],
                'detail' => (string) $r['component'],
                'level' => 'logged',
                'courseid' => (int) $r['courseid'],
                'cmid' => 0,
                'userid' => (int) $r['userid'],
                // Related to this connection only when the course publishes a resource through it.
                'assigned' => $r['courseid'] && isset($courses[(int) $r['courseid']]),
                'correlationid' => '',
            ];
        }
        $notes = ['ev_note_logwindow'];
        if ($scope->cmid) {
            $notes[] = 'ev_note_logcm';
        }
        return ['rows' => $rows, 'total' => (int) ($log['total'] ?? count($rows)), 'scoped' => !$scope->cmid,
            'notes' => $notes];
    }

    /**
     * Scheduled tasks and the task log (system wide: not restricted by course).
     *
     * @param scope $scope Scope (period only).
     * @param string $service Service.
     * @param string $text Free text (class name).
     * @param int $limit Limit.
     * @param int $offset Offset.
     * @param int $now Time.
     * @return array
     */
    private function tasks(scope $scope, string $service, string $text, int $limit, int $offset, int $now): array {
        $source = new task_source();
        $filters = ['from' => $scope->from, 'to' => $scope->to, 'text' => $text, 'limit' => $limit, 'offset' => $offset];
        if ($service !== '' && !empty(self::TASKCLASSES[$service])) {
            $filters['classes'] = self::TASKCLASSES[$service];
        } else if ($service !== '' && $service !== 'tasks') {
            $filters['classes'] = ['-no-task-for-this-service-'];
        }
        $rows = [];
        foreach ($source->task_log($filters) as $t) {
            $rows[] = [
                'time' => (int) $t['start'],
                'source' => 'tasklog',
                'service' => 'tasks',
                'direction' => 'local',
                'label' => (string) $t['classname'],
                'detail' => $t['type'],
                'level' => $t['failed'] ? 'failed' : 'ran',
                'courseid' => 0,
                'cmid' => 0,
                'userid' => 0,
                'assigned' => false,
                'correlationid' => '',
            ];
        }
        $scheduled = [];
        foreach ($source->scheduled($now) as $t) {
            if ($text !== '' && stripos($t['classname'], $text) === false) {
                continue;
            }
            $scheduled[] = $t;
        }
        return ['rows' => $rows, 'total' => $source->task_log_count($filters), 'scoped' => !$scope->courseid && !$scope->cmid,
            'scheduled' => $scheduled, 'cron' => $source->cron_status($now), 'notes' => ['ev_note_tasks_system']];
    }

    /**
     * LTI family transfer events (for example completion sync), with their correlation ids.
     *
     * @param connection $conn Connection.
     * @param scope $scope Scope.
     * @param string $text Free text.
     * @param int $limit Limit.
     * @param int $offset Offset.
     * @return array
     */
    private function family(connection $conn, scope $scope, string $text, int $limit, int $offset): array {
        $log = (new logstore_source())->fetch(\context_system::instance(), $scope->filters() + ['limit' => $limit,
            'offset' => $offset, 'events' => [], 'family' => true, 'text' => $text, 'count' => true]);
        if (!$log['available']) {
            return ['rows' => [], 'total' => 0, 'available' => false, 'reason' => 'logstore_' . $log['reason']];
        }
        $rows = [];
        foreach ($log['rows'] as $r) {
            if (empty($r['family'])) {
                continue;
            }
            $rows[] = [
                'time' => (int) $r['time'],
                'source' => 'family',
                'service' => 'completion',
                'direction' => 'local',
                'label' => (string) ($r['step'] ?? $r['eventname']),
                'detail' => (string) ($r['code'] ?? ''),
                'level' => 'logged',
                'courseid' => (int) $r['courseid'],
                'cmid' => 0,
                'userid' => 0,
                'assigned' => ($r['connkey'] ?? '') === $conn->key,
                'correlationid' => (string) ($r['correlationid'] ?? ''),
            ];
        }
        $notes = family_events::emitter_installed() ? [] : ['fam_noemitter'];
        return ['rows' => $rows, 'total' => (int) ($log['total'] ?? count($rows)), 'notes' => $notes];
    }

    /**
     * Import jobs of web server logs (system wide).
     *
     * @param int $limit Limit.
     * @param int $offset Offset.
     * @return array
     */
    private function imports(int $limit, int $offset): array {
        global $DB;
        $total = (int) $DB->count_records('tool_ltidiagnostics_imp');
        $imports = $DB->get_records('tool_ltidiagnostics_imp', null, 'timecreated DESC, id DESC', '*', $offset, $limit);
        return ['rows' => [], 'imports' => array_values($imports), 'total' => $total, 'notes' => ['ev_note_imports_system']];
    }

    /**
     * Service of an observation event type.
     *
     * @param string $type Event type.
     * @return string
     */
    public static function service_of_type(string $type): string {
        foreach (self::OBSTYPES as $service => $types) {
            if (in_array($type, $types, true)) {
                return $service;
            }
        }
        return 'other';
    }

    /**
     * Service of a log store event.
     *
     * @param string $eventname Event name.
     * @return string
     */
    public static function service_of_event(string $eventname): string {
        foreach (self::LOGEVENTS as $service => $events) {
            if (in_array($eventname, $events, true)) {
                return $service;
            }
        }
        return 'other';
    }

    /**
     * Filter the timeline items by service and text (the timeline is built from bounded sources; this only narrows it).
     *
     * @param array $tl Timeline.
     * @param string $service Service.
     * @param string $text Text.
     * @return array
     */
    public static function filter_timeline(array $tl, string $service, string $text): array {
        if ($service === '' && $text === '') {
            return $tl;
        }
        $keep = [];
        foreach ($tl['items'] as $i => $item) {
            $itemservice = self::timeline_service((string) $item['stage'], (string) $item['label']);
            if ($service !== '' && $itemservice !== $service) {
                continue;
            }
            $nomatch = stripos((string) $item['label'], $text) === false && stripos((string) $item['stage'], $text) === false;
            if ($text !== '' && $nomatch) {
                continue;
            }
            $keep[$i] = $item;
        }
        $map = array_flip(array_keys($keep));
        $edges = [];
        foreach ($tl['edges'] as $e) {
            if (isset($map[$e['from']], $map[$e['to']])) {
                $edges[] = ['from' => $map[$e['from']], 'to' => $map[$e['to']]] + $e;
            }
        }
        $tl['items'] = array_values($keep);
        $tl['edges'] = $edges;
        return $tl;
    }

    /**
     * Service of a timeline item: from its event name when known, otherwise from its stage.
     *
     * @param string $stage Stage.
     * @param string $label Event name or label.
     * @return string
     */
    public static function timeline_service(string $stage, string $label = ''): string {
        $byevent = self::service_of_event($label);
        if ($byevent !== 'other') {
            return $byevent;
        }
        if (in_array($stage, ['launch', 'oidc', 'jwt'], true)) {
            return 'launch';
        }
        if ($stage === 'task') {
            return 'tasks';
        }
        if (
            in_array($stage, ['ack', 'http_jwt', 'native_completion', 'outbox', 'receiver', 'retry', 'revocation',
                'source_event'], true)
        ) {
            return 'completion';
        }
        foreach (['grade' => 'ags', 'enrol' => 'nrps', 'group' => 'groups'] as $part => $service) {
            if (stripos($label, $part) !== false) {
                return $service;
            }
        }
        return 'other';
    }
}
