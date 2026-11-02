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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/lti/tests/local/ltiadvantage/lti_advantage_testcase.php');
require_once(__DIR__ . '/../../fixtures/grade_scenario.php');

/**
 * Connection related grade diagnosis (issue #3) against real enrol_lti launches, gradebook data and core task runs.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\grades\grade_diagnosis
 * @covers     \tool_ltidiagnostics\local\grades\source_reader
 */
final class grade_diagnosis_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /**
     * Rows of a diagnosis keyed by "linkid:userid".
     *
     * @param array $d Diagnosis.
     * @return array
     */
    private static function rows(array $d): array {
        $out = [];
        foreach ($d['rows'] as $r) {
            $out[$r['linkid'] . ':' . $r['userid']] = $r;
        }
        return $out;
    }

    /**
     * The same learner at two consumers is shown strictly per connection; lastgrade is a risk, never a proof.
     */
    public function test_two_consumers_are_separate_and_lastgrade_is_a_risk(): void {
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $inv = new inventory();
        $da = (new grade_diagnosis())->run($inv->get_connection($s->keya), ['showscores' => true]);
        $db = (new grade_diagnosis())->run($inv->get_connection($s->keyb), ['showscores' => true]);
        $ra = self::rows($da);
        $rb = self::rows($db);
        $l1 = (int) $s->users->l1->id;

        // Control consumer A: only A's link; the task log shows the accepted core send.
        $this->assertSame([$s->linka], array_values(array_unique(array_column($da['rows'], 'linkid'))));
        $a1 = $ra[$s->linka . ':' . $l1];
        $this->assertSame('lastgrade_unchanged', $a1['prediction']);
        $this->assertSame('http_accepted_unconfirmed', $a1['evidence']['level'], 'A 2xx is never a booked grade.');
        $this->assertSame('task_log', $a1['evidence']['source']);
        $this->assertNotContains('lastgrade_blocks', $a1['risks']);
        // Core keeps the subject and deployment of the learner's first launch (A) for the publication.
        $this->assertSame('linked_login', $a1['mapping']);
        $this->assertContains('multi_consumer', $a1['risks']);

        // BUW consumer B, defect 1: core skips because lastgrade equals the grade sent to A.
        $blinks = array_values(array_unique(array_column($db['rows'], 'linkid')));
        $this->assertEqualsCanonicalizing([$s->linkb1, $s->linkb2], $blinks);
        $b1 = $rb[$s->linkb1 . ':' . $l1];
        $this->assertSame('lastgrade_unchanged', $b1['prediction']);
        $this->assertSame('skipped', $b1['evidence']['level']);
        $this->assertSame('lastgrade_unchanged', $b1['evidence']['skipreason']);
        $this->assertContains('lastgrade_blocks', $b1['risks']);
        $this->assertContains('lastgrade_shared', $b1['risks']);
        // ...and core would send A's subject to B (enrol_lti_users.sourceid is one value per learner and publication).
        $this->assertSame('core_sends_other_subject', $b1['mapping']);
        $this->assertContains('mapping_core_sends_other_subject', $b1['risks']);
        $this->assertTrue($b1['replayable'], 'A replay uses the auth_lti link of B\'s issuer.');
        $codes = array_column($db['findings'], 'code');
        $this->assertContains('gr_lastgrade_blocks', $codes);
        // On A only L4 (0 points, never sent by core) is blocked, L1 is not.
        $this->assertContains('gr_zero_never_sent', array_column($da['findings'], 'code'));
        $this->assertSame(1, array_column($da['findings'], 'count', 'code')['gr_lastgrade_blocks']);

        // BUW defect 2: old claim without score scope; core tried and got 401.
        $b2 = $rb[$s->linkb2 . ':' . (int) $s->users->l3->id];
        $this->assertSame('no_scope', $b2['prediction']);
        $this->assertSame('error', $b2['evidence']['level']);
        $this->assertSame(401, $b2['evidence']['httpstatus']);
        $this->assertContains('gr_no_scope', $codes);
        $this->assertFalse($b2['replayable']);
    }

    /**
     * Missing and zero grades, course and activity grades and completion gating are told apart.
     */
    public function test_missing_zero_gating_and_kinds(): void {
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $conn = (new inventory())->get_connection($s->keya);
        $rows = self::rows((new grade_diagnosis())->run($conn, ['showscores' => true]));
        $l2 = $rows[$s->linka . ':' . (int) $s->users->l2->id];
        $this->assertSame('no_grade', $l2['prediction']);
        $this->assertContains($l2['source']['state'], ['none', 'null']);
        $this->assertNull($l2['source']['grade']);

        $l4 = $rows[$s->linka . ':' . (int) $s->users->l4->id];
        $this->assertSame('zero', $l4['source']['state'], '0 points are a grade, not a missing one.');
        $this->assertSame(0.0, (float) $l4['source']['grade']);
        $this->assertSame('module', $l4['source']['kind']);
        // Core starts every learner with lastgrade 0.0, so it skips a 0 as "unchanged" and never sends it.
        $this->assertSame('lastgrade_unchanged', $l4['prediction']);
        $this->assertSame('skipped', $l4['evidence']['level']);
        $this->assertContains('zero_never_sent', $l4['risks']);
        $this->assertTrue($l4['replayable']);

        // Course publication: kind course. Gating: a learner without completion is "gated", never "no grade".
        $reader = new source_reader();
        $course = $reader->resource((int) $s->tool3->id);
        $this->assertSame('course', $course['kind']);
        global $DB;
        $DB->set_field('enrol_lti_tools', 'gradesynccompletion', 1, ['id' => $s->tool->id]);
        $gated = self::rows((new grade_diagnosis())->run($conn, ['showscores' => true]));
        $this->assertSame('gated', $gated[$s->linka . ':' . (int) $s->users->l1->id]['prediction']);
        $this->assertFalse($gated[$s->linka . ':' . (int) $s->users->l1->id]['source']['completed']);
    }

    /**
     * Core's preconditions: plugin switches, scheduled task, ad-hoc queue and task log with dates and quality.
     */
    public function test_task_chain_and_prerequisites(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $conn = (new inventory())->get_connection($s->keyb);
        $task = new \enrol_lti\local\ltiadvantage\task\sync_tool_grades();
        $task->set_custom_data($s->tool);
        \core\task\manager::queue_adhoc_task($task);
        $DB->set_field('task_adhoc', 'faildelay', 60, ['classname' => '\\' . tasklog_parser::TASKCLASS]);
        $d = (new grade_diagnosis())->run($conn);
        $p = $d['prerequisites'];
        $this->assertTrue($p['authlti']);
        $this->assertTrue($p['enrollti']);
        $this->assertNotNull($p['scheduled']);
        $this->assertSame(1, $p['adhoc'][(int) $s->tool->id]['queued']);
        $this->assertSame(1, $p['adhoc'][(int) $s->tool->id]['failed']);
        $this->assertGreaterThan(0, $p['lastruns'][(int) $s->tool->id]['time']);
        $this->assertContains('gr_adhoc_failing', array_column($d['findings'], 'code'));

        // Switching the scheduled task off is a blocking finding and changes every prediction.
        $DB->set_field('task_scheduled', 'disabled', 1, ['classname' => grade_diagnosis::SCHEDULEDCLASS]);
        $d = (new grade_diagnosis())->run($conn);
        $this->assertContains('gr_task_disabled', array_column($d['findings'], 'code'));
        $this->assertSame(['prereq_missing'], array_values(array_unique(array_column($d['rows'], 'prediction'))));

        // Without auth_lti: also blocking.
        set_config('auth', '');
        \core\session\manager::gc();
        $d = (new grade_diagnosis())->run($conn);
        $this->assertContains('gr_prereq_auth', array_column($d['findings'], 'code'));
    }

    /**
     * Every observation names source, time, certainty and the limit of what can be seen; strings exist for each.
     */
    public function test_observations_are_complete(): void {
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        foreach ([$s->keya, $s->keyb] as $key) {
            $d = (new grade_diagnosis())->run((new inventory())->get_connection($key));
            foreach ($d['rows'] as $r) {
                $this->assertNotEmpty($r['observations']);
                foreach ($r['observations'] as $o) {
                    $this->assertNotSame('', $o['source']);
                    $this->assertGreaterThan(0, $o['time']);
                    $this->assertContains($o['certainty'], ['certain', 'likely', 'possible']);
                    $limitstring = get_string_manager()->string_exists('g' . $o['limit'], 'tool_ltidiagnostics');
                    $this->assertTrue($limitstring, $o['limit']);
                }
                $this->assertTrue(get_string_manager()->string_exists('gpred_' . $r['prediction'], 'tool_ltidiagnostics'));
                $this->assertTrue(get_string_manager()->string_exists('gev_' . $r['evidence']['level'], 'tool_ltidiagnostics'));
                foreach ($r['risks'] as $risk) {
                    $this->assertTrue(get_string_manager()->string_exists('grisk_' . $risk, 'tool_ltidiagnostics'), $risk);
                }
            }
            foreach ($d['findings'] as $f) {
                $ids = ['gf_' . $f['code'], 'gf_' . $f['code'] . '_next', 'g' . $f['limit'], 'gresp_' . $f['responsible']];
                foreach ($ids as $id) {
                    $this->assertTrue(get_string_manager()->string_exists($id, 'tool_ltidiagnostics'), $id);
                }
            }
        }
    }

    /**
     * Score values only with viewgrades, persons pseudonymised, identifiers masked; nothing of the gradebook changes.
     */
    public function test_redaction_and_read_only(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $conn = (new inventory())->get_connection($s->keyb);
        $before = [
            $DB->get_records('grade_grades', null, 'id'),
            $DB->get_records('enrol_lti_users', null, 'id'),
            $DB->get_records('enrol_lti_resource_link', null, 'id'),
        ];
        $d = (new grade_diagnosis())->run($conn);
        foreach ($d['rows'] as $r) {
            $this->assertNull($r['source']['grade']);
            $this->assertNull($r['source']['max']);
            $this->assertMatchesRegularExpression('/^P-[0-9A-F]{8}$/', $r['person']);
            $this->assertStringNotContainsString((string) $r['userid'], $r['person']);
        }
        foreach ($d['links'] as $l) {
            $this->assertStringNotContainsString('rl-b-', $l['resourcelinkid']);
            $this->assertStringNotContainsString('buw.example.org', $l['lineitem']);
        }
        $this->assertEquals($before, [
            $DB->get_records('grade_grades', null, 'id'),
            $DB->get_records('enrol_lti_users', null, 'id'),
            $DB->get_records('enrol_lti_resource_link', null, 'id'),
        ], 'The diagnosis must not change the gradebook or enrol_lti.');
    }

    /**
     * Query budget: the number of database reads does not grow with the number of learners (no N+1).
     */
    public function test_query_budget_is_constant(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $conn = (new inventory())->get_connection($s->keya);
        $measure = function () use ($conn, $DB): int {
            $before = $DB->perf_get_reads();
            (new grade_diagnosis())->run($conn, ['limit' => 500, 'importtasklog' => false]);
            return $DB->perf_get_reads() - $before;
        };
        $small = $measure();
        // 300 more learners linked to the same resource link, half of them graded.
        $lu = $DB->get_record('enrol_lti_users', ['userid' => $s->users->l2->id, 'toolid' => $s->tool->id]);
        for ($i = 0; $i < 300; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $id = $DB->insert_record('enrol_lti_users', (object) ['userid' => $u->id, 'toolid' => $s->tool->id,
                'ltideploymentid' => $lu->ltideploymentid, 'sourceid' => 'x' . $i, 'timecreated' => time()]);
            $DB->insert_record('enrol_lti_user_resource_link', (object) ['ltiuserid' => $id, 'resourcelinkid' => $s->linka]);
            if ($i % 2) {
                $this->set_user_grade_for_resource_local((int) $u->id, 50, $s->tool);
            }
        }
        $large = $measure();
        $this->assertLessThanOrEqual($small + 2, $large, "reads: $small for 3 learners, $large for 303");
        $d = (new grade_diagnosis())->run($conn, ['limit' => 100, 'offset' => 100, 'importtasklog' => false]);
        $this->assertSame(303, $d['total']);
        $this->assertCount(100, $d['rows']);
    }
}
