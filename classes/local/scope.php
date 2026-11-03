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
 * The scope of a connection report (issue #13): course, activity and period, set once above the tabs and applied to
 * every tab, sub-view and export.
 *
 * Validated on the server: an unknown course or activity is dropped with a visible message (never guessed), an activity
 * outside the chosen course is refused, an activity alone sets its course. Dates are calendar days in the viewer's time
 * zone; "from" is the start of that day, "to" the end of that day, stored and compared as UTC timestamps.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /** @var int Course id (0: all). */
    public $courseid = 0;

    /** @var int Course module id (0: all). */
    public $cmid = 0;

    /** @var int Start (UTC timestamp, 0: open). */
    public $from = 0;

    /** @var int End (UTC timestamp, inclusive, 0: open). */
    public $to = 0;

    /** @var string[] Problems with the input (string ids), shown to the user. */
    public $errors = [];

    /** @var string Course name (formatted) when set. */
    public $coursename = '';

    /** @var string Activity name (formatted) when set. */
    public $cmname = '';

    /**
     * Build from raw values.
     *
     * @param int $courseid Course id.
     * @param int $cmid Course module id.
     * @param string $from Start date (Y-m-d) or ''.
     * @param string $to End date (Y-m-d) or ''.
     * @return self
     */
    public static function make(int $courseid, int $cmid, string $from = '', string $to = ''): self {
        global $DB;
        $s = new self();
        if ($cmid > 0) {
            $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course');
            if (!$cm) {
                $s->errors[] = 'scope_err_cm';
            } else if ($courseid > 0 && (int) $cm->course !== $courseid) {
                $s->errors[] = 'scope_err_cmcourse';
            } else {
                $s->cmid = $cmid;
                $courseid = (int) $cm->course;
            }
        }
        if ($courseid > 0) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
            if (!$course) {
                $s->errors[] = 'scope_err_course';
                $s->cmid = 0;
            } else {
                $s->courseid = $courseid;
                $s->coursename = format_string($course->fullname, true, ['context' => \context_course::instance($courseid)]);
            }
        }
        if ($s->cmid) {
            try {
                $info = get_fast_modinfo($s->courseid)->get_cm($s->cmid);
                $s->cmname = $info->get_formatted_name();
            } catch (\moodle_exception $e) {
                $s->cmname = '';
            }
        }
        $s->from = self::day($from, false);
        $s->to = self::day($to, true);
        if (($from !== '' && !$s->from) || ($to !== '' && !$s->to)) {
            $s->errors[] = 'scope_err_date';
        }
        if ($s->from && $s->to && $s->from > $s->to) {
            $s->errors[] = 'scope_err_range';
            $s->from = 0;
            $s->to = 0;
        }
        return $s;
    }

    /**
     * Build from the request.
     *
     * @return self
     */
    public static function from_request(): self {
        return self::make(
            optional_param('courseid', 0, PARAM_INT),
            optional_param('cmid', 0, PARAM_INT),
            trim(optional_param('from', '', PARAM_TEXT)),
            trim(optional_param('to', '', PARAM_TEXT))
        );
    }

    /**
     * Timestamp of the start or end of a calendar day in the viewer's time zone.
     *
     * @param string $date Y-m-d.
     * @param bool $end End of the day.
     * @return int 0 when empty or invalid.
     */
    private static function day(string $date, bool $end): int {
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return 0;
        }
        $tz = \core_date::get_user_timezone_object();
        $d = \DateTime::createFromFormat('!Y-m-d', $date, $tz);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return 0;
        }
        if ($end) {
            $d->setTime(23, 59, 59);
        }
        return $d->getTimestamp();
    }

    /**
     * True when nothing restricts the report.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return !$this->courseid && !$this->cmid && !$this->from && !$this->to;
    }

    /**
     * URL parameters of the scope (dates as Y-m-d in the viewer's time zone).
     *
     * @return array
     */
    public function params(): array {
        $out = [];
        if ($this->courseid) {
            $out['courseid'] = $this->courseid;
        }
        if ($this->cmid) {
            $out['cmid'] = $this->cmid;
        }
        if ($this->from) {
            $out['from'] = userdate($this->from, '%Y-%m-%d', \core_date::get_user_timezone(), false);
        }
        if ($this->to) {
            $out['to'] = userdate($this->to, '%Y-%m-%d', \core_date::get_user_timezone(), false);
        }
        return $out;
    }

    /**
     * Filters in the form the existing sources read (courseid, cmid, from, to as timestamps).
     *
     * @return array
     */
    public function filters(): array {
        return ['courseid' => $this->courseid, 'cmid' => $this->cmid, 'from' => $this->from, 'to' => $this->to];
    }

    /**
     * True when a timestamp is inside the period.
     *
     * @param int $time Time.
     * @return bool
     */
    public function contains_time(int $time): bool {
        return (!$this->from || $time >= $this->from) && (!$this->to || $time <= $this->to);
    }

    /**
     * Readable parts of the scope (for the chips and export metadata).
     *
     * @return string[] HTML-safe text (course and activity names come from format_string(), the rest are ids and dates).
     */
    public function describe(): array {
        $out = [];
        if ($this->courseid) {
            $out['course'] = get_string('scope_course', 'tool_ltidiagnostics', (object) ['name' => $this->coursename,
                'id' => $this->courseid]);
        }
        if ($this->cmid) {
            $out['cm'] = get_string('scope_cm', 'tool_ltidiagnostics', (object) ['name' => $this->cmname, 'id' => $this->cmid]);
        }
        if ($this->from || $this->to) {
            $p = $this->params();
            $out['period'] = get_string('scope_period', 'tool_ltidiagnostics', (object) [
                'from' => $p['from'] ?? get_string('scope_open', 'tool_ltidiagnostics'),
                'to' => $p['to'] ?? get_string('scope_open', 'tool_ltidiagnostics'), 'tz' => \core_date::get_user_timezone()]);
        }
        return $out;
    }
}
