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
 * Field matrix of the launch message (audit item DIA-02): per claim, what the platform is configured to send and what
 * the tool demonstrably received, without ever storing a value.
 *
 * The platform cannot see the id_token it hands to the browser (no hook in mod_lti), so its column says what its
 * settings make core send ("always", "yes", "no", "per_activity"). The tool sees the effect of the claims it
 * received: core validated iss/aud/deployment_id/sub/nonce before it logged the user in ("validated"), stored the
 * resource link, context and service endpoints ("present"/"absent"), and kept a placeholder for a missing name or
 * e-mail ("present"/"absent", see {@see \tool_ltidiagnostics\observer}). Everything else is "not_observable".
 * With an imported partner package the other column is filled from the partner's own view, and a claim that is sent
 * but not received is marked "mismatch".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class field_matrix {
    /** @var array Claims of an LTI 1.3 resource link launch: name => category. */
    public const FIELDS = [
        'iss' => 'core', 'aud' => 'core', 'sub' => 'core', 'deployment_id' => 'core', 'nonce' => 'core',
        'message_type' => 'core', 'version' => 'core', 'target_link_uri' => 'core', 'resource_link' => 'core',
        'roles' => 'roles', 'context' => 'context', 'given_name' => 'identity', 'family_name' => 'identity',
        'email' => 'identity', 'lis' => 'lis', 'ags_endpoint' => 'ags', 'ags_scope_score' => 'ags',
        'nrps_endpoint' => 'nrps', 'launch_presentation' => 'presentation', 'tool_platform' => 'platform',
        'custom_id' => 'custom',
    ];

    /** @var string[] States meaning "the claim travels". */
    private const SENT = ['always', 'yes', 'validated', 'present'];

    /**
     * Build the matrix.
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param array|null $partner Field column of an imported partner package (field => state), or null.
     * @return array[] Rows: field, category, local, partner, evidence, agreement
     */
    public static function build(connection $c, array $facts, ?array $partner = null): array {
        $local = $c->role === connection::ROLE_PLATFORM ? self::platform_column($c) : self::tool_column($c, $facts);
        $rows = [];
        foreach ($local as $field => [$state, $evidence]) {
            $other = $partner[$field] ?? '';
            $rows[$field] = [
                'field' => $field,
                'category' => self::FIELDS[$field] ?? (strpos($field, 'custom.') === 0 ? 'custom' : 'unknown'),
                'local' => $state,
                'partner' => (string) $other,
                'evidence' => $evidence,
                'agreement' => self::agreement($c->role, $state, (string) $other),
            ];
        }
        // Fields only the partner reports (e.g. custom parameters of the platform seen from the tool).
        foreach ((array) $partner as $field => $state) {
            if (!isset($rows[$field]) && is_string($field) && preg_match('/^[a-z0-9_.\-]{1,80}$/', $field)) {
                $rows[$field] = ['field' => $field, 'category' => strpos($field, 'custom.') === 0 ? 'custom' : 'unknown',
                    'local' => $c->role === connection::ROLE_TOOL ? 'not_observable' : 'unknown', 'partner' => (string) $state,
                    'evidence' => 'partner_package', 'agreement' => 'na'];
            }
        }
        return $rows;
    }

    /**
     * The column of a platform: what its settings make mod_lti send.
     *
     * @param connection $c Platform connection.
     * @return array field => [state, evidence]
     */
    public static function platform_column(connection $c): array {
        $f = $c->facts;
        $setting = function (int $value): string {
            return [0 => 'no', 1 => 'yes', 2 => 'per_activity'][$value] ?? 'unknown';
        };
        $out = [];
        foreach (self::FIELDS as $field => $category) {
            $out[$field] = ['always', 'core'];
        }
        $out['given_name'] = [$setting((int) ($f['sendname'] ?? 0)), 'setting:sendname'];
        $out['family_name'] = $out['given_name'];
        $out['email'] = [$setting((int) ($f['sendemail'] ?? 0)), 'setting:sendemailaddr'];
        $out['ags_endpoint'] = [(int) ($f['ags'] ?? 0) > 0 ? 'yes' : 'no', 'setting:ltiservice_gradesynchronization'];
        $out['ags_scope_score'] = $out['ags_endpoint'];
        $out['nrps_endpoint'] = [(int) ($f['nrps'] ?? 0) > 0 ? 'yes' : 'no', 'setting:ltiservice_memberships'];
        $keys = $f['customkeys'] ?? [];
        $out['custom_id'] = [in_array('id', $keys, true) ? 'yes' : 'per_activity', 'setting:customparameters'];
        foreach ($keys as $key) {
            if ($key !== 'id') {
                $out['custom.' . $key] = ['yes', 'setting:customparameters'];
            }
        }
        return $out;
    }

    /**
     * The column of a tool: what the stored effects prove it received.
     *
     * @param connection $c Tool connection.
     * @param array $facts Facts.
     * @return array field => [state, evidence]
     */
    public static function tool_column(connection $c, array $facts): array {
        $launched = (int) ($facts['obs']['launch'] ?? 0) > 0;
        $t = $facts['tool'] ?? [];
        $claims = $facts['claims'] ?? [];
        $links = (int) ($t['links'] ?? 0) > 0;
        $out = [];
        foreach (self::FIELDS as $field => $category) {
            $out[$field] = ['not_observable', 'no_hook'];
        }
        foreach (['iss', 'aud', 'sub', 'deployment_id', 'nonce', 'message_type', 'version'] as $field) {
            $out[$field] = $launched ? ['validated', 'observer:launch'] : ['unknown', 'no_launch_seen'];
        }
        foreach (['given_name', 'family_name', 'email'] as $field) {
            $out[$field] = isset($claims[$field]) ? [$claims[$field], 'observer:placeholder_check'] : ['unknown', 'no_launch_seen'];
        }
        $db = function (bool $flag) use ($links): array {
            return $links ? [$flag ? 'present' : 'absent', 'db:enrol_lti_resource_link'] : ['unknown', 'no_resource_link'];
        };
        $out['resource_link'] = $db($links);
        $out['context'] = $db($links);
        $out['custom_id'] = $db($links);
        $out['ags_endpoint'] = $db(!empty($t['agsclaim']));
        $out['ags_scope_score'] = $db(!empty($t['scorescope']));
        $out['nrps_endpoint'] = $db(!empty($t['nrpsclaim']));
        return $out;
    }

    /**
     * Agreement of the two columns.
     *
     * @param string $role Role of this site.
     * @param string $local Local state.
     * @param string $partner Partner state.
     * @return string match, mismatch or na
     */
    public static function agreement(string $role, string $local, string $partner): string {
        $sent = $role === connection::ROLE_PLATFORM ? $local : $partner;
        $received = $role === connection::ROLE_PLATFORM ? $partner : $local;
        $decided = ['always', 'yes', 'no'];
        if (!in_array($sent, $decided, true) || !in_array($received, ['validated', 'present', 'absent'], true)) {
            return 'na';
        }
        return in_array($sent, self::SENT, true) === in_array($received, self::SENT, true) ? 'match' : 'mismatch';
    }
}
