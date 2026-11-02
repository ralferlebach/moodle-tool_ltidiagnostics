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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/lti/tests/local/ltiadvantage/lti_advantage_testcase.php');
require_once(__DIR__ . '/../fixtures/grade_scenario.php');

use tool_ltidiagnostics\local\grades\grade_diagnosis;
use tool_ltidiagnostics\local\grades\journal;
use tool_ltidiagnostics\local\grades\support_package;
use tool_ltidiagnostics\local\inventory;

/**
 * Grade channel under load (issue #9): 100 000 journal rows and 1 000 learner mappings of one consumer link. Every read
 * is bounded: keyset pages, grouped counts, one query set per page of the diagnosis independent of the number of
 * learners, the support package limited, retention in one statement.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\grades\journal
 * @covers     \tool_ltidiagnostics\local\grades\grade_diagnosis
 * @covers     \tool_ltidiagnostics\local\grades\support_package
 */
final class grades_large_data_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /** @var int Journal rows. */
    private const ROWS = 100000;

    /** @var int Additional learners mapped to one consumer link. */
    private const LEARNERS = 1000;

    /**
     * Reads and seconds of a call.
     *
     * @param callable $fn Function.
     * @return array [result, reads, seconds]
     */
    private function measure(callable $fn): array {
        global $DB;
        $reads = $DB->perf_get_reads();
        $start = microtime(true);
        $result = $fn();
        return [$result, $DB->perf_get_reads() - $reads, microtime(true) - $start];
    }

    /**
     * 100 000 journal rows and 1 000 mappings: bounded pages, constant diagnosis reads, bounded package, retention.
     */
    public function test_journal_and_diagnosis_stay_bounded(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $conn = (new inventory())->get_connection($s->keyb);

        $diagnose = function () use ($conn) {
            return (new grade_diagnosis())->run($conn, ['limit' => grade_diagnosis::MAXPAGE, 'importtasklog' => false]);
        };
        [, $smallreads] = $this->measure($diagnose);

        // 1 000 more learners linked to the BUW link (bulk rows: user, LTI user, user resource link, auth_lti link).
        $base = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}');
        $now = time();
        $users = [];
        for ($i = 1; $i <= self::LEARNERS; $i++) {
            $users[] = ['auth' => 'lti', 'confirmed' => 1, 'mnethostid' => 1, 'username' => 'ldl' . $i,
                'firstname' => 'L', 'lastname' => (string) $i, 'email' => 'ldl' . $i . '@example.com', 'timecreated' => $now];
        }
        $DB->insert_records('user', $users);
        $ids = $DB->get_fieldset_select('user', 'id', 'id > ? AND ' . $DB->sql_like('username', '?'), [$base, 'ldl%']);
        $this->assertCount(self::LEARNERS, $ids);
        $dep = (int) $DB->get_field('enrol_lti_resource_link', 'ltideploymentid', ['id' => $s->linkb1]);
        $ltiusers = [];
        foreach ($ids as $n => $uid) {
            $ltiusers[] = ['userid' => $uid, 'toolid' => $s->tool->id, 'ltideploymentid' => $dep, 'sourceid' => 'b-x' . $n,
                'lastgrade' => 0, 'timecreated' => $now];
        }
        $DB->insert_records('enrol_lti_users', $ltiusers);
        $links = [];
        foreach ($DB->get_records_select('enrol_lti_users', 'userid > ?', [$base], '', 'id, userid') as $lu) {
            $links[] = ['ltiuserid' => $lu->id, 'resourcelinkid' => $s->linkb1];
        }
        $DB->insert_records('enrol_lti_user_resource_link', $links);

        // 100 000 journal rows of this connection, most of them old, a few open.
        $rows = [];
        $states = ['http_accepted', 'skipped', 'http_rejected', 'dead', 'retry_wait'];
        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = ['attemptid' => sprintf('%08x-0000-4000-8000-%012x', $i, $i), 'correlationid' => sprintf('c%07d', $i),
                'parentid' => 0, 'attemptno' => 1, 'origin' => 'core_task', 'source' => 'task_log', 'sourcekey' => sha1('l' . $i),
                'connkey' => $s->keyb, 'deploymentrowid' => $dep, 'resourcelinkrowid' => $s->linkb1, 'toolid' => $s->tool->id,
                'userid' => $ids[$i % self::LEARNERS], 'subjecthash' => '', 'lineitemhash' => '', 'sourceversion' => '',
                'gradetime' => 0, 'agstimestamp' => 0, 'stateversion' => 0, 'state' => $states[$i % 5], 'skipreason' => '',
                'httpstatus' => 0, 'tokenstatus' => 0, 'errorclass' => '', 'retryat' => 0, 'evidence' => '',
                'bookingstate' => 'unknown', 'bookingdetail' => '', 'bookingcheckedat' => 0, 'operatorid' => 0, 'purpose' => '',
                'timecreated' => $now - 400 * DAYSECS + intdiv($i, 4), 'timemodified' => $now];
            if (count($rows) === 5000) {
                $DB->insert_records(journal::TABLE, $rows);
                $rows = [];
            }
        }
        if ($rows) {
            $DB->insert_records(journal::TABLE, $rows);
        }

        // Keyset pages: bounded size and reads, complete and without duplicates over three pages.
        [$p1, $reads1, $secs1] = $this->measure(fn() => journal::page($s->keyb, ['limit' => journal::MAXPAGE]));
        $this->assertCount(journal::MAXPAGE, $p1['rows']);
        $this->assertLessThanOrEqual(3, $reads1);
        $this->assertLessThan(5, $secs1);
        [$p2] = $this->measure(fn() => journal::page($s->keyb, ['limit' => journal::MAXPAGE, 'before' => $p1['next']]));
        $this->assertSame([], array_intersect(array_column($p1['rows'], 'id'), array_column($p2['rows'], 'id')));
        $this->assertCount(journal::MAXPAGE, journal::page($s->keyb, ['limit' => 100000])['rows'], 'The page size is capped.');

        // Grouped counts: one statement per group, independent of the number of rows.
        [$counts, $countreads] = $this->measure(fn() => journal::counts($s->keyb));
        $this->assertLessThanOrEqual(3, $countreads);
        $stored = $DB->count_records(journal::TABLE, ['connkey' => $s->keyb, 'source' => 'task_log']);
        $this->assertGreaterThanOrEqual(self::ROWS, $stored);
        $this->assertSame($stored, array_sum($counts['task_log']));

        // Diagnosis of 1 001+ learners: the reads of one page do not grow with the learners or the journal.
        [$d, $largereads, $secs] = $this->measure($diagnose);
        $this->assertGreaterThanOrEqual(self::LEARNERS, $d['total']);
        $this->assertCount(grade_diagnosis::MAXPAGE, $d['rows']);
        $this->assertLessThanOrEqual($smallreads + 3, $largereads, "reads: $smallreads small, $largereads large");
        $this->assertLessThan(20, $secs);

        // Support package: bounded rows and attempts.
        [$pkg, $pkgreads] = $this->measure(fn() => support_package::build($conn, $now));
        $this->assertLessThanOrEqual(grade_diagnosis::MAXPAGE, count($pkg['grades']['rows']));
        $this->assertLessThanOrEqual(support_package::MAXATTEMPTS, count($pkg['grades']['attempts']));
        $this->assertLessThanOrEqual($largereads + 10, $pkgreads);

        // Retention: old closed rows go in one statement, open attempts stay.
        $open = $DB->count_records_select(journal::TABLE, 'state = ?', ['retry_wait']);
        $deleted = journal::purge($now - 365 * DAYSECS);
        $this->assertGreaterThan(0, $deleted);
        $this->assertSame($open, $DB->count_records_select(journal::TABLE, 'state = ?', ['retry_wait']));
    }
}
