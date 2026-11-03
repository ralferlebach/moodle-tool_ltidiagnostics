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
 * readable label of a connection with its local ids, and direct links to the core LTI configuration.
 *
 * Core links are built only from ids of this site's own tables with the Moodle URL API, only when the target script
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
     * Direct links to the core configuration of a connection.
     *
     * @param connection $c Connection.
     * @return array[] Each: label (string), url (moodle_url), kind (registration, deployments, resources, tooltype).
     */
    public static function core_links(connection $c): array {
        global $CFG;
        $out = [];
        $system = \context_system::instance();
        if ($c->role === connection::ROLE_TOOL) {
            if (has_capability('moodle/site:config', $system) && is_file($CFG->dirroot . '/enrol/lti/register_platform.php')) {
                $out[] = ['kind' => 'registration', 'label' => get_string('nav_edit_registration', 'tool_ltidiagnostics'),
                    'url' => new moodle_url('/enrol/lti/register_platform.php', ['action' => 'edit', 'regid' => $c->localid])];
                $out[] = ['kind' => 'deployments', 'label' => get_string('nav_edit_deployments', 'tool_ltidiagnostics'),
                    'url' => new moodle_url('/enrol/lti/register_platform.php', ['action' => 'view', 'regid' => $c->localid,
                        'tabselect' => 'tooldeployments'])];
            }
            foreach (self::published_courses($c) as $courseid) {
                if (
                    is_file($CFG->dirroot . '/enrol/lti/index.php')
                    && has_capability('moodle/course:enrolreview', \context_course::instance($courseid))
                ) {
                    $out[] = ['kind' => 'resources', 'label' => get_string('nav_edit_resources', 'tool_ltidiagnostics', $courseid),
                        'url' => new moodle_url('/enrol/lti/index.php', ['courseid' => $courseid])];
                }
            }
        } else if (has_capability('moodle/site:config', $system) && is_file($CFG->dirroot . '/mod/lti/typessettings.php')) {
            $out[] = ['kind' => 'tooltype', 'label' => get_string('nav_edit_tooltype', 'tool_ltidiagnostics'),
                'url' => new moodle_url('/mod/lti/typessettings.php', ['action' => 'update', 'id' => $c->localid,
                    'sesskey' => sesskey(), 'returnto' => 'toolconfigure'])];
        }
        return $out;
    }

    /**
     * Courses with resources published through a tool side connection (bounded).
     *
     * @param connection $c Connection.
     * @return int[]
     */
    public static function published_courses(connection $c): array {
        global $DB;
        if ($c->role !== connection::ROLE_TOOL || !$c->deploymentrowid) {
            return [];
        }
        $rows = $DB->get_records_sql("SELECT DISTINCT e.courseid
                                        FROM {enrol_lti_resource_link} rl
                                        JOIN {enrol_lti_tools} t ON t.id = rl.resourceid
                                        JOIN {enrol} e ON e.id = t.enrolid
                                       WHERE rl.ltideploymentid = :dep
                                    ORDER BY e.courseid", ['dep' => $c->deploymentrowid], 0, 20);
        return array_map('intval', array_keys($rows));
    }

    /**
     * Courses and activities a connection reaches on this site (for the scope bar), bounded.
     *
     * Tool side: courses and activities of resources published through the deployment. Platform side: courses and
     * external tool activities using the tool type.
     *
     * @param connection $c Connection.
     * @return array courses (id => name), cms (id => [courseid, name])
     */
    public static function related(connection $c): array {
        global $DB;
        $courses = [];
        $cms = [];
        if ($c->role === connection::ROLE_TOOL && $c->deploymentrowid) {
            $rows = $DB->get_records_sql("SELECT DISTINCT t.id, e.courseid, ctx.contextlevel, ctx.instanceid
                                            FROM {enrol_lti_resource_link} rl
                                            JOIN {enrol_lti_tools} t ON t.id = rl.resourceid
                                            JOIN {enrol} e ON e.id = t.enrolid
                                       LEFT JOIN {context} ctx ON ctx.id = t.contextid
                                           WHERE rl.ltideploymentid = :dep", ['dep' => $c->deploymentrowid], 0, 200);
            foreach ($rows as $r) {
                $courses[(int) $r->courseid] = '';
                if ((int) $r->contextlevel === CONTEXT_MODULE) {
                    $cms[(int) $r->instanceid] = ['courseid' => (int) $r->courseid, 'name' => ''];
                }
            }
        } else if ($c->role === connection::ROLE_PLATFORM) {
            $rows = $DB->get_records_sql("SELECT cm.id, cm.course
                                            FROM {lti} l
                                            JOIN {modules} m ON m.name = 'lti'
                                            JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = l.id
                                           WHERE l.typeid = :type", ['type' => $c->localid], 0, 200);
            foreach ($rows as $r) {
                $courses[(int) $r->course] = '';
                $cms[(int) $r->id] = ['courseid' => (int) $r->course, 'name' => ''];
            }
        }
        if ($courses) {
            foreach ($DB->get_records_list('course', 'id', array_keys($courses), 'fullname', 'id, fullname') as $course) {
                $courses[(int) $course->id] = format_string(
                    $course->fullname,
                    true,
                    ['context' => \context_course::instance((int) $course->id)]
                );
            }
        }
        foreach ($cms as $cmid => $info) {
            try {
                $cms[$cmid]['name'] = get_fast_modinfo($info['courseid'])->get_cm($cmid)->get_formatted_name();
            } catch (\moodle_exception $e) {
                unset($cms[$cmid]);
            }
        }
        return ['courses' => array_filter($courses, fn($n) => $n !== ''), 'cms' => $cms];
    }
}
