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
 * Message matrix (audit item DIA-02): configured / observed / verified / booked / not observable per message, and the
 * end-to-end chains. Pure unit tests over the observability model.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\message_matrix
 * @covers     \tool_ltidiagnostics\local\observability
 */
final class message_matrix_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var int Fixed time. */
    private const NOW = 1790000000;

    /**
     * Platform connection.
     *
     * @param array $facts Connection facts.
     * @return connection
     */
    private function platform(array $facts = []): connection {
        $c = new connection();
        $c->role = connection::ROLE_PLATFORM;
        $c->key = 'platform:1';
        $c->ltiversion = '1.3.0';
        $c->facts = $facts + ['keytype' => 'JWK_KEYSET', 'ags' => 2, 'nrps' => 1, 'deeplinking' => 1, 'customkeys' => ['id', 'x']];
        return $c;
    }

    /**
     * Tool connection.
     *
     * @return connection
     */
    private function tool(): connection {
        $c = new connection();
        $c->role = connection::ROLE_TOOL;
        $c->key = 'tool:1:1';
        $c->ltiversion = '1.3.0';
        return $c;
    }

    /**
     * Facts.
     *
     * @param array $override Overrides (merged one level deep).
     * @return array
     */
    private function facts(array $override = []): array {
        $base = [
            'obs' => ['launch' => 0, 'enrolment' => 0, 'group' => 0, 'grade_in' => 0, 'grade_local' => 0, 'view_out' => 0,
                'account_created' => 0, 'service_unknown' => 0, 'groupassign' => 0, 'groupassign_failed' => 0, 'count' => 0],
            'access' => [], 'tasklog' => [], 'family' => [], 'claims' => [],
            'tokens' => ['ags' => 0, 'nrps' => 0, 'any' => 0, 'scopes' => []],
            'tool' => ['complete' => true, 'hasauth' => true, 'hasjwks' => true, 'hasoauth' => true, 'tools' => 1,
                'gradesync' => true, 'membersync' => true, 'links' => 1, 'agsclaim' => true, 'scorescope' => true,
                'nrpsclaim' => true, 'lastgrade' => false],
            'accessimported' => true,
        ];
        foreach ($override as $k => $v) {
            $base[$k] = is_array($v) && isset($base[$k]) && is_array($base[$k]) ? $v + $base[$k] : $v;
        }
        return $base;
    }

    /**
     * Build.
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param array $probes Probes.
     * @param array $enabled Source switches.
     * @return array
     */
    private function build(connection $c, array $facts, array $probes = [], array $enabled = []): array {
        $enabled += [observability::SRC_OBSERVER => true, observability::SRC_ACCESSLOG => !empty($facts['accessimported']),
            observability::SRC_PROBE => true] + observability::family_sources(true, true, true);
        $obs = observability::build($c, $facts, $probes, $enabled);
        return message_matrix::build($c, $facts, $obs, $probes);
    }

    /**
     * The audit rule: an HTTP 200 in a web server log is "observed (transport only)" and never "verified", for every
     * message that a web server log can see.
     */
    public function test_http_200_alone_never_verifies(): void {
        $access = [];
        foreach (
            ['oidc', 'oauth', 'service_ags_scores', 'service_ags_lineitems', 'service_ags_results', 'service_nrps',
                'deeplinking', 'launch', 'jwks', 'completion_sync'] as $cat
        ) {
            $access[$cat] = ['ok' => 5, 'fail' => 0, 'last' => self::NOW - 10, 'lastok' => self::NOW - 10, 'lastfail' => 0,
                'codes' => [200 => 5], 'paged' => 0];
        }
        foreach ([$this->platform(), $this->tool()] as $c) {
            $m = $this->build($c, $this->facts(['access' => $access]));
            foreach ($m['rows'] as $id => $row) {
                if ($row['observed'] === observability::TRANSPORT) {
                    $this->assertFalse($row['verified'], "$c->role/$id verified from a 200");
                    $this->assertTrue($row['transportonly'], "$c->role/$id");
                    $this->assertSame(message_matrix::OBSERVED, $row['state'], "$c->role/$id");
                }
            }
            foreach ($m['chains'] as $chain) {
                $this->assertNotSame('end_to_end', $chain['verdict'], "$c->role chain {$chain['id']} green from 200s");
            }
        }
    }

    /**
     * Exhaustive invariant over the facts that can verify: verified implies a recorded observation or the own probe, and
     * booked implies verified.
     */
    public function test_invariants_over_combinations(): void {
        $times = [0, self::NOW - 5];
        $count = 0;
        foreach ($times as $gradein) {
            foreach ($times as $launch) {
                foreach ($times as $enrol) {
                    foreach ($times as $token) {
                        foreach ($times as $access) {
                            foreach ([true, false] as $imported) {
                                $facts = $this->facts([
                                    'obs' => ['grade_in' => $gradein, 'launch' => $launch, 'enrolment' => $enrol],
                                    'tokens' => ['any' => $token, 'ags' => $token,
                                        'scopes' => $token ? ['https://purl.imsglobal.org/spec/lti-ags/scope/score' => $token]
                                        : []],
                                    'access' => $access ? ['service_ags_scores' => ['ok' => 1, 'fail' => 0, 'last' => $access,
                                        'lastok' => $access, 'lastfail' => 0, 'codes' => [200 => 1], 'paged' => 0]] : [],
                                    'accessimported' => $imported,
                                ]);
                                foreach ([$this->platform(), $this->tool()] as $c) {
                                    foreach ($this->build($c, $facts)['rows'] as $id => $row) {
                                        $count++;
                                        if ($row['state'] === message_matrix::BOOKED) {
                                            $this->assertTrue($row['verified'], $id);
                                        }
                                        if ($row['verified']) {
                                            $this->assertContains($row['observed'], [observability::RECORDED,
                                                observability::PAYLOAD], "$c->role/$id");
                                        }
                                        if ($row['potential'] === observability::NONE && !$row['verified']) {
                                            $this->assertContains($row['state'], [message_matrix::NOT_OBSERVABLE,
                                                message_matrix::FAILED], "$c->role/$id");
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(1000, $count);
    }

    /**
     * Platform: a score written by the service script is verified and booked; with an access token the grade chain is
     * end to end. Without a token the OAuth leg is not proven.
     */
    public function test_platform_grade_chain(): void {
        $scope = ['https://purl.imsglobal.org/spec/lti-ags/scope/score' => self::NOW - 30,
            'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly' => self::NOW - 30];
        $m = $this->build($this->platform(), $this->facts(['obs' => ['grade_in' => self::NOW - 20],
            'tokens' => ['any' => self::NOW - 30, 'ags' => self::NOW - 30, 'scopes' => $scope]]));
        $this->assertSame(message_matrix::BOOKED, $m['rows']['ags_score']['state']);
        $this->assertSame(message_matrix::VERIFIED, $m['rows']['oauth_token']['state']);
        $this->assertSame('end_to_end', $m['chains']['grades']['verdict']);
        $this->assertSame(message_matrix::VERIFIED, $m['rows']['service_permissions']['state']);

        $none = $this->build($this->platform(), $this->facts(['obs' => ['grade_in' => self::NOW - 20]]));
        $this->assertSame('incomplete', $none['chains']['grades']['verdict']);
        $this->assertSame('oauth_token', $none['chains']['grades']['weakest']);
    }

    /**
     * Tool: a validated launch with an enrolment makes the launch chain end to end only when the OIDC initiation was seen
     * as well; the OIDC login is transport only, so the chain stays limited.
     */
    public function test_tool_launch_chain(): void {
        $m = $this->build($this->tool(), $this->facts(['obs' => ['launch' => self::NOW - 10, 'enrolment' => self::NOW - 9]]));
        $this->assertSame(message_matrix::VERIFIED, $m['rows']['id_token_launch']['state']);
        $this->assertSame(message_matrix::BOOKED, $m['rows']['local_effects']['state']);
        $this->assertSame('limited', $m['chains']['launch']['verdict']);
        $this->assertSame('oidc_login_init', $m['chains']['launch']['weakest']);
        // The tool's score is sent by a task: recorded, never verified, the gradebook is the partner's.
        $grades = $this->build($this->tool(), $this->facts(['tasklog' => ['grades' => ['ok' => self::NOW - 5]]]));
        $this->assertFalse($grades['rows']['ags_score']['verified']);
        $this->assertSame(message_matrix::B_REMOTE, $grades['rows']['ags_score']['booked']);
        $this->assertSame('limited', $grades['chains']['grades']['verdict']);
    }

    /**
     * Unknown custom claims, deep linking responses and OIDC redirects through the browser are named as not observable,
     * never as missing.
     */
    public function test_not_observable_messages(): void {
        $p = $this->build($this->platform(), $this->facts());
        $this->assertSame(message_matrix::NOT_OBSERVABLE, $p['rows']['custom_claims']['state']);
        $this->assertSame('not_observable_remote', $p['rows']['custom_claims']['limit']);
        $this->assertSame(message_matrix::NOT_OBSERVABLE, $p['rows']['deeplinking_request']['state']);
        $t = $this->build($this->tool(), $this->facts());
        $this->assertSame(message_matrix::NOT_OBSERVABLE, $t['rows']['custom_claims']['state']);
        $this->assertSame(message_matrix::NOT_OBSERVABLE, $t['rows']['oauth_token']['state']);
        // Without an imported web server log the OIDC message is configured, not "not observable": a source exists.
        $q = $this->build($this->platform(), $this->facts(['accessimported' => false]));
        $this->assertSame(message_matrix::CONFIGURED, $q['rows']['oidc_auth_request']['state']);
    }

    /**
     * NRPS paging is counted from the query keys of imported lines; failures (403) make the permissions row fail.
     */
    public function test_nrps_paging_and_denied_service(): void {
        $access = ['service_nrps' => ['ok' => 3, 'fail' => 1, 'last' => self::NOW - 5, 'lastok' => self::NOW - 9,
            'lastfail' => self::NOW - 5, 'codes' => [200 => 3, 403 => 1], 'paged' => 2]];
        $m = $this->build($this->platform(), $this->facts(['access' => $access, 'tokens' => ['any' => self::NOW - 20,
            'scopes' => ['https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly' => self::NOW - 20,
            'https://purl.imsglobal.org/spec/lti-ags/scope/score' => self::NOW - 20]]]));
        $this->assertSame(2, $m['rows']['nrps']['paged']);
        $this->assertSame(message_matrix::FAILED, $m['rows']['nrps']['state'], 'The newest NRPS evidence is a 403.');
        $this->assertSame(message_matrix::FAILED, $m['rows']['service_permissions']['state']);
        $this->assertSame(1, $m['rows']['service_permissions']['failures']);
    }

    /**
     * Key rotation: a key set without the expected kid, or a partner package whose key does not match, fails the row.
     */
    public function test_key_rotation(): void {
        $probe = ['jwks' => ['purpose' => 'jwks', 'code' => 'ok', 'checkedat' => self::NOW, 'jwks' => ['valid' => true,
            'keys' => [['kid' => 'a']], 'haskid' => false, 'privateleak' => false]]];
        $m = $this->build($this->platform(), $this->facts(), $probe);
        $this->assertSame(message_matrix::FAILED, $m['rows']['key_rotation']['state']);
        $this->assertSame(message_matrix::FAILED, $m['rows']['jwks_fetch']['state']);
        $probe['jwks']['jwks']['haskid'] = true;
        $ok = $this->build($this->platform(), $this->facts(), $probe);
        $this->assertSame(message_matrix::VERIFIED, $ok['rows']['key_rotation']['state']);
        $this->assertSame(message_matrix::VERIFIED, $ok['rows']['jwks_fetch']['state']);
        $stale = $this->build($this->platform(), $this->facts(['partnerkeys' => ['match' => false, 'time' => self::NOW]]));
        $this->assertSame(message_matrix::FAILED, $stale['rows']['key_rotation']['state']);
        $none = $this->build($this->platform(), $this->facts());
        $this->assertSame(message_matrix::CONFIGURED, $none['rows']['key_rotation']['state']);
    }

    /**
     * Completion round trip: the receiver books (native completion applied), the sender sees an acknowledgement only;
     * a dead letter fails the row.
     */
    public function test_completion_roundtrip(): void {
        $recv = $this->build($this->platform(), $this->facts(['family' => ['http_received' => self::NOW - 3,
            'completion_applied' => self::NOW - 2, 'any' => self::NOW - 2]]));
        $this->assertSame(message_matrix::BOOKED, $recv['rows']['completion_roundtrip']['state']);
        $this->assertSame('end_to_end', $recv['chains']['completion']['verdict']);
        $send = $this->build($this->tool(), $this->facts(['family' => ['sent' => self::NOW - 3, 'acknowledged' => self::NOW - 2,
            'any' => self::NOW - 2]]));
        $this->assertSame(message_matrix::VERIFIED, $send['rows']['completion_roundtrip']['state']);
        $this->assertSame('limited', $send['chains']['completion']['verdict']);
        $this->assertSame('booked_remote', $send['chains']['completion']['reason']);
        $dead = $this->build($this->tool(), $this->facts(['family' => ['sent' => self::NOW - 3, 'dead_letter' => self::NOW - 1,
            'any' => self::NOW - 1]]));
        $this->assertSame(message_matrix::FAILED, $dead['rows']['completion_roundtrip']['state']);
        $this->assertSame('failed', $dead['chains']['completion']['verdict']);
    }

    /**
     * Group assignment (GA-03): absent plugin, fallback signals, events, failures.
     */
    public function test_group_assignment_row(): void {
        $absent = $this->build($this->tool(), $this->facts(['groupenrol' => ['installed' => false]]));
        $this->assertSame(message_matrix::NOT_OBSERVABLE, $absent['rows']['group_assignment']['state']);
        $this->assertSame('plugin_absent', $absent['rows']['group_assignment']['limit']);
        $g = ['installed' => true, 'events' => false, 'mappedtools' => 1, 'assigned' => self::NOW - 5, 'failures' => 0,
            'lastfailure' => 0, 'failedevents' => 0];
        $ok = $this->build($this->tool(), $this->facts(['groupenrol' => $g]));
        $this->assertSame(message_matrix::BOOKED, $ok['rows']['group_assignment']['state']);
        $this->assertSame('fallback_signals', $ok['rows']['group_assignment']['limit']);
        $failed = $this->build($this->tool(), $this->facts(['groupenrol' => ['failures' => 2, 'lastfailure' => self::NOW]
            + $g]));
        $this->assertSame(message_matrix::FAILED, $failed['rows']['group_assignment']['state']);
        $events = $this->build($this->tool(), $this->facts(['groupenrol' => ['events' => true] + $g]));
        $this->assertSame('', $events['rows']['group_assignment']['limit']);
    }

    /**
     * Chain verdicts directly.
     */
    public function test_chain_verdicts(): void {
        $row = function (
            string $state,
            bool $verified,
            string $booked,
            string $configured = 'yes',
            bool $transport = false,
            string $potential = observability::RECORDED
): array {
            return ['state' => $state, 'verified' => $verified, 'booked' => $booked, 'configured' => $configured,
                'transportonly' => $transport, 'potential' => $potential];
        };
        $ok = message_matrix::chain('x', ['a' => $row('verified', true, 'na'), 'b' => $row('booked', true, 'yes')], ['a', 'b']);
        $this->assertSame('end_to_end', $ok['verdict']);
        $limited = message_matrix::chain('x', ['a' => $row('observed', false, 'na', 'yes', true, observability::TRANSPORT),
            'b' => $row('booked', true, 'yes')], ['a', 'b']);
        $this->assertSame('limited', $limited['verdict']);
        $this->assertSame('transport_only', $limited['reason']);
        $inc = message_matrix::chain('x', ['a' => $row('configured', false, 'na'), 'b' => $row('booked', true, 'yes')], ['a', 'b']);
        $this->assertSame('incomplete', $inc['verdict']);
        $off = message_matrix::chain('x', ['a' => $row('not_configured', false, 'na', 'no')], ['a']);
        $this->assertSame('not_configured', $off['verdict']);
        $failed = message_matrix::chain('x', ['a' => $row('failed', false, 'na')], ['a']);
        $this->assertSame('failed', $failed['verdict']);
    }
}
