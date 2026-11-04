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

/**
 * Read-only access to the source grade of a published enrol_lti resource, for many users at once.
 *
 * The values are the ones core's sync_tool_grades task would send: the course total for a published course, the first
 * grade item (itemnumber 0) of the activity for a published activity, the final grade, the item maximum and the
 * date graded (overridden time, else time modified). Unlike core this reader never triggers a regrade: a course
 * total that still needs an update is reported as stale instead (the diagnosis must not change the gradebook).
 *
 * One query per data kind and resource, never one per user.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_reader {
    /** @var string Kind of the published resource: course. */
    public const KIND_COURSE = 'course';
    /** @var string Kind of the published resource: activity. */
    public const KIND_MODULE = 'module';
    /** @var int Maximum number of users per call (callers page). */
    public const MAXUSERS = 1000;

    /**
     * Describe the published resource (enrol_lti_tools row) without secrets.
     *
     * @param int $toolid enrol_lti_tools.id.
     * @return array|null id, kind, courseid, cmid, modname, instance, contextid, gradesync, gradesynccompletion, enabled,
     *     itemid, grademax, needsupdate, itemlocked, itemhidden, itemname; null when the resource does not exist.
     */
    public function resource(int $toolid): ?array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $sql = "SELECT t.id, t.contextid, t.enrolid, t.gradesync, t.gradesynccompletion, e.courseid, e.status AS enrolstatus,
                       ctx.contextlevel, ctx.instanceid
                  FROM {enrol_lti_tools} t
                  JOIN {enrol} e ON e.id = t.enrolid
             LEFT JOIN {context} ctx ON ctx.id = t.contextid
                 WHERE t.id = :id";
        $t = $DB->get_record_sql($sql, ['id' => $toolid]);
        if (!$t) {
            return null;
        }
        $out = [
            'id' => (int) $t->id,
            'kind' => '',
            'courseid' => (int) $t->courseid,
            'enrolid' => (int) $t->enrolid,
            'cmid' => 0,
            'modname' => '',
            'instance' => 0,
            'contextid' => (int) $t->contextid,
            'gradesync' => (int) $t->gradesync,
            'gradesynccompletion' => (int) $t->gradesynccompletion,
            'enabled' => (int) ((int) $t->enrolstatus === ENROL_INSTANCE_ENABLED),
            'contextvalid' => $t->contextlevel !== null,
            'itemid' => 0,
            'grademax' => null,
            'needsupdate' => 0,
            'itemlocked' => 0,
            'itemhidden' => 0,
        ];
        if ((int) $t->contextlevel === CONTEXT_COURSE) {
            $out['kind'] = self::KIND_COURSE;
            $item = $DB->get_record(
                'grade_items',
                ['courseid' => $out['courseid'], 'itemtype' => 'course'],
                'id, grademax, needsupdate, locked, hidden, gradetype'
            );
        } else if ((int) $t->contextlevel === CONTEXT_MODULE) {
            $out['kind'] = self::KIND_MODULE;
            $cm = $DB->get_record_sql("SELECT cm.id, cm.instance, m.name AS modname
                                         FROM {course_modules} cm
                                         JOIN {modules} m ON m.id = cm.module
                                        WHERE cm.id = :cmid", ['cmid' => (int) $t->instanceid]);
            $item = false;
            if ($cm) {
                $out['cmid'] = (int) $cm->id;
                $out['modname'] = (string) $cm->modname;
                $out['instance'] = (int) $cm->instance;
                $items = $DB->get_records('grade_items', [
                    'courseid' => $out['courseid'], 'itemtype' => 'mod', 'itemmodule' => $out['modname'],
                    'iteminstance' => $out['instance'],
                ], 'itemnumber ASC', 'id, grademax, needsupdate, locked, hidden, gradetype, itemnumber', 0, 1);
                $item = $items ? reset($items) : false;
            }
        } else {
            return $out;
        }
        if ($item) {
            $out['itemid'] = (int) $item->id;
            $out['grademax'] = ((int) $item->gradetype === GRADE_TYPE_NONE || (int) $item->gradetype === GRADE_TYPE_TEXT)
                ? 0.0 : (float) $item->grademax;
            $out['needsupdate'] = (int) $item->needsupdate;
            $out['itemlocked'] = (int) ((int) $item->locked > 0);
            $out['itemhidden'] = (int) ((int) $item->hidden > 0);
        }
        return $out;
    }

    /**
     * Source grades and completion of many users of one resource.
     *
     * @param array $resource Result of resource().
     * @param int[] $userids Local user ids (at most MAXUSERS).
     * @return array[] Keyed by user id: grade (float|null), state (none, null, zero, value), dategraded (int),
     *     overridden, locked, hidden (0/1), completed (null when gating is off, else bool), version (fingerprint).
     */
    public function grades(array $resource, array $userids): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (count($userids) > self::MAXUSERS) {
            throw new \coding_exception('Too many users for one call: ' . count($userids));
        }
        $out = [];
        foreach ($userids as $userid) {
            $out[$userid] = [
                'grade' => null,
                'state' => 'none',
                'dategraded' => 0,
                'overridden' => 0,
                'locked' => 0,
                'hidden' => 0,
                'completed' => null,
                'version' => '',
            ];
        }
        if (!$userids) {
            return $out;
        }
        if (!empty($resource['itemid'])) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $params['itemid'] = (int) $resource['itemid'];
            $rows = $DB->get_records_sql("SELECT userid, finalgrade, rawgrade, overridden, locked, hidden, timemodified,
                                                 feedback
                                            FROM {grade_grades}
                                           WHERE itemid = :itemid AND userid $insql", $params);
            foreach ($rows as $r) {
                $uid = (int) $r->userid;
                $grade = $r->finalgrade === null ? null : (float) $r->finalgrade;
                $dategraded = 0;
                if ($r->finalgrade !== null || $r->feedback !== null) {
                    $dategraded = (int) $r->overridden > 0 ? (int) $r->overridden : (int) $r->timemodified;
                }
                $out[$uid]['grade'] = $grade;
                $out[$uid]['state'] = $grade === null ? 'null' : (abs($grade) < 0.000005 ? 'zero' : 'value');
                $out[$uid]['dategraded'] = $dategraded;
                $out[$uid]['overridden'] = (int) ((int) $r->overridden > 0);
                $out[$uid]['locked'] = (int) ((int) $r->locked > 0);
                $out[$uid]['hidden'] = (int) ((int) $r->hidden > 0);
            }
        }
        if (!empty($resource['gradesynccompletion'])) {
            foreach ($out as $uid => $unused) {
                $out[$uid]['completed'] = false;
            }
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'c');
            if ($resource['kind'] === self::KIND_COURSE) {
                $params['courseid'] = (int) $resource['courseid'];
                $done = $DB->get_fieldset_sql("SELECT userid FROM {course_completions}
                                                WHERE course = :courseid AND timecompleted IS NOT NULL AND timecompleted > 0
                                                  AND userid $insql", $params);
            } else if ($resource['kind'] === self::KIND_MODULE && !empty($resource['cmid'])) {
                $params['cmid'] = (int) $resource['cmid'];
                $params['s1'] = COMPLETION_COMPLETE;
                $params['s2'] = COMPLETION_COMPLETE_PASS;
                $done = $DB->get_fieldset_sql("SELECT userid FROM {course_modules_completion}
                                                WHERE coursemoduleid = :cmid AND completionstate IN (:s1, :s2)
                                                  AND userid $insql", $params);
            } else {
                $done = [];
            }
            foreach ($done as $uid) {
                $out[(int) $uid]['completed'] = true;
            }
        }
        foreach ($out as $uid => $row) {
            $out[$uid]['version'] = self::version($resource, $row);
        }
        return $out;
    }

    /**
     * Fingerprint of the source state that a replay sends. A replay is refused when it changed after the preview.
     *
     * @param array $resource Result of resource().
     * @param array $row One row of grades().
     * @return string sha256 hex.
     */
    public static function version(array $resource, array $row): string {
        $grade = $row['grade'] === null ? 'null' : sprintf('%.5F', (float) $row['grade']);
        $max = $resource['grademax'] === null ? 'null' : sprintf('%.5F', (float) $resource['grademax']);
        return hash('sha256', implode('|', [
            (int) $resource['id'], (int) $resource['itemid'], $grade, $max, (int) $row['dategraded'],
            (int) $row['overridden'], (int) $row['locked'], $row['completed'] === null ? '-' : (int) $row['completed'],
        ]));
    }
}
