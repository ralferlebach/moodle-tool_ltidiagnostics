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
 * Reporting, export, audit, diagnostic mode, snapshots, profiles and retention.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\report
 * @covers     \tool_ltidiagnostics\local\report_exporter
 * @covers     \tool_ltidiagnostics\local\audit
 * @covers     \tool_ltidiagnostics\local\diagnostic_mode
 * @covers     \tool_ltidiagnostics\local\snapshot
 * @covers     \tool_ltidiagnostics\local\profile_manager
 * @covers     \tool_ltidiagnostics\task\cleanup_task
 */
final class reporting_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * A small report fixture produced by the real report builder.
     *
     * @param array $override Connection overrides.
     * @param bool $reveal Whether identifiers are revealed.
     * @return array
     */
    private function report(array $override = [], bool $reveal = false): array {
        $c = new connection();
        $c->role = 'tool';
        $c->key = 'tool:3:4';
        $c->name = 'Platform with = name';
        $c->ltiversion = '1.3.0';
        $c->issuer = 'https://platform.example.org';
        $c->clientid = 'abcdefghijkl';
        $c->deploymentid = 'deployment-1';
        $c->state = 'complete';
        $c->endpoints = ['jwks' => 'https://platform.example.org/jwks?token=SECRETTOKEN&x=1'];
        foreach ($override as $k => $v) {
            $c->$k = $v;
        }
        $facts = [
            'now' => 1792800000,
            'implemented' => ['lti_core' => true, 'ags' => true, 'nrps' => true, 'deeplinking' => true, 'dynreg' => true,
                'submission_review' => false, 'platform_storage' => false, 'course_group' => false],
            'obs' => ['launch' => 0, 'enrolment' => 0, 'group' => 0, 'grade_in' => 0, 'grade_local' => 0, 'view_out' => 0,
                'account_created' => 0, 'service_unknown' => 0, 'count' => 0],
            'access' => [], 'tasklog' => [], 'tokens' => [],
            'tool' => ['complete' => true, 'hasauth' => true, 'hasjwks' => true, 'hasoauth' => true, 'tools' => 0,
                'gradesync' => false, 'membersync' => false, 'links' => 0, 'agsclaim' => false, 'scorescope' => false,
                'nrpsclaim' => false, 'lastgrade' => false],
        ];
        $env = ['readers' => [], 'cron' => ['lastcronstart' => 1792799900, 'age' => 100, 'status' => 'ok'], 'scheduled' => [],
            'adhoc' => [], 'tasklogfailed' => [], 'recordevents' => true, 'imports' => 0];
        $result = (new health_check())->evaluate($c, ['now' => 1792800000, 'facts' => $facts, 'probes' => [], 'profile' => null,
            'env' => $env]);
        return (new report())->build($c, $result, ['reveal' => $reveal, 'now' => 1792800000, 'timezone' => 'Europe/Berlin',
            'viewerrole' => 'admin', 'filters' => ['courseid' => 5]]);
    }

    /**
     * The report carries the metadata required by the specification.
     */
    public function test_report_metadata(): void {
        $this->resetAfterTest();
        $r = $this->report();
        $this->assertSame('tool_ltidiagnostics', $r['meta']['plugin']);
        $this->assertNotSame('', $r['meta']['pluginversion']);
        $this->assertNotSame('', $r['meta']['moodleversion']);
        $this->assertSame(gmdate('c', 1792800000), $r['meta']['generated']);
        $this->assertSame('Europe/Berlin', $r['meta']['timezone']);
        $this->assertSame('admin', $r['meta']['viewerrole']);
        $this->assertSame('excluded', $r['meta']['payloads']);
        $this->assertSame(5, $r['filters']->courseid);
        $this->assertSame(support::diagnostic_id('tool:3:4'), $r['meta']['diagnosticid']);
        $this->assertCount(6, $r['limits']);
        $this->assertSame(finding::layers(), array_keys($r['layers']));
    }

    /**
     * Identifiers are masked by default and secrets never appear, in any export format.
     */
    public function test_exports_mask_and_redact(): void {
        $this->resetAfterTest();
        $r = $this->report();
        foreach (report_exporter::FORMATS as $format) {
            $out = report_exporter::export($r, $format);
            $this->assertStringNotContainsString('SECRETTOKEN', $out, $format);
            $this->assertStringNotContainsString('abcdefghijkl', $out, $format);
            $this->assertStringNotContainsString('platform.example.org', $out, $format);
        }
        $revealed = $this->report([], true);
        $json = report_exporter::export($revealed, 'json');
        $this->assertStringContainsString('abcdefghijkl', $json);
        $this->assertStringContainsString('platform.example.org', $json);
        $this->assertStringNotContainsString('SECRETTOKEN', $json, 'Secrets are never revealed, not even in diagnostic mode.');
    }

    /**
     * JSON export is valid and complete.
     */
    public function test_json_export(): void {
        $this->resetAfterTest();
        $json = report_exporter::export($this->report(), 'json');
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        foreach (['meta', 'connection', 'filters', 'layers', 'summary', 'matrix', 'findings', 'delta', 'limits'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertCount(8, $data['matrix']);
        $this->assertSame(['mimetype' => 'application/json', 'ext' => 'json'], ['mimetype' => report_exporter::file_info('json')[0],
            'ext' => report_exporter::file_info('json')[1]]);
    }

    /**
     * Markdown export: headings, no raw pipes that break tables, limits section.
     */
    public function test_markdown_export(): void {
        $this->resetAfterTest();
        $md = report_exporter::export($this->report(), 'md');
        $this->assertStringStartsWith('# ' . get_string('report_title', 'tool_ltidiagnostics'), $md);
        $this->assertStringContainsString('## ' . get_string('report_matrix', 'tool_ltidiagnostics'), $md);
        $this->assertStringContainsString('## ' . get_string('report_findings', 'tool_ltidiagnostics'), $md);
        $this->assertStringContainsString('## ' . get_string('report_limits', 'tool_ltidiagnostics'), $md);
        $this->assertStringContainsString('LTD-', $md);
        $report = $this->report();
        $report['matrix'][0]['reason'] = "a | b\nc";
        $table = report_exporter::to_markdown($report);
        $this->assertStringContainsString('a \\| b c', $table);
    }

    /**
     * CSV export neutralises spreadsheet formulas and quotes properly.
     */
    public function test_csv_export_prevents_formula_injection(): void {
        $this->resetAfterTest();
        $this->assertSame("'=1+1", report_exporter::cell('=1+1'));
        $this->assertSame("'+cmd", report_exporter::cell('+cmd'));
        $this->assertSame("'-1", report_exporter::cell('-1'));
        $this->assertSame("'@SUM(A1)", report_exporter::cell('@SUM(A1)'));
        $this->assertSame("'\tx", report_exporter::cell("\tx"));
        $this->assertSame('plain', report_exporter::cell('plain'));
        $this->assertSame('', report_exporter::cell(''));
        $this->assertSame('5', report_exporter::cell(5));

        $report = $this->report();
        $report['matrix'][0]['label'] = '=HYPERLINK("http://evil.example","x")';
        $csv = report_exporter::to_csv($report);
        $rows = array_map(fn(string $line): array => str_getcsv($line, ',', '"', '\\'), array_filter(explode("\n", $csv)));
        $header = array_shift($rows);
        $this->assertSame('section', $header[0]);
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, 'Formula start in: ' . $cell);
            }
        }
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    /**
     * Unknown formats are refused.
     */
    public function test_unknown_export_format(): void {
        $this->expectException(\coding_exception::class);
        report_exporter::export([], 'xlsx');
    }

    /**
     * The audit log stores a clean action, a valid connection key and a redacted detail.
     */
    public function test_audit_log(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = audit::log('view connection<script>', 'tool:1:2', 'token=abc Bearer abcdefghijklmnop mail a@b.example');
        $row = $DB->get_record('tool_ltidiagnostics_audit', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('viewconnectionscript', $row->action);
        $this->assertSame('tool:1:2', $row->connkey);
        $this->assertStringNotContainsString('abcdefghijklmnop', $row->detail);
        $this->assertStringNotContainsString('a@b.example', $row->detail);
        $this->assertSame((int) get_admin()->id, (int) $row->userid);
        $other = audit::log('x', 'not a key', str_repeat('y', 600));
        $row = $DB->get_record('tool_ltidiagnostics_audit', ['id' => $other], '*', MUST_EXIST);
        $this->assertSame('', $row->connkey);
        $this->assertLessThanOrEqual(255, strlen($row->detail));
        $this->assertCount(2, audit::recent());
        $this->assertCount(1, audit::recent(1));
    }

    /**
     * The diagnostic mode has a time limit, a maximum and an audit trail.
     */
    public function test_diagnostic_mode_ttl(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        $this->assertFalse(diagnostic_mode::is_active($now));
        $until = diagnostic_mode::enable(30, $now);
        $this->assertSame($now + 30 * 60, $until);
        $this->assertTrue(diagnostic_mode::is_active($now + 29 * 60));
        $this->assertFalse(diagnostic_mode::is_active($now + 30 * 60 + 1));
        $this->assertSame('modeenable', $DB->get_field('tool_ltidiagnostics_audit', 'action', [], IGNORE_MULTIPLE));

        // The maximum applies.
        set_config('diagmodemaxminutes', 20, 'tool_ltidiagnostics');
        $this->assertSame($now + 20 * 60, diagnostic_mode::enable(100000, $now));
        $this->assertSame($now + 60, diagnostic_mode::enable(-5, $now), 'At least one minute.');

        // Expiry (not yet, then after the end).
        $this->assertFalse(diagnostic_mode::expire());
        set_config('diagmode_until', time() - 10, 'tool_ltidiagnostics');
        $this->assertTrue(diagnostic_mode::expire());
        $this->assertFalse(diagnostic_mode::is_active());
        $this->assertFalse(diagnostic_mode::expire(), 'Nothing left to expire.');
        $this->assertSame(0, diagnostic_mode::until());
        $this->assertTrue($DB->record_exists('tool_ltidiagnostics_audit', ['action' => 'modedisable']));

        // Manual disable.
        diagnostic_mode::enable(10);
        $this->assertGreaterThan(time(), diagnostic_mode::until());
        diagnostic_mode::disable();
        $this->assertFalse(diagnostic_mode::is_active());
    }

    /**
     * Mode changes trigger an event.
     */
    public function test_diagnostic_mode_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $sink = $this->redirectEvents();
        diagnostic_mode::enable(5);
        diagnostic_mode::disable();
        $events = array_filter($sink->get_events(), function ($e) {
            return $e instanceof \tool_ltidiagnostics\event\diagnostic_mode_changed;
        });
        $this->assertCount(2, $events);
        $first = reset($events);
        $this->assertSame(1, $first->other['enabled']);
        $this->assertNotEmpty($first->get_name());
        $this->assertNotEmpty($first->get_description());
    }

    /**
     * Snapshots store only changes and can be compared.
     */
    public function test_snapshots_and_compare(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $builder = new matrix_builder();
        $c = new connection();
        $c->role = 'platform';
        $c->key = 'platform:9';
        $c->ltiversion = '1.3.0';
        $c->state = 'active';
        $c->facts = ['ags' => 0, 'nrps' => 1];
        $facts = [
            'implemented' => ['lti_core' => true, 'ags' => true, 'nrps' => true, 'deeplinking' => true, 'dynreg' => true,
                'submission_review' => false, 'platform_storage' => false, 'course_group' => false],
            'obs' => ['launch' => 0, 'enrolment' => 0, 'group' => 0, 'grade_in' => 0, 'grade_local' => 0, 'view_out' => 0],
            'access' => [], 'tokens' => ['ags' => 0, 'nrps' => 0, 'any' => 0],
        ];
        $m1 = $builder->build($c, $facts);
        $this->assertGreaterThan(0, snapshot::take('platform:9', $m1, 1000));
        $this->assertSame(0, snapshot::take('platform:9', $m1, 1100), 'An unchanged matrix adds no row.');
        $c->facts['ags'] = 2;
        $m2 = $builder->build($c, $facts);
        $this->assertGreaterThan(0, snapshot::take('platform:9', $m2, 1200));
        $list = snapshot::get_list('platform:9');
        $this->assertCount(2, $list);
        $diff = snapshot::compare(snapshot::condense($m1), snapshot::condense($m2));
        $this->assertCount(1, $diff);
        $this->assertSame('ags', $diff[0]['feature']);
        $this->assertSame('supported', $diff[0]['from']);
        $this->assertSame('configured', $diff[0]['to']);
        $this->assertContains('enabled', $diff[0]['changedsteps']);
        $this->assertSame([], snapshot::compare(snapshot::condense($m2), snapshot::condense($m2)));
        $first = snapshot::compare([], snapshot::condense($m1));
        $this->assertCount(count($m1), $first, 'Everything is new against an empty snapshot.');
    }

    /**
     * Probe snapshots keep the results but not the addresses.
     */
    public function test_probe_snapshot_drops_addresses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $probes = ['jwks' => [
            'purpose' => 'jwks', 'code' => 'ok', 'url' => 'https://secret.example.org/jwks', 'dns' => ['203.0.113.7'],
            'httpstatus' => 200, 'checkedat' => 1000,
        ]];
        snapshot::save_probes('platform:9', $probes, 1000);
        $back = snapshot::latest_probes('platform:9');
        $this->assertSame('ok', $back['jwks']['code']);
        $this->assertArrayNotHasKey('url', $back['jwks']);
        $this->assertArrayNotHasKey('dns', $back['jwks']);
        $this->assertSame([], snapshot::latest_probes('platform:404'));
    }

    /**
     * Desired-state profiles are stored per connection, validated, replaced and deleted.
     */
    public function test_profile_manager(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $json = json_encode(['schema' => 1, 'name' => 'First', 'wanted' => ['ags']]);
        [$id, $errors] = profile_manager::save('platform:1', $json);
        $this->assertGreaterThan(0, $id);
        $this->assertSame([], $errors);
        $this->assertSame('First', profile_manager::get('platform:1')->name);
        [$again] = profile_manager::save('platform:1', json_encode(['schema' => 1, 'name' => 'Second']));
        $this->assertSame($id, $again);
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_profile'));
        $this->assertSame('Second', profile_manager::get('platform:1')->name);
        $this->assertNull(profile_manager::get('platform:2'));
        [$none, $errors] = profile_manager::save('platform:1', '{broken');
        $this->assertSame(0, $none);
        $this->assertSame('notjson', $errors[0][0]);
        [$none, $errors] = profile_manager::save('not a key', $json);
        $this->assertSame('badkey', $errors[0][0]);
        $this->assertSame('Second', profile_manager::get('platform:1')->name, 'A refused save changes nothing.');
        profile_manager::delete('platform:1');
        $this->assertNull(profile_manager::get('platform:1'));
    }

    /**
     * The retention task removes old data, keeps recent data and ends an expired diagnostic mode.
     */
    public function test_cleanup_task(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('retentiondays', 10, 'tool_ltidiagnostics');
        // Since 0.3.0 imported lines and jobs have their own retention (the upgrade starts it with retentiondays).
        set_config('retentionaccdays', 10, 'tool_ltidiagnostics');
        $old = time() - 11 * DAYSECS;
        $new = time() - 1 * DAYSECS;
        observation_store::record(['eventtype' => 'launch', 'eventname' => 'old', 'timecreated' => $old]);
        observation_store::record(['eventtype' => 'launch', 'eventname' => 'new', 'timecreated' => $new]);
        $acc = function (string $hash, int $time) use ($DB) {
            $DB->insert_record('tool_ltidiagnostics_acc', (object) [
                'importid' => 1, 'linehash' => $hash, 'kind' => 'access', 'logtime' => $time, 'method' => 'GET',
                'path' => '/mod/lti/certs.php', 'httpstatus' => 200, 'bytes' => 1, 'ipmask' => '', 'viaproxy' => 0,
                'requestid' => '', 'category' => 'jwks', 'message' => '',
            ]);
        };
        $acc('a', $old);
        $acc('b', $new);
        $imp = function (int $time, string $fp) use ($DB) {
            $DB->insert_record('tool_ltidiagnostics_imp', (object) [
                'kind' => 'access', 'format' => 'combined', 'sourcetype' => 'upload', 'sourcename' => 'x', 'fingerprint' => $fp,
                'timezone' => 'UTC', 'skew' => 0, 'rangefrom' => 0, 'rangeto' => 0, 'linesread' => 0, 'linesstored' => 0,
                'linesskipped' => 0, 'linesinvalid' => 0, 'status' => 'done', 'errormessage' => '', 'userid' => 0,
                'timecreated' => $time,
            ]);
        };
        $imp($old, 'o');
        $imp($new, 'n');
        $DB->insert_record('tool_ltidiagnostics_audit', (object) ['userid' => 0, 'action' => 'ancient', 'connkey' => '',
            'detail' => '', 'timecreated' => time() - 400 * DAYSECS]);
        $DB->insert_record('tool_ltidiagnostics_audit', (object) ['userid' => 0, 'action' => 'recent', 'connkey' => '',
            'detail' => '', 'timecreated' => time() - 5 * DAYSECS]);
        set_config('diagmode_until', time() - 5, 'tool_ltidiagnostics');

        $task = new \tool_ltidiagnostics\task\cleanup_task();
        $this->assertNotSame('', $task->get_name());
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('removed 1 observations', $output);

        $this->assertSame(['new'], array_values($DB->get_fieldset_select('tool_ltidiagnostics_obs', 'eventname', '1=1')));
        $this->assertSame(['b'], array_values($DB->get_fieldset_select('tool_ltidiagnostics_acc', 'linehash', '1=1')));
        $this->assertSame(['n'], array_values($DB->get_fieldset_select('tool_ltidiagnostics_imp', 'fingerprint', '1=1')));
        $actions = $DB->get_fieldset_select('tool_ltidiagnostics_audit', 'action', '1=1');
        $this->assertContains('recent', $actions);
        $this->assertNotContains('ancient', $actions);
        $this->assertFalse(diagnostic_mode::is_active());
    }

    /**
     * The other events of the plugin have names and are not writeable.
     */
    public function test_events(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $viewed = \tool_ltidiagnostics\event\report_viewed::create([
            'context' => \context_system::instance(), 'other' => ['key' => 'platform:1'],
        ]);
        $exported = \tool_ltidiagnostics\event\report_exported::create([
            'context' => \context_system::instance(), 'other' => ['key' => 'platform:1', 'format' => 'json'],
        ]);
        foreach ([$viewed, $exported] as $event) {
            $this->assertNotSame('', $event->get_name());
            $this->assertNotSame('', $event->get_description());
            $this->assertSame(\core\event\base::LEVEL_OTHER, $event->edulevel);
            $this->assertInstanceOf(\moodle_url::class, $event->get_url());
        }
    }
}
