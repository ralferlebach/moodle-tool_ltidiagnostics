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
 * Tests of the capability matrix and the guided health check with controlled facts.
 *
 * The facts are built by hand here so that every branch can be reached. The adapters that produce
 * real facts are tested separately against real Moodle data.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\matrix_builder
 * @covers     \tool_ltidiagnostics\local\health_check
 * @covers     \tool_ltidiagnostics\local\finding
 * @covers     \tool_ltidiagnostics\local\desired_profile
 */
final class matrix_health_test extends \advanced_testcase {
    /** @var int Fixed "now". */
    private const NOW = 1792800000;

    /**
     * A platform connection.
     *
     * @param array $facts Fact overrides.
     * @param array $props Property overrides.
     * @return connection
     */
    private function platform(array $facts = [], array $props = []): connection {
        $c = new connection();
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
        $c->facts = $facts + ['keytype' => 'JWK_KEYSET', 'ags' => 2, 'nrps' => 1, 'toolsettings' => 0, 'deeplinking' => 0,
            'sendname' => 1, 'sendemail' => 1, 'acceptgrades' => 1, 'hasredirects' => 1, 'services' => []];
        foreach ($props as $k => $v) {
            $c->$k = $v;
        }
        return $c;
    }

    /**
     * A tool connection.
     *
     * @param array $props Property overrides.
     * @return connection
     */
    private function tool(array $props = []): connection {
        $c = new connection();
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
        foreach ($props as $k => $v) {
            $c->$k = $v;
        }
        return $c;
    }

    /**
     * Facts for a role, everything quiet.
     *
     * @param string $role Role.
     * @param array $override Overrides, merged one level deep.
     * @return array
     */
    private function facts(string $role, array $override = []): array {
        $facts = [
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
        foreach ($override as $k => $v) {
            $facts[$k] = is_array($v) && isset($facts[$k]) && is_array($facts[$k]) ? $v + $facts[$k] : $v;
        }
        return $facts;
    }

    /**
     * A quiet environment.
     *
     * @param array $override Overrides.
     * @return array
     */
    private function env(array $override = []): array {
        return $override + [
            'readers' => [['name' => 'logstore_standard', 'class' => 'x', 'logging' => true]],
            'cron' => ['lastcronstart' => self::NOW - 60, 'age' => 60, 'status' => 'ok'],
            'scheduled' => [], 'adhoc' => [], 'tasklogfailed' => [], 'recordevents' => true, 'imports' => 1,
        ];
    }

    /**
     * Run the pure health evaluation.
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param desired_profile|null $profile Profile.
     * @param array $probes Probe results.
     * @param array $env Environment.
     * @return array
     */
    private function evaluate(
        connection $c,
        array $facts,
        ?desired_profile $profile = null,
        array $probes = [],
        array $env = []
    ): array {
        return (new health_check())->evaluate($c, ['now' => self::NOW, 'facts' => $facts, 'probes' => $probes,
            'profile' => $profile, 'env' => $env ?: $this->env()]);
    }

    /**
     * Build a profile from an array.
     *
     * @param array $data Profile data.
     * @return desired_profile
     */
    private function profile(array $data): desired_profile {
        [$profile, $errors] = desired_profile::from_array($data + ['schema' => 1, 'name' => 'Test']);
        $this->assertSame([], $errors);
        return $profile;
    }

    /**
     * Codes of all findings, with their severity.
     *
     * @param array $result Health result.
     * @return string[] Map code => severity (last wins).
     */
    private function codes(array $result): array {
        $out = [];
        foreach ($result['findings'] as $f) {
            $out[$f->code] = $f->severity;
        }
        return $out;
    }

    /**
     * Platform side: AGS configured and authorised, but no grade seen, is not verified.
     */
    public function test_platform_ags_configured_is_not_verified(): void {
        $c = $this->platform();
        $matrix = (new matrix_builder())->build($c, $this->facts('platform'));
        $this->assertSame(status::CONFIGURED, $matrix['ags']['status']);
        $this->assertSame(status::CONFIGURED, $matrix['nrps']['status']);
        $this->assertSame('configured_not_observed', $matrix['ags']['reason']);
        $this->assertSame(status::STATE_NOT_OBSERVABLE, $matrix['ags']['ladder']['declared']->state);
        $this->assertNotSame(status::VERIFIED, $matrix['lti_core']['status']);
        foreach ($matrix as $row) {
            $this->assertNotSame(status::VERIFIED, $row['status'], $row['feature']);
        }
    }

    /**
     * An issued token moves AGS to authorised, still not verified.
     */
    public function test_platform_token_authorises_but_does_not_verify(): void {
        $facts = $this->facts('platform', ['tokens' => ['ags' => self::NOW - 10, 'nrps' => 0, 'any' => self::NOW - 10]]);
        $row = (new matrix_builder())->build($this->platform(), $facts)['ags'];
        $this->assertSame(status::AUTHORISED, $row['status']);
    }

    /**
     * A grade that arrived through the service entry point is verified; a local grade is not.
     */
    public function test_platform_ags_verified_only_by_service_entry_point(): void {
        $c = $this->platform();
        $viaservice = $this->facts('platform', ['obs' => ['grade_in' => self::NOW - 5]]);
        $local = $this->facts('platform', ['obs' => ['grade_local' => self::NOW - 5]]);
        $log = $this->facts('platform', ['access' => ['service_ags_scores' => ['ok' => 3, 'last' => self::NOW - 5]]]);
        $failonly = $this->facts('platform', ['access' => ['service_ags_scores' => ['fail' => 3, 'last' => self::NOW - 5]]]);
        $builder = new matrix_builder();
        $this->assertSame(status::VERIFIED, $builder->build($c, $viaservice)['ags']['status']);
        $this->assertSame(status::CONFIGURED, $builder->build($c, $local)['ags']['status']);
        $this->assertSame(status::CONFIGURED, $builder->build($c, $log)['ags']['status'], 'A log line is not a transaction.');
        $this->assertContains('evidence_insufficient', $builder->build($c, $log)['ags']['notes']);
        $this->assertSame(status::CONFIGURED, $builder->build($c, $failonly)['ags']['status']);
    }

    /**
     * Platform side: NRPS and deep linking can never be verified without a hook; the log shows only requests.
     */
    public function test_platform_nrps_and_deeplinking_never_verified(): void {
        $c = $this->platform(['deeplinking' => 1]);
        $facts = $this->facts('platform', ['access' => [
            'service_nrps' => ['ok' => 5, 'last' => self::NOW - 5], 'deeplinking' => ['ok' => 2, 'last' => self::NOW - 5],
        ]]);
        $matrix = (new matrix_builder())->build($c, $facts);
        $this->assertSame(status::CONFIGURED, $matrix['nrps']['status']);
        $this->assertSame(status::CONFIGURED, $matrix['deeplinking']['status']);
        $nohook = (new matrix_builder())->build($c, $this->facts('platform'));
        $this->assertSame(status::STATE_NOT_OBSERVABLE, $nohook['nrps']['ladder']['observed']->state);
    }

    /**
     * Platform side: disabled services give "supported", never "configured".
     */
    public function test_platform_disabled_services(): void {
        $matrix = (new matrix_builder())->build($this->platform(['ags' => 0, 'nrps' => 0]), $this->facts('platform'));
        $this->assertSame(status::SUPPORTED, $matrix['ags']['status']);
        $this->assertSame(status::SUPPORTED, $matrix['nrps']['status']);
        $this->assertSame('enable_locally', $matrix['ags']['next']);
    }

    /**
     * Features that do not exist in the installed Moodle are "not supported", not "unknown".
     */
    public function test_unimplemented_features(): void {
        $matrix = (new matrix_builder())->build($this->platform(), $this->facts('platform'));
        $this->assertSame(status::NOT_SUPPORTED, $matrix['platform_storage']['status']);
        $this->assertSame(status::NOT_SUPPORTED, $matrix['course_group']['status']);
        $this->assertSame('not_implemented', $matrix['platform_storage']['reason']);
    }

    /**
     * A legacy tool is not a modern connection.
     */
    public function test_platform_legacy_version(): void {
        $c = $this->platform([], ['ltiversion' => 'LTI-1p0']);
        $row = (new matrix_builder())->build($c, $this->facts('platform'))['lti_core'];
        $this->assertSame(status::SUPPORTED, $row['status']);
    }

    /**
     * Tool side: grade sync switched on, nothing else known, is configured and not verified.
     */
    public function test_tool_ags_states(): void {
        $c = $this->tool();
        $builder = new matrix_builder();
        $base = $this->facts('tool', ['tool' => ['gradesync' => true]]);
        $row = $builder->build($c, $base)['ags'];
        $this->assertSame(status::CONFIGURED, $row['status']);

        $claim = $this->facts('tool', ['tool' => ['gradesync' => true, 'links' => 1, 'agsclaim' => true, 'scorescope' => true]]);
        $this->assertSame(status::AUTHORISED, $builder->build($c, $claim)['ags']['status']);

        $noscope = $this->facts('tool', ['tool' => ['gradesync' => true, 'links' => 1, 'agsclaim' => true, 'scorescope' => false]]);
        $row = $builder->build($c, $noscope)['ags'];
        $this->assertSame(status::BLOCKED, $row['status']);
        $this->assertSame('scope_missing', $row['reason']);

        $noclaim = $this->facts('tool', ['tool' => ['gradesync' => true, 'links' => 1, 'agsclaim' => false]]);
        $row = $builder->build($c, $noclaim)['ags'];
        $this->assertSame(status::BLOCKED, $row['status']);
        $this->assertSame('remote_not_offered', $row['reason']);

        $stored = $this->facts('tool', ['tool' => ['gradesync' => true, 'links' => 1, 'agsclaim' => true, 'scorescope' => true,
            'lastgrade' => true]]);
        $this->assertSame(
            status::AUTHORISED,
            $builder->build($c, $stored)['ags']['status'],
            'A stored grade is a local effect. The tool cannot see the platform accepting it.'
        );
    }

    /**
     * Tool side: a task run is not proof; membership sync enrolment is a local effect.
     */
    public function test_tool_task_run_is_not_proof(): void {
        $c = $this->tool();
        $facts = $this->facts('tool', [
            'tool' => ['gradesync' => true, 'membersync' => true], 'tasklog' => ['grades' => ['ok' => self::NOW - 5],
                'members' => ['ok' => self::NOW - 5]],
        ]);
        $matrix = (new matrix_builder())->build($c, $facts);
        $this->assertSame(status::CONFIGURED, $matrix['ags']['status']);
        $this->assertSame(status::CONFIGURED, $matrix['nrps']['status']);
        $this->assertSame(status::KIND_TASKLOG, $matrix['ags']['ladder']['observed']->kind);
    }

    /**
     * Tool side core: launch evidence needs an observed launch; the resource link is a local effect only.
     */
    public function test_tool_core_launch(): void {
        $c = $this->tool();
        $builder = new matrix_builder();
        $none = $builder->build($c, $this->facts('tool'))['lti_core'];
        $this->assertSame(status::CONFIGURED, $none['status']);
        $link = $builder->build($c, $this->facts('tool', ['tool' => ['links' => 2]]))['lti_core'];
        $this->assertSame(status::CONFIGURED, $link['status']);
        $seen = $builder->build($c, $this->facts('tool', ['obs' => ['launch' => self::NOW - 20]]))['lti_core'];
        $this->assertSame(status::VERIFIED, $seen['status']);
        $draft = $builder->build($this->tool(['state' => 'draft']), $this->facts('tool', ['tool' => ['complete' => false]]));
        $this->assertSame(status::SUPPORTED, $draft['lti_core']['status']);
    }

    /**
     * The probe result feeds the "reachable" step.
     */
    public function test_probe_feeds_reachability(): void {
        $c = $this->platform();
        $builder = new matrix_builder();
        $ok = $builder->build($c, $this->facts('platform'), ['jwks' => ['code' => 'ok', 'checkedat' => self::NOW]]);
        $this->assertSame(status::STATE_YES, $ok['ags']['ladder']['reachable']->state);
        $this->assertSame(status::CONFIGURED, $ok['ags']['status'], 'Reachable is not verified.');
        $bad = $builder->build($c, $this->facts('platform'), ['jwks' => ['code' => 'cert_expired', 'checkedat' => self::NOW]]);
        $this->assertSame(status::BLOCKED, $bad['ags']['status']);
        $this->assertSame('unreachable', $bad['ags']['reason']);
        $blocked = $builder->build($c, $this->facts('platform'), ['jwks' => ['code' => 'blocked_by_policy']]);
        $this->assertSame(status::CONFIGURED, $blocked['ags']['status'], 'A blocked probe says nothing about the target.');
        $this->assertSame(status::STATE_UNKNOWN, $blocked['ags']['ladder']['reachable']->state);
    }

    /**
     * Spec A.6, example 1: grade return is wanted, but the grade service is switched off.
     */
    public function test_spec_example_grade_return_wanted_but_ags_off(): void {
        $profile = $this->profile(['wanted' => ['lti_core', 'ags'], 'expect' => ['gradebook_update' => true]]);
        $result = $this->evaluate($this->platform(['ags' => 0]), $this->facts('platform'), $profile);
        $codes = $this->codes($result);
        $this->assertSame('blocking', $codes['adv_localoff']);
        $this->assertSame('warning', $codes['core_no_score']);
        $this->assertSame('red', $result['layers']['advantage']['light']);
        $row = array_values(array_filter($result['delta'], function ($d) {
            return $d['feature'] === 'ags';
        }))[0];
        $this->assertSame('no', $row['local']);
        $this->assertSame('supported', $row['status']);
    }

    /**
     * Spec A.6, example 2: the service is on, but the score scope is missing.
     */
    public function test_spec_example_scope_missing(): void {
        $profile = $this->profile(['wanted' => ['ags'], 'ags_scopes' => ['score'], 'expect' => ['gradebook_update' => true]]);
        $facts = $this->facts('tool', ['tool' => ['gradesync' => true, 'links' => 1, 'agsclaim' => true, 'scorescope' => false]]);
        $result = $this->evaluate($this->tool(), $facts, $profile);
        $codes = $this->codes($result);
        $this->assertSame('blocking', $codes['adv_scopemissing']);
        $this->assertSame('blocking', $codes['adv_noscorescope']);
        $this->assertArrayNotHasKey('adv_verified', $codes);
        $finding = array_values(array_filter($result['findings'], function ($f) {
            return $f->code === 'adv_noscorescope';
        }))[0];
        $this->assertSame('partner', $finding->responsible);
        $this->assertSame('oauth2', $finding->layer);
    }

    /**
     * Spec A.6, example 3: all switched on and authorised, but no score has been observed.
     */
    public function test_spec_example_no_score_observed(): void {
        $profile = $this->profile(['wanted' => ['ags'], 'expect' => ['gradebook_update' => true]]);
        $facts = $this->facts('platform', ['tokens' => ['ags' => self::NOW - 100, 'nrps' => 0, 'any' => self::NOW - 100]]);
        $result = $this->evaluate($this->platform(), $facts, $profile);
        $codes = $this->codes($result);
        $this->assertSame('warning', $codes['adv_notverified']);
        $this->assertSame('warning', $codes['core_no_score']);
        $this->assertArrayNotHasKey('core_grade_ok', $codes);
        $this->assertSame('authorised', $result['matrix']['ags']['status']);
    }

    /**
     * The same case when the score was observed through the service entry point.
     */
    public function test_spec_example_score_observed(): void {
        $profile = $this->profile(['wanted' => ['ags'], 'expect' => ['gradebook_update' => true]]);
        $facts = $this->facts('platform', ['tokens' => ['ags' => self::NOW - 100, 'nrps' => 0, 'any' => self::NOW - 100],
            'obs' => ['grade_in' => self::NOW - 50]]);
        $result = $this->evaluate($this->platform(), $facts, $profile);
        $codes = $this->codes($result);
        $this->assertSame('ok', $codes['adv_verified']);
        $this->assertSame('ok', $codes['core_grade_ok']);
        $this->assertArrayNotHasKey('core_no_score', $codes);
    }

    /**
     * Requests in the access log without a gradebook change are reported as unconfirmed.
     */
    public function test_score_request_without_gradebook_change(): void {
        $profile = $this->profile(['wanted' => ['ags'], 'expect' => ['gradebook_update' => true]]);
        $facts = $this->facts('platform', ['access' => ['service_ags_scores' => ['ok' => 2, 'last' => self::NOW - 10]]]);
        $codes = $this->codes($this->evaluate($this->platform(), $facts, $profile));
        $this->assertSame('warning', $codes['core_grade_unconfirmed']);
        $this->assertArrayNotHasKey('core_grade_ok', $codes);
    }

    /**
     * AGS disabled while NRPS is active: the two features are judged separately.
     */
    public function test_ags_disabled_but_nrps_active(): void {
        $profile = $this->profile(['wanted' => ['ags', 'nrps']]);
        $result = $this->evaluate($this->platform(['ags' => 0, 'nrps' => 1]), $this->facts('platform'), $profile);
        $this->assertSame('supported', $result['matrix']['ags']['status']);
        $this->assertSame('configured', $result['matrix']['nrps']['status']);
        $codes = $this->codes($result);
        $this->assertSame('blocking', $codes['adv_localoff']);
        $this->assertSame('warning', $codes['adv_notverified']);
    }

    /**
     * Read-only grade mode with score writing wanted.
     */
    public function test_readonly_mode_with_score_wanted(): void {
        $profile = $this->profile(['wanted' => ['ags'], 'ags_scopes' => ['score']]);
        $codes = $this->codes($this->evaluate($this->platform(['ags' => 1]), $this->facts('platform'), $profile));
        $this->assertSame('blocking', $codes['adv_readonlyscope']);
    }

    /**
     * A pseudonymised subject identifier is incompatible with AGS and NRPS.
     */
    public function test_pseudonymised_sub_is_incompatible_with_services(): void {
        $profile = $this->profile(['wanted' => ['lti_core', 'ags', 'nrps'], 'expect' => ['pseudonymised_sub' => true]]);
        $result = $this->evaluate($this->platform(), $this->facts('platform'), $profile);
        $this->assertSame('incompatible', $result['matrix']['ags']['status']);
        $this->assertSame('incompatible', $result['matrix']['nrps']['status']);
        $this->assertNotSame('incompatible', $result['matrix']['lti_core']['status']);
        $codes = $this->codes($result);
        $this->assertSame('blocking', $codes['adv_incompatible']);
        $this->assertSame('warning', $codes['priv_pseudonym_services']);
        $this->assertSame('warning', $codes['priv_loginhint']);
        $without = $this->profile(['wanted' => ['lti_core'], 'expect' => ['pseudonymised_sub' => true]]);
        $codes = $this->codes($this->evaluate($this->platform(), $this->facts('platform'), $without));
        $this->assertArrayNotHasKey('priv_pseudonym_services', $codes);
        $this->assertArrayNotHasKey('adv_incompatible', $codes);
    }

    /**
     * Name and e-mail expectations against the tool settings.
     */
    public function test_privacy_expectations_against_settings(): void {
        $profile = $this->profile(['expect' => ['send_name' => false, 'send_email' => false]]);
        $codes = $this->codes($this->evaluate($this->platform(), $this->facts('platform'), $profile));
        $this->assertSame('warning', $codes['priv_send_name']);
        $this->assertSame('warning', $codes['priv_send_email']);
        $silent = $this->platform(['sendname' => 0, 'sendemail' => 0]);
        $quiet = $this->codes($this->evaluate($silent, $this->facts('platform'), $profile));
        $this->assertArrayNotHasKey('priv_send_name', $quiet);
    }

    /**
     * Tool side enrolment and group expectations: the launch and the enrolment are separate rows.
     */
    public function test_tool_enrolment_and_launch_are_separate(): void {
        $profile = $this->profile(['wanted' => ['lti_core'], 'expect' => ['enrolment' => true, 'groups' => true]]);
        $facts = $this->facts('tool', ['obs' => ['launch' => self::NOW - 30, 'enrolment' => self::NOW - 29]]);
        $result = $this->evaluate($this->tool(), $facts, $profile);
        $codes = $this->codes($result);
        $this->assertSame('ok', $codes['core_launch_seen']);
        $this->assertSame('info', $codes['core_enrol_seen']);
        $this->assertSame('ok', $codes['core_enrol_ok']);
        $this->assertSame('warning', $codes['core_group_missing']);
        $none = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), $profile));
        $this->assertSame('warning', $none['core_enrol_missing']);
        $this->assertArrayNotHasKey('core_launch_seen', $none);
    }

    /**
     * No profile and no evidence still yields a complete report.
     */
    public function test_quiet_connection_without_profile(): void {
        $result = $this->evaluate($this->tool(), $this->facts('tool'));
        $this->assertSame(array_keys($result['layers']), finding::layers());
        $codes = $this->codes($result);
        $this->assertSame('ok', $codes['cfg_ok']);
        $this->assertSame('info', $codes['core_nothing']);
        $this->assertSame('not_checked', $codes['net_notchecked']);
        $this->assertSame('not_checked', $codes['fw_notchecked']);
        $this->assertSame('grey', $result['layers']['firewall']['light']);
        $this->assertSame([], $result['delta']);
        $this->assertFalse($result['progress'][2]['done']);
    }

    /**
     * Configuration findings for broken platform and tool settings.
     */
    public function test_configuration_findings(): void {
        $bad = $this->platform(['hasredirects' => 0], ['clientid' => '', 'state' => 'pending', 'endpoints' => ['launch' => 'x']]);
        $codes = $this->codes($this->evaluate($bad, $this->facts('platform')));
        $this->assertSame('blocking', $codes['cfg_state']);
        $this->assertSame('blocking', $codes['cfg_clientid']);
        $this->assertSame('blocking', $codes['cfg_initiate']);
        $this->assertSame('blocking', $codes['cfg_jwks']);
        $this->assertSame('warning', $codes['cfg_redirect']);
        $this->assertArrayNotHasKey('cfg_ok', $codes);

        $legacy = $this->platform([], ['ltiversion' => 'LTI-1p0']);
        $this->assertSame('warning', $this->codes($this->evaluate($legacy, $this->facts('platform')))['cfg_legacy']);

        $draft = $this->tool(['state' => 'draft', 'endpoints' => [], 'clientid' => '', 'deploymentid' => '']);
        $codes = $this->codes($this->evaluate($draft, $this->facts('tool')));
        $this->assertSame('blocking', $codes['cfg_registration_draft']);
        $this->assertSame('blocking', $codes['cfg_endpoints']);
        $this->assertSame('warning', $codes['cfg_nodeployment']);
    }

    /**
     * Probe results become findings in the right layer; a blocked probe is "not checked".
     *
     * @dataProvider probe_provider
     * @param string $code Probe code.
     * @param string $finding Expected finding code.
     * @param string $layer Expected layer.
     * @param string $severity Expected severity.
     */
    public function test_probe_findings(string $code, string $finding, string $layer, string $severity): void {
        $probe = ['purpose' => 'jwks', 'code' => $code, 'checkedat' => self::NOW, 'skew' => null, 'certexpiry' => null,
            'jwks' => null, 'framing' => null];
        $result = $this->evaluate($this->tool(), $this->facts('tool'), null, ['jwks' => $probe]);
        $found = array_values(array_filter($result['findings'], function ($f) use ($finding) {
            return $f->code === $finding;
        }));
        $this->assertCount(1, $found, $finding);
        $this->assertSame($layer, $found[0]->layer);
        $this->assertSame($severity, $found[0]->severity);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function probe_provider(): array {
        return [
            'expired certificate' => ['cert_expired', 'probe_cert_expired', 'tls', 'blocking'],
            'wrong host' => ['cert_hostname', 'probe_cert_hostname', 'tls', 'blocking'],
            'missing intermediate' => ['cert_chain_incomplete', 'probe_cert_chain_incomplete', 'tls', 'blocking'],
            'dns' => ['dns_failure', 'probe_dns_failure', 'dns_network', 'blocking'],
            'refused' => ['connection_refused', 'probe_connection_refused', 'dns_network', 'blocking'],
            'timeout' => ['timeout', 'probe_timeout', 'dns_network', 'blocking'],
            'firewall' => ['firewall_drop_suspected', 'probe_firewall_drop_suspected', 'firewall', 'blocking'],
            'proxy' => ['proxy_407', 'probe_proxy_407', 'dns_network', 'blocking'],
            'denied' => ['http_denied', 'probe_http_denied', 'oidc_jwt', 'blocking'],
            'not found' => ['http_notfound', 'probe_http_notfound', 'oidc_jwt', 'blocking'],
            'redirect' => ['redirect', 'probe_redirect', 'oidc_jwt', 'warning'],
            'server error' => ['http_server_error', 'probe_http_server_error', 'oidc_jwt', 'blocking'],
            'policy' => ['blocked_by_policy', 'probe_blocked_by_policy', 'dns_network', 'not_checked'],
            'ok network' => ['ok', 'net_ok', 'dns_network', 'ok'],
            'ok firewall caveat' => ['ok', 'fw_inbound', 'firewall', 'info'],
        ];
    }

    /**
     * Key set findings: leak of private key material and a missing key id.
     */
    public function test_jwks_findings(): void {
        $jwks = ['valid' => true, 'reason' => 'ok', 'keys' => [['kid' => 'a']], 'haskid' => false, 'privateleak' => true];
        $probe = ['purpose' => 'jwks', 'code' => 'ok', 'checkedat' => self::NOW, 'skew' => null, 'certexpiry' => null,
            'jwks' => $jwks, 'framing' => null];
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, ['jwks' => $probe]));
        $this->assertSame('blocking', $codes['jwks_privateleak']);
        $this->assertSame('blocking', $codes['jwks_kidmissing']);
        $this->assertArrayNotHasKey('jwks_ok', $codes);
        $probe['jwks'] = ['valid' => true, 'reason' => 'ok', 'keys' => [['kid' => 'a']], 'haskid' => true, 'privateleak' => false];
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, ['jwks' => $probe]));
        $this->assertSame('ok', $codes['jwks_ok']);
        $probe['jwks'] = ['valid' => false, 'reason' => 'notjson', 'keys' => [], 'haskid' => null, 'privateleak' => false];
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, ['jwks' => $probe]));
        $this->assertSame('blocking', $codes['jwks_invalid']);
    }

    /**
     * Clock skew thresholds and certificate expiry.
     */
    public function test_skew_and_expiry_findings(): void {
        $probe = ['purpose' => 'jwks', 'code' => 'ok', 'checkedat' => self::NOW, 'skew' => 120,
            'certexpiry' => self::NOW + 3 * DAYSECS, 'jwks' => null, 'framing' => null];
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, ['jwks' => $probe]));
        $this->assertSame('warning', $codes['skew_warn']);
        $this->assertSame('warning', $codes['tls_expiring']);
        $probe['skew'] = -900;
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, ['jwks' => $probe]));
        $this->assertSame('blocking', $codes['skew_blocking']);
        $probe['skew'] = 30;
        $probe['certexpiry'] = self::NOW + 90 * DAYSECS;
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, ['jwks' => $probe]));
        $this->assertArrayNotHasKey('skew_warn', $codes);
        $this->assertArrayNotHasKey('tls_expiring', $codes);
    }

    /**
     * Framing headers of a launch URL.
     */
    public function test_framing_finding(): void {
        $probe = ['purpose' => 'launch', 'code' => 'ok', 'checkedat' => self::NOW, 'skew' => null, 'certexpiry' => null,
            'jwks' => null, 'framing' => ['xfo' => 'deny', 'frameancestors' => '', 'blocksembedding' => true]];
        $codes = $this->codes($this->evaluate($this->platform(), $this->facts('platform'), null, ['launch' => $probe]));
        $this->assertSame('warning', $codes['bs_framing_blocked']);
        $probe['framing']['blocksembedding'] = false;
        $codes = $this->codes($this->evaluate($this->platform(), $this->facts('platform'), null, ['launch' => $probe]));
        $this->assertSame('info', $codes['bs_server_only']);
    }

    /**
     * Background findings.
     */
    public function test_background_findings(): void {
        $env = $this->env([
            'cron' => ['lastcronstart' => 0, 'age' => -1, 'status' => 'never'],
            'scheduled' => [
                ['classname' => '\enrol_lti\task\sync_grades', 'overdue' => true, 'faildelay' => 0, 'disabled' => false],
                ['classname' => '\enrol_lti\task\sync_members', 'overdue' => false, 'faildelay' => 120, 'disabled' => false],
                ['classname' => '\x\disabled', 'overdue' => false, 'faildelay' => 120, 'disabled' => true],
            ],
            'adhoc' => [
                ['classname' => '\a\b', 'failed' => 2, 'due' => 0], ['classname' => '\a\c', 'failed' => 0, 'due' => 3],
            ],
            'tasklogfailed' => [['id' => 1]],
        ]);
        $result = $this->evaluate($this->tool(), $this->facts('tool'), null, [], $env);
        $codes = $this->codes($result);
        foreach (
            ['bg_cron_never' => 'blocking', 'bg_task_overdue' => 'warning', 'bg_task_failing' => 'warning',
            'bg_adhoc_failed' => 'warning', 'bg_adhoc_pending' => 'info', 'bg_tasklog_failed' => 'warning'] as $code => $sev
        ) {
            $this->assertSame($sev, $codes[$code], $code);
        }
        $this->assertArrayNotHasKey('bg_ok', $codes);
        $this->assertSame('red', $result['layers']['background']['light']);
        $stale = $this->evaluate($this->tool(), $this->facts('tool'), null, [], $this->env([
            'cron' => ['lastcronstart' => self::NOW - 7200, 'age' => 7200, 'status' => 'stale']]));
        $this->assertSame('blocking', $this->codes($stale)['bg_cron_stale']);
        $ok = $this->codes($this->evaluate($this->tool(), $this->facts('tool')));
        $this->assertSame('ok', $ok['bg_ok']);
    }

    /**
     * Observability findings: no log source and recording switched off.
     */
    public function test_observability_findings(): void {
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, [], $this->env([
            'readers' => [], 'recordevents' => false, 'imports' => 0,
        ])));
        $this->assertSame('warning', $codes['obs_noreader']);
        $this->assertSame('warning', $codes['obs_recording_off']);
        $this->assertSame('info', $codes['obs_noaccesslog']);
        $this->assertSame('info', $codes['obs_incomplete']);
        $codes = $this->codes($this->evaluate($this->tool(), $this->facts('tool'), null, [], $this->env([
            'readers' => [['name' => 'x', 'class' => 'y', 'logging' => false]],
        ])));
        $this->assertSame('warning', $codes['obs_notlogging']);
        $this->assertArrayNotHasKey('obs_noaccesslog', $codes);
    }

    /**
     * Findings are sorted: blocking first.
     */
    public function test_findings_sorted_by_urgency(): void {
        $result = $this->evaluate($this->platform([], ['state' => 'pending']), $this->facts('platform'));
        $weights = array_map(function ($f) {
            return finding::weight($f->severity);
        }, $result['findings']);
        $sorted = $weights;
        sort($sorted);
        $this->assertSame($sorted, $weights);
        $this->assertSame('blocking', $result['findings'][0]->severity);
    }

    /**
     * Light calculation per layer.
     */
    public function test_layer_light(): void {
        $mk = function (string $sev) {
            return new finding('cfg_ok', 'configuration', $sev, 'admin');
        };
        $this->assertSame('grey', health_check::light([]));
        $this->assertSame('red', health_check::light([$mk('ok'), $mk('blocking')]));
        $this->assertSame('amber', health_check::light([$mk('ok'), $mk('warning')]));
        $this->assertSame('green', health_check::light([$mk('ok'), $mk('info')]));
        $this->assertSame('amber', health_check::light([$mk('ok'), $mk('not_checked')]));
        $this->assertSame('grey', health_check::light([$mk('info')]));
        $this->assertSame('grey', health_check::light([$mk('not_checked')]));
    }

    /**
     * Profiles: valid input, round trip and every refusal.
     */
    public function test_profile_parsing(): void {
        [$p, $errors] = desired_profile::from_json(json_encode([
            'schema' => 1, 'name' => 'Grades', 'wanted' => ['ags', 'lti_core'], 'ags_scopes' => ['score'],
            'expect' => ['send_name' => false, 'gradebook_update' => true],
        ]));
        $this->assertSame([], $errors);
        $this->assertTrue($p->wants('ags'));
        $this->assertFalse($p->wants('nrps'));
        $this->assertTrue($p->expects('gradebook_update'));
        $this->assertFalse($p->expects('send_name'), 'An expectation of false is not an expectation of true.');
        [$again] = desired_profile::from_json($p->to_json());
        $this->assertEquals($p, $again);

        $bad = [
            'notjson' => ['{nope', 'notjson'],
            'schema' => [json_encode(['schema' => 99, 'name' => 'x']), 'schema'],
            'noname' => [json_encode(['schema' => 1]), 'noname'],
            'feature' => [json_encode(['schema' => 1, 'name' => 'x', 'wanted' => ['teleport']]), 'badfeature'],
            'scope' => [json_encode(['schema' => 1, 'name' => 'x', 'wanted' => ['ags'], 'ags_scopes' => ['root']]), 'badscope'],
            'expect' => [json_encode(['schema' => 1, 'name' => 'x', 'expect' => ['mind_reading' => true]]), 'badexpect'],
            'expect type' => [json_encode(['schema' => 1, 'name' => 'x', 'expect' => ['send_name' => 'yes']]), 'badexpect'],
            'unknown key' => [json_encode(['schema' => 1, 'name' => 'x', 'features' => ['ags']]), 'badkey'],
            'scopes without ags' => [json_encode(['schema' => 1, 'name' => 'x', 'ags_scopes' => ['score']]), 'scopesnoags'],
        ];
        foreach ($bad as $label => [$json, $code]) {
            [$profile, $errors] = desired_profile::from_json($json);
            $this->assertNull($profile, $label);
            $this->assertSame($code, $errors[0][0], $label);
        }
    }
}
