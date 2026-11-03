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

use moodle_url;

/**
 * Readable identities in the internal UI, graded by capability (issues #12, #15, #21, #23, #27).
 *
 * Tiers (independent of role names):
 * - `tool/ltidiagnostics:view` or `viewsupport`: connection names, local ids, issuer, client and deployment ids (LTI
 *   configuration, not secrets), course and activity names;
 * - `tool/ltidiagnostics:viewidentities`: learners' full names (personal data, purpose bound, audited by the pages);
 * - `tool/ltidiagnostics:viewgrades`: score values (unchanged).
 * Without a tier the UI shows the stable per-connection pseudonym. Pseudonyms remain the rule for documents that leave
 * the site (support package, partner package, receipts). Tokens, keys, JWTs and secrets are never shown to anyone.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class identity {
    /** @var array Cached user records (name fields). */
    private static $users = [];

    /** @var string[] Rendered course and activity references of this request, per viewer (edit links depend on rights). */
    private static $html = [];

    /**
     * True when the current user may see learners' names.
     *
     * @return bool
     */
    public static function can_see_names(): bool {
        return has_capability('tool/ltidiagnostics:viewidentities', \context_system::instance());
    }

    /**
     * True when the current user may see connection identities (names, local ids, issuer, client, deployment).
     *
     * @return bool
     */
    public static function can_see_connections(): bool {
        $ctx = \context_system::instance();
        return has_capability('tool/ltidiagnostics:view', $ctx) || has_capability('tool/ltidiagnostics:viewsupport', $ctx);
    }

    /**
     * Load users in one query (name fields only).
     *
     * @param int[] $userids Users.
     */
    public static function preload(array $userids): void {
        global $DB;
        $missing = array_values(array_diff(array_unique(array_map('intval', $userids)), array_keys(self::$users), [0]));
        foreach (array_chunk($missing, 500) as $chunk) {
            $fields = 'id, ' . implode(', ', \core_user\fields::get_name_fields()) . ', deleted';
            foreach ($DB->get_records_list('user', 'id', $chunk, '', $fields) as $u) {
                self::$users[(int) $u->id] = $u;
            }
        }
    }

    /**
     * Display of a learner: full name (with the pseudonym) when allowed, otherwise the pseudonym.
     *
     * @param string $connkey Connection key (pseudonym scope).
     * @param int $userid User.
     * @param bool|null $names Override of the capability check (null: check).
     * @return string Plain text.
     */
    public static function person(string $connkey, int $userid, ?bool $names = null): string {
        $pseudonym = grades\grade_diagnosis::pseudonym($connkey, $userid);
        if (!$userid || !($names ?? self::can_see_names())) {
            return $pseudonym;
        }
        self::preload([$userid]);
        $u = self::$users[$userid] ?? null;
        if (!$u) {
            return get_string('id_userunknown', 'tool_ltidiagnostics', $pseudonym);
        }
        return get_string('id_person', 'tool_ltidiagnostics', (object) ['name' => fullname($u), 'pseudonym' => $pseudonym]);
    }

    /**
     * Link to a user's profile when names are allowed, else the pseudonym (escaped HTML).
     *
     * @param string $connkey Connection key.
     * @param int $userid User.
     * @return string HTML.
     */
    public static function person_html(string $connkey, int $userid): string {
        $text = s(self::person($connkey, $userid));
        if ($userid && self::can_see_names()) {
            return \html_writer::link(new moodle_url('/user/profile.php', ['id' => $userid]), $text);
        }
        return $text;
    }

    /**
     * Course name with its id and links (view; edit when allowed).
     *
     * @param int $courseid Course.
     * @return string HTML ('' for 0).
     */
    public static function course_html(int $courseid): string {
        global $DB, $USER;
        if (!$courseid) {
            return '';
        }
        $cachekey = 'c' . $courseid . ':' . (int) ($USER->id ?? 0);
        if (isset(self::$html[$cachekey])) {
            return self::$html[$cachekey];
        }
        $c = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
        if (!$c) {
            return s(get_string('id_coursemissing', 'tool_ltidiagnostics', $courseid));
        }
        $ctx = \context_course::instance($courseid);
        $out = \html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), format_string(
            $c->fullname,
            true,
            ['context' => $ctx]
        )) . ' ' . s(get_string('id_idsuffix', 'tool_ltidiagnostics', $courseid));
        if (has_capability('moodle/course:update', $ctx)) {
            $out .= ' ' . \html_writer::link(
                new moodle_url('/course/edit.php', ['id' => $courseid]),
                get_string('id_editcourse', 'tool_ltidiagnostics'),
                ['class' => 'small']
            );
        }
        self::$html[$cachekey] = $out;
        return $out;
    }

    /**
     * Activity name with its cmid and links (view; edit settings when allowed).
     *
     * @param int $cmid Course module.
     * @return string HTML ('' for 0).
     */
    public static function cm_html(int $cmid): string {
        global $DB, $USER;
        if (!$cmid) {
            return '';
        }
        $cachekey = 'm' . $cmid . ':' . (int) ($USER->id ?? 0);
        if (isset(self::$html[$cachekey])) {
            return self::$html[$cachekey];
        }
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if (!$courseid) {
            return s(get_string('id_cmmissing', 'tool_ltidiagnostics', $cmid));
        }
        try {
            $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        } catch (\moodle_exception $e) {
            return s(get_string('id_cmmissing', 'tool_ltidiagnostics', $cmid));
        }
        $out = ($cm->url ? \html_writer::link($cm->url, $cm->get_formatted_name()) : s($cm->get_formatted_name()))
            . ' ' . s(get_string('id_cmidsuffix', 'tool_ltidiagnostics', $cmid));
        if (has_capability('moodle/course:manageactivities', $cm->context)) {
            $out .= ' ' . \html_writer::link(
                new moodle_url('/course/modedit.php', ['update' => $cmid]),
                get_string('id_editcm', 'tool_ltidiagnostics'),
                ['class' => 'small']
            );
        }
        self::$html[$cachekey] = $out;
        return $out;
    }

    /**
     * Clear the user cache (tests).
     */
    public static function reset(): void {
        self::$users = [];
        self::$html = [];
    }
}
