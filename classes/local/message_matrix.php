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
 * Status of every data direction and protocol message of a connection (audit item DIA-02).
 *
 * Per message the matrix keeps five statements apart, each with its own evidence:
 *  - configured: the local settings ask for the message (or it is part of LTI core);
 *  - observed: this system saw the message (level and time from {@see observability});
 *  - verified: this system saw a successful exchange at the level "recorded" or better (Moodle itself handled the
 *    message: a validated launch, an issued token, a score written by the service script, an acknowledged transfer),
 *    or its own probe received the content. A web server log line (HTTP 200) is "transport" and never verifies;
 *  - booked: the business effect is recorded locally (account/enrolment, gradebook row, native completion);
 *  - not_observable: no source of this installation can see the message at all.
 *
 * The final state is the strongest proven one: booked > verified > observed > configured. A message whose effect is
 * on the partner's side can never be "booked" here; it stays "limited" until a partner trace (see
 * {@see transfer_report}) supplies the other half. The chains at the end combine the messages of a flow; a chain is
 * "end_to_end" only when every leg is verified and the last one booked.
 *
 * Pure function of its input: no database access, no clock.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_matrix {
    /** @var string State: nothing of this message can be seen from here. */
    public const NOT_OBSERVABLE = 'not_observable';
    /** @var string State: neither configured nor seen. */
    public const NOT_CONFIGURED = 'not_configured';
    /** @var string State: configured, nothing seen. */
    public const CONFIGURED = 'configured';
    /** @var string State: seen, success not proven (for example only a web server log line). */
    public const OBSERVED = 'observed';
    /** @var string State: a successful exchange was recorded by Moodle or received by the own probe. */
    public const VERIFIED = 'verified';
    /** @var string State: verified and the local business effect is recorded. */
    public const BOOKED = 'booked';
    /** @var string State: the newest evidence is a failure. */
    public const FAILED = 'failed';

    /** @var string Booked: the effect is recorded here. */
    public const B_YES = 'yes';
    /** @var string Booked: the effect would be visible here but was not found. */
    public const B_NO = 'no';
    /** @var string Booked: the effect happens on the partner's side. */
    public const B_REMOTE = 'remote';
    /** @var string Booked: the message has no business effect. */
    public const B_NA = 'na';

    /** @var string[] All states, weakest first (FAILED is shown separately). */
    public const STATES = [self::NOT_OBSERVABLE, self::NOT_CONFIGURED, self::CONFIGURED, self::OBSERVED, self::VERIFIED,
        self::BOOKED, self::FAILED];

    /** @var string[] Chain verdicts. */
    public const VERDICTS = ['end_to_end', 'incomplete', 'limited', 'failed', 'not_configured'];

    /**
     * Build the rows and the chains.
     *
     * @param connection $c Connection.
     * @param array $facts Facts of the fact collector.
     * @param array $obsrows Result of {@see observability::build()}.
     * @param array $probes Stored probe results keyed by endpoint.
     * @return array rows (keyed by message id), chains (keyed by chain id)
     */
    public static function build(connection $c, array $facts, array $obsrows, array $probes = []): array {
        $rows = $c->role === connection::ROLE_PLATFORM
            ? self::platform($c, $facts, $obsrows, $probes)
            : self::tool($c, $facts, $obsrows, $probes);
        $chains = [];
        $defs = $c->role === connection::ROLE_PLATFORM
            ? ['launch' => ['oidc_auth_request', 'id_token_launch'], 'grades' => ['oauth_token', 'ags_score'],
                'roster' => ['oauth_token', 'nrps'], 'completion' => ['completion_roundtrip']]
            : ['launch' => ['oidc_login_init', 'id_token_launch', 'local_effects'], 'grades' => ['oauth_token', 'ags_score'],
                'roster' => ['oauth_token', 'nrps'], 'completion' => ['completion_roundtrip']];
        foreach ($defs as $id => $legs) {
            $chains[$id] = self::chain($id, array_intersect_key($rows, array_flip($legs)), $legs);
        }
        return ['rows' => $rows, 'chains' => $chains];
    }

    /**
     * Platform (mod_lti) side.
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param array $obsrows Observability rows.
     * @param array $probes Probes.
     * @return array[]
     */
    private static function platform(connection $c, array $facts, array $obsrows, array $probes): array {
        $f = $c->facts;
        $lti13 = $c->ltiversion === '1.3.0';
        $tokens = $facts['tokens'] ?? [];
        $obs = $facts['obs'] ?? [];
        $family = $facts['family'] ?? [];
        $rows = [];
        // OIDC: the tool's authentication request reaches mod/lti/auth.php (only a web server log sees it).
        $rows['oidc_auth_request'] = self::row(
            'oidc_auth_request',
            'oidc',
            'in',
            $lti13,
            $obsrows['oidc_login'] ?? null,
            $facts,
            'oidc',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'no_hook']
        );
        // The launch itself leaves the platform through the browser: only the view of the activity is seen.
        $rows['id_token_launch'] = self::row(
            'id_token_launch',
            'launch',
            'out',
            true,
            $obsrows['launch_message'] ?? null,
            $facts,
            'launch',
            ['verify' => 'never', 'booked' => self::B_REMOTE, 'limit' => 'browser_channel']
        );
        // The tool fetches this platform's key set.
        $rows['jwks_served'] = self::access_row('jwks_served', 'keys', 'in', $lti13, $facts, 'jwks');
        // This platform fetches the tool's key set (only the own probe does that visibly).
        $rows['jwks_fetch'] = self::probe_row(
            'jwks_fetch',
            'keys',
            'out',
            ($f['keytype'] ?? '') === 'JWK_KEYSET',
            $obsrows['jwks_fetch'] ?? null,
            $probes['jwks'] ?? null
        );
        $agsconf = (int) ($f['ags'] ?? 0) > 0;
        $nrpsconf = (int) ($f['nrps'] ?? 0) > 0;
        // OAuth 2 token: an issued token is a database record, i.e. the client assertion was validated by core.
        $rows['oauth_token'] = self::row(
            'oauth_token',
            'oauth',
            'in',
            $agsconf || $nrpsconf,
            $obsrows['oauth_token'] ?? null,
            $facts,
            'oauth',
            ['verify' => 'response', 'booked' => self::B_NA]
        );
        $rows['ags_lineitems'] = self::row(
            'ags_lineitems',
            'ags',
            'in',
            $agsconf,
            $obsrows['ags_lineitems'] ?? null,
            $facts,
            'service_ags_lineitems',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'no_hook']
        );
        // A score that the service script wrote shows up as a gradebook event from that script: verified and booked.
        $gradein = (int) ($obs['grade_in'] ?? 0);
        $rows['ags_score'] = self::row(
            'ags_score',
            'ags',
            'in',
            $agsconf,
            $obsrows['ags_scores'] ?? null,
            $facts,
            'service_ags_scores',
            ['verify' => 'request', 'booked' => $gradein > 0 ? self::B_YES : self::B_NO,
            'bookedat' => $gradein]
        );
        $rows['ags_results'] = self::row(
            'ags_results',
            'ags',
            'in',
            $agsconf,
            $obsrows['ags_results'] ?? null,
            $facts,
            'service_ags_results',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'no_hook']
        );
        $rows['nrps'] = self::row(
            'nrps',
            'nrps',
            'in',
            $nrpsconf,
            $obsrows['nrps'] ?? null,
            $facts,
            'service_nrps',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'no_hook',
            'paged' => (int) ($facts['access']['service_nrps']['paged'] ?? 0)]
        );
        $rows['deeplinking_request'] = self::row(
            'deeplinking_request',
            'deeplinking',
            'out',
            !empty($f['deeplinking']),
            null,
            $facts,
            '',
            ['verify' => 'never', 'booked' => self::B_REMOTE, 'limit' => 'browser_channel']
        );
        $rows['deeplinking_response'] = self::row(
            'deeplinking_response',
            'deeplinking',
            'in',
            !empty($f['deeplinking']),
            $obsrows['deeplinking'] ?? null,
            $facts,
            'deeplinking',
            ['verify' => 'never', 'booked' => self::B_NA,
            'limit' => 'no_hook']
        );
        $applied = (int) ($family['completion_applied'] ?? 0);
        $rows['completion_roundtrip'] = self::row(
            'completion_roundtrip',
            'completion',
            'in',
            null,
            $obsrows['completion_sync'] ?? null,
            $facts,
            'completion_sync',
            ['verify' => 'both',
            'booked' => $applied > 0 ? self::B_YES : self::B_NO, 'bookedat' => $applied,
            'failedat' => (int) ($family['dead_letter'] ?? 0)]
        );
        $rows['custom_claims'] = self::field_row(
            'custom_claims',
            'claims',
            'out',
            ($f['customkeys'] ?? []) !== [],
            'not_observable_remote'
        );
        $rows['service_permissions'] = self::permissions_row($c, $facts, $agsconf, $nrpsconf);
        $rows['key_rotation'] = self::keys_row(
            'key_rotation',
            $probes['jwks'] ?? null,
            $facts['partnerkeys'] ?? null,
            ($f['keytype'] ?? '') !== ''
        );
        return $rows;
    }

    /**
     * Tool (enrol_lti) side.
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param array $obsrows Observability rows.
     * @param array $probes Probes.
     * @return array[]
     */
    private static function tool(connection $c, array $facts, array $obsrows, array $probes): array {
        $t = $facts['tool'] ?? [];
        $obs = $facts['obs'] ?? [];
        $family = $facts['family'] ?? [];
        $tasklog = $facts['tasklog'] ?? [];
        $rows = [];
        $complete = !empty($t['complete']);
        $rows['oidc_login_init'] = self::row(
            'oidc_login_init',
            'oidc',
            'in',
            $complete,
            $obsrows['oidc_login'] ?? null,
            $facts,
            'oidc',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'no_hook']
        );
        // A login that the launch script recorded happens only after enrol_lti validated the id_token (signature, iss,
        // aud, nonce): the launch is verified. Its local effects are a separate row.
        $launch = (int) ($obs['launch'] ?? 0);
        $rows['id_token_launch'] = self::row(
            'id_token_launch',
            'launch',
            'in',
            $complete,
            $obsrows['launch_message'] ?? null,
            $facts,
            'launch',
            ['verify' => 'request', 'booked' => self::B_NA]
        );
        $effect = max((int) ($obs['enrolment'] ?? 0), (int) ($obs['account_created'] ?? 0), $launch > 0 ? $launch : 0);
        $rows['local_effects'] = self::row(
            'local_effects',
            'launch',
            'local',
            $complete,
            $obsrows['local_effects'] ?? null,
            $facts,
            '',
            ['verify' => 'response', 'booked' => $effect > 0 && ((int) ($obs['enrolment'] ?? 0) > 0
            || (int) ($obs['account_created'] ?? 0) > 0) ? self::B_YES : self::B_NO,
            'bookedat' => max(
                (int) ($obs['enrolment'] ?? 0),
                (int) ($obs['account_created'] ?? 0)
            )]
        );
        $rows['group_assignment'] = self::group_row($facts['groupenrol'] ?? [], $obs);
        $rows['jwks_served'] = self::access_row('jwks_served', 'keys', 'in', $complete, $facts, 'jwks');
        // The enrol_lti library fetches the platform's key set inside the launch library: only the own probe sees a key set.
        $rows['jwks_fetch'] = self::probe_row(
            'jwks_fetch',
            'keys',
            'out',
            !empty($t['hasjwks']),
            $obsrows['jwks_fetch'] ?? null,
            $probes['jwks'] ?? null
        );
        $rows['oauth_token'] = self::row(
            'oauth_token',
            'oauth',
            'out',
            !empty($t['gradesync']) || !empty($t['membersync']),
            $obsrows['oauth_token'] ?? null,
            $facts,
            '',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'no_hook']
        );
        // Grade and member sync run as scheduled tasks: a task that did not fail is "recorded", but it does not prove that
        // the platform accepted the score; the gradebook is the partner's.
        $rows['ags_score'] = self::row(
            'ags_score',
            'ags',
            'out',
            !empty($t['gradesync']),
            $obsrows['ags_scores'] ?? null,
            $facts,
            '',
            ['verify' => 'never', 'booked' => self::B_REMOTE, 'limit' => 'task_not_acceptance',
            'failedat' => (int) ($tasklog['grades']['failed'] ?? 0)]
        );
        $rows['nrps'] = self::row(
            'nrps',
            'nrps',
            'out',
            !empty($t['membersync']),
            $obsrows['nrps'] ?? null,
            $facts,
            '',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'paging_internal',
            'failedat' => (int) ($tasklog['members']['failed'] ?? 0)]
        );
        $rows['deeplinking_request'] = self::row(
            'deeplinking_request',
            'deeplinking',
            'in',
            $complete,
            $obsrows['deeplinking'] ?? null,
            $facts,
            'deeplinking',
            ['verify' => 'never', 'booked' => self::B_NA,
            'limit' => 'no_hook']
        );
        $rows['deeplinking_response'] = self::row(
            'deeplinking_response',
            'deeplinking',
            'out',
            $complete,
            null,
            $facts,
            '',
            ['verify' => 'never', 'booked' => self::B_REMOTE, 'limit' => 'browser_channel']
        );
        $rows['completion_roundtrip'] = self::row(
            'completion_roundtrip',
            'completion',
            'out',
            null,
            $obsrows['completion_sync'] ?? null,
            $facts,
            '',
            ['verify' => 'both', 'booked' => self::B_REMOTE,
            'limit' => 'partner_trace',
            'failedat' => (int) ($family['dead_letter'] ?? 0)]
        );
        $rows['custom_claims'] = self::field_row('custom_claims', 'claims', 'in', $complete, 'no_hook');
        $rows['service_permissions'] = self::tool_permissions_row($t, $facts);
        $rows['key_rotation'] = self::keys_row(
            'key_rotation',
            $probes['jwks'] ?? null,
            $facts['partnerkeys'] ?? null,
            !empty($t['hasjwks'])
        );
        return $rows;
    }

    /**
     * A row from an observability cell pair.
     *
     * @param string $id Message id.
     * @param string $group Group.
     * @param string $direction in, out or local (seen from this site).
     * @param bool|null $configured Configured (null: no setting decides, e.g. an optional plugin).
     * @param array|null $cell Observability row (request/response cells) or null when no source exists.
     * @param array $facts Facts.
     * @param string $category Access log category for failures (empty: none).
     * @param array $opt verify (never, request, response, both), booked, bookedat, limit, paged, failedat
     * @return array
     */
    private static function row(
        string $id,
        string $group,
        string $direction,
        ?bool $configured,
        ?array $cell,
        array $facts,
        string $category,
        array $opt
    ): array {
        $req = $cell['request'] ?? ['level' => observability::NONE, 'potential' => observability::NONE, 'seen' => 0,
            'basis' => []];
        $res = $cell['response'] ?? ['level' => observability::NONE, 'potential' => observability::NONE, 'seen' => 0,
            'basis' => []];
        $potential = max(observability::rank($req['potential']), observability::rank($res['potential']));
        $seenlevel = observability::NONE;
        $seen = 0;
        // Only the level of the sources that really saw something counts: a recorded source that saw nothing and a web
        // server line that did see the request make "transport", not "recorded".
        $partlevel = function (array $part): string {
            return (int) $part['seen'] > 0 ? (string) ($part['seenlevel'] ?? $part['level']) : observability::NONE;
        };
        foreach ([$req, $res] as $part) {
            if (observability::rank($partlevel($part)) > observability::rank($seenlevel)) {
                $seenlevel = $partlevel($part);
            }
            $seen = max($seen, (int) $part['seen']);
        }
        $recorded = function (array $part) use ($partlevel): bool {
            return observability::rank($partlevel($part)) >= observability::rank(observability::RECORDED);
        };
        $verify = $opt['verify'] ?? 'never';
        $verified = ($verify === 'request' && $recorded($req)) || ($verify === 'response' && $recorded($res))
            || ($verify === 'both' && $recorded($req) && $recorded($res));
        $access = $category !== '' ? ($facts['access'][$category] ?? []) : [];
        $failedat = max((int) ($opt['failedat'] ?? 0), (int) ($access['lastfail'] ?? 0));
        $okat = max($seen, (int) ($access['lastok'] ?? 0));
        $booked = $opt['booked'] ?? self::B_NA;
        return self::finish([
            'id' => $id,
            'group' => $group,
            'direction' => $direction,
            'configured' => $configured === null ? 'na' : ($configured ? 'yes' : 'no'),
            'observed' => $seenlevel,
            'potential' => observability::levels()[$potential],
            'seen' => $seen,
            'verified' => $verified,
            'booked' => $booked,
            'bookedat' => (int) ($opt['bookedat'] ?? 0),
            'failures' => (int) ($access['fail'] ?? 0),
            'failedat' => $failedat,
            'failing' => $failedat > 0 && $failedat >= $okat,
            'paged' => (int) ($opt['paged'] ?? 0),
            'basis' => array_values(array_unique(array_merge($req['basis'] ?? [], $res['basis'] ?? []))),
            'limit' => (string) ($opt['limit'] ?? ''),
        ]);
    }

    /**
     * Group assignment by the optional plugin tool_ltigroupautoenrol (GA-03).
     *
     * @param array $g Facts of {@see groupenrol_source::facts()} (empty when not collected).
     * @param array $obs Observation summary.
     * @return array
     */
    private static function group_row(array $g, array $obs): array {
        $installed = !empty($g['installed']);
        $assigned = (int) ($g['assigned'] ?? 0);
        $failed = max((int) ($g['lastfailure'] ?? 0), (int) ($g['failedevents'] ?? 0));
        $cell = ['level' => $installed ? observability::RECORDED : observability::NONE,
            'potential' => $installed ? observability::RECORDED : observability::NONE, 'seen' => $assigned,
            'basis' => $installed ? [observability::SRC_OBSERVER] : []];
        $row = self::row(
            'group_assignment',
            'launch',
            'local',
            $installed ? (int) ($g['mappedtools'] ?? 0) > 0 : null,
            ['request' => $cell, 'response' => $cell],
            [],
            '',
            ['verify' => 'request',
            'booked' => $assigned > 0 ? self::B_YES : self::B_NO, 'bookedat' => $assigned, 'failedat' => $failed,
            'limit' => $installed ? (!empty($g['events']) ? '' : 'fallback_signals') : 'plugin_absent']
        );
        $row['failures'] = (int) ($g['failures'] ?? 0);
        return $row;
    }

    /**
     * Row that only a web server log can see.
     *
     * @param string $id Message id.
     * @param string $group Group.
     * @param string $direction Direction.
     * @param bool $configured Configured.
     * @param array $facts Facts.
     * @param string $category Access log category.
     * @return array
     */
    private static function access_row(
        string $id,
        string $group,
        string $direction,
        bool $configured,
        array $facts,
        string $category
    ): array {
        $access = $facts['access'][$category] ?? [];
        $last = (int) ($access['lastok'] ?? 0);
        // Without an imported web server log the cell can reach "transport" but has no source yet.
        $usable = !empty($facts['accessimported']);
        $cell = ['level' => $usable ? observability::TRANSPORT : observability::NONE, 'potential' => observability::TRANSPORT,
            'seen' => $usable ? $last : 0, 'basis' => $usable ? [observability::SRC_ACCESSLOG] : []];
        return self::row(
            $id,
            $group,
            $direction,
            $configured,
            ['request' => $cell, 'response' => $cell],
            $facts,
            $category,
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => 'transport_only']
        );
    }

    /**
     * Row of a key set fetch: only the own probe receives the content.
     *
     * @param string $id Message id.
     * @param string $group Group.
     * @param string $direction Direction.
     * @param bool $configured Configured.
     * @param array|null $cell Observability row.
     * @param array|null $probe Stored probe of the key set.
     * @return array
     */
    private static function probe_row(
        string $id,
        string $group,
        string $direction,
        bool $configured,
        ?array $cell,
        ?array $probe
    ): array {
        $row = self::row($id, $group, $direction, $configured, $cell, [], '', ['verify' => 'both', 'booked' => self::B_NA,
            'limit' => 'own_probe_only']);
        if (is_array($probe) && ($probe['code'] ?? '') !== '' && $probe['code'] !== 'ok') {
            $row['failedat'] = (int) ($probe['checkedat'] ?? 0);
            $row['failing'] = true;
            $row['failures'] = 1;
            $row['verified'] = false;
        }
        if (is_array($probe) && !empty($probe['jwks']) && ($probe['jwks']['haskid'] ?? null) === false) {
            $row['verified'] = false;
            $row['failing'] = true;
            $row['failedat'] = (int) ($probe['checkedat'] ?? 0);
        }
        return self::finish($row);
    }

    /**
     * Row of claims that no source can see in this direction.
     *
     * @param string $id Message id.
     * @param string $group Group.
     * @param string $direction Direction.
     * @param bool $configured Configured.
     * @param string $limit Limit code.
     * @return array
     */
    private static function field_row(string $id, string $group, string $direction, bool $configured, string $limit): array {
        return self::row($id, $group, $direction, $configured, null, [], '', ['verify' => 'never', 'booked' => self::B_NA,
            'limit' => $limit]);
    }

    /**
     * Permissions of the platform: configured services against the scopes of issued tokens and denied service calls.
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param bool $ags AGS configured.
     * @param bool $nrps NRPS configured.
     * @return array
     */
    private static function permissions_row(connection $c, array $facts, bool $ags, bool $nrps): array {
        $scopes = $facts['tokens']['scopes'] ?? [];
        $missing = [];
        if ($ags && !self::has_scope($scopes, 'lti-ags/scope/score')) {
            $missing[] = 'score';
        }
        if ($nrps && !self::has_scope($scopes, 'lti-nrps/scope/contextmembership.readonly')) {
            $missing[] = 'contextmembership.readonly';
        }
        $denied = 0;
        $deniedat = 0;
        foreach (['service_ags_scores', 'service_ags_lineitems', 'service_ags_results', 'service_nrps', 'oauth'] as $cat) {
            foreach ([401, 403] as $code) {
                $denied += (int) ($facts['access'][$cat]['codes'][$code] ?? 0);
            }
            if (!empty($facts['access'][$cat]['codes'][401]) || !empty($facts['access'][$cat]['codes'][403])) {
                $deniedat = max($deniedat, (int) ($facts['access'][$cat]['lastfail'] ?? 0));
            }
        }
        $anytoken = (int) ($facts['tokens']['any'] ?? 0);
        $row = self::row('service_permissions', 'permissions', 'in', $ags || $nrps, null, [], '', ['verify' => 'never',
            'booked' => self::B_NA, 'limit' => '']);
        $row['potential'] = observability::RECORDED;
        $row['observed'] = $anytoken > 0 ? observability::RECORDED : observability::NONE;
        $row['seen'] = $anytoken;
        $row['verified'] = $anytoken > 0 && !$missing;
        $row['missingscopes'] = $anytoken > 0 ? $missing : [];
        $row['failures'] = $denied;
        $row['failedat'] = $deniedat;
        $row['failing'] = ($anytoken > 0 && $missing) || ($deniedat > 0 && $deniedat >= $anytoken);
        return self::finish($row);
    }

    /**
     * Permissions of the tool: what the platform declared in the resource link claims.
     *
     * @param array $t Tool facts.
     * @param array $facts Facts.
     * @return array
     */
    private static function tool_permissions_row(array $t, array $facts): array {
        $row = self::row(
            'service_permissions',
            'permissions',
            'in',
            !empty($t['gradesync']) || !empty($t['membersync']),
            null,
            [],
            '',
            ['verify' => 'never', 'booked' => self::B_NA, 'limit' => '']
        );
        $declared = !empty($t['links']);
        $missing = [];
        if (!empty($t['gradesync']) && $declared && empty($t['scorescope'])) {
            $missing[] = 'score';
        }
        if (!empty($t['membersync']) && $declared && empty($t['nrpsclaim'])) {
            $missing[] = 'contextmembership.readonly';
        }
        $row['potential'] = observability::RECORDED;
        $row['observed'] = $declared ? observability::RECORDED : observability::NONE;
        $row['verified'] = $declared && !$missing;
        $row['missingscopes'] = $missing;
        $row['failing'] = (bool) $missing;
        $row['limit'] = 'declared_not_granted';
        return self::finish($row);
    }

    /**
     * Key rotation: is the key the partner signs with the one this side can find?
     *
     * @param string $id Message id.
     * @param array|null $probe Stored key set probe.
     * @param array|null $partner Key comparison from an imported partner package (see {@see partner_package}).
     * @param bool $configured A key source is configured.
     * @return array
     */
    private static function keys_row(string $id, ?array $probe, ?array $partner, bool $configured): array {
        $row = self::row($id, 'keys', 'in', $configured, null, [], '', ['verify' => 'never', 'booked' => self::B_NA,
            'limit' => 'partner_package']);
        $row['potential'] = observability::PAYLOAD;
        $checked = 0;
        $ok = null;
        if (is_array($probe) && !empty($probe['jwks']) && $probe['jwks']['haskid'] !== null) {
            $checked = (int) ($probe['checkedat'] ?? 0);
            $ok = (bool) $probe['jwks']['haskid'];
        }
        if (is_array($partner) && isset($partner['match'])) {
            $checked = max($checked, (int) ($partner['time'] ?? 0));
            $ok = ($ok ?? true) && (bool) $partner['match'];
        }
        if ($checked > 0) {
            $row['observed'] = observability::PAYLOAD;
            $row['seen'] = $checked;
            $row['verified'] = (bool) $ok;
            $row['failing'] = !$ok;
            $row['failedat'] = $ok ? 0 : $checked;
            $row['limit'] = '';
        }
        return self::finish($row);
    }

    /**
     * Whether a scope (by its path ending) was granted.
     *
     * @param array $scopes Scope => time.
     * @param string $suffix Ending of the scope URL.
     * @return bool
     */
    private static function has_scope(array $scopes, string $suffix): bool {
        foreach (array_keys($scopes) as $scope) {
            if (substr((string) $scope, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * Derive the final state.
     *
     * @param array $row Row.
     * @return array
     */
    private static function finish(array $row): array {
        if ($row['failing']) {
            $state = self::FAILED;
        } else if ($row['verified'] && $row['booked'] === self::B_YES) {
            $state = self::BOOKED;
        } else if ($row['verified']) {
            $state = self::VERIFIED;
        } else if ($row['seen'] > 0 && $row['observed'] !== observability::NONE) {
            $state = self::OBSERVED;
        } else if ($row['potential'] === observability::NONE) {
            $state = self::NOT_OBSERVABLE;
        } else if ($row['configured'] === 'yes') {
            $state = self::CONFIGURED;
        } else {
            $state = $row['configured'] === 'no' ? self::NOT_CONFIGURED : self::CONFIGURED;
        }
        $row['state'] = $state;
        // A transport level observation is named as such: an HTTP 200 never verifies.
        $row['transportonly'] = $row['observed'] === observability::TRANSPORT && !$row['verified'];
        $row['missingscopes'] = $row['missingscopes'] ?? [];
        return $row;
    }

    /**
     * Combine the legs of a flow.
     *
     * @param string $id Chain id.
     * @param array[] $rows Rows of the legs.
     * @param string[] $legs Leg ids in flow order.
     * @return array id, legs, verdict, weakest (leg id or ''), reason
     */
    public static function chain(string $id, array $rows, array $legs): array {
        $states = [];
        $verdict = 'end_to_end';
        $weakest = '';
        $reason = '';
        $allunconfigured = true;
        foreach ($legs as $i => $leg) {
            $row = $rows[$leg] ?? null;
            if ($row === null) {
                continue;
            }
            $states[$leg] = $row['state'];
            if ($row['configured'] !== 'no') {
                $allunconfigured = false;
            }
            $last = $i === count($legs) - 1;
            if ($row['state'] === self::FAILED) {
                return ['id' => $id, 'legs' => $states + array_fill_keys(array_diff($legs, array_keys($states)), ''),
                    'verdict' => 'failed', 'weakest' => $leg, 'reason' => 'leg_failed'];
            }
            $legok = $row['verified'] && (!$last || $row['booked'] === self::B_YES || $row['booked'] === self::B_NA);
            if ($legok) {
                continue;
            }
            $limited = $row['state'] === self::NOT_OBSERVABLE || $row['transportonly'] || $row['booked'] === self::B_REMOTE
                || ($row['potential'] !== observability::RECORDED && $row['potential'] !== observability::PAYLOAD
                    && !$row['verified']);
            if ($weakest === '') {
                $weakest = $leg;
                $reason = $row['booked'] === self::B_REMOTE && $row['verified'] ? 'booked_remote'
                    : ($row['transportonly'] ? 'transport_only' : ($limited ? 'not_observable_here' : 'not_proven'));
            }
            if ($verdict !== 'incomplete') {
                $verdict = $limited ? 'limited' : 'incomplete';
            }
        }
        if ($allunconfigured) {
            return ['id' => $id, 'legs' => $states, 'verdict' => 'not_configured', 'weakest' => '', 'reason' => 'not_configured'];
        }
        return ['id' => $id, 'legs' => $states, 'verdict' => $verdict, 'weakest' => $weakest, 'reason' => $reason];
    }
}
