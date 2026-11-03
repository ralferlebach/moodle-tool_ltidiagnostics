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
use tool_ltidiagnostics\local\scope;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/lti/tests/local/ltiadvantage/lti_advantage_testcase.php');
require_once(__DIR__ . '/../../fixtures/grade_scenario.php');

/**
 * Count invariants of the grade rows (issues #13, #21, #22): rows, totals, counters, filters, paging and the "select
 * every match" population are one and the same population.
 *
 * The scenario has two consumers (A and BUW) on one published resource, a learner with 65 points (sent, HTTP 200), a
 * learner without grade (NULL), a learner with 0 points (skipped by core because lastgrade is 0), and a learner at BUW
 * whose transfer failed with HTTP 401.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\grades\pair_query
 * @covers     \tool_ltidiagnostics\local\grades\grade_diagnosis
 */
final class pair_query_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /**
     * Run the diagnosis.
     *
     * @param string $key Connection key.
     * @param array $options Options.
     * @return array
     */
    private function diag(string $key, array $options = []): array {
        $conn = (new inventory())->get_connection($key);
        return (new grade_diagnosis())->run($conn, $options + ['importtasklog' => true, 'limit' => 500]);
    }

    /**
     * Every counter is the size of the population its filter shows, the counters of one dimension add up to the total,
     * and the rows of a page belong to the counted population.
     */
    public function test_counters_equal_filtered_totals(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        foreach ([$s->keya, $s->keyb] as $key) {
            $all = $this->diag($key);
            $this->assertGreaterThan(0, $all['total']);
            $this->assertCount($all['total'], $all['rows']);
            foreach ($all['counters'] as $dim => $values) {
                $this->assertSame($all['total'], array_sum($values), "$key: counters of $dim are disjoint and complete");
                foreach ($values as $value => $n) {
                    $filtered = $this->diag($key, ['filters' => [$dim => $value]]);
                    $this->assertSame($n, $filtered['total'], "$key: filter $dim=$value");
                    $this->assertCount($n, $filtered['rows']);
                    $this->assertSame($n, $filtered['counters'][$dim][$value]);
                }
            }
        }
    }

    /**
     * The concrete states of the scenario: NULL is "no grade", 0 is "grade 0" and skipped by core, HTTP 2xx is
     * "accepted" but the booking is not proven, HTTP 401 is "failed"; the consumers are counted separately.
     */
    public function test_states_of_the_scenario(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        $a = $this->diag($s->keya);
        $this->assertSame(3, $a['total'], 'l1, l2 and l4 at consumer A');
        $this->assertSame(1, $a['counters']['grade']['present']);
        $this->assertSame(1, $a['counters']['grade']['zero']);
        $this->assertSame(1, $a['counters']['grade']['none']);
        $this->assertSame(0, $a['counters']['booking']['confirmed'], 'A 2xx is never counted as a booking.');
        $this->assertSame($a['counters']['transfer']['accepted'], $a['counters']['booking']['unknown']);
        $zero = $this->diag($s->keya, ['filters' => ['grade' => 'zero']]);
        $this->assertSame((int) $s->users->l4->id, (int) $zero['rows'][0]['userid']);
        $none = $this->diag($s->keya, ['filters' => ['grade' => 'none']]);
        $this->assertSame((int) $s->users->l2->id, (int) $none['rows'][0]['userid']);

        $b = $this->diag($s->keyb);
        $this->assertSame(2, $b['total'], 'l1 and l3 at BUW');
        $this->assertSame(0, $b['counters']['transfer']['accepted']);
        $this->assertSame($b['total'], $b['counters']['booking']['none']);
    }

    /**
     * Paging returns every row exactly once; "every match" returns the same population and refuses above the maximum.
     */
    public function test_paging_and_every_match_are_the_same_population(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        $conn = (new inventory())->get_connection($s->keya);
        $seen = [];
        for ($offset = 0; $offset < 3; $offset++) {
            $page = $this->diag($s->keya, ['offset' => $offset, 'limit' => 1]);
            $this->assertSame(3, $page['total'], 'The total does not depend on the page.');
            foreach ($page['rows'] as $r) {
                $seen[] = $r['linkid'] . ':' . $r['userid'];
            }
        }
        $this->assertCount(3, array_unique($seen));
        [$pairs, $total] = (new grade_diagnosis())->matching_pairs($conn, scope::make(0, 0), [], 100);
        $this->assertSame(3, $total);
        $this->assertEqualsCanonicalizing($seen, array_map(fn($p) => $p['linkid'] . ':' . $p['userid'], $pairs));
        [$pairs, $total] = (new grade_diagnosis())->matching_pairs($conn, scope::make(0, 0), [], 2);
        $this->assertSame([], $pairs, 'More matches than allowed: nothing is selected.');
        $this->assertSame(3, $total);
        [$pairs, $total] = (new grade_diagnosis())->matching_pairs(
            $conn,
            scope::make(0, 0),
            ['usersearch' => $s->users->l2->email],
            100
        );
        $this->assertSame(1, $total, 'The name search is part of "every match".');
        $this->assertSame((int) $s->users->l2->id, $pairs[0]['userid']);
        [$pairs] = (new grade_diagnosis())->matching_pairs($conn, scope::make(0, 0), ['grade' => 'zero'], 100);
        $this->assertSame([['linkid' => $s->linka, 'userid' => (int) $s->users->l4->id]], $pairs);
    }

    /**
     * Course scope: an empty course has an empty population with zero counters; grade sync and search filters work in
     * SQL; unknown filter values are dropped, not guessed.
     */
    public function test_scope_and_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        $empty = $this->getDataGenerator()->create_course();
        $d = $this->diag($s->keya, ['courseid' => $empty->id]);
        $this->assertSame(0, $d['total']);
        $this->assertSame(pair_query::empty_counters(), $d['counters']);
        $d = $this->diag($s->keya, ['courseid' => $s->course->id]);
        $this->assertSame(3, $d['total']);
        $this->assertSame(3, $this->diag($s->keya, ['filters' => ['gradesync' => '1']])['total']);
        $this->assertSame(0, $this->diag($s->keya, ['filters' => ['gradesync' => '0']])['total']);
        $found = $this->diag($s->keya, ['filters' => ['usersearch' => $s->users->l2->email]]);
        $this->assertSame(1, $found['total']);
        $this->assertSame((int) $s->users->l2->id, (int) $found['rows'][0]['userid']);
        $clean = pair_query::clean(['grade' => 'lots', 'transfer' => 'x', 'booking' => "1' OR 1=1", 'gradesync' => '2',
            'usersearch' => str_repeat('a', 300)]);
        $this->assertSame('', $clean['grade']);
        $this->assertSame('', $clean['transfer']);
        $this->assertSame('', $clean['booking']);
        $this->assertSame('', $clean['gradesync']);
        $this->assertSame(100, \core_text::strlen($clean['usersearch']));
    }
}
