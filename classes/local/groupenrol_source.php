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
 * Read-only adapter for the optional plugin tool_ltigroupautoenrol (audit item GA-03): which LTI tools of a tool-side
 * connection assign groups, whether assignments were observed and which failures that plugin recorded.
 *
 * No dependency: no class of tool_ltigroupautoenrol is loaded. Its table is read only after table_exists(), and only
 * the columns that exist are used (event_errors and friends since 1.2.0). Its events group_assignment_completed and
 * group_assignment_failed (from 1.2.1) are read like every other event, from the own observer and the log stores;
 * older versions are diagnosed from the fallback signals: the core event group_member_added in the launch request
 * (recorded by the observer with the same trace id as the enrolment) and the failure counter of the course.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groupenrol_source {
    /** @var string Component. */
    public const COMPONENT = 'tool_ltigroupautoenrol';
    /** @var string Event: assignment done. */
    public const EVENT_COMPLETED = '\tool_ltigroupautoenrol\event\group_assignment_completed';
    /** @var string Event: assignment failed. */
    public const EVENT_FAILED = '\tool_ltigroupautoenrol\event\group_assignment_failed';

    /**
     * Whether the plugin is installed (its table exists).
     *
     * @param string $table Table name (tests use a copy of the table).
     * @return bool
     */
    public static function installed(string $table = self::COMPONENT): bool {
        global $DB;
        return $DB->get_manager()->table_exists($table);
    }

    /**
     * Whether the installed version emits its own events.
     *
     * @return bool
     */
    public static function has_events(): bool {
        return class_exists(self::EVENT_COMPLETED) && class_exists(self::EVENT_FAILED);
    }

    /**
     * Facts of a tool-side connection.
     *
     * @param connection $c Tool connection.
     * @param array $obs Observation summary (group, enrolment, groupassign, groupassign_failed times).
     * @param string $table Table name (tests use a copy of the table).
     * @return array installed, events (bool), courses, mappedtools, mappedgroups, enabled, failures, lastfailure,
     *         firstfailure, backfill (time), assigned (time), failedevents (time)
     */
    public static function facts(connection $c, array $obs = [], string $table = self::COMPONENT): array {
        global $DB;
        $out = ['installed' => self::installed($table), 'events' => self::has_events(), 'courses' => 0, 'enabled' => 0,
            'mappedtools' => 0, 'mappedgroups' => 0, 'failures' => 0, 'firstfailure' => 0, 'lastfailure' => 0, 'backfill' => 0,
            'assigned' => max((int) ($obs['group'] ?? 0), (int) ($obs['groupassign'] ?? 0)),
            'failedevents' => (int) ($obs['groupassign_failed'] ?? 0)];
        if (!$out['installed'] || !$c->tools) {
            return $out;
        }
        $courses = array_values(array_unique(array_map(function ($t) {
            return (int) $t['courseid'];
        }, $c->tools)));
        $columns = $DB->get_columns($table);
        $fields = ['id', 'courseid', 'enable_enrol', 'settings'];
        foreach (['event_errors', 'event_errortime', 'event_errorlast', 'backfill_time'] as $optional) {
            if (isset($columns[$optional])) {
                $fields[] = $optional;
            }
        }
        [$insql, $params] = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED);
        foreach ($DB->get_records_select($table, "courseid $insql", $params, 'courseid', implode(', ', $fields)) as $r) {
            $out['courses']++;
            if (empty($r->enable_enrol)) {
                continue;
            }
            $out['enabled']++;
            $mapping = json_decode((string) $r->settings, true);
            foreach (is_array($mapping) ? $mapping : [] as $toolid => $groups) {
                if (isset($c->tools[(int) $toolid]) && is_array($groups) && $groups) {
                    $out['mappedtools']++;
                    $out['mappedgroups'] += count($groups);
                }
            }
            $out['failures'] += (int) ($r->event_errors ?? 0);
            if (!empty($r->event_errortime)) {
                $out['firstfailure'] = $out['firstfailure'] ? min($out['firstfailure'], (int) $r->event_errortime)
                    : (int) $r->event_errortime;
            }
            $out['lastfailure'] = max($out['lastfailure'], (int) ($r->event_errorlast ?? 0), (int) ($r->event_errortime ?? 0));
            $out['backfill'] = max($out['backfill'], (int) ($r->backfill_time ?? 0));
        }
        return $out;
    }

    /**
     * Findings for the core layer of a tool-side connection.
     *
     * @param array $g Facts of {@see self::facts()}.
     * @param array $obs Observation summary.
     * @param int $now Time.
     * @return finding[]
     */
    public static function findings(array $g, array $obs, int $now): array {
        if (!$g['installed'] || $g['mappedtools'] === 0) {
            return [];
        }
        $out = [];
        $a = ['checkedat' => $now, 'feature' => 'groups'];
        if ($g['failures'] > 0 || ($g['failedevents'] > 0 && $g['failedevents'] >= $g['assigned'])) {
            $out[] = new finding('ga_failures', finding::L_CORE, finding::WARNING, finding::ADMIN, $a + [
                'a' => (object) ['count' => max($g['failures'], 1), 'time' => userdate(max($g['lastfailure'], $g['failedevents']))],
                'evidencekind' => status::KIND_DBRECORD, 'certainty' => finding::CERTAIN,
                'evidence' => $g['failures'] > 0 ? 'tool_ltigroupautoenrol.event_errors' : 'event:group_assignment_failed']);
        }
        $enrolled = (int) ($obs['enrolment'] ?? 0);
        if ($g['assigned'] > 0) {
            $out[] = new finding('ga_assigned', finding::L_CORE, finding::OK, finding::ADMIN, $a + [
                'a' => userdate($g['assigned']), 'evidencekind' => status::KIND_LOCALEFFECT]);
        } else if ($enrolled > 0) {
            // An LTI enrolment of a mapped tool without any group membership in the launch request.
            $out[] = new finding('ga_assignment_missing', finding::L_CORE, finding::WARNING, finding::ADMIN, $a + [
                'evidencekind' => status::KIND_LOCALEFFECT, 'certainty' => finding::LIKELY]);
        }
        return $out;
    }
}
