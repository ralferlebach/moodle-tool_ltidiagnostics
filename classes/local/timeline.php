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

use tool_ltidiagnostics\local\accesslog\importer;

/**
 * Connection timeline: launch, OIDC, JWT, OAuth 2, service call, task, follow-up effects.
 *
 * Items come from separate sources and stay separate. An edge between two stages is only drawn if the
 * items are correlated with the confidence "direct" or "strong".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class timeline {
    /** @var string[] Stages in causal order. */
    public const STAGES = ['launch', 'oidc', 'jwt', 'oauth2', 'service', 'task', 'followup'];

    /**
     * Map a stage to the order index.
     *
     * @param string $stage Stage.
     * @return int
     */
    public static function stage_index(string $stage): int {
        $i = array_search($stage, self::STAGES, true);
        return $i === false ? count(self::STAGES) : (int) $i;
    }

    /**
     * Stage of an access log category.
     *
     * @param string $category Category from the parser.
     * @return string
     */
    public static function stage_of_category(string $category): string {
        if ($category === 'oidc') {
            return 'oidc';
        }
        if ($category === 'jwks') {
            return 'jwt';
        }
        if ($category === 'oauth') {
            return 'oauth2';
        }
        if ($category === 'launch') {
            return 'launch';
        }
        return 'service';
    }

    /**
     * Stage of an observation.
     *
     * @param \stdClass $o Observation.
     * @return string
     */
    public static function stage_of_observation(\stdClass $o): string {
        switch ($o->eventtype) {
            case 'view':
            case 'launch':
            case 'launch_sync':
                return 'launch';
            case 'service_unknown':
                return 'service';
            case 'grade':
                return $o->direction === 'in' ? 'service' : 'followup';
        }
        return 'followup';
    }

    /**
     * Build the timeline of a connection.
     *
     * @param connection $c Connection.
     * @param \context $context Context for the logstore capability check.
     * @param array $filters from, to, courseid, cmid.
     * @param int $now Time.
     * @return array items (oldest first), edges, sources (availability), stages
     */
    public function build(connection $c, \context $context, array $filters, int $now): array {
        $items = [];
        foreach (observation_store::get_for_connection($c, $filters + ['limit' => 500]) as $o) {
            $items[] = [
                'source' => 'observer',
                'time' => (int) $o->timecreated,
                'stage' => self::stage_of_observation($o),
                'label' => $o->eventname,
                'traceid' => (string) $o->traceid,
                'refs' => ['issuerhash' => (string) $o->issuerhash, 'courseid' => (int) $o->courseid, 'userid' => (int) $o->userid],
                'direction' => $o->direction,
            ];
        }
        $from = !empty($filters['from']) ? (int) $filters['from'] : $now - 7 * DAYSECS;
        $to = !empty($filters['to']) ? (int) $filters['to'] : 0;
        foreach (importer::get_rows($from, $to, [], 500) as $r) {
            $items[] = [
                'source' => 'accesslog',
                'time' => (int) $r->logtime,
                'stage' => self::stage_of_category($r->category),
                'label' => trim($r->method . ' ' . $r->path),
                'traceid' => (string) $r->requestid,
                'refs' => [],
                'direction' => 'in',
                'status' => (int) $r->httpstatus,
            ];
        }
        $sources = ['observer' => true, 'accesslog' => true];
        $log = (new logstore_source())->fetch($context, $filters + ['limit' => 200]);
        $sources['logstore'] = $log['available'];
        $sources['logstorereason'] = $log['reason'];
        foreach ($log['rows'] as $r) {
            $items[] = [
                'source' => 'logstore',
                'time' => $r['time'],
                'stage' => self::stage_of_event($r['eventname']),
                'label' => $r['eventname'],
                'traceid' => '',
                'refs' => ['courseid' => $r['courseid'], 'userid' => $r['userid']],
                'direction' => 'local',
            ];
        }
        foreach ((new task_source())->task_log(['from' => $from, 'to' => $to, 'limit' => 100]) as $t) {
            $items[] = [
                'source' => 'tasklog',
                'time' => $t['start'],
                'stage' => 'task',
                'label' => $t['classname'] . ($t['failed'] ? ' (failed)' : ''),
                'traceid' => '',
                'refs' => ['taskid' => $t['id']],
                'direction' => 'local',
            ];
        }
        usort($items, function ($x, $y) {
            return [$x['time'], self::stage_index($x['stage'])] <=> [$y['time'], self::stage_index($y['stage'])];
        });
        return ['items' => $items, 'edges' => self::edges($items), 'sources' => $sources, 'stages' => self::STAGES];
    }

    /**
     * Stage of a logstore event name.
     *
     * @param string $eventname Event name.
     * @return string
     */
    public static function stage_of_event(string $eventname): string {
        if (strpos($eventname, 'course_module_viewed') !== false || strpos($eventname, 'user_loggedin') !== false) {
            return 'launch';
        }
        if (strpos($eventname, 'unknown_service') !== false) {
            return 'service';
        }
        return 'followup';
    }

    /**
     * Draw edges between items of different, ascending stages when they are correlated direct or strong.
     *
     * @param array $items Items.
     * @param int $window Time window in seconds.
     * @return array[] Each with from (index), to (index), confidence.
     */
    public static function edges(array $items, int $window = 120): array {
        $edges = [];
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (self::stage_index($items[$i]['stage']) >= self::stage_index($items[$j]['stage'])) {
                    continue;
                }
                if (
                    abs($items[$j]['time'] - $items[$i]['time']) > $window && ($items[$i]['traceid'] === ''
                        || $items[$i]['traceid'] !== $items[$j]['traceid'])
                ) {
                    continue;
                }
                $conf = correlation::classify($items[$i], $items[$j], $window);
                if (correlation::rank($conf) >= correlation::rank(correlation::STRONG)) {
                    $edges[] = ['from' => $i, 'to' => $j, 'confidence' => $conf];
                }
            }
        }
        return $edges;
    }
}
