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

namespace tool_ltidiagnostics\local\grades;

use tool_ltidiagnostics\local\connection;

/**
 * Copies the outcomes of core's grade sync task from the task log into the grade attempt journal (passive source).
 *
 * The task log keeps only the newest runs per task (task_logretainruns), so the journal is what makes these outcomes
 * auditable for longer. Every journal row of this source says exactly what core printed: a skip with its reason, a
 * send that core reported as successful (an HTTP 2xx, status unknown) or a failed send with its status class. The line
 * item and the subject of a core send are not printed by core and stay unknown; a resource link is attributed only when
 * the printed resource link id matches exactly one link of that user.
 *
 * Work per call is bounded (keyset over task_log.id, MAXRUNS runs) and idempotent (sourcekey per output line).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tasklog_importer {
    /** @var int Task log runs read per call. */
    public const MAXRUNS = 200;
    /** @var string Config name of the cursor (highest task_log id read). */
    public const CURSOR = 'gradetasklogcursor';

    /** @var array Map of task outcome => [journal state, skip reason]. */
    private const MAP = [
        'no_service' => ['skipped', 'no_service'],
        'invalid_context' => ['skipped', 'invalid_context'],
        'gated_course' => ['skipped', 'gated_course'],
        'gated_activity' => ['skipped', 'gated_activity'],
        'invalid_grade' => ['skipped', 'no_grade'],
        'invalid_grademax' => ['skipped', 'no_grademax'],
        'skipped_unchanged' => ['skipped', 'lastgrade_unchanged'],
        'sent_ok' => ['http_accepted', ''],
        'send_failed' => ['http_rejected', ''],
    ];

    /**
     * Import the runs written since the last call.
     *
     * @param int $maxruns Upper bound of runs read in this call.
     * @return array runs (read), rows (journal rows written), unknown (lines not understood), cursor (new cursor).
     */
    public function import(int $maxruns = self::MAXRUNS): array {
        global $DB;
        $cursor = (int) get_config('tool_ltidiagnostics', self::CURSOR);
        $runs = $DB->get_records_select(
            'task_log',
            'classname = :c AND id > :cursor',
            ['c' => tasklog_parser::TASKCLASS, 'cursor' => $cursor],
            'id ASC',
            'id, timestart, result, output',
            0,
            max(1, min(self::MAXRUNS, $maxruns))
        );
        $stats = ['runs' => 0, 'rows' => 0, 'unknown' => 0, 'cursor' => $cursor];
        foreach ($runs as $run) {
            $parsed = tasklog_parser::parse((string) $run->output);
            $stats['unknown'] += $parsed['unknown'];
            $stats['rows'] += $this->store_run((int) $run->id, (int) $run->timestart, $parsed);
            $stats['runs']++;
            $stats['cursor'] = (int) $run->id;
        }
        if ($stats['cursor'] !== $cursor) {
            set_config(self::CURSOR, $stats['cursor'], 'tool_ltidiagnostics');
        }
        return $stats;
    }

    /**
     * Store the events of one run.
     *
     * @param int $runid task_log.id.
     * @param int $time Start of the run.
     * @param array $parsed Result of tasklog_parser::parse().
     * @return int Rows written.
     */
    private function store_run(int $runid, int $time, array $parsed): int {
        global $DB;
        $events = array_filter($parsed['events'], fn($e) => $e['user'] > 0 && $e['resource'] > 0);
        if (!$events) {
            return 0;
        }
        $links = $this->links_for($events);
        $keys = [];
        foreach ($events as $e) {
            $keys[] = sha1('tasklog:' . $runid . ':' . $e['line']);
        }
        [$ksql, $kparams] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED, 'k');
        $existing = array_flip($DB->get_fieldset_select(journal::TABLE, 'sourcekey', "sourcekey $ksql", $kparams));
        $written = 0;
        foreach ($events as $e) {
            $key = sha1('tasklog:' . $runid . ':' . $e['line']);
            if (isset($existing[$key])) {
                continue;
            }
            [$state, $skip] = self::MAP[$e['outcome']];
            $link = $links[$e['resource'] . ':' . $e['user'] . ':' . $e['resourcelink']] ?? null;
            $grade = $e['grade'] !== '' && is_numeric($e['grade']) ? (float) $e['grade'] : null;
            journal::insert([
                'attemptid' => journal::new_id(),
                'origin' => journal::ORIGIN_CORE,
                'source' => 'task_log',
                'sourcekey' => $key,
                'connkey' => $link ? $link['connkey'] : '',
                'deploymentrowid' => $link ? $link['deploymentrowid'] : 0,
                'resourcelinkrowid' => $link ? $link['id'] : 0,
                'toolid' => $e['resource'],
                'userid' => $e['user'],
                // Core prints the normalised grade (score / maximum); it is stored as such, with a maximum of 1.
                'scoregiven' => $grade,
                'scoremax' => $grade === null ? null : 1.0,
                'state' => $state,
                'skipreason' => $skip,
                'httpstatus' => $e['httpstatus'],
                'errorclass' => $e['errorclass'] === 'unclassified' && $e['httpstatus'] === 0 ? 'unclassified'
                    : $e['errorclass'],
                'evidence' => 'task_log',
                'tokenstatus' => 'none',
                'timecreated' => $time,
                'timemodified' => $time,
            ]);
            $written++;
        }
        return $written;
    }

    /**
     * Resource link rows for the events, attributed only when exactly one link of that user carries the printed id.
     *
     * @param array[] $events Events.
     * @return array Keyed "resource:user:resourcelinkid" => [id, deploymentrowid, connkey].
     */
    private function links_for(array $events): array {
        global $DB;
        $resources = array_values(array_unique(array_column($events, 'resource')));
        $users = array_values(array_unique(array_column($events, 'user')));
        [$rsql, $rparams] = $DB->get_in_or_equal($resources, SQL_PARAMS_NAMED, 'r');
        [$usql, $uparams] = $DB->get_in_or_equal($users, SQL_PARAMS_NAMED, 'u');
        $sql = "SELECT url.id AS mapid, rl.id, rl.resourcelinkid, rl.resourceid, rl.ltideploymentid, lu.userid,
                       d.platformid AS registrationid
                  FROM {enrol_lti_users} lu
                  JOIN {enrol_lti_user_resource_link} url ON url.ltiuserid = lu.id
                  JOIN {enrol_lti_resource_link} rl ON rl.id = url.resourcelinkid AND rl.resourceid = lu.toolid
                  JOIN {enrol_lti_deployment} d ON d.id = rl.ltideploymentid
                 WHERE lu.toolid $rsql AND lu.userid $usql";
        $candidates = [];
        foreach ($DB->get_records_sql($sql, $rparams + $uparams) as $r) {
            $k = (int) $r->resourceid . ':' . (int) $r->userid . ':' . (string) $r->resourcelinkid;
            $candidates[$k][] = [
                'id' => (int) $r->id,
                'deploymentrowid' => (int) $r->ltideploymentid,
                'connkey' => connection::make_key(connection::ROLE_TOOL, (int) $r->registrationid, (int) $r->ltideploymentid),
            ];
        }
        $out = [];
        foreach ($candidates as $k => $list) {
            if (count($list) === 1) {
                $out[$k] = $list[0];
            }
        }
        return $out;
    }
}
