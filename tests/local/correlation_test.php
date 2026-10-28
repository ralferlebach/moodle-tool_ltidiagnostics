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
 * Tests of the correlation confidence classes.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\correlation
 */
final class correlation_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * The four classes.
     */
    public function test_classes(): void {
        $a = ['traceid' => 'T1', 'time' => 1000, 'refs' => ['courseid' => 5, 'cmid' => 9, 'userid' => 7]];
        $this->assertSame(
            correlation::DIRECT,
            correlation::classify($a, ['traceid' => 'T1', 'time' => 99999, 'refs' => []]),
            'The same trace id is direct evidence, whatever the clock says.'
        );
        $this->assertSame(
            correlation::STRONG,
            correlation::classify($a, ['traceid' => 'T2', 'time' => 1030, 'refs' => ['courseid' => 5, 'cmid' => 9]])
        );
        $this->assertSame(
            correlation::TENTATIVE,
            correlation::classify($a, ['traceid' => '', 'time' => 1030, 'refs' => ['courseid' => 5]])
        );
        $this->assertSame(
            correlation::TENTATIVE,
            correlation::classify($a, ['time' => 1030, 'refs' => []])
        );
        $this->assertSame(
            correlation::UNMATCHED,
            correlation::classify($a, ['time' => 5000, 'refs' => ['courseid' => 5, 'cmid' => 9]])
        );
    }

    /**
     * An address alone never correlates beyond tentative.
     */
    public function test_ip_alone_is_not_a_reference(): void {
        $a = ['time' => 1000, 'refs' => ['ip' => '203.0.113.0', 'ipmask' => '203.0.113.0']];
        $b = ['time' => 1001, 'refs' => ['ip' => '203.0.113.0', 'ipmask' => '203.0.113.0']];
        $this->assertSame(correlation::TENTATIVE, correlation::classify($a, $b));
        $a['refs']['courseid'] = 3;
        $b['refs']['courseid'] = 3;
        $this->assertSame(
            correlation::TENTATIVE,
            correlation::classify($a, $b),
            'One real reference plus an address is not strong.'
        );
        $a['refs']['cmid'] = 4;
        $b['refs']['cmid'] = 4;
        $this->assertSame(correlation::STRONG, correlation::classify($a, $b));
    }

    /**
     * Empty references do not count as shared.
     */
    public function test_empty_refs_do_not_match(): void {
        $a = ['time' => 1000, 'refs' => ['courseid' => 0, 'cmid' => '', 'userid' => null]];
        $b = ['time' => 1000, 'refs' => ['courseid' => 0, 'cmid' => '', 'userid' => null]];
        $this->assertSame(correlation::TENTATIVE, correlation::classify($a, $b));
    }

    /**
     * The clock skew shifts the window.
     */
    public function test_skew_and_window(): void {
        $a = ['time' => 1000, 'refs' => ['x' => 1, 'y' => 2]];
        $b = ['time' => 1000 - 600, 'refs' => ['x' => 1, 'y' => 2]];
        $this->assertSame(correlation::UNMATCHED, correlation::classify($a, $b, 120, 0));
        $this->assertSame(correlation::STRONG, correlation::classify($a, $b, 120, 600));
        $this->assertSame(correlation::STRONG, correlation::classify($a, $b, 700, 0));
    }

    /**
     * Skew estimation uses direct matches only.
     */
    public function test_estimate_skew(): void {
        $pairs = [
            [['traceid' => 'a', 'time' => 100], ['traceid' => 'a', 'time' => 160]],
            [['traceid' => 'b', 'time' => 200], ['traceid' => 'b', 'time' => 262]],
            [['traceid' => 'c', 'time' => 300], ['traceid' => 'c', 'time' => 358]],
            [['traceid' => 'd', 'time' => 400], ['traceid' => 'z', 'time' => 9999]],
        ];
        $this->assertSame(60, correlation::estimate_skew($pairs));
        $this->assertNull(correlation::estimate_skew([[['time' => 1], ['time' => 2]]]));
        $this->assertNull(correlation::estimate_skew([]));
    }

    /**
     * Ranking.
     */
    public function test_rank(): void {
        $this->assertGreaterThan(correlation::rank(correlation::STRONG), correlation::rank(correlation::DIRECT));
        $this->assertGreaterThan(correlation::rank(correlation::TENTATIVE), correlation::rank(correlation::STRONG));
        $this->assertGreaterThan(correlation::rank(correlation::UNMATCHED), correlation::rank(correlation::TENTATIVE));
        $this->assertSame(0, correlation::rank('invented'));
    }
}
