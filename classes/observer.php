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
use tool_ltidiagnostics\local\family_events;
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
        if (family_events::is_family_component((string) $event->component)) {
            return self::family($event, $base);
        }
        switch ($event->eventname) {
            case '\core\event\user_loggedin':
            case '\core\event\user_created':
                $userid = (int) $event->objectid;
                if ($DB->get_field('user', 'auth', ['id' => $userid]) !== 'lti') {
                    return null;
                }
                return self::with_claims(self::with_issuer($base + ['userid' => $userid], $userid) + [
                    'eventtype' => $event->eventname === '\core\event\user_loggedin' ? 'launch' : 'account_created',
                    'direction' => 'in',
                ], $userid, $entry);
            case '\core\event\user_updated':
                $userid = (int) $event->objectid;
                if ($entry !== entrypoint::LTI_LAUNCH || $DB->get_field('user', 'auth', ['id' => $userid]) !== 'lti') {
                    return null;
                }
                return self::with_claims(self::with_issuer($base + ['userid' => $userid], $userid) + [
                    'eventtype' => 'launch_sync',
                    'direction' => 'in',
                ], $userid, $entry);
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
            case '\tool_ltigroupautoenrol\event\group_assignment_completed':
            case '\tool_ltigroupautoenrol\event\group_assignment_failed':
                // Optional plugin (GA-03): the enrol instance of the LTI tool is the object; nothing from "other" is kept.
                $enrolid = (int) $event->objectid;
                return $base + [
                    'eventtype' => substr($event->eventname, -6) === 'failed' ? 'groupassign_failed' : 'groupassign',
                    'direction' => 'local',
                    'userid' => (int) $event->relateduserid,
                    'connkey' => $enrolid ? resolver::tool_key_for_enrol_instance($enrolid) : '',
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
     * Observation of an LTI family transfer event: only the correlation id and the connection are taken from "other".
     *
     * @param \core\event\base $event The event.
     * @param array $base Common fields.
     * @return array|null Null for a family event without a correlation id.
     */
    private static function family(\core\event\base $event, array $base): ?array {
        $other = family_events::sanitise_other($event->other);
        if ($other['correlationid'] === '') {
            return null;
        }
        $class = family_events::classify((string) $event->eventname);
        return $base + [
            'eventtype' => observation_store::FAMILY_EVENT,
            'direction' => $class['direction'],
            'userid' => (int) $event->relateduserid,
            'connkey' => family_events::connection_key((string) $event->component, $other['connectionid']),
            'issuerhash' => '',
            'correlationid' => $other['correlationid'],
        ];
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
     * Presence of the optional identity claims of the last launch (DIA-02 field matrix), derived without reading or
     * storing a value: auth_lti stores a placeholder when a claim is missing (first name = sub, last name = iss, e-mail
     * = a generated address in the example.com domain, identical in Moodle 4.5 and 5.3). Comparing the stored user record
     * with that placeholder tells whether the claim was sent. Only "present"/"absent" markers are added to the field
     * names, never a value. Skipped outside the launch scripts and when the user has more than one LTI binding.
     *
     * @param array $data Observation data.
     * @param int $userid User id.
     * @param string $entry Entry point.
     * @return array
     */
    private static function with_claims(array $data, int $userid, string $entry): array {
        global $DB;
        if ($entry !== entrypoint::LTI_LAUNCH || !$DB->get_manager()->table_exists('auth_lti_linked_login')) {
            return $data;
        }
        $links = $DB->get_records('auth_lti_linked_login', ['userid' => $userid], '', 'id, issuer, sub', 0, 2);
        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email');
        if (count($links) !== 1 || !$user) {
            return $data;
        }
        $link = reset($links);
        $placeholder = \core_user::clean_field('enrol_lti_13_' . sha1($link->issuer . '_' . $link->sub) . '@example.com', 'email');
        $markers = [
            'claim.given_name=' . ((string) $user->firstname !== (string) $link->sub ? 'present' : 'absent'),
            'claim.family_name=' . ((string) $user->lastname !== (string) $link->issuer ? 'present' : 'absent'),
            'claim.email=' . ((string) $user->email !== (string) $placeholder ? 'present' : 'absent'),
        ];
        $names = $data['fieldnames'] !== null ? (array) json_decode((string) $data['fieldnames'], true) : [];
        $data['fieldnames'] = json_encode(array_values(array_merge($names, $markers)));
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
