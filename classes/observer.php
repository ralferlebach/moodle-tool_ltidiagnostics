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

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\local\config;
use tool_ltidiagnostics\local\entrypoint;
use tool_ltidiagnostics\local\observation_store;
use tool_ltidiagnostics\local\resolver;

/**
 * Event observer recording LTI related events as minimal observations.
 *
 * Records only that something happened (event name, ids, entry point, key names). It never reads
 * or stores names, e-mail addresses, claims, tokens or event payload values.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Entry point for every observed event.
     *
     * Failures never propagate: diagnostics must not break a launch.
     *
     * @param \core\event\base $event The event.
     */
    public static function handle(\core\event\base $event): void {
        if (!config::get('recordevents')) {
            return;
        }
        try {
            $data = self::build($event);
            if ($data !== null) {
                observation_store::record($data);
            }
        } catch (\Throwable $e) {
            debugging('tool_ltidiagnostics observer failed: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }

    /**
     * Turn an event into an observation, or null when it is not LTI related.
     *
     * @param \core\event\base $event The event.
     * @return array|null Observation data.
     */
    public static function build(\core\event\base $event): ?array {
        global $DB;
        if ($event->component === 'tool_ltidiagnostics') {
            return null;
        }
        $entry = entrypoint::detect();
        $base = [
            'eventname' => $event->eventname,
            'component' => $event->component,
            'courseid' => (int) $event->courseid,
            'contextid' => (int) $event->contextid,
            'objectid' => (int) $event->objectid,
            'entrypoint' => $entry,
            'timecreated' => (int) $event->timecreated,
            'fieldnames' => self::field_names($event),
        ];
        switch ($event->eventname) {
            case '\core\event\user_loggedin':
            case '\core\event\user_created':
                $userid = (int) $event->objectid;
                if ($DB->get_field('user', 'auth', ['id' => $userid]) !== 'lti') {
                    return null;
                }
                return self::with_issuer($base + ['userid' => $userid], $userid) + [
                    'eventtype' => $event->eventname === '\core\event\user_loggedin' ? 'launch' : 'account_created',
                    'direction' => 'in',
                ];
            case '\core\event\user_updated':
                $userid = (int) $event->objectid;
                if ($entry !== entrypoint::LTI_LAUNCH || $DB->get_field('user', 'auth', ['id' => $userid]) !== 'lti') {
                    return null;
                }
                return self::with_issuer($base + ['userid' => $userid], $userid) + [
                    'eventtype' => 'launch_sync',
                    'direction' => 'in',
                ];
            case '\core\event\user_enrolment_created':
                if (($event->other['enrol'] ?? '') !== 'lti') {
                    return null;
                }
                $enrolid = (int) $DB->get_field('user_enrolments', 'enrolid', ['id' => $event->objectid]);
                return $base + [
                    'eventtype' => 'enrolment',
                    'direction' => 'local',
                    'userid' => (int) $event->relateduserid,
                    'connkey' => $enrolid ? resolver::tool_key_for_enrol_instance($enrolid) : '',
                    'issuerhash' => '',
                ];
            case '\core\event\group_member_added':
                if ($entry !== entrypoint::LTI_LAUNCH) {
                    return null;
                }
                $userid = (int) $event->relateduserid;
                return self::with_issuer($base + ['userid' => $userid], $userid) + [
                    'eventtype' => 'group',
                    'direction' => 'local',
                ];
            case '\core\event\user_graded':
                $gradeitemid = (int) $DB->get_field('grade_grades', 'itemid', ['id' => $event->objectid]);
                if (!$gradeitemid || !resolver::is_lti_grade_item($gradeitemid)) {
                    return null;
                }
                return $base + [
                    'eventtype' => 'grade',
                    'direction' => $entry === entrypoint::LTI_SERVICES ? 'in' : 'local',
                    'userid' => (int) $event->relateduserid,
                    'connkey' => resolver::platform_key_for_grade_item($gradeitemid),
                    'issuerhash' => '',
                ];
            case '\mod_lti\event\course_module_viewed':
                return $base + [
                    'eventtype' => 'view',
                    'direction' => 'out',
                    'cmid' => (int) $event->contextinstanceid,
                    'userid' => (int) $event->userid,
                    'connkey' => resolver::platform_key_for_lti_instance((int) $event->objectid),
                    'issuerhash' => '',
                ];
            case '\mod_lti\event\unknown_service_api_called':
                return $base + [
                    'eventtype' => 'service_unknown',
                    'direction' => 'in',
                    'connkey' => '',
                    'issuerhash' => '',
                ];
        }
        return null;
    }

    /**
     * Attach issuer hash and, when unambiguous, the connection key of a user's platform.
     *
     * @param array $data Observation data.
     * @param int $userid User id.
     * @return array
     */
    private static function with_issuer(array $data, int $userid): array {
        $hashes = resolver::issuer_hashes_of_user($userid);
        $hash = count($hashes) === 1 ? reset($hashes) : '';
        $data['issuerhash'] = $hash;
        $data['connkey'] = $hash !== '' ? resolver::tool_key_for_issuer_hash($hash) : '';
        return $data;
    }

    /**
     * Names of the other-data keys of an event as JSON. Values are never stored.
     *
     * @param \core\event\base $event The event.
     * @return string|null
     */
    private static function field_names(\core\event\base $event): ?string {
        $other = $event->other;
        if (!is_array($other) || !$other) {
            return null;
        }
        $keys = array_map(function ($k) {
            return clean_param((string) $k, PARAM_ALPHANUMEXT);
        }, array_keys($other));
        sort($keys);
        return json_encode($keys);
    }
}
