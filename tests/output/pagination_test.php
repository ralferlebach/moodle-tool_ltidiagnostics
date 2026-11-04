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

namespace tool_ltidiagnostics\output;

use tool_ltidiagnostics\local\grades\journal;

/**
 * Pagination (issue #31): the core paging bar, page sizes, clamped page numbers, stable journal pages.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\output\grades_renderer
 * @covers     \tool_ltidiagnostics\output\sections
 * @covers     \tool_ltidiagnostics\local\grades\journal
 */
final class pagination_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * The grade renderer.
     *
     * @return grades_renderer
     */
    private function renderer(): grades_renderer {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => 'tool:1:1']));
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('tool_ltidiagnostics', 'grades');
    }

    /**
     * Page boundaries.
     *
     * @return array
     */
    public static function boundaries(): array {
        return [
            'empty' => [0, 50, 0, 0],
            'one row' => [1, 50, 0, 0],
            'one full page' => [50, 50, 0, 0],
            'one more' => [51, 50, 1, 1],
            'max page size' => [500, 500, 0, 0],
            'one above the max page size' => [501, 500, 1, 1],
            'large' => [100000, 50, 1999, 1999],
        ];
    }

    /**
     * The core paging bar appears only when there is more than one page; page numbers are clamped to the last page.
     *
     * @dataProvider boundaries
     * @param int $total Rows.
     * @param int $perpage Page size.
     * @param int $lastpage Expected last page index.
     * @param int $expectbar 1 when a paging bar is expected.
     */
    public function test_pager_boundaries(int $total, int $perpage, int $lastpage, int $expectbar): void {
        $this->resetAfterTest();
        $out = $this->renderer();
        $base = new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => 'tool:1:1', 'tab' => 'grades']);
        $html = $out->pager($base, ['gpage' => 3, 'perpage' => $perpage], 'gpage', 0, $perpage, $total);
        if ($expectbar) {
            $this->assertStringContainsString('class="pagination', $html);
            $this->assertStringContainsString('data-pagevar="gpage"', $html);
            $this->assertStringContainsString('gpage=' . $lastpage, $html, 'The last page is offered.');
        } else {
            $this->assertSame('', $html, 'No paging bar for a single page.');
        }
        $this->assertSame($lastpage, sections::clamp_page(PHP_INT_MAX, $perpage, $total));
        $this->assertSame(0, sections::clamp_page(-5, $perpage, $total));
    }

    /**
     * Only the allowed page sizes are accepted; anything else is the default.
     */
    public function test_page_sizes(): void {
        $this->assertSame([20, 50, 100, 500], sections::SIZES);
        foreach ([20, 50, 100, 500] as $n) {
            $this->assertSame($n, sections::size($n));
        }
        foreach ([0, -1, 7, 501, 100000] as $n) {
            $this->assertSame(50, sections::size($n));
        }
    }

    /**
     * Each section owns its own page parameter, so paging one block keeps the page of the other.
     */
    public function test_independent_page_parameters(): void {
        $this->assertContains('gpage', sections::PARAMS['grades_rows']);
        $this->assertContains('jpage', sections::PARAMS['grades_journal']);
        $this->assertContains('jsearch', sections::PARAMS['grades_journal']);
        $this->assertNotContains('jpage', sections::PARAMS['grades_rows']);
        $this->assertNotContains('gpage', sections::PARAMS['grades_journal']);
        $this->assertContains('epage', sections::PARAMS['evidence']);
        $this->assertContains('tpage', sections::PARAMS['timeline']);
    }

    /**
     * Journal pages are numbered over a snapshot: newer rows neither shift a page nor appear twice; every row is on
     * exactly one page, newest first (timecreated DESC, id DESC), also on pages read from the far end.
     */
    public function test_journal_numbered_pages_are_stable(): void {
        $this->resetAfterTest();
        $now = time();
        for ($i = 1; $i <= 120; $i++) {
            // Pairs of rows share a time, so the id decides the order inside a second.
            journal::insert(['connkey' => 'tool:1:1', 'resourcelinkrowid' => 1, 'userid' => 10 + ($i % 3),
                'state' => 'http_accepted', 'timecreated' => $now - 1000 + intdiv($i, 2)]);
        }
        $first = journal::numbered_page('tool:1:1', [], 0, 50, 0);
        $this->assertSame(120, $first['total']);
        $this->assertSame(3, $first['pages']);
        $this->assertFalse($first['newer']);
        $ids = [];
        $prev = null;
        foreach ([0, 1, 2] as $p) {
            $page = journal::numbered_page('tool:1:1', [], $p, 50, $first['max']);
            foreach ($page['rows'] as $r) {
                if ($prev) {
                    $this->assertTrue((int) $r->timecreated < (int) $prev->timecreated
                        || ((int) $r->timecreated === (int) $prev->timecreated && (int) $r->id < (int) $prev->id));
                }
                $prev = $r;
                $ids[] = (int) $r->id;
            }
        }
        $this->assertCount(120, $ids);
        $this->assertCount(120, array_unique($ids), 'No row twice.');

        $before = array_column(journal::numbered_page('tool:1:1', [], 1, 50, $first['max'])['rows'], 'id');
        for ($i = 1; $i <= 7; $i++) {
            journal::insert(['connkey' => 'tool:1:1', 'resourcelinkrowid' => 1, 'userid' => 10,
                'state' => 'http_accepted', 'timecreated' => $now + $i]);
        }
        $again = journal::numbered_page('tool:1:1', [], 1, 50, $first['max']);
        $this->assertSame($before, array_column($again['rows'], 'id'), 'Page 2 is the same while new rows arrive.');
        $this->assertSame(120, $again['total']);
        $this->assertTrue($again['newer'], 'The page says that newer rows exist.');
        $fresh = journal::numbered_page('tool:1:1', [], 0, 50, 0);
        $this->assertSame(127, $fresh['total']);
        $this->assertFalse($fresh['newer']);

        // Tampered page numbers are clamped; a tampered snapshot id never shows rows above the current maximum.
        $this->assertSame(2, journal::numbered_page('tool:1:1', [], 99999, 50, $first['max'])['page']);
        $this->assertSame(0, journal::numbered_page('tool:1:1', [], -3, 50, $first['max'])['page']);
        $this->assertSame(127, journal::numbered_page('tool:1:1', [], 0, 50, PHP_INT_MAX)['total']);
        // An empty population: one empty page.
        $none = journal::numbered_page('tool:9:9', [], 4, 50, 0);
        $this->assertSame(['rows' => [], 'total' => 0, 'page' => 0, 'pages' => 1], array_intersect_key(
            $none,
            array_flip(['rows', 'total', 'page', 'pages'])
        ));
    }

    /**
     * The journal section shows "X–Y of Z", the paging bar above and below the table, and the page of the scope only.
     */
    public function test_journal_section_range_and_pagers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        for ($i = 1; $i <= 60; $i++) {
            journal::insert(['connkey' => 'tool:1:1', 'resourcelinkrowid' => 1, 'userid' => 10,
                'state' => 'http_accepted', 'timecreated' => $now - $i]);
        }
        $out = $this->renderer();
        $page = journal::numbered_page('tool:1:1', [], 1, 50, 0);
        $base = new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => 'tool:1:1', 'tab' => 'grades']);
        $html = $out->journal_section($page, ['key' => 'tool:1:1', 'names' => true, 'showscores' => false,
            'baseurl' => $base, 'params' => ['jmax' => $page['max']], 'perpage' => 50, 'page' => 1,
            'scope' => \tool_ltidiagnostics\local\scope::make(0, 0)]);
        $this->assertStringContainsString(get_string('gj_range', 'tool_ltidiagnostics', (object) ['from' => 51, 'to' => 60,
            'total' => 60]), $html);
        $this->assertSame(2, substr_count($html, 'data-pagevar="jpage"'), 'Paging bar above and below.');
        $this->assertStringContainsString('jmax=' . $page['max'], $html, 'The links keep the snapshot.');
    }
}
