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

use tool_ltidiagnostics\local\active\url_guard;
use tool_ltidiagnostics\local\config;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\redactor;

/**
 * Resolves and validates exactly one grade replay target: registration, deployment, consumer resource link, line item
 * and learner of one connection, plus the unchanged source grade (issues #5, #9).
 *
 * Nothing is sent and nothing is written. Every reason that forbids a send is a blocker; an ambiguous target is
 * never "fixed" by guessing. The fingerprint covers everything a send depends on; a send is refused when the
 * fingerprint changed between preview and send (for example a new grade in between).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_resolver {
    /** @var source_reader Reader. */
    private $reader;

    /**
     * Constructor.
     *
     * @param source_reader|null $reader Reader.
     */
    public function __construct(?source_reader $reader = null) {
        $this->reader = $reader ?? new source_reader();
    }

    /**
     * Resolve a target.
     *
     * @param connection $conn Connection (tool role).
     * @param int $linkid enrol_lti_resource_link.id.
     * @param int $userid Local user id.
     * @return array blockers (string[]), warnings (string[]), fingerprint (string), internal (array: values needed to
     *     send, never shown), display (array: masked values for the preview).
     */
    public function resolve(connection $conn, int $linkid, int $userid): array {
        global $DB;
        $out = ['blockers' => [], 'warnings' => [], 'fingerprint' => '', 'internal' => [], 'display' => []];
        if ($conn->role !== connection::ROLE_TOOL || $conn->deploymentrowid <= 0) {
            $out['blockers'][] = 'wrong_connection';
            return $out;
        }
        $link = $DB->get_record('enrol_lti_resource_link', ['id' => $linkid]);
        if (!$link || (int) $link->ltideploymentid !== $conn->deploymentrowid) {
            // The link must belong to exactly this registration and deployment: no cross-consumer target.
            $out['blockers'][] = 'link_not_in_connection';
            return $out;
        }
        $reg = $DB->get_record('enrol_lti_app_registration', ['id' => $conn->localid]);
        $dep = $DB->get_record('enrol_lti_deployment', ['id' => $conn->deploymentrowid]);
        if (!$reg || !$dep || (int) $dep->platformid !== (int) $reg->id || (int) $reg->status !== 1) {
            $out['blockers'][] = 'registration_invalid';
            return $out;
        }
        $ltiuser = $DB->get_record_sql(
            "SELECT lu.id, lu.userid, lu.toolid, lu.sourceid, lu.ltideploymentid, lu.lastgrade
                                          FROM {enrol_lti_users} lu
                                          JOIN {enrol_lti_user_resource_link} url ON url.ltiuserid = lu.id
                                         WHERE lu.userid = :userid AND lu.toolid = :toolid AND url.resourcelinkid = :linkid",
            ['userid' => $userid, 'toolid' => (int) $link->resourceid, 'linkid' => $linkid]
        );
        if (!$ltiuser) {
            $out['blockers'][] = 'user_not_in_link';
            return $out;
        }
        $res = $this->reader->resource((int) $link->resourceid);
        if (!$res || !$res['enabled']) {
            $out['blockers'][] = 'publication_disabled';
        } else if (!$res['gradesync']) {
            $out['blockers'][] = 'gradesync_off';
        } else if ($res['kind'] === '' || !$res['contextvalid']) {
            $out['blockers'][] = 'invalid_context';
        }
        $src = $res ? ($this->reader->grades($res, [$userid])[$userid] ?? null) : null;
        if ($res && $src) {
            if ($res['gradesynccompletion'] && $src['completed'] === false) {
                $out['blockers'][] = 'gated';
            }
            if ($src['grade'] === null) {
                $out['blockers'][] = 'no_grade';
            }
            if (empty($res['grademax'])) {
                $out['blockers'][] = 'no_grademax';
            }
            if (!empty($res['needsupdate'])) {
                // Core would regrade first; a stale total is not sent.
                $out['blockers'][] = 'source_stale';
            }
            if ($src['locked']) {
                $out['warnings'][] = 'source_locked';
            }
            if ($src['overridden']) {
                $out['warnings'][] = 'source_overridden';
            }
        }

        // Scopes and endpoints from the AGS claim of the last launch.
        $scopes = grade_diagnosis::scopes($link);
        if (!$scopes['score']) {
            $out['blockers'][] = 'scope_missing';
        }
        $lineitem = (string) $link->lineitemservice;
        $lineitems = (string) $link->lineitemsservice;
        if ($lineitem === '' && $lineitems === '') {
            $out['blockers'][] = 'no_service';
        } else if ($lineitem === '' && !$scopes['lineitem'] && !$scopes['lineitemreadonly']) {
            // Without a coupled line item the line item can only be looked up, which needs a line item scope.
            $out['blockers'][] = 'lineitem_lookup_impossible';
        }
        foreach (array_filter([$lineitem, $lineitems]) as $url) {
            $reason = self::endpoint_problem($url, (string) $reg->platformid, (string) $reg->accesstokenurl);
            if ($reason !== '') {
                $out['blockers'][] = $reason;
            }
        }
        if (
            self::endpoint_problem(
                (string) $reg->accesstokenurl,
                (string) $reg->platformid,
                (string) $reg->accesstokenurl,
                true
            ) !== ''
        ) {
            $out['blockers'][] = 'token_url_blocked';
        }

        // AGS userId of this consumer.
        $subs = [];
        if ($DB->get_manager()->table_exists('auth_lti_linked_login')) {
            $subs = $DB->get_fieldset_select(
                'auth_lti_linked_login',
                'sub',
                'userid = :u AND issuer256 = :i',
                ['u' => $userid, 'i' => hash('sha256', (string) $reg->platformid)]
            );
        }
        $subs = array_values(array_unique(array_map('strval', $subs)));
        $sub = '';
        $mappingsource = '';
        if (count($subs) === 1) {
            $sub = $subs[0];
            $mappingsource = 'linked_login';
        } else if (count($subs) > 1) {
            $out['blockers'][] = 'mapping_ambiguous';
        } else if ((int) $ltiuser->ltideploymentid === $conn->deploymentrowid && (string) $ltiuser->sourceid !== '') {
            $sub = (string) $ltiuser->sourceid;
            $mappingsource = 'sourceid';
        } else {
            $out['blockers'][] = 'mapping_missing';
        }

        $out['blockers'] = array_values(array_unique($out['blockers']));
        $subjecthash = journal::subject_hash((string) $reg->platformid, $sub);
        $version = $src && $res ? $src['version'] : '';
        $out['fingerprint'] = hash('sha256', implode('|', [$conn->key, $linkid, $userid, $version, $lineitem, $lineitems,
            $subjecthash, (string) $link->scorescope, (string) $link->lineitemscope, (string) $link->resultscope]));
        $out['internal'] = [
            'linkid' => $linkid,
            'userid' => $userid,
            'toolid' => (int) $link->resourceid,
            'resourcelinkid' => (string) $link->resourcelinkid,
            'issuer' => (string) $reg->platformid,
            'clientid' => (string) $reg->clientid,
            'sub' => $sub,
            'subjecthash' => $subjecthash,
            'lineitem' => $lineitem,
            'lineitems' => $lineitems,
            'scopes' => $scopes,
            'grade' => $src ? $src['grade'] : null,
            'grademax' => $res ? $res['grademax'] : null,
            'dategraded' => $src ? (int) $src['dategraded'] : 0,
            'sourceversion' => $version,
        ];
        $out['display'] = [
            'registration' => redactor::identifier((string) $reg->name, true),
            'issuer' => redactor::url((string) $reg->platformid, false),
            'clientid' => redactor::identifier((string) $reg->clientid),
            'deployment' => redactor::identifier((string) $dep->deploymentid),
            'resourcelinkid' => redactor::identifier((string) $link->resourcelinkid),
            'lineitem' => $lineitem !== '' ? redactor::url($lineitem) : '',
            'lineitemlookup' => $lineitem === '' && $lineitems !== '',
            'person' => grade_diagnosis::pseudonym($conn->key, $userid),
            'mapping' => $mappingsource,
            'grade' => $src ? $src['grade'] : null,
            'grademax' => $res ? $res['grademax'] : null,
            'gradestate' => $src ? $src['state'] : 'none',
            'dategraded' => $src ? (int) $src['dategraded'] : 0,
            'scopes' => $scopes,
            'resultcheck' => $scopes['result'],
        ];
        return $out;
    }

    /**
     * Why an endpoint URL must not be used, '' when it may.
     *
     * The URL comes from the platform's launch claim. It must pass the SSRF guard and belong to the platform: same host
     * as the issuer or the token endpoint. A claim that points elsewhere (remote URL injection) is refused.
     *
     * @param string $url Endpoint.
     * @param string $issuer Issuer.
     * @param string $tokenurl Token endpoint.
     * @param bool $istokenurl True when checking the token endpoint itself (host rule not applied).
     * @return string
     */
    public static function endpoint_problem(string $url, string $issuer, string $tokenurl, bool $istokenurl = false): string {
        if (url_guard::check($url, (bool) config::get('allowprivatetargets')) !== url_guard::OK) {
            return $istokenurl ? 'token_url_blocked' : 'endpoint_blocked';
        }
        if ($istokenurl) {
            return '';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_filter([
            strtolower((string) parse_url($issuer, PHP_URL_HOST)),
            strtolower((string) parse_url($tokenurl, PHP_URL_HOST)),
        ]);
        return in_array($host, $allowed, true) ? '' : 'endpoint_foreign_host';
    }
}
