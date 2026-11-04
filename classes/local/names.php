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
 * Readable names of courses, activities and published resources from the Moodle API (issues #29, #30): one query for
 * the courses, the course module info of core (cached per course) for the activities - never one query per row.
 *
 * Names are formatted with format_string() (HTML-safe); the id follows in brackets: "Physics I (id: 12)".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class names {
    /** @var string[] Formatted course names of this request. */
    private static $courses = [];

    /** @var string[] Formatted activity names of this request. */
    private static $cms = [];

    /** @var bool[] Course ids already looked up that do not exist (asked only once per request). */
    private static $nocourse = [];

    /**
     * Load the names of courses and activities in bulk.
     *
     * @param int[] $courseids Courses.
     * @param array $cms Course module id => course id.
     */
    public static function preload(array $courseids, array $cms = []): void {
        global $DB;
        $missing = array_values(array_diff(
            array_unique(array_map('intval', array_merge($courseids, array_values($cms)))),
            array_keys(self::$courses),
            array_keys(self::$nocourse),
            [0]
        ));
        foreach (array_chunk($missing, 500) as $chunk) {
            // The course contexts come with the courses, so that format_string() needs no query per course.
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'nc');
            $ctxfields = \context_helper::get_preload_record_columns_sql('ctx');
            $params['ctxlevel'] = CONTEXT_COURSE;
            $rs = $DB->get_recordset_sql("SELECT c.id, c.fullname, $ctxfields
                                            FROM {course} c
                                       LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                                           WHERE c.id $in", $params);
            foreach ($rs as $c) {
                \context_helper::preload_from_record($c);
                self::$courses[(int) $c->id] = format_string(
                    $c->fullname,
                    true,
                    ['context' => \context_course::instance((int) $c->id)]
                );
            }
            $rs->close();
            foreach ($chunk as $id) {
                if (!isset(self::$courses[$id])) {
                    self::$nocourse[$id] = true;
                }
            }
        }
        foreach ($cms as $cmid => $courseid) {
            if (isset(self::$cms[(int) $cmid]) || !isset(self::$courses[(int) $courseid])) {
                continue;
            }
            try {
                self::$cms[(int) $cmid] = get_fast_modinfo((int) $courseid)->get_cm((int) $cmid)->get_formatted_name();
            } catch (\moodle_exception $e) {
                // A deleted activity keeps no name; it is shown as missing.
                continue;
            }
        }
    }

    /**
     * Names for the usage entries of a connection.
     *
     * @param array[] $usage Usage entries (courseid, cmid).
     * @return array courses (id => name), cms (id => name)
     */
    public static function for_usage(array $usage): array {
        $cms = [];
        foreach ($usage as $u) {
            if (!empty($u['cmid'])) {
                $cms[(int) $u['cmid']] = (int) $u['courseid'];
            }
        }
        self::preload(array_column($usage, 'courseid'), $cms);
        $out = ['courses' => [], 'cms' => []];
        foreach ($usage as $u) {
            if (isset(self::$courses[(int) $u['courseid']])) {
                $out['courses'][(int) $u['courseid']] = self::$courses[(int) $u['courseid']];
            }
            if (!empty($u['cmid']) && isset(self::$cms[(int) $u['cmid']])) {
                $out['cms'][(int) $u['cmid']] = self::$cms[(int) $u['cmid']];
            }
        }
        return $out;
    }

    /**
     * "Course name (id: 12)", or the missing state.
     *
     * @param int $courseid Course.
     * @return string HTML-safe text.
     */
    public static function course(int $courseid): string {
        self::preload([$courseid]);
        if (!isset(self::$courses[$courseid])) {
            return s(get_string('id_coursemissing', 'tool_ltidiagnostics', $courseid));
        }
        return self::$courses[$courseid] . ' ' . s(get_string('id_idsuffix', 'tool_ltidiagnostics', $courseid));
    }

    /**
     * "Activity name (id: 34)", or the missing state.
     *
     * @param int $cmid Course module.
     * @param int $courseid Its course.
     * @return string HTML-safe text.
     */
    public static function cm(int $cmid, int $courseid): string {
        self::preload([$courseid], [$cmid => $courseid]);
        if (!isset(self::$cms[$cmid])) {
            return s(get_string('id_cmmissing', 'tool_ltidiagnostics', $cmid));
        }
        return self::$cms[$cmid] . ' ' . s(get_string('id_cmidsuffix', 'tool_ltidiagnostics', $cmid));
    }

    /**
     * Name of a published resource: the activity for an activity publication, the course for a course publication.
     *
     * @param array $u Usage entry or resource (courseid, cmid).
     * @return string HTML-safe text.
     */
    public static function publication(array $u): string {
        return !empty($u['cmid']) ? self::cm((int) $u['cmid'], (int) $u['courseid']) : self::course((int) $u['courseid']);
    }

    /**
     * Plain text of a name for an accessible label (the label is escaped again where it is used).
     *
     * @param string $html HTML-safe name.
     * @return string
     */
    public static function plain(string $html): string {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Forget the names (tests).
     */
    public static function reset(): void {
        self::$courses = [];
        self::$cms = [];
        self::$nocourse = [];
    }
}
