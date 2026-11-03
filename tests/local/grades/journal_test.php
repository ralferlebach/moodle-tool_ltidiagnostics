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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/lti/tests/local/ltiadvantage/lti_advantage_testcase.php');
require_once(__DIR__ . '/../../fixtures/grade_scenario.php');

/**
 * Grade attempt journal (issue #4): passive import of core's task log, classification, idempotency, paging, retention.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\grades\journal
 * @covers     \tool_ltidiagnostics\local\grades\tasklog_parser
 * @covers     \tool_ltidiagnostics\local\grades\tasklog_importer
 * @covers     \tool_ltidiagnostics\local\grades\error_classes
 */
final class journal_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /**
     * The parser understands the real output of core's task (produced here by core itself) and keeps no message text.
     */
    public function test_parser_reads_real_core_output(): void {
        $this->resetAfterTest();
        [$course, $tool] = $this->create_test_environment();
        $u = $this->getDataGenerator()->create_user();
        $this->learner_launch($tool, $u, self::$issuera, '123', 'p-1', 'rl-p', self::ags_claim(self::$issuera, ['score']));
        $this->set_user_grade_for_resource_local((int) $u->id, 80, $tool);
        $ok = tasklog_parser::parse($this->run_core_grade_sync($tool, 200, time()));
        $this->assertSame((int) $tool->id, $ok['resource']);
        $this->assertSame((int) $course->id, $ok['course']);
        $this->assertCount(1, $ok['events']);
        $this->assertSame('sent_ok', $ok['events'][0]['outcome']);
        $this->assertSame('rl-p', $ok['events'][0]['resourcelink']);
        $this->assertSame((int) $u->id, $ok['events'][0]['user']);
        $this->assertSame('0.8', $ok['events'][0]['grade']);
        $this->assertSame(0, $ok['unknown']);

        $this->set_user_grade_for_resource_local((int) $u->id, 90, $tool);
        $failed = tasklog_parser::parse($this->run_core_grade_sync($tool, 409, time()));
        $this->assertSame('send_failed', $failed['events'][0]['outcome']);
        $this->assertSame(409, $failed['events'][0]['httpstatus']);
        $this->assertSame('http_409', $failed['events'][0]['errorclass']);
        $same = tasklog_parser::parse($this->run_core_grade_sync($tool, 200, time()));
        $this->assertSame('sent_ok', $same['events'][0]['outcome'], 'After the 409 lastgrade was not updated: core sends again.');
        $unchanged = tasklog_parser::parse($this->run_core_grade_sync($tool, 200, time()));
        $this->assertSame('skipped_unchanged', $unchanged['events'][0]['outcome']);
    }

    /**
     * Synthetic lines: every outcome, an exception line that is classified but never kept, unknown lines counted.
     */
    public function test_parser_outcomes_and_exception_lines(): void {
        $subject = "for the user '5', for the resource '3' and the course '2'";
        $out = implode("\n", [
            "Starting - LTI Advantage grade sync for shared resource '3' in course '2'.",
            "Found 1 resource link(s) $subject. Attempting to sync grades for all.",
            "Processing resource link 'abc'.",
            "Skipping - No grade service found $subject.",
            "Skipping - Course not completed $subject.",
            "Skipping - Activity not completed $subject.",
            "Skipping - Invalid grade $subject.",
            "Skipping - Invalid grademax $subject.",
            "Not sent - The grade $subject was not sent as the grades are the same.",
            "Failed - The grade '0.5' $subject failed to send.",
            'cURL error 28: Operation timed out after 5000 milliseconds with secret token=abc',
            "Failed - Invalid contextid '99' for the resource '3'.",
            'Skipping - Something new in a future Moodle.',
        ]);
        $p = tasklog_parser::parse($out);
        $this->assertSame(['no_service', 'gated_course', 'gated_activity', 'invalid_grade', 'invalid_grademax',
            'skipped_unchanged', 'send_failed', 'invalid_context'], array_column($p['events'], 'outcome'));
        $this->assertSame('timeout', $p['events'][6]['errorclass']);
        $this->assertSame(1, $p['unknown']);
        $this->assertStringNotContainsString('secret', json_encode($p));
        $this->assertSame('http_5xx', error_classes::from_http(503));
        $this->assertSame('redirect_refused', error_classes::from_http(302));
        $this->assertSame('tls', error_classes::from_curl(60));
        $this->assertSame('dns', error_classes::from_curl(6));
        $this->assertTrue(error_classes::retryable('http_429'));
        $this->assertFalse(error_classes::retryable('http_409'), '409 is never retried automatically.');
        $this->assertFalse(error_classes::retryable('http_401'));
        $this->assertSame('token', error_classes::from_message('invalid_client: client assertion rejected'));
    }

    /**
     * The importer attributes outcomes to exactly one link, is idempotent, keeps a cursor and is bounded per call.
     */
    public function test_importer_attribution_idempotency_and_bounds(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $first = (new tasklog_importer())->import();
        $this->assertSame(2, $first['runs']);
        $rows = $DB->get_records(journal::TABLE, ['source' => 'task_log'], 'id');
        $this->assertCount($first['rows'], $rows);
        $l1 = (int) $s->users->l1->id;
        $sent = $DB->get_records(journal::TABLE, ['source' => 'task_log', 'userid' => $l1, 'state' => 'http_accepted']);
        $this->assertCount(1, $sent);
        $sent = reset($sent);
        $this->assertSame($s->linka, (int) $sent->resourcelinkrowid);
        $this->assertSame($s->keya, $sent->connkey);
        $this->assertSame('', $sent->subjecthash, 'Core prints no subject; the journal does not invent one.');
        $this->assertSame('', $sent->lineitemhash);
        $this->assertSame('unknown', $sent->bookingstate);
        $this->assertEqualsWithDelta(0.65, (float) $sent->scoregiven, 0.00001);
        $skips = $DB->get_records(journal::TABLE, ['userid' => $l1, 'skipreason' => 'lastgrade_unchanged']);
        $this->assertEqualsCanonicalizing([$s->linka, $s->linkb1], array_map('intval', array_column(
            $skips,
            'resourcelinkrowid'
        )));
        $failed = $DB->get_record(journal::TABLE, ['userid' => (int) $s->users->l3->id, 'state' => 'http_rejected']);
        $this->assertSame(401, (int) $failed->httpstatus);
        $this->assertSame('http_401', $failed->errorclass);
        $this->assertSame($s->keyb, $failed->connkey);

        // Idempotent: no new rows; the cursor moved.
        $again = (new tasklog_importer())->import();
        $this->assertSame(0, $again['runs']);
        $this->assertCount(count($rows), $DB->get_records(journal::TABLE, ['source' => 'task_log']));
        set_config(tasklog_importer::CURSOR, 0, 'tool_ltidiagnostics');
        (new tasklog_importer())->import();
        $this->assertCount(
            count($rows),
            $DB->get_records(journal::TABLE, ['source' => 'task_log']),
            'A second import of the same lines writes nothing.'
        );

        // Bounded: one run per call when asked.
        $DB->delete_records(journal::TABLE);
        set_config(tasklog_importer::CURSOR, 0, 'tool_ltidiagnostics');
        $this->assertSame(1, (new tasklog_importer())->import(1)['runs']);
        $this->assertSame(1, (new tasklog_importer())->import(1)['runs']);
        $this->assertSame(0, (new tasklog_importer())->import(1)['runs']);
    }

    /**
     * Ambiguous link ids (same id at two consumers for the same learner) are not attributed.
     */
    public function test_ambiguous_link_is_not_attributed(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        // Make the BUW link carry the same platform id as A's link.
        $DB->set_field('enrol_lti_resource_link', 'resourcelinkid', 'rl-a-1', ['id' => $s->linkb1]);
        $DB->delete_records(journal::TABLE);
        set_config(tasklog_importer::CURSOR, 0, 'tool_ltidiagnostics');
        (new tasklog_importer())->import();
        $l1rows = $DB->get_records(journal::TABLE, ['userid' => (int) $s->users->l1->id]);
        foreach ($l1rows as $r) {
            $this->assertSame(0, (int) $r->resourcelinkrowid);
            $this->assertSame('', $r->connkey);
        }
    }

    /**
     * Keyset paging, latest rows per link and learner, counts, retention without open attempts, purpose redaction.
     */
    public function test_paging_latest_counts_and_retention(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        for ($i = 1; $i <= 250; $i++) {
            journal::insert(['connkey' => 'tool:1:1', 'resourcelinkrowid' => 1 + ($i % 2), 'userid' => 10 + ($i % 5),
                'state' => $i % 3 ? 'http_accepted' : 'skipped', 'timecreated' => $now - 1000 + $i]);
        }
        $page1 = journal::page('tool:1:1', ['limit' => 100]);
        $this->assertCount(100, $page1['rows']);
        $page2 = journal::page('tool:1:1', ['limit' => 100, 'before' => $page1['next']]);
        $page3 = journal::page('tool:1:1', ['limit' => 100, 'before' => $page2['next']]);
        $this->assertCount(50, $page3['rows']);
        $this->assertSame(0, $page3['next']);
        $ids = array_merge(
            array_column($page1['rows'], 'id'),
            array_column($page2['rows'], 'id'),
            array_column($page3['rows'], 'id')
        );
        $this->assertCount(250, array_unique($ids));
        $this->assertSame(500, journal::MAXPAGE);
        $all = journal::page('tool:1:1', ['limit' => 5000]);
        $this->assertCount(250, $all['rows'], 'A limit above the maximum page is cut to 500, which holds all 250 rows.');
        $this->assertSame(250, $all['total']);

        $latest = journal::latest('tool:1:1', [1, 2], [10, 11, 12, 13, 14]);
        $this->assertCount(10, $latest);
        $transfers = journal::latest('tool:1:1', [1, 2], [10, 11, 12, 13, 14], '', true);
        foreach ($transfers as $r) {
            $this->assertNotSame('skipped', $r->state);
        }
        $counts = journal::counts('tool:1:1');
        $this->assertSame(250, array_sum($counts['instrumented']));

        $open = journal::insert(['connkey' => 'tool:1:1', 'state' => 'queued', 'timecreated' => $now - 400 * DAYSECS,
            'purpose' => 'see https://x.example.org/?token=abc']);
        $this->assertStringNotContainsString('abc', $DB->get_field(journal::TABLE, 'purpose', ['id' => $open->id]));
        journal::insert(['connkey' => 'tool:1:1', 'state' => 'dead', 'timecreated' => $now - 400 * DAYSECS]);
        $this->assertSame(1, journal::purge($now - 365 * DAYSECS));
        $this->assertTrue($DB->record_exists(journal::TABLE, ['id' => $open->id]), 'Open attempts survive the retention.');
    }

    /**
     * Journal filters run on the server and the total matches the rows (issue #23): origin, transfer class, attribution,
     * period, scope (tool ids, empty scope = nothing), person, link and batch.
     */
    public function test_server_side_filters(): void {
        $this->resetAfterTest();
        $base = ['connkey' => 'tool:1:1', 'timecreated' => 1000];
        journal::insert(['origin' => journal::ORIGIN_CORE, 'source' => 'task_log', 'state' => 'skipped',
            'resourcelinkrowid' => 0, 'userid' => 5, 'toolid' => 1] + $base);
        journal::insert(['origin' => journal::ORIGIN_REPLAY, 'state' => 'http_accepted', 'resourcelinkrowid' => 7,
            'userid' => 5, 'toolid' => 1, 'batchid' => 'b-1', 'timecreated' => 2000] + $base);
        journal::insert(['origin' => journal::ORIGIN_RETRY, 'state' => 'http_rejected', 'httpstatus' => 409,
            'resourcelinkrowid' => 7, 'userid' => 6, 'toolid' => 2, 'batchid' => 'b-1', 'timecreated' => 3000] + $base);
        journal::insert(['connkey' => 'tool:9:9', 'state' => 'http_accepted', 'resourcelinkrowid' => 7, 'userid' => 5]);
        $count = function (array $f): int {
            $page = journal::page('tool:1:1', $f + ['limit' => 50]);
            $this->assertCount($page['total'], $page['rows'], json_encode($f));
            return $page['total'];
        };
        $this->assertSame(3, $count([]), 'Never another connection.');
        $this->assertSame(1, $count(['origin' => 'core']));
        $this->assertSame(2, $count(['origin' => 'replay']), 'Retries belong to the replays.');
        $this->assertSame(1, $count(['transfer' => 'skipped']));
        $this->assertSame(1, $count(['transfer' => 'accepted']));
        $this->assertSame(1, $count(['transfer' => 'failed']));
        $this->assertSame(0, $count(['transfer' => 'pending']));
        $this->assertSame(1, $count(['attribution' => 'unattributed']), 'Core log rows without mapping stay apart.');
        $this->assertSame(2, $count(['attribution' => 'attributed']));
        $this->assertSame(2, $count(['from' => 1500]));
        $this->assertSame(1, $count(['to' => 1500]));
        $this->assertSame(2, $count(['toolids' => [1]]));
        $this->assertSame(0, $count(['toolids' => []]), 'An empty scope shows nothing, not everything.');
        $this->assertSame(2, $count(['userid' => 5]));
        $this->assertSame(1, $count(['resourcelinkrowid' => 7, 'userid' => 5]));
        $this->assertSame(2, $count(['batchid' => 'b-1']));
        $this->assertSame(3, $count(['transfer' => 'nonsense', 'origin' => 'nonsense']), 'Unknown values do not filter.');
    }
}
