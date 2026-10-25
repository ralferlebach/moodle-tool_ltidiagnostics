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
 * Resolves Moodle records to connection keys, only when the answer is unambiguous.
 *
 * An ambiguous answer is returned as an empty string. A wrong association would be worse than none.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {
    /**
     * Issuer hash(es) of the platform(s) a user is bound to through auth_lti.
     *
     * @param int $userid User id.
     * @return string[] SHA-256 hashes of the issuers.
     */
    public static function issuer_hashes_of_user(int $userid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('auth_lti_linked_login')) {
            return [];
        }
        return array_values($DB->get_fieldset_select('auth_lti_linked_login', 'DISTINCT issuer256', 'userid = ?', [$userid]));
    }

    /**
     * Tool-side connection key for an issuer hash, when exactly one connection matches.
     *
     * @param string $issuerhash SHA-256 of the issuer.
     * @return string Key or empty string.
     */
    public static function tool_key_for_issuer_hash(string $issuerhash): string {
        global $DB;
        if ($issuerhash === '' || !$DB->get_manager()->table_exists('enrol_lti_app_registration')) {
            return '';
        }
        $keys = [];
        foreach ($DB->get_records('enrol_lti_app_registration', null, 'id', 'id, platformid') as $reg) {
            if (hash('sha256', (string) $reg->platformid) === $issuerhash) {
                $deps = $DB->get_records('enrol_lti_deployment', ['platformid' => $reg->id], 'id', 'id');
                if (!$deps) {
                    $keys[] = connection::make_key(connection::ROLE_TOOL, (int) $reg->id, 0);
                }
                foreach ($deps as $d) {
                    $keys[] = connection::make_key(connection::ROLE_TOOL, (int) $reg->id, (int) $d->id);
                }
            }
        }
        return count($keys) === 1 ? $keys[0] : '';
    }

    /**
     * Tool-side connection key for the enrolment instance of a published tool, when unambiguous.
     *
     * @param int $enrolid Enrolment instance id.
     * @return string Key or empty string.
     */
    public static function tool_key_for_enrol_instance(int $enrolid): string {
        global $DB;
        if (!$DB->get_manager()->table_exists('enrol_lti_tools')) {
            return '';
        }
        $toolid = $DB->get_field('enrol_lti_tools', 'id', ['enrolid' => $enrolid]);
        if (!$toolid) {
            return '';
        }
        $deps = $DB->get_fieldset_select('enrol_lti_resource_link', 'DISTINCT ltideploymentid', 'resourceid = ?', [$toolid]);
        if (count($deps) !== 1) {
            return '';
        }
        $platformid = $DB->get_field('enrol_lti_deployment', 'platformid', ['id' => reset($deps)]);
        return $platformid ? connection::make_key(connection::ROLE_TOOL, (int) $platformid, (int) reset($deps)) : '';
    }

    /**
     * Platform-side connection key for an LTI activity instance.
     *
     * @param int $ltiid Id in the lti table.
     * @return string Key or empty string.
     */
    public static function platform_key_for_lti_instance(int $ltiid): string {
        global $DB;
        $typeid = (int) $DB->get_field('lti', 'typeid', ['id' => $ltiid]);
        return $typeid > 0 ? connection::make_key(connection::ROLE_PLATFORM, $typeid) : '';
    }

    /**
     * Platform-side connection key for a grade item created by or for an LTI activity or AGS line item.
     *
     * @param int $gradeitemid Grade item id.
     * @return string Key or empty string.
     */
    public static function platform_key_for_grade_item(int $gradeitemid): string {
        global $DB;
        if ($DB->get_manager()->table_exists('ltiservice_gradebookservices')) {
            $gbs = $DB->get_record(
                'ltiservice_gradebookservices',
                ['gradeitemid' => $gradeitemid],
                'id, typeid, ltilinkid',
                IGNORE_MULTIPLE
            );
            if ($gbs) {
                if (!empty($gbs->typeid)) {
                    return connection::make_key(connection::ROLE_PLATFORM, (int) $gbs->typeid);
                }
                if (!empty($gbs->ltilinkid)) {
                    return self::platform_key_for_lti_instance((int) $gbs->ltilinkid);
                }
            }
        }
        $item = $DB->get_record('grade_items', ['id' => $gradeitemid], 'id, itemtype, itemmodule, iteminstance');
        if ($item && $item->itemtype === 'mod' && $item->itemmodule === 'lti') {
            return self::platform_key_for_lti_instance((int) $item->iteminstance);
        }
        return '';
    }

    /**
     * Whether a grade item is LTI related (LTI activity or AGS line item).
     *
     * @param int $gradeitemid Grade item id.
     * @return bool
     */
    public static function is_lti_grade_item(int $gradeitemid): bool {
        global $DB;
        if (
            $DB->get_manager()->table_exists('ltiservice_gradebookservices')
                && $DB->record_exists('ltiservice_gradebookservices', ['gradeitemid' => $gradeitemid])
        ) {
            return true;
        }
        return $DB->record_exists('grade_items', ['id' => $gradeitemid, 'itemtype' => 'mod', 'itemmodule' => 'lti']);
    }
}
