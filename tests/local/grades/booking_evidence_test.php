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

use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\tests\fake_ags_transport;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/lti/tests/local/ltiadvantage/lti_advantage_testcase.php');
require_once(__DIR__ . '/../../fixtures/grade_scenario.php');
require_once(__DIR__ . '/../../fixtures/fake_ags_transport.php');

/**
 * Booking evidence at the consumer (issue #6): AGS results comparison and the signed partner receipt.
 *
 * The receipt round trip runs on one site that is tool and platform at the same time: the registration's issuer is
 * this site, the line item URL points at a real grade item of this site, and the receipt is signed with this site's
 * real mod_lti platform key and verified against its real key set.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\grades\results_checker
 * @covers     \tool_ltidiagnostics\local\grades\receipt
 */
final class booking_evidence_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /**
     * Results comparison: the ratio decides (Moodle rescales to its own maximum), each mismatch is named.
     */
    public function test_results_compare(): void {
        $li = 'https://p.example.org/mod/lti/services.php/2/lineitems/4/lineitem?type_id=1';
        $base = ['userId' => 's1', 'resultScore' => 32.5, 'resultMaximum' => 50, 'timestamp' => gmdate('c', 1000),
            'scoreOf' => 'https://p.example.org/mod/lti/services.php/2/lineitems/4/lineitem?type_id=1'];
        $ok = results_checker::compare([$base], 's1', $li, 65.0, 100.0, 1000);
        $this->assertSame('results_match', $ok['verdict'], 'Moodle answers scoreOf with the query of the line item URL.');
        $plain = ['scoreOf' => 'https://p.example.org/mod/lti/services.php/2/lineitems/4/lineitem/'] + $base;
        $this->assertSame('results_match', results_checker::compare([$plain], 's1', $li, 65.0, 100.0, 1000)['verdict']);
        $this->assertSame(['user'], results_checker::compare([$base], 's2', $li, 65.0, 100.0, 1000)['mismatches']);
        $this->assertSame(['score'], results_checker::compare(
            [['resultScore' => 30] + $base],
            's1',
            $li,
            65.0,
            100.0,
            1000
        )['mismatches']);
        $this->assertSame(['timestamp_older'], results_checker::compare([$base], 's1', $li, 65.0, 100.0, 2000)['mismatches']);
        $this->assertSame(['timestamp_newer'], results_checker::compare([$base], 's1', $li, 65.0, 100.0, 500)['mismatches']);
        $this->assertSame(['lineitem'], results_checker::compare(
            [['scoreOf' => 'https://p.example.org/mod/lti/services.php/2/lineitems/5/lineitem?type_id=1'] + $base],
            's1',
            $li,
            65.0,
            100.0,
            1000
        )['mismatches']);
        $this->assertSame(['score'], results_checker::compare(
            [['resultScore' => null] + $base],
            's1',
            $li,
            0.0,
            100.0,
            1000
        )['mismatches'], 'A missing result is not 0.');
        $zero = results_checker::compare([['resultScore' => 0] + $base], 's1', $li, 0.0, 100.0, 1000);
        $this->assertSame('results_match', $zero['verdict']);
    }

    /**
     * Results check after a replay: without the result scope the booking stays unobservable and nothing is read.
     */
    public function test_results_need_the_granted_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        $t = new fake_ags_transport();
        $r = (new results_checker($t))->check_link(
            (new inventory())->get_connection($s->keyb),
            $s->linkb1,
            (int) $s->users->l1->id
        );
        $this->assertSame('scope_missing', $r['errorclass']);
        $this->assertSame([], $t->calls);
        // Scope in the claim, but the token answer does not contain it: unobservable, no GET.
        $t = new fake_ags_transport();
        $t->tokens[] = ['status' => 200, 'errorclass' => '', 'token' => 'x', 'scopes' => ['other']];
        $r = (new results_checker($t))->check_link(
            (new inventory())->get_connection($s->keya),
            $s->linka,
            (int) $s->users->l1->id
        );
        $this->assertSame('scope_missing', $r['errorclass']);
        $this->assertSame([], $t->of('get'));
        // A core send (task log) gets its booking evidence from the check: here the consumer shows another value.
        $t = new fake_ags_transport();
        $t->gets[] = ['status' => 200, 'errorclass' => '', 'json' => [['userId' => 'a-l1', 'resultScore' => 10,
            'resultMaximum' => 100]]];
        (new tasklog_importer())->import();
        $r = (new results_checker($t))->check_link(
            (new inventory())->get_connection($s->keya),
            $s->linka,
            (int) $s->users->l1->id
        );
        $this->assertSame('results_mismatch', $r['verdict']);
        $this->assertTrue($r['recorded']);
        $this->assertSame('results_mismatch', journal::latest(
            $s->keya,
            [$s->linka],
            [(int) $s->users->l1->id],
            '',
            true
        )[$s->linka . ':' . (int) $s->users->l1->id]->bookingstate);
    }

    /**
     * Full receipt round trip on one site acting as tool and platform.
     */
    public function test_receipt_round_trip(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Consumer side: a course with a grade item (maximum 50) and the learner.
        $gen = $this->getDataGenerator();
        $ccourse = $gen->create_course();
        $item = $gen->create_grade_item(['courseid' => $ccourse->id, 'grademax' => 50, 'itemname' => 'LTI item']);
        $learner = $gen->create_user();
        $gen->enrol_user($learner->id, $ccourse->id, 'student');
        $lineitem = $CFG->wwwroot . '/mod/lti/services.php/' . $ccourse->id . '/lineitems/' . $item->id .
            '/lineitem?type_id=1';
        // Tool side: this site registered as platform; the learner launches with the platform user id as subject.
        $this->issuer = $CFG->wwwroot;
        [, $tool, , , $reg, $dep] = $this->create_test_environment();
        $this->learner_launch(
            $tool,
            $learner,
            $CFG->wwwroot,
            '123',
            (string) $learner->id,
            'rl-self',
            ['scope' => ['https://purl.imsglobal.org/spec/lti-ags/scope/score'], 'lineitem' => $lineitem]
        );
        $this->set_user_grade_for_resource_local((int) $learner->id, 65, $tool);
        $conn = (new inventory())->get_connection('tool:' . $reg->get_id() . ':' . $dep->get_id());
        $link = (int) $DB->get_field('enrol_lti_resource_link', 'id', ['resourcelinkid' => 'rl-self']);
        set_config('graderepair', 1, 'tool_ltidiagnostics');
        repair_service::set_opt_in($conn->key, true);
        $now = time();
        $svc = new repair_service(new fake_ags_transport(), null, fn() => $now);
        $r = $svc->request(
            $conn,
            $link,
            (int) $learner->id,
            $svc->preview($conn, $link, (int) $learner->id)['fingerprint'],
            'receipt test',
            2
        );
        $this->assertSame('http_accepted', $svc->process($r['attempt']->attemptid));
        $row = journal::get($r['attempt']->attemptid);

        // The consumer booked it (Moodle stores the score timestamp as time modified, rescaled to 50).
        $grade = new \grade_grade(['itemid' => $item->id, 'userid' => $learner->id, 'finalgrade' => 32.5,
            'rawgrade' => 32.5, 'timemodified' => $now], false);
        $grade->insert();
        $DB->set_field('grade_grades', 'timemodified', $now, ['id' => $grade->id]);

        $req = receipt::request($conn, $row, $now);
        $this->assertTrue($req['ok']);
        $json = json_encode($req['document']);
        $this->assertStringNotContainsString((string) $learner->id . '"', $json, 'No user id in the request.');
        $answer = receipt::answer($json, $now);
        $this->assertTrue($answer['ok'], $answer['reason']);
        $this->assertTrue($answer['payload']['found']['user']);
        $this->assertStringNotContainsString('"userid"', json_encode($answer['payload']));
        $keyset = \mod_lti\local\ltiopenid\jwks_helper::get_jwks();
        $doc = json_encode($answer['document']);
        $imported = receipt::import($conn, $doc, $now, $keyset);
        $this->assertSame('receipt_confirmed', $imported['verdict'], implode(',', $imported['mismatches']));
        $this->assertSame('receipt_confirmed', journal::get($row->attemptid)->bookingstate);
        $this->assertSame('already_imported', receipt::import($conn, $doc, $now, $keyset)['reason']);
        // A receipt from a consumer whose clock is far ahead is refused as clock skew, not as a booking.
        $ahead = receipt::answer($json, $now + 3600);
        $this->assertSame('clock_skew', receipt::import($conn, json_encode($ahead['document']), $now, $keyset)['reason']);

        // Mismatches: older state at the consumer, overridden, other value.
        foreach ([['timemodified' => $now - 5], ['overridden' => $now], ['finalgrade' => 20]] as $i => $change) {
            foreach ($change as $f => $v) {
                $DB->set_field('grade_grades', $f, $v, ['id' => $grade->id]);
            }
            $imp = receipt::import(
                $conn,
                json_encode(receipt::answer($json, $now + $i + 1)['document']),
                $now + $i + 1,
                $keyset
            );
            $this->assertSame('receipt_mismatch', $imp['verdict'], $imp['reason']);
        }
        $this->assertContains('score', $imp['mismatches']);
        $this->assertContains('overridden', $imp['mismatches']);

        // Tampered signature, receipt for other values, a foreign platform, a key set that did not sign.
        $parts = explode('.', $answer['document']['jwt']);
        $parts[1] = rtrim(strtr(base64_encode(json_encode(['attemptid' => $row->attemptid] + $answer['payload'] +
            ['found' => ['lineitem' => true, 'user' => true]])), '+/', '-_'), '=');
        $this->assertSame('signature', receipt::import($conn, json_encode(['schema' => receipt::SCHEMA_RECEIPT,
            'jwt' => implode('.', $parts)]), $now, $keyset)['reason']);
        $other = $req['document'];
        $other['score']['given'] = 99.0;
        $other['requesthash'] = receipt::request_hash($other);
        $this->assertSame('request_mismatch', receipt::import($conn, json_encode(receipt::answer(
            json_encode($other),
            $now
        )['document']), $now, $keyset)['reason']);
        $foreign = $req['document'];
        $foreign['issuerhash'] = hash('sha256', 'https://elsewhere.example.org');
        $foreign['requesthash'] = receipt::request_hash($foreign);
        $this->assertSame('not_this_platform', receipt::answer(json_encode($foreign), $now)['reason']);
        $this->assertSame('invalid_request', receipt::answer('{"schema":"x"}', $now)['reason']);
        $otherkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($otherkey);
        $wrongset = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $keyset['keys'][0]['kid'],
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=')]]];
        $this->assertSame('signature', receipt::import($conn, $doc, $now, $wrongset)['reason']);

        // A learner the consumer does not know.
        $stranger = $req['document'];
        $stranger['subjecthash'] = journal::subject_hash($CFG->wwwroot, '999999');
        $stranger['requesthash'] = receipt::request_hash($stranger);
        $this->assertFalse(receipt::answer(json_encode($stranger), $now)['payload']['found']['user']);
    }
}
