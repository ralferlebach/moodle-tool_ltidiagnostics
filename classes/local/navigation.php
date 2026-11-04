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
 * Navigation of the plugin's pages (issue #12): the way back to the connection overview with its last filters, the
 * readable label of a connection with its local ids, and the edit pencils of connections and publications (issue #30).
 *
 * Edit links are built only from ids of this site's own tables with the Moodle URL API, only when the target script
 * exists in this Moodle version and the user has the capability the target page requires; never from issuer or partner
 * URLs.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class navigation {
    /** @var string Session key of the overview filters. */
    private const SESSIONKEY = 'tool_ltidiagnostics_overview';

    /**
     * Remember the filters of the connection overview (search, role, course).
     *
     * @param array $params Filters.
     */
    public static function remember_overview(array $params): void {
        global $SESSION;
        $SESSION->{self::SESSIONKEY} = array_filter($params, fn($v) => $v !== '' && $v !== 0 && $v !== null);
    }

    /**
     * URL of the connection overview with the remembered filters.
     *
     * @return moodle_url
     */
    public static function overview_url(): moodle_url {
        global $SESSION;
        $params = $SESSION->{self::SESSIONKEY} ?? [];
        return new moodle_url('/admin/tool/ltidiagnostics/index.php', is_array($params) ? $params : []);
    }

    /**
     * Link back to the connection overview.
     *
     * @return string HTML.
     */
    public static function back_link(): string {
        return \html_writer::link(
            self::overview_url(),
            get_string('nav_backtooverview', 'tool_ltidiagnostics'),
            ['class' => 'd-inline-block mb-2', 'id' => 'tool_ltidiagnostics_backtooverview']
        );
    }

    /**
     * Readable label of a connection: name and the local ids, each with its own label.
     *
     * @param connection $c Connection.
     * @return string Plain text.
     */
    public static function label(connection $c): string {
        if ($c->role === connection::ROLE_TOOL) {
            $ids = get_string('nav_ids_tool', 'tool_ltidiagnostics', (object) ['reg' => $c->localid,
                'dep' => $c->deploymentrowid]);
        } else {
            $ids = get_string('nav_ids_platform', 'tool_ltidiagnostics', $c->localid);
        }
        return get_string('nav_connlabel', 'tool_ltidiagnostics', (object) ['name' => $c->name, 'ids' => $ids]);
    }

    /**
     * Edit target of the connection itself (issue #30): the platform registration (tool role) or the external tool type
     * (platform role), only with moodle/site:config and when the core script exists.
     *
     * @param connection $c Connection.
     * @return array|null url (moodle_url), label (accessible name of the pencil)
     */
    public static function edit_connection(connection $c): ?array {
        global $CFG;
        if (!has_capability('moodle/site:config', \context_system::instance())) {
            return null;
        }
        if ($c->role === connection::ROLE_TOOL && is_file($CFG->dirroot . '/enrol/lti/register_platform.php')) {
            return ['url' => new moodle_url('/enrol/lti/register_platform.php', ['action' => 'edit', 'regid' => $c->localid]),
                'label' => get_string('nav_edit_registration_of', 'tool_ltidiagnostics', $c->name)];
        }
        if ($c->role === connection::ROLE_PLATFORM && is_file($CFG->dirroot . '/mod/lti/typessettings.php')) {
            return ['url' => new moodle_url('/mod/lti/typessettings.php', ['action' => 'update', 'id' => $c->localid,
                'sesskey' => sesskey(), 'returnto' => 'toolconfigure']),
                'label' => get_string('nav_edit_tooltype_of', 'tool_ltidiagnostics', $c->name)];
        }
        return null;
    }

    /**
     * Edit target of a published resource (enrol_lti instance), only with enrol/lti:config in its course.
     *
     * @param int $courseid Course.
     * @param int $enrolid Enrolment instance of the publication.
     * @return moodle_url|null
     */
    public static function edit_publication(int $courseid, int $enrolid): ?moodle_url {
        global $CFG;
        if (!$courseid || !$enrolid || !is_file($CFG->dirroot . '/enrol/editinstance.php')) {
            return null;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || !has_capability('enrol/lti:config', $context)) {
            return null;
        }
        return new moodle_url('/enrol/editinstance.php', ['type' => 'lti', 'courseid' => $courseid, 'id' => $enrolid]);
    }

    /**
     * The standard edit pencil of Moodle, as a link with an accessible name (issue #30 AK4).
     *
     * @param moodle_url $url Target.
     * @param string $label Accessible name, for example "Edit Graded task".
     * @return string HTML.
     */
    public static function pencil(moodle_url $url, string $label): string {
        global $OUTPUT;
        return \html_writer::link($url, $OUTPUT->pix_icon('i/edit', $label), ['class' => 'ltidiag-edit ml-1 ms-1',
            'aria-label' => $label, 'title' => $label]);
    }

    /**
     * Courses and activities a connection reaches on this site (for the scope bar), from its proven use (issue #29).
     *
     * @param connection $c Connection (with usage from the inventory).
     * @return array courses (id => "name (id: n)", HTML-safe), cms (id => [courseid, name "name (id: n)", HTML-safe])
     */
    public static function related(connection $c): array {
        return self::related_usage($c->usage);
    }

    /**
     * Courses and activities of a set of usage entries (one connection, or all connections of the overview).
     *
     * @param array[] $usage Usage entries (courseid, cmid).
     * @return array courses (id => "name (id: n)"), cms (id => [courseid, name]); only existing ones, sorted by name.
     */
    public static function related_usage(array $usage): array {
        $names = names::for_usage($usage);
        $courses = [];
        $cms = [];
        foreach ($usage as $u) {
            if (isset($names['courses'][$u['courseid']])) {
                $courses[$u['courseid']] = names::course((int) $u['courseid']);
            }
            if ($u['cmid'] && isset($names['cms'][$u['cmid']])) {
                $cms[$u['cmid']] = ['courseid' => (int) $u['courseid'], 'name' => names::cm(
                    (int) $u['cmid'],
                    (int) $u['courseid']
                )];
            }
        }
        \core_collator::asort($courses);
        uasort($cms, fn($a, $b) => strcmp(\core_text::strtolower($a['name']), \core_text::strtolower($b['name'])));
        return ['courses' => $courses, 'cms' => $cms];
    }
}
