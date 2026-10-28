<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_ltidiagnostics\local;

/**
 * Reproducible fault scenarios of the one-click health check (audit item DIA-02): for each prepared fault the
 * check must name one most likely cause, the responsible team, where to look, a low risk verification and what it
 * cannot see; after the fix the cause must be gone. Nothing here touches a network: the scenarios feed the pure
 * evaluation with the evidence that the real probes, logs and tasks would deliver.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\health_check
 * @covers     \tool_ltidiagnostics\local\finding
 */
final class diagnosis_scenarios_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var int Fixed "now". */
    private const NOW = 1792800000;

    /**
     * Connection of a role.
     *
     * @param string $role platform or tool
     * @return connection
     */
    private function connection(string $role): connection {
        $c = new connection();
        if ($role === 'platform') {
            $c->role = connection::ROLE_PLATFORM;
            $c->localid = 7;
            $c->key = 'platform:7';
            $c->name = 'Tool';
            $c->ltiversion = '1.3.0';
            $c->clientid = 'client-1';
            $c->deploymentid = '7';
            $c->state = 'active';
            $c->endpoints = ['launch' => 'https://t.example.org/l', 'jwks' => 'https://t.example.org/jwks',
                'initiatelogin' => 'https://t.example.org/login'];
            $c->facts = ['keytype' => 'JWK_KEYSET', 'ags' => 2, 'nrps' => 1, 'toolsettings' => 0, 'deeplinking' => 0,
                'sendname' => 1, 'sendemail' => 1, 'acceptgrades' => 1, 'hasredirects' => 1, 'services' => []];
            return $c;
        }
        $c->role = connection::ROLE_TOOL;
        $c->localid = 3;
        $c->deploymentrowid = 4;
        $c->key = 'tool:3:4';
        $c->name = 'Platform';
        $c->ltiversion = '1.3.0';
        $c->issuer = 'https://p.example.org';
        $c->clientid = 'cid';
        $c->deploymentid = 'dep1';
        $c->state = 'complete';
        $c->endpoints = ['auth' => 'https://p.example.org/auth', 'jwks' => 'https://p.example.org/jwks',
            'oauth' => 'https://p.example.org/token'];
        $c->facts = ['issuerhash' => hash('sha256', 'https://p.example.org'), 'toolcount' => 1];
        return $c;
    }

    /**
     * Quiet facts.
     *
     * @param string $role Role.
     * @param array $override Top level overrides.
     * @return array
     */
    private function facts(string $role, array $override = []): array {
        return $override + [
            'now' => self::NOW,
            'implemented' => ['lti_core' => true, 'ags' => true, 'nrps' => true, 'deeplinking' => true, 'dynreg' => true,
                'submission_review' => $role === 'platform', 'platform_storage' => false, 'course_group' => false],
            'obs' => ['launch' => 0, 'enrolment' => 0, 'group' => 0, 'grade_in' => 0, 'grade_local' => 0, 'view_out' => 0,
                'account_created' => 0, 'service_unknown' => 0, 'count' => 0],
            'access' => [],
            'tasklog' => [],
            'tokens' => ['ags' => 0, 'nrps' => 0, 'any' => 0],
            'tool' => ['complete' => true, 'hasauth' => true, 'hasjwks' => true, 'hasoauth' => true, 'tools' => 1,
                'gradesync' => false, 'membersync' => false, 'links' => 0, 'agsclaim' => false, 'scorescope' => false,
                'nrpsclaim' => false, 'lastgrade' => false],
        ];
    }

    /**
     * Quiet environment.
     *
     * @param array $override Overrides.
     * @return array
     */
    private function env(array $override = []): array {
        return $override + [
            'readers' => [['name' => 'logstore_standard', 'class' => 'x', 'logging' => true]],
            'cron' => ['lastcronstart' => self::NOW - 60, 'age' => 60, 'status' => 'ok'],
            'scheduled' => [], 'adhoc' => [], 'tasklogfailed' => [], 'recordevents' => true, 'imports' => 1,
            'activechecks' => true,
        ];
    }

    /**
     * A probe result.
     *
     * @param string $code Classifier code.
     * @param array $extra Overrides.
     * @return array
     */
    private function probe(string $code, array $extra = []): array {
        return $extra + ['purpose' => 'jwks', 'code' => $code, 'checkedat' => self::NOW, 'skew' => null, 'certexpiry' => null,
            'jwks' => null, 'framing' => null, 'viaproxy' => false, 'pinned' => true, 'blockreason' => ''];
    }

    /**
     * The scenarios: name => [role, broken inputs, expected root cause code, layer, responsible, fixed inputs].
     * Inputs: probes, facts (merged one level), env (replaced keys).
     *
     * @return array
     */
    public static function scenario_provider(): array {
        $now = self::NOW;
        $okprobe = ['jwks' => ['purpose' => 'jwks', 'code' => 'ok', 'checkedat' => $now, 'skew' => 0, 'certexpiry' => null,
            'jwks' => ['valid' => true, 'reason' => 'ok', 'keys' => [['kid' => 'a']], 'haskid' => true, 'privateleak' => false],
            'framing' => null]];
        $probe = function (string $code, array $extra = []) use ($now) {
            return ['jwks' => $extra + ['purpose' => 'jwks', 'code' => $code, 'checkedat' => $now, 'skew' => null,
                'certexpiry' => null, 'jwks' => null, 'framing' => null, 'viaproxy' => false, 'blockreason' => '']];
        };
        $logfail = function (string $category, array $codes) {
            $fail = array_sum($codes);
            return ['access' => [$category => ['ok' => 0, 'fail' => $fail, 'last' => self::NOW - 60, 'codes' => $codes]]];
        };
        return [
            'dns failure' => ['tool', ['probes' => $probe('dns_failure')], 'probe_dns_failure', 'dns_network', 'network',
                ['probes' => $okprobe]],
            'tls chain incomplete' => ['tool', ['probes' => $probe('cert_chain_incomplete')], 'probe_cert_chain_incomplete',
                'tls', 'network', ['probes' => $okprobe]],
            'tls hostname mismatch' => ['tool', ['probes' => $probe('cert_hostname')], 'probe_cert_hostname', 'tls', 'network',
                ['probes' => $okprobe]],
            'tls expired' => ['tool', ['probes' => $probe('cert_expired')], 'probe_cert_expired', 'tls', 'network',
                ['probes' => $okprobe]],
            'firewall timeout' => ['tool', ['probes' => $probe('firewall_drop_suspected')], 'probe_firewall_drop_suspected',
                'firewall', 'network', ['probes' => $okprobe]],
            'proxy authentication' => ['tool', ['probes' => $probe('proxy_407', ['viaproxy' => true])], 'probe_proxy_407',
                'dns_network', 'network', ['probes' => $okprobe]],
            'oauth token endpoint refuses' => ['platform', ['facts' => $logfail('oauth', [401 => 3])], 'oauth_token_rejected',
                'oauth2', 'partner',
                ['facts' => ['access' => ['oauth' => ['ok' => 3, 'fail' => 0, 'last' => $now, 'codes' => [200 => 3]]]]]],
            'service calls lack the scope' => ['platform', ['facts' => $logfail('service_ags_scores', [403 => 2])],
                'oauth_service_denied', 'oauth2', 'partner', ['facts' => ['access' => []]]],
            'wrong deployment id or client id at launch' => ['tool', ['facts' => $logfail('launch', [400 => 2, 401 => 1])],
                'core_launch_rejected', 'moodle_core', 'admin', ['facts' => ['access' => []]]],
            'login request refused' => ['tool', ['facts' => $logfail('oidc', [400 => 4])], 'oidc_login_rejected', 'oidc_jwt',
                'partner', ['facts' => ['access' => []]]],
            'cron never ran' => ['tool', ['env' => ['cron' => ['lastcronstart' => 0, 'age' => -1, 'status' => 'never']]],
                'bg_cron_never', 'background', 'admin', ['env' => []]],
            'cron stopped' => ['tool', ['env' => ['cron' => ['lastcronstart' => $now - 7200, 'age' => 7200, 'status' => 'stale']]],
                'bg_cron_stale', 'background', 'admin', ['env' => []]],
            'grade task fails' => ['tool', ['facts' => ['tasklog' => ['grades' => ['failed' => $now - 600, 'ok' => 0]]]],
                'bg_sync_failed', 'background', 'admin', ['facts' => ['tasklog' => ['grades' => ['ok' => $now - 60]]]]],
            'scores cannot be matched to a user or grade item' => ['platform', ['facts' => $logfail(
                'service_ags_scores',
                [422 => 5]
            )], 'core_score_unmapped', 'moodle_core', 'partner', ['facts' => ['access' => []]]],
        ];
    }

    /**
     * Each prepared fault yields its cause as the single root cause, with all the guidance, and the fix removes it.
     *
     * @dataProvider scenario_provider
     * @param string $role Role.
     * @param array $broken Inputs with the fault.
     * @param string $code Expected finding code.
     * @param string $layer Expected layer.
     * @param string $responsible Expected responsible team.
     * @param array $fixed Inputs after the repair.
     */
    public function test_fault_is_diagnosed_and_fix_removes_it(
        string $role,
        array $broken,
        string $code,
        string $layer,
        string $responsible,
        array $fixed
    ): void {
        $this->resetAfterTest();
        $c = $this->connection($role);
        $before = $this->diagnose($c, $role, $broken);
        $root = $before['rootcause'];
        $this->assertNotNull($root, 'A fault must have a root cause.');
        $this->assertSame($code, $root->code);
        $this->assertSame($layer, $root->layer);
        $this->assertSame($responsible, $root->responsible);
        $this->assertContains($root->severity, ['blocking', 'warning']);
        $this->assertFalse($root->consequence, 'The root cause is never a consequence of something else.');
        // Guidance: cause, intervention, team, place, verification, what is not visible.
        $strings = $root->strings();
        foreach (['title', 'detail', 'fix', 'verify', 'where', 'uncertain'] as $part) {
            $this->assertNotSame('', trim($strings[$part]), "$code: $part");
        }
        $this->assertStringNotContainsString('{$a', json_encode($strings));

        $after = $this->diagnose($c, $role, $fixed);
        $codes = array_map(function ($f) {
            return $f->code;
        }, $after['findings']);
        $this->assertNotContains($code, $codes, 'After the fix the finding must be gone.');
        if ($after['rootcause'] !== null) {
            $this->assertNotSame($code, $after['rootcause']->code);
        }
    }

    /**
     * Run the evaluation.
     *
     * @param connection $c Connection.
     * @param string $role Role.
     * @param array $inputs probes, facts, env
     * @return array
     */
    private function diagnose(connection $c, string $role, array $inputs): array {
        $facts = $inputs['facts'] ?? [];
        $base = $this->facts($role);
        foreach ($facts as $k => $v) {
            $base[$k] = is_array($v) && isset($base[$k]) && is_array($base[$k]) ? $v + $base[$k] : $v;
        }
        return (new health_check())->evaluate($c, [
            'now' => self::NOW,
            'facts' => $base,
            'probes' => $inputs['probes'] ?? [],
            'profile' => null,
            'env' => ($inputs['env'] ?? []) + $this->env(),
        ]);
    }

    /**
     * Where a plausible different cause exists, the finding names it (the check never claims certainty it lacks).
     */
    public function test_alternative_causes_are_named_for_ambiguous_symptoms(): void {
        $this->resetAfterTest();
        foreach (
            ['probe_firewall_drop_suspected', 'probe_timeout', 'probe_cert_untrusted', 'oauth_token_rejected',
            'core_launch_rejected', 'oidc_login_rejected', 'oauth_service_denied', 'core_score_unmapped', 'bg_sync_failed',
            'probe_dns_failure', 'probe_proxy_407'] as $code
        ) {
            $f = new finding($code, 'dns_network', 'blocking', 'network', ['a' => (object) ['ok' => 1, 'fail' => 2]]);
            $this->assertNotSame('', $f->strings()['alt'], $code);
        }
        // Unambiguous findings have no invented alternative.
        $f = new finding('cfg_ok', 'configuration', 'ok', 'admin');
        $this->assertSame('', $f->strings()['alt']);
    }

    /**
     * Lower layers hide the upper ones: when the network is down, probe based findings above it are marked as
     * probable consequences, while an independent background failure is not.
     */
    public function test_consequences_are_marked_below_a_broken_layer(): void {
        $this->resetAfterTest();
        $c = $this->connection('platform');
        $result = $this->diagnose($c, 'platform', [
            'probes' => [
                'jwks' => $this->probe('dns_failure'),
                'launch' => $this->probe('ok', ['purpose' => 'launch', 'framing' => ['xfo' => 'deny', 'frameancestors' => '',
                    'blocksembedding' => true]]),
            ],
            'facts' => ['access' => ['oauth' => ['ok' => 0, 'fail' => 2, 'last' => self::NOW, 'codes' => [500 => 2]]]],
            'env' => ['cron' => ['lastcronstart' => 0, 'age' => -1, 'status' => 'never']],
        ]);
        $this->assertSame('probe_dns_failure', $result['rootcause']->code);
        $flags = [];
        foreach ($result['findings'] as $f) {
            $flags[$f->code] = $f->consequence;
        }
        $this->assertTrue($flags['oauth_token_rejected'], 'A token refusal seen in a log after a DNS failure is unreliable.');
        $this->assertFalse($flags['bg_cron_never'], 'Cron does not depend on the connection chain.');
        $this->assertFalse($flags['probe_dns_failure']);
    }

    /**
     * A configuration error ranks before network symptoms: the check says "fix the setting first".
     */
    public function test_configuration_comes_before_network(): void {
        $this->resetAfterTest();
        $c = $this->connection('tool');
        $c->clientid = '';
        $result = $this->diagnose($c, 'tool', ['probes' => ['jwks' => $this->probe('timeout')]]);
        $this->assertSame('cfg_endpoints', $result['rootcause']->code);
    }

    /**
     * Without a fault there is no root cause, and the report says what it cannot see instead of claiming success.
     */
    public function test_quiet_connection_has_no_invented_root_cause(): void {
        $this->resetAfterTest();
        $result = $this->diagnose($this->connection('tool'), 'tool', ['env' => ['imports' => 0]]);
        $this->assertNull($result['rootcause']);
        $codes = array_map(function ($f) {
            return $f->code;
        }, $result['findings']);
        $this->assertContains('obs_incomplete', $codes);
        $this->assertNotContains('core_launch_rejected', $codes);
    }

    /**
     * An access log that shows successes and failures at once yields a warning, not a blocking error.
     */
    public function test_mixed_log_results_are_a_warning(): void {
        $this->resetAfterTest();
        $result = $this->diagnose($this->connection('tool'), 'tool', ['facts' => ['access' => ['launch' => [
            'ok' => 10, 'fail' => 1, 'last' => self::NOW, 'codes' => [200 => 10, 500 => 1]]]]]);
        $found = array_values(array_filter($result['findings'], function ($f) {
            return $f->code === 'core_launch_rejected';
        }));
        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]->severity);
        $this->assertSame('tentative', $found[0]->confidence, 'A status code does not prove the cause.');
    }

    /**
     * The proxy path is flagged once, as information.
     */
    public function test_proxy_path_is_flagged(): void {
        $this->resetAfterTest();
        $result = $this->diagnose($this->connection('tool'), 'tool', ['probes' => [
            'jwks' => $this->probe('ok', ['viaproxy' => true, 'pinned' => false]),
            'auth' => $this->probe('ok', ['viaproxy' => true, 'pinned' => false, 'purpose' => 'endpoint']),
        ]]);
        $hits = array_filter($result['findings'], function ($f) {
            return $f->code === 'net_proxy_unpinned';
        });
        $this->assertCount(1, $hits);
        $this->assertSame('info', reset($hits)->severity);
    }

    /**
     * A policy refusal names its reason in the finding text, so a user can tell "private target" from "bad URL".
     */
    public function test_policy_refusal_names_the_reason(): void {
        $this->resetAfterTest();
        foreach (['resolved_private', 'pin_mismatch', 'redirect_limit', 'scheme', 'credentials'] as $reason) {
            $result = $this->diagnose($this->connection('tool'), 'tool', ['probes' => [
                'jwks' => $this->probe('blocked_by_policy', ['blockreason' => $reason]),
            ]]);
            $hits = array_values(array_filter($result['findings'], function ($f) {
                return $f->code === 'probe_blocked_by_policy';
            }));
            $this->assertCount(1, $hits, $reason);
            $this->assertSame('not_checked', $hits[0]->severity, 'A refused probe is "not checked", not a partner error.');
            $this->assertStringContainsString(
                get_string('blockreason_' . $reason, 'tool_ltidiagnostics'),
                $hits[0]->strings()['detail']
            );
        }
    }
}
