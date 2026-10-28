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
 * Observability levels per transaction type and direction (audit item DIA-01).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\observability
 * @covers     \tool_ltidiagnostics\local\report
 */
final class observability_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var int Fixed "now". */
    private const NOW = 1792800000;

    /**
     * Connection of a role.
     *
     * @param string $role Role.
     * @return connection
     */
    private function connection(string $role): connection {
        $c = new connection();
        $c->role = $role;
        $c->key = $role . ':1';
        $c->name = 'X';
        $c->ltiversion = '1.3.0';
        $c->clientid = 'c';
        $c->deploymentid = 'd';
        $c->state = $role === 'platform' ? 'active' : 'complete';
        $c->endpoints = ['jwks' => 'https://x.example.org/jwks', 'initiatelogin' => 'https://x.example.org/login'];
        $c->facts = $role === 'platform'
            ? ['keytype' => 'JWK_KEYSET', 'ags' => 2, 'nrps' => 1, 'toolsettings' => 0, 'deeplinking' => 0, 'sendname' => 1,
                'sendemail' => 1, 'acceptgrades' => 1, 'hasredirects' => 1, 'services' => []]
            : ['issuerhash' => hash('sha256', 'https://p.example.org'), 'toolcount' => 1];
        return $c;
    }

    /**
     * Facts without any evidence.
     *
     * @return array
     */
    private function nofacts(): array {
        return [
            'now' => self::NOW,
            'implemented' => array_fill_keys(matrix_builder::FEATURES, true),
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
     * All sources usable.
     *
     * @return bool[]
     */
    private function all(): array {
        return ['observer' => true, 'accesslog' => true, 'probe' => true, 'dbrecord' => true, 'tasklog' => true];
    }

    /**
     * No source at all: every cell that depends on a switch is "not observable" and says which switch would help.
     */
    public function test_without_instrumentation_nothing_is_observable(): void {
        $rows = observability::build(
            $this->connection('platform'),
            $this->nofacts(),
            [],
            ['observer' => false, 'accesslog' => false, 'probe' => false, 'dbrecord' => true, 'tasklog' => true]
        );
        $this->assertSame('not_observable', $rows['oidc_login']['request']['level']);
        $this->assertSame('transport', $rows['oidc_login']['request']['potential']);
        $this->assertSame(['accesslog'], $rows['oidc_login']['request']['needs']);
        $this->assertSame('not_observable', $rows['jwks_fetch']['request']['level']);
        $this->assertSame('payload', $rows['jwks_fetch']['request']['potential']);
        $this->assertSame(['activechecks'], $rows['jwks_fetch']['request']['needs']);
        // The launch message of the platform has no response that this system can see, whatever is switched on.
        $this->assertSame('not_observable', $rows['launch_message']['response']['potential']);
        foreach ($rows as $row) {
            $this->assertFalse($row['capable'], $row['transaction']);
        }
    }

    /**
     * A web server log gives "transport" and nothing more, even when the status is 200.
     */
    public function test_http_200_in_a_log_is_only_transport(): void {
        $facts = $this->nofacts();
        $facts['access'] = ['service_ags_scores' => ['ok' => 5, 'fail' => 0, 'last' => self::NOW - 10, 'codes' => [200 => 5]]];
        $rows = observability::build(
            $this->connection('platform'),
            $facts,
            [],
            ['observer' => false, 'accesslog' => true, 'probe' => false, 'dbrecord' => true, 'tasklog' => true]
        );
        $scores = $rows['ags_scores'];
        $this->assertSame('transport', $scores['request']['level']);
        $this->assertSame('transport', $scores['response']['level']);
        $this->assertSame(self::NOW - 10, $scores['request']['seen']);
        $this->assertFalse($scores['capable'], 'Transport is not enough to call the exchange recorded.');
        $this->assertSame(['accesslog'], $scores['response']['basis']);
        // With the observer the request reaches "recorded", the response still does not.
        $facts['obs'] = ['grade_in' => self::NOW - 5];
        $rows = observability::build($this->connection('platform'), $facts, [], $this->all());
        $this->assertSame('recorded', $rows['ags_scores']['request']['level']);
        $this->assertSame(self::NOW - 5, $rows['ags_scores']['request']['seen']);
        $this->assertSame('transport', $rows['ags_scores']['response']['level']);
        $this->assertFalse($rows['ags_scores']['capable']);
    }

    /**
     * The level is a capability: without a matching event the cell has no "seen" time.
     */
    public function test_capability_is_not_observation(): void {
        $rows = observability::build($this->connection('tool'), $this->nofacts(), [], $this->all());
        $launch = $rows['launch_message'];
        $this->assertSame('recorded', $launch['request']['level']);
        $this->assertSame(0, $launch['request']['seen']);
        $facts = $this->nofacts();
        $facts['obs'] = ['launch' => self::NOW - 30];
        $rows = observability::build($this->connection('tool'), $facts, [], $this->all());
        $this->assertSame(self::NOW - 30, $rows['launch_message']['request']['seen']);
    }

    /**
     * Content is seen only for requests that this plugin sends itself, and only when the probe really got an answer.
     */
    public function test_payload_only_for_own_probe(): void {
        foreach (['platform', 'tool'] as $role) {
            $failed = observability::build($this->connection($role), $this->nofacts(), [
                'jwks' => ['code' => 'dns_failure', 'checkedat' => self::NOW],
            ], $this->all());
            $blocked = observability::build($this->connection($role), $this->nofacts(), [
                'jwks' => ['code' => 'blocked_by_policy', 'checkedat' => self::NOW],
            ], $this->all());
            $ok = observability::build($this->connection($role), $this->nofacts(), [
                'jwks' => ['code' => 'ok', 'checkedat' => self::NOW],
            ], $this->all());
            $this->assertSame(0, $failed['jwks_fetch']['response']['seen'], 'A failed probe saw no content.');
            $this->assertSame(0, $blocked['jwks_fetch']['response']['seen']);
            $this->assertSame(self::NOW, $ok['jwks_fetch']['response']['seen']);
            // Every other exchange: never more than "recorded", whatever sources and evidence exist.
            foreach ($ok as $id => $row) {
                foreach (['request', 'response'] as $part) {
                    if ($id !== 'jwks_fetch') {
                        $this->assertNotSame('payload', $row[$part]['level'], "$role $id $part");
                        $this->assertNotSame('payload', $row[$part]['potential'], "$role $id $part");
                    }
                }
            }
        }
    }

    /**
     * Messages exchanged between other parties have no instrumentation: they stay "not observable" in every
     * configuration, and the tool side of outbound token and service calls is only inferred from tasks.
     */
    public function test_third_party_messages_stay_not_observable(): void {
        $facts = $this->nofacts();
        $facts['tasklog'] = ['grades' => ['ok' => self::NOW - 100], 'members' => ['failed' => self::NOW - 50]];
        $rows = observability::build($this->connection('tool'), $facts, [], $this->all());
        $this->assertSame('not_observable', $rows['oauth_token']['request']['level']);
        $this->assertSame('not_observable', $rows['oauth_token']['response']['level']);
        $this->assertSame('not_observable', $rows['ags_scores']['response']['level'], 'The answer of the platform is invisible.');
        $this->assertSame('recorded', $rows['ags_scores']['request']['level'], 'Only that the task ran is recorded.');
        $this->assertSame(self::NOW - 100, $rows['ags_scores']['request']['seen']);
        $this->assertSame(self::NOW - 50, $rows['nrps']['request']['seen'], 'A failed run is also something seen.');
        $platform = observability::build($this->connection('platform'), $this->nofacts(), [], $this->all());
        $this->assertSame('not_observable', $platform['launch_message']['response']['level']);
        $this->assertNotSame('payload', $platform['launch_message']['request']['potential']);
    }

    /**
     * Each role lists its transactions with a direction.
     */
    public function test_specification_is_complete(): void {
        foreach (['platform', 'tool'] as $role) {
            $spec = observability::specification($role);
            $this->assertArrayHasKey('oidc_login', $spec);
            $this->assertArrayHasKey('launch_message', $spec);
            $this->assertArrayHasKey('ags_scores', $spec);
            $this->assertArrayHasKey('nrps', $spec);
            $this->assertArrayHasKey('jwks_fetch', $spec);
            foreach ($spec as $id => $row) {
                $this->assertContains($row['direction'], [observability::INBOUND, observability::OUTBOUND], $id);
                $this->assertArrayHasKey('request', $row);
                $this->assertArrayHasKey('response', $row);
            }
        }
        $this->assertSame(observability::OUTBOUND, observability::specification('tool')['ags_scores']['direction']);
        $this->assertSame(observability::INBOUND, observability::specification('platform')['ags_scores']['direction']);
        $this->assertSame(['not_observable', 'inferred', 'transport', 'recorded', 'payload'], observability::levels());
        $this->assertGreaterThan(observability::rank('transport'), observability::rank('recorded'));
        $this->assertSame(0, observability::rank('nonsense'));
    }

    /**
     * Switch names for the sources come from the configuration.
     */
    public function test_enabled_sources_follow_configuration(): void {
        $this->resetAfterTest();
        set_config('recordevents', 0, 'tool_ltidiagnostics');
        set_config('activechecks', 0, 'tool_ltidiagnostics');
        $sources = observability::enabled_sources(0);
        $this->assertFalse($sources['observer']);
        $this->assertFalse($sources['accesslog']);
        $this->assertFalse($sources['probe']);
        $this->assertTrue($sources['tasklog']);
        set_config('recordevents', 1, 'tool_ltidiagnostics');
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        $sources = observability::enabled_sources(2);
        $this->assertTrue($sources['observer']);
        $this->assertTrue($sources['accesslog']);
        $this->assertTrue($sources['probe']);
    }

    /**
     * The report, the export formats and the findings carry the observability table.
     */
    public function test_report_and_exports_contain_the_levels(): void {
        $this->resetAfterTest();
        $c = $this->connection('platform');
        $facts = $this->nofacts();
        $facts['access'] = ['oauth' => ['ok' => 2, 'fail' => 0, 'last' => self::NOW - 60, 'codes' => [200 => 2]]];
        $result = (new health_check())->evaluate($c, [
            'now' => self::NOW, 'facts' => $facts, 'probes' => [], 'profile' => null,
            'env' => ['readers' => [], 'cron' => ['lastcronstart' => self::NOW, 'age' => 0, 'status' => 'ok'], 'scheduled' => [],
                'adhoc' => [], 'tasklogfailed' => [], 'recordevents' => false, 'activechecks' => false, 'imports' => 1],
        ]);
        $this->assertArrayHasKey('observability', $result);
        $this->assertSame('transport', $result['observability']['oauth_token']['request']['level']);
        $this->assertSame('not_observable', $result['observability']['launch_message']['response']['level']);
        $this->assertSame('not_observable', $result['observability']['jwks_fetch']['request']['level'], 'Checks are off.');

        $report = (new report())->build($c, $result, ['now' => self::NOW, 'timezone' => 'UTC', 'viewerrole' => 'admin']);
        $byid = array_column($report['observability'], null, 'transaction');
        $this->assertSame(gmdate('c', self::NOW - 60), $byid['oauth_token']['request']['seen']);
        $this->assertSame('', $byid['launch_message']['request']['seen']);
        $this->assertSame(['activechecks'], $byid['jwks_fetch']['request']['needs']);
        $this->assertContains(get_string('limit_nopayloads', 'tool_ltidiagnostics'), $report['limits']);

        $json = json_decode(report_exporter::export($report, 'json'), true);
        $rows = array_column($json['observability'], null, 'transaction');
        $this->assertSame('transport', $rows['oauth_token']['request']['level']);
        $md = report_exporter::export($report, 'md');
        $this->assertStringContainsString(get_string('report_observability', 'tool_ltidiagnostics'), $md);
        $this->assertStringContainsString(get_string('obslevel_transport', 'tool_ltidiagnostics'), $md);
        $csv = report_exporter::export($report, 'csv');
        $this->assertStringContainsString('observability,oauth_token.request,oauth_token,transport', $csv);
        // Payloads stay excluded, and no raw client address is in any format.
        $this->assertSame('excluded', $report['meta']['payloads']);
    }

    /**
     * The observability table is rendered with text for every level (colour never carries the meaning) and with a
     * visible statement when nothing was seen.
     */
    public function test_rendered_table_has_text_and_headers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $rows = observability::build($this->connection('tool'), $this->nofacts(), [], $this->all());
        $html = $this->renderer()->observability_table($rows);
        $this->assertStringContainsString('<caption', $html);
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertStringContainsString('scope="row"', $html);
        $this->assertStringContainsString(get_string('obs_notseen', 'tool_ltidiagnostics'), $html);
        $this->assertStringContainsString(get_string('obslevel_not_observable', 'tool_ltidiagnostics'), $html);
        $this->assertStringContainsString(get_string('obs_tx_launch_message', 'tool_ltidiagnostics'), $html);
        $this->assertSame('', $this->renderer()->observability_table([]));
    }

    /**
     * The plugin renderer.
     *
     * @return \tool_ltidiagnostics\output\renderer
     */
    private function renderer(): \tool_ltidiagnostics\output\renderer {
        global $PAGE;
        $PAGE->set_url('/admin/tool/ltidiagnostics/index.php');
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('tool_ltidiagnostics');
    }
}
