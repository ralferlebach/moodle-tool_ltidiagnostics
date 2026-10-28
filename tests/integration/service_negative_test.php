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

namespace tool_ltidiagnostics\integration;

use tool_ltidiagnostics\local\accesslog\importer;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\desired_profile;
use tool_ltidiagnostics\local\finding;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\partner_package;
use tool_ltidiagnostics\local\partner_service;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/lti_data.php');

/**
 * Reproducible negative integration tests of the configuration, OAuth, background and gradebook layers (audit item
 * DIA-03), each with a positive control: wrong iss/aud, missing registration, stale static key, missing AGS scope,
 * cron outage and gradebook booking. Real core data (mod_lti types, enrol_lti registrations, access tokens issued by
 * mod_lti, task log rows, imported web server lines, gradebook writes from the service script).
 *
 * The platform and the tool live on one test site; their partner packages are exchanged exactly as between two sites.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\partner_package
 * @covers     \tool_ltidiagnostics\local\partner_service
 * @covers     \tool_ltidiagnostics\local\health_check
 * @covers     \tool_ltidiagnostics\local\message_matrix
 */
final class service_negative_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use lti_data;

    /** @var string|null */
    private $script;

    /**
     * Remember the script name.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->start_deprecation_guard();
        $this->script = $_SERVER['SCRIPT_NAME'] ?? null;
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Restore the script name.
     */
    protected function tearDown(): void {
        if ($this->script === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $this->script;
        }
        parent::tearDown();
        $this->check_deprecation_guard();
    }

    /**
     * A platform type and a tool registration that describe the same connection correctly (positive control).
     *
     * @param array $typeoverride Overrides of the type.
     * @param array $regoverride Overrides of the registration.
     * @return connection[] [platform, tool]
     */
    private function pair(array $typeoverride = [], array $regoverride = []): array {
        global $CFG, $DB;
        $typeid = $this->create_platform_type($typeoverride + [
            'lti_publickeyset' => $CFG->wwwroot . '/enrol/lti/jwks.php',
            'lti_initiatelogin' => $CFG->wwwroot . '/enrol/lti/login.php?id=placeholder',
            'baseurl' => $CFG->wwwroot . '/enrol/lti/launch.php',
        ]);
        $clientid = (string) $DB->get_field('lti_types', 'clientid', ['id' => $typeid]);
        $reg = $this->create_registration($regoverride + [
            'platformid' => $CFG->wwwroot,
            'clientid' => $clientid,
            'authrequesturl' => $CFG->wwwroot . '/mod/lti/auth.php',
            'jwksurl' => $CFG->wwwroot . '/mod/lti/certs.php',
            'accesstokenurl' => $CFG->wwwroot . '/mod/lti/token.php',
            'deploymentid' => (string) $typeid,
        ]);
        $uniqueid = (string) $DB->get_field('enrol_lti_app_registration', 'uniqueid', ['id' => $reg->regid]);
        if (!array_key_exists('lti_initiatelogin', $typeoverride)) {
            $login = $CFG->wwwroot . '/enrol/lti/login.php?id=' . $uniqueid;
            $DB->set_field('lti_types_config', 'value', $login, ['typeid' => $typeid, 'name' => 'initiatelogin']);
        }
        $inv = new inventory();
        return [$inv->get_connection(connection::make_key(connection::ROLE_PLATFORM, $typeid)),
            $inv->get_connection(connection::make_key(connection::ROLE_TOOL, $reg->regid, $reg->depid))];
    }

    /**
     * Compare the platform with the package of the tool and the tool with the package of the platform.
     *
     * @param connection $platform Platform.
     * @param connection $tool Tool.
     * @return array [codes on the platform, codes on the tool, findings of the platform]
     */
    private function exchange(connection $platform, connection $tool): array {
        $now = time();
        $fromtool = partner_package::parse(json_encode(partner_package::export($tool, [], $now)));
        $fromplatform = partner_package::parse(json_encode(partner_package::export($platform, [], $now)));
        $a = partner_package::compare($platform, $fromtool, $now);
        $b = partner_package::compare($tool, $fromplatform, $now);
        $codes = function (array $r): array {
            $out = array_map(function (finding $f) {
                return $f->code;
            }, $r['findings']);
            sort($out);
            return $out;
        };
        foreach (array_merge($a['findings'], $b['findings']) as $f) {
            $this->assert_diagnosis($f);
        }
        return [$codes($a), $codes($b), $a['findings']];
    }

    /**
     * The four parts of a diagnosis are present and resolved.
     *
     * @param finding $f Finding.
     */
    private function assert_diagnosis(finding $f): void {
        $d = $f->diagnosis();
        foreach (['cause', 'detail', 'uncertainty', 'check', 'fix'] as $part) {
            $this->assertNotSame('', trim($d[$part]), "$part of {$f->code}");
            $this->assertStringNotContainsString('[[', $d[$part], "$part of {$f->code} is a missing string");
            $this->assertStringNotContainsString('{$a', $d[$part], "$part of {$f->code} has an unresolved placeholder");
        }
    }

    /**
     * Profile.
     *
     * @param array $data Data.
     * @return desired_profile
     */
    private function profile(array $data): desired_profile {
        [$profile, $errors] = desired_profile::from_array($data + ['schema' => 1, 'name' => 'Negative']);
        $this->assertSame([], $errors);
        return $profile;
    }

    /**
     * Codes of a health result.
     *
     * @param array $result Result.
     * @return string[]
     */
    private function codes(array $result): array {
        return array_map(function (finding $f) {
            return $f->code;
        }, $result['findings']);
    }

    /**
     * The finding with a code.
     *
     * @param array $result Result.
     * @param string $code Code.
     * @return finding
     */
    private function finding(array $result, string $code): finding {
        foreach ($result['findings'] as $f) {
            if ($f->code === $code) {
                $this->assert_diagnosis($f);
                return $f;
            }
        }
        $this->fail("Finding $code expected, got " . implode(', ', $this->codes($result)));
    }

    /**
     * Positive control: two correctly configured sides agree, in both directions; the package carries no secret.
     */
    public function test_matching_pair_agrees(): void {
        [$platform, $tool] = $this->pair();
        [$a, $b] = $this->exchange($platform, $tool);
        $this->assertSame(['partner_ok'], $a);
        $this->assertSame(['partner_ok'], $b);
        $json = json_encode(partner_package::export($tool, [], time()));
        $this->assertStringNotContainsString('PRIVATE KEY', $json);
        $this->assertStringNotContainsString($platform->clientid, $json, 'Identifiers travel as hashes and masked forms.');
    }

    /**
     * Wrong iss and wrong aud: the tool registered another issuer and another client id. Both sides name both values
     * as a mismatch whose responsible system is "one of the two sites".
     */
    public function test_wrong_iss_and_aud(): void {
        global $CFG;
        [$platform, $tool] = $this->pair([], ['platformid' => $CFG->wwwroot . '/other', 'clientid' => 'not-the-client']);
        [$a, $b, $findings] = $this->exchange($platform, $tool);
        $this->assertSame(['partner_aud_mismatch', 'partner_iss_mismatch'], $a);
        $this->assertSame($a, $b);
        foreach ($findings as $f) {
            $this->assertSame(finding::SYS_BOTH, $f->system);
            $this->assertSame(finding::CERTAIN, $f->certainty);
            $this->assertSame(finding::BLOCKING, $f->severity);
        }
    }

    /**
     * Missing registration: the tool has no deployment for the platform; and an incomplete (draft) registration.
     */
    public function test_missing_registration(): void {
        global $DB;
        [$platform, $tool] = $this->pair();
        $DB->delete_records('enrol_lti_deployment', ['id' => $tool->deploymentrowid]);
        $tool = (new inventory())->get_connection(connection::make_key(connection::ROLE_TOOL, $tool->localid, 0));
        $this->assertNotNull($tool);
        [$a] = $this->exchange($platform, $tool);
        $this->assertContains('partner_registration_missing', $a);
        $result = (new health_check())->run($tool, time());
        $this->assertContains('cfg_nodeployment', $this->codes($result));

        $DB->set_field('enrol_lti_app_registration', 'status', 0, ['id' => $tool->localid]);
        $draft = (new inventory())->get_connection($tool->key);
        $this->finding((new health_check())->run($draft, time()), 'cfg_registration_draft');
        [$a2] = $this->exchange($platform, $draft);
        $this->assertContains('partner_registration_incomplete', $a2);
    }

    /**
     * Stale key: the platform holds a static public key of the tool, but the tool now signs with another key.
     */
    public function test_stale_static_key(): void {
        $old = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048]))['key'];
        [$platform, $tool] = $this->pair(['lti_keytype' => LTI_RSA_KEY, 'lti_publickey' => $old]);
        [$a, , $findings] = $this->exchange($platform, $tool);
        $this->assertContains('partner_key_stale', $a);
        foreach ($findings as $f) {
            if ($f->code === 'partner_key_stale') {
                $this->assertSame(finding::SYS_LOCAL, $f->system, 'The platform holds the stale key.');
            }
        }
        // Positive control: the current public key of the tool.
        $current = openssl_pkey_get_details(openssl_pkey_get_private((string) get_config('enrol_lti', 'lti_13_privatekey')))['key'];
        [$platform2, $tool2] = $this->pair(['lti_keytype' => LTI_RSA_KEY, 'lti_publickey' => $current]);
        [$a2] = $this->exchange($platform2, $tool2);
        $this->assertNotContains('partner_key_stale', $a2);
    }

    /**
     * Missing AGS scope: mod_lti issued a real token without the score scope although grade sync is fully enabled. The
     * finding names the partner and differs from a network failure; the message matrix shows the permission as failing.
     */
    public function test_missing_ags_scope(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        [$platform] = $this->pair();
        lti_new_access_token($platform->localid, ['https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly']);
        $result = (new health_check())->run($platform, time());
        $f = $this->finding($result, 'oauth_scope_not_granted');
        $this->assertSame(finding::L_OAUTH, $f->layer);
        $this->assertSame(finding::SYS_PARTNER, $f->system);
        $this->assertSame(finding::CERTAIN, $f->certainty);
        $this->assertSame('failed', $result['messages']['service_permissions']['state']);
        $this->assertSame(['score'], $result['messages']['service_permissions']['missingscopes']);
        // Positive control: a token with the score scope.
        lti_new_access_token($platform->localid, ['https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly']);
        $after = (new health_check())->run($platform, time());
        $this->assertNotContains('oauth_scope_not_granted', $this->codes($after));
        $this->assertSame('verified', $after['messages']['service_permissions']['state']);
    }

    /**
     * Task and cron outage: the last cron start is three hours old and the task log has a gap of five hours yesterday.
     */
    public function test_cron_outage(): void {
        global $DB;
        [$platform] = $this->pair();
        $now = time();
        set_config('lastcronstart', $now - 3 * HOURSECS, 'tool_task');
        $runs = [];
        $starts = [$now - 20 * HOURSECS, $now - 19 * HOURSECS, $now - 14 * HOURSECS, $now - 13 * HOURSECS,
            $now - 3 * HOURSECS - 60];
        foreach ($starts as $start) {
            $runs[] = (object) ['type' => 0, 'component' => 'core', 'classname' => '\core\task\session_cleanup_task',
                'userid' => 0, 'timestart' => $start, 'timeend' => $start + 2, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 0,
                'output' => '', 'hostname' => 'h', 'pid' => 1];
        }
        $DB->insert_records('task_log', $runs);
        $result = (new health_check())->run($platform, $now);
        $stale = $this->finding($result, 'bg_cron_stale');
        $this->assertSame(finding::BLOCKING, $stale->severity);
        $this->assertSame(finding::SYS_LOCAL, $stale->system);
        $gap = $this->finding($result, 'bg_cron_gap');
        $this->assertSame(finding::LIKELY, $gap->certainty);
        // Positive control: cron ran a minute ago and every hour.
        set_config('lastcronstart', $now - 60, 'tool_task');
        $DB->delete_records('task_log');
        $fresh = [];
        for ($t = $now - DAYSECS + 60; $t < $now; $t += 1800) {
            $fresh[] = (object) ['type' => 0, 'component' => 'core', 'classname' => '\core\task\session_cleanup_task',
                'userid' => 0, 'timestart' => $t, 'timeend' => $t + 1, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 0,
                'output' => '', 'hostname' => 'h', 'pid' => 1];
        }
        $DB->insert_records('task_log', $fresh);
        $ok = (new health_check())->run($platform, $now);
        $this->assertNotContains('bg_cron_stale', $this->codes($ok));
        $this->assertNotContains('bg_cron_gap', $this->codes($ok));
    }

    /**
     * Gradebook booking: an imported web server log shows a successful score request (HTTP 200), but nothing was
     * written to the gradebook. Status 200 alone is "observed (transport only)", never verified; once the service script
     * writes the grade, the score is verified and booked.
     */
    public function test_gradebook_booking(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course);
        [$platform] = $this->pair();
        $profile = $this->profile(['wanted' => ['lti_core', 'ags'], 'expect' => ['gradebook_update' => true]]);
        $line = '203.0.113.9 - - [' . gmdate('d/M/Y:H:i:s', time() - 60) . ' +0000] '
            . '"POST /mod/lti/services.php/' . $course->id . '/lineitems/5/lineitem/scores HTTP/1.1" 200 5 "-" "UA"' . "\n";
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $line);
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['format' => 'combined', 'sourcename' => 'access.log']);
        fclose($fh);
        $this->assertSame(1, (int) $job->linesstored);

        $result = (new health_check())->run($platform, time(), ['profile' => $profile]);
        $f = $this->finding($result, 'core_grade_unconfirmed');
        $this->assertSame(finding::SYS_LOCAL, $f->system);
        $score = $result['messages']['ags_score'];
        $this->assertSame('observed', $score['state']);
        $this->assertTrue($score['transportonly']);
        $this->assertFalse($score['verified'], 'HTTP 200 never verifies.');
        $this->assertSame('no', $score['booked']);
        $this->assertNotSame('end_to_end', $result['chains']['grades']['verdict']);

        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'typeid' => $platform->localid]);
        $_SERVER['SCRIPT_NAME'] = '/mod/lti/services.php';
        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $user->id, 'rawgrade' => 80]);
        $after = (new health_check())->run($platform, time(), ['profile' => $profile]);
        $this->assertContains('core_grade_ok', $this->codes($after));
        $this->assertSame('booked', $after['messages']['ags_score']['state']);
        $this->assertTrue($after['messages']['ags_score']['verified']);
    }

    /**
     * Import and audit through the service: the stored package is used by the health check and the messages tab, and
     * the actions are audited; nothing in the LTI configuration changes.
     */
    public function test_import_through_service(): void {
        global $DB, $CFG;
        [$platform, $tool] = $this->pair([], ['clientid' => 'other-client']);
        $before = $DB->count_records('lti_types_config') . '/' . $DB->count_records('enrol_lti_app_registration');
        $package = partner_service::export_package($tool, time());
        $result = partner_service::import_package($platform, json_encode($package), time());
        $this->assertSame(['partner_aud_mismatch'], $result['mismatches']);
        $health = (new health_check())->run($platform, time());
        $this->assertContains('partner_aud_mismatch', $this->codes($health));
        $this->assertNotEmpty($health['partner']);
        $this->assertNotSame([], array_filter($health['fields'], function ($row) {
            return $row['partner'] !== '';
        }));
        $this->assertTrue($DB->record_exists('tool_ltidiagnostics_audit', ['action' => 'partner_export']));
        $this->assertTrue($DB->record_exists('tool_ltidiagnostics_audit', ['action' => 'partner_import']));
        $this->assertSame($before, $DB->count_records('lti_types_config') . '/' . $DB->count_records('enrol_lti_app_registration'));
        $this->assertNotEmpty($CFG->wwwroot);
        // A package of the same role is refused as a comparison.
        $same = partner_service::import_package($platform, json_encode(partner_service::export_package($platform, time())), time());
        $this->assertSame(['partner_role_conflict'], $same['mismatches']);
        // Malformed packages are refused.
        $this->expectException(\moodle_exception::class);
        partner_service::import_package($platform, '{"schema":"other"}', time());
    }
}
