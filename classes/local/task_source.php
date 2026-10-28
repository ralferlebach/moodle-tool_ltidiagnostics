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
 * Evidence source: scheduled and ad-hoc task inventory and the task log.
 *
 * Uses \core\task\manager for definitions and queue state. The task log has no reader API in
 * Moodle, so the core table task_log is read-only and paginated; this is documented as gate G4.
 * A task log line proves that a task ran, not that a remote system accepted its result.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_source {
    /** @var string[] Component prefixes of the LTI family. */
    private const PREFIXES = ['enrol_lti', 'mod_lti', 'auth_lti', 'tool_ltidiagnostics', 'ltiservice_', 'ltisource_',
        'local_lti'];

    /**
     * Whether a task class or component belongs to the LTI family.
     *
     * @param string $classname Class name.
     * @param string $component Component.
     * @return bool
     */
    public static function is_relevant(string $classname, string $component = ''): bool {
        $classname = ltrim($classname, '\\');
        foreach (self::PREFIXES as $prefix) {
            if (strpos($classname, $prefix) === 0 || strpos($component, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cron health from the core cron bookkeeping.
     *
     * @param int $now Current time.
     * @return array lastcronstart (int), age (int seconds, -1 when never), status (ok|stale|never)
     */
    public function cron_status(int $now): array {
        $last = (int) get_config('tool_task', 'lastcronstart');
        if ($last <= 0) {
            return ['lastcronstart' => 0, 'age' => -1, 'status' => 'never'];
        }
        $age = max(0, $now - $last);
        return ['lastcronstart' => $last, 'age' => $age, 'status' => $age > 3600 ? 'stale' : 'ok'];
    }

    /**
     * Scheduled tasks of the LTI family with their state.
     *
     * @param int $now Current time.
     * @return array[]
     */
    public function scheduled(int $now): array {
        $out = [];
        foreach (\core\task\manager::get_all_scheduled_tasks() as $task) {
            $class = get_class($task);
            if (!self::is_relevant($class, (string) $task->get_component())) {
                continue;
            }
            $next = (int) $task->get_next_run_time();
            $out[] = [
                'classname' => '\\' . ltrim($class, '\\'),
                'component' => (string) $task->get_component(),
                'disabled' => (bool) $task->get_disabled(),
                'lastrun' => (int) $task->get_last_run_time(),
                'nextrun' => $next,
                'faildelay' => (int) $task->get_fail_delay(),
                'overdue' => !$task->get_disabled() && $next > 0 && $next < $now - 3600,
            ];
        }
        return $out;
    }

    /**
     * Ad-hoc queue summary of the LTI family.
     *
     * @return array[] Each with component, classname, count, failed, running, due.
     */
    public function adhoc(): array {
        $out = [];
        foreach (\core\task\manager::get_adhoc_tasks_summary() as $component => $classes) {
            foreach ($classes as $classname => $s) {
                if (!self::is_relevant($classname, (string) $component)) {
                    continue;
                }
                $out[] = ['component' => (string) $component, 'classname' => $classname] + array_intersect_key(
                    $s,
                    array_flip(['count', 'failed', 'running', 'due'])
                );
            }
        }
        return $out;
    }

    /**
     * Read the task log for the LTI family, newest first.
     *
     * @param array $filters Optional: from, to (unix), limit (max 500), offset, onlyfailed (bool).
     * @return array[] Each with classname, component, type, start, duration, failed, output (redacted, shortened).
     */
    public function task_log(array $filters = []): array {
        global $DB;
        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        $likes = [];
        $params = [];
        foreach (self::PREFIXES as $i => $c) {
            $likes[] = $DB->sql_like('component', ':c' . $i, false) . ' OR ' . $DB->sql_like('classname', ':k' . $i, false);
            $params['c' . $i] = $DB->sql_like_escape($c) . '%';
            $params['k' . $i] = '%' . $DB->sql_like_escape($c) . '%';
        }
        $where = '(' . implode(' OR ', $likes) . ')';
        if (!empty($filters['from'])) {
            $where .= ' AND timestart >= :tfrom';
            $params['tfrom'] = (int) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where .= ' AND timestart <= :tto';
            $params['tto'] = (int) $filters['to'];
        }
        if (!empty($filters['onlyfailed'])) {
            $where .= ' AND result = 1';
        }
        $out = [];
        foreach ($DB->get_records_select('task_log', $where, $params, 'timestart DESC, id DESC', '*', $offset, $limit) as $r) {
            $out[] = [
                'id' => (int) $r->id,
                'classname' => (string) $r->classname,
                'component' => (string) $r->component,
                'type' => (int) $r->type === 1 ? 'adhoc' : 'scheduled',
                'start' => (int) $r->timestart,
                'duration' => max(0, (float) $r->timeend - (float) $r->timestart),
                'failed' => (int) $r->result === 1,
                'output' => \core_text::substr(redactor::text((string) $r->output), 0, 200),
            ];
        }
        return $out;
    }

    /**
     * How far back the task log can reach.
     *
     * Moodle keeps only the newest N runs per task ("task_logretainruns"). Once an LTI task has N entries, older runs
     * are gone, so an empty result for an earlier period proves nothing.
     *
     * @return array retainruns (int), count (int), oldest (int, 0 if none), newest (int), saturated (bool)
     */
    public function coverage(): array {
        global $CFG, $DB;
        $retain = (int) ($CFG->task_logretainruns ?? 0);
        $likes = [];
        $params = [];
        foreach (self::PREFIXES as $i => $c) {
            $likes[] = $DB->sql_like('classname', ':k' . $i, false);
            $params['k' . $i] = '%' . $DB->sql_like_escape($c) . '%';
        }
        $rows = $DB->get_records_sql(
            'SELECT classname, COUNT(1) AS runs, MIN(timestart) AS oldest, MAX(timestart) AS newest FROM {task_log} WHERE ('
            . implode(' OR ', $likes) . ') GROUP BY classname',
            $params
        );
        $count = 0;
        $oldest = 0;
        $newest = 0;
        $saturated = false;
        foreach ($rows as $r) {
            $count += (int) $r->runs;
            $oldest = $oldest === 0 ? (int) $r->oldest : min($oldest, (int) $r->oldest);
            $newest = max($newest, (int) $r->newest);
            $saturated = $saturated || ($retain > 0 && (int) $r->runs >= $retain);
        }
        return ['retainruns' => $retain, 'count' => $count, 'oldest' => $oldest, 'newest' => $newest, 'saturated' => $saturated];
    }

    /**
     * Cron windows: periods in which any scheduled or ad hoc task ran, merged from the task log (all components, the
     * cron runs core tasks every minute), and the gaps between them. A gap longer than the threshold is a period
     * without cron. The query is bounded (indexed column timestart, at most $limit rows, the newest first); when the
     * limit is reached the result says so and the earliest part of the period stays unknown.
     *
     * @param int $from Start (unix time).
     * @param int $to End (unix time).
     * @param int $gap Smallest gap that counts (seconds).
     * @param int $limit Row limit.
     * @return array windows (list of [start, end]), gaps (list of [start, end]), rows (int), truncated (bool),
     *         coveredfrom (int: the earliest time the answer is complete for)
     */
    public function cron_windows(int $from, int $to, int $gap = 3600, int $limit = 5000): array {
        global $DB;
        $limit = max(10, min(20000, $limit));
        $rs = $DB->get_recordset_select(
            'task_log',
            'timestart >= :tfrom AND timestart <= :tto',
            ['tfrom' => $from, 'tto' => $to],
            'timestart DESC',
            'id, timestart, timeend',
            0,
            $limit
        );
        $runs = [];
        foreach ($rs as $r) {
            $runs[] = [(int) floor((float) $r->timestart), (int) ceil(max((float) $r->timeend, (float) $r->timestart))];
        }
        $rs->close();
        $truncated = count($runs) >= $limit;
        $runs = array_reverse($runs);
        $coveredfrom = $truncated && $runs ? $runs[0][0] : $from;
        $windows = [];
        foreach ($runs as [$start, $end]) {
            $n = count($windows);
            if ($n && $start - $windows[$n - 1][1] < $gap) {
                $windows[$n - 1][1] = max($windows[$n - 1][1], $end);
            } else {
                $windows[] = [$start, $end];
            }
        }
        $gaps = [];
        $cursor = $coveredfrom;
        foreach ($windows as [$start, $end]) {
            if ($start - $cursor >= $gap) {
                $gaps[] = [$cursor, $start];
            }
            $cursor = max($cursor, $end);
        }
        if ($to - $cursor >= $gap) {
            $gaps[] = [$cursor, $to];
        }
        return ['windows' => $windows, 'gaps' => $gaps, 'rows' => count($runs), 'truncated' => $truncated,
            'coveredfrom' => $coveredfrom];
    }
}
