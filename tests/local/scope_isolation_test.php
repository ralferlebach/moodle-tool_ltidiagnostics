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

namespace tool_ltidiagnostics\local;

use tool_ltidiagnostics\local\grades\grade_diagnosis;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/admin/tool/ltidiagnostics/tests/fixtures/lti_data.php');

/**
 * Course and activity filter (issue #29): only connections and data with a proven assignment to the course or activity.
 *
 * The fixture has three courses with two activities each, several deployments of one shared registration and of a
 * second registration, platform tool types (site-wide, course-bound and unused), course-wide and activity publications,
 * a publication shared by two deployments and a publication without any resource link.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\inventory
 * @covers     \tool_ltidiagnostics\local\scope
 * @covers     \tool_ltidiagnostics\local\names
 * @covers     \tool_ltidiagnostics\local\grades\grade_diagnosis
 * @covers     \tool_ltidiagnostics\local\grades\support_package
 * @covers     \tool_ltidiagnostics\output\renderer
 */
final class scope_isolation_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use lti_data;

    /**
     * The fixture.
     *
     * @return \stdClass
     */
    private function build(): \stdClass {
        global $DB;
        $f = new \stdClass();
        $gen = $this->getDataGenerator();
        $f->a = $gen->create_course(['fullname' => 'Course A']);
        $f->b = $gen->create_course(['fullname' => 'Course B']);
        $f->c = $gen->create_course(['fullname' => 'Course C']);
        // Platform side.
        $f->t1 = $this->create_platform_type(['name' => 'Site tool one']);
        $f->t2 = $this->create_platform_type(['name' => 'Site tool two']);
        $f->t4 = $this->create_platform_type(['name' => 'Unused site tool']);
        $f->t3 = (int) $gen->get_plugin_generator('mod_lti')->create_course_tool_types([
            'name' => 'Course tool B', 'baseurl' => 'https://b.example.org/launch', 'course' => $f->b->id,
        ]);
        $f->cma1 = $this->create_lti_activity($f->a, $f->t1, 'A one');
        $f->cma2 = $this->create_lti_activity($f->a, $f->t1, 'A two');
        $f->cmb1 = $this->create_lti_activity($f->b, $f->t1, 'B one');
        $f->cmc1 = $this->create_lti_activity($f->c, $f->t2, 'C one');
        $f->cmc2 = $this->create_lti_activity($f->c, $f->t2, 'C two');
        // Tool side: registration R1 with two deployments, R2 with one.
        $f->r1 = $this->create_registration(['name' => 'Platform R1', 'deploymentid' => 'r1-d1']);
        $f->d2 = (int) $DB->insert_record('enrol_lti_deployment', (object) ['name' => 'Second', 'deploymentid' => 'r1-d2',
            'platformid' => $f->r1->regid, 'legacyconsumerkey' => null, 'timecreated' => time(), 'timemodified' => time()]);
        $f->r2 = $this->create_registration(['name' => 'Platform R2', 'platformid' => 'https://two.example.org',
            'clientid' => 'tool-client-2', 'deploymentid' => 'r2-d1']);
        $f->pa1 = $this->create_published_tool($f->a);
        $f->pacourse = $this->create_course_publication($f->a);
        $f->pb1 = $this->create_published_tool($f->b);
        $f->pc = $this->create_course_publication($f->c);
        $this->create_resource_link($f->r1->depid, (int) $f->pa1->id);
        $this->create_resource_link($f->r1->depid, (int) $f->pacourse->id);
        $this->create_resource_link($f->d2, (int) $f->pb1->id);
        $this->create_resource_link($f->r2->depid, (int) $f->pa1->id);
        // Pc stays unassigned: no resource link.
        $f->k1 = "tool:{$f->r1->regid}:{$f->r1->depid}";
        $f->k2 = "tool:{$f->r1->regid}:{$f->d2}";
        $f->k3 = "tool:{$f->r2->regid}:{$f->r2->depid}";
        $f->pa1cm = (int) $DB->get_field('context', 'instanceid', ['id' => $f->pa1->contextid]);
        $f->pb1cm = (int) $DB->get_field('context', 'instanceid', ['id' => $f->pb1->contextid]);
        return $f;
    }

    /**
     * Keys of the connections inside a filter.
     *
     * @param int $courseid Course.
     * @param int $cmid Activity.
     * @return string[]
     */
    private static function keys(int $courseid, int $cmid = 0): array {
        $keys = array_keys(inventory::filter_by_scope((new inventory())->get_connections(), $courseid, $cmid));
        sort($keys);
        return $keys;
    }

    /**
     * Expected keys, sorted.
     *
     * @param string[] $keys Keys.
     * @return string[]
     */
    private static function sorted(array $keys): array {
        sort($keys);
        return $keys;
    }

    /**
     * Course filter: a course shows exactly the connections used in it or in its activities; site-wide tool types, the
     * tool role and unassigned publications never match on their own.
     */
    public function test_course_filter_isolates_connections(): void {
        $this->resetAfterTest();
        $f = $this->build();
        $this->assertSame(self::sorted(["platform:{$f->t1}", $f->k1, $f->k3]), self::keys((int) $f->a->id));
        $this->assertSame(self::sorted(["platform:{$f->t1}", "platform:{$f->t3}", $f->k2]), self::keys((int) $f->b->id));
        $this->assertSame(["platform:{$f->t2}"], self::keys((int) $f->c->id), 'An unassigned publication is no match.');
        $other = $this->getDataGenerator()->create_course();
        $this->assertSame([], self::keys((int) $other->id), 'A course without use shows nothing.');
        $all = (new inventory())->get_connections();
        $this->assertArrayHasKey("platform:{$f->t4}", $all, 'Without a filter every connection is listed.');
    }

    /**
     * Activity filter: only the connections of that activity; course-wide publications do not match an activity.
     */
    public function test_activity_filter_isolates_connections(): void {
        $this->resetAfterTest();
        $f = $this->build();
        $this->assertSame(["platform:{$f->t1}"], self::keys(0, $f->cma1));
        $this->assertSame(["platform:{$f->t1}"], self::keys((int) $f->a->id, $f->cma2));
        $this->assertSame(self::sorted([$f->k1, $f->k3]), self::keys(0, $f->pa1cm), 'Both deployments of the shared publication.');
        $this->assertSame([$f->k2], self::keys(0, $f->pb1cm));
        $this->assertSame([], self::keys((int) $f->b->id, $f->cma1), 'A contradictory pair matches nothing.');
        $usage = (new inventory())->get_connection($f->k1)->usage;
        $this->assertCount(2, $usage);
        $this->assertSame([0, $f->pa1cm], self::sorted(array_column($usage, 'cmid')));
    }

    /**
     * Fail closed: unknown or contradictory restrictions are invalid and keep their values; they never fall back to all.
     */
    public function test_invalid_scope_fails_closed(): void {
        $this->resetAfterTest();
        $f = $this->build();
        $this->assertTrue(scope::make(999999, 0)->invalid);
        $this->assertTrue(scope::make(0, 999999)->invalid);
        $bad = scope::make((int) $f->b->id, $f->cma1);
        $this->assertTrue($bad->invalid);
        $this->assertSame(['courseid' => (int) $f->b->id, 'cmid' => $f->cma1], $bad->params(), 'Invalid input is kept.');
        $this->assertFalse($bad->is_empty());
        $this->assertSame(scope::NEVER, $bad->from, 'Nothing dated matches an invalid scope.');
        // An invalid period alone also matches nothing in the grade channel, the journal and the bulk selection.
        $period = scope::make(0, 0, 'garbage');
        $this->assertTrue($period->invalid);
        $conn = (new inventory())->get_connection($f->k1);
        $diag = new grade_diagnosis();
        $d = $diag->run($conn, ['importtasklog' => false, 'invalidscope' => true]);
        $this->assertSame([], $d['scopetools']);
        $this->assertSame(0, $d['total']);
        $this->assertSame([], $diag->scope_toolids($conn, $period));
        $this->assertSame(0, $diag->matching_pairs($conn, $period, [], 500)[1]);
        $pkg = grades\support_package::build($conn, time(), $period);
        $this->assertTrue($pkg['scope']['invalid']);
        $this->assertSame([], $pkg['grades']['rows']);
        $this->assertSame([], $pkg['grades']['attempts']);
        $ok = scope::make(0, $f->cma1);
        $this->assertFalse($ok->invalid);
        $this->assertSame((int) $f->a->id, $ok->courseid, 'The course follows from the activity.');
    }

    /**
     * Grade channel: the publications, the population and the journal follow the scope; an empty scope set is empty.
     */
    public function test_grade_channel_scope(): void {
        $this->resetAfterTest();
        $f = $this->build();
        $conn = (new inventory())->get_connection($f->k1);
        $diag = new grade_diagnosis();
        $d = $diag->run($conn, ['importtasklog' => false, 'courseid' => (int) $f->a->id]);
        $this->assertTrue($d['scoped']);
        $this->assertSame(self::sorted([(int) $f->pa1->id, (int) $f->pacourse->id]), self::sorted($d['scopetools']));
        $d = $diag->run($conn, ['importtasklog' => false, 'cmid' => $f->pa1cm, 'courseid' => (int) $f->a->id]);
        $this->assertSame([(int) $f->pa1->id], $d['scopetools'], 'The activity filter leaves out the course publication.');
        $d = $diag->run($conn, ['importtasklog' => false, 'courseid' => (int) $f->b->id]);
        $this->assertSame([], $d['scopetools']);
        $this->assertSame(0, $d['total']);
        $this->assertSame([], $diag->scope_toolids($conn, scope::make((int) $f->b->id, 0)), 'Journal: nothing, not all.');
        $this->assertNull($diag->scope_toolids($conn, scope::make(0, 0)));
        $d = $diag->run($conn, ['importtasklog' => false]);
        $this->assertFalse($d['scoped']);
        $this->assertCount(2, $d['resources']);
    }

    /**
     * Names: "name (id: n)", formatted, the missing state for deleted courses and activities, labels without double escaping.
     */
    public function test_names(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $f = $this->build();
        identity::reset();
        $suffix = fn(string $id, int $n): string => ' ' . get_string($id, 'tool_ltidiagnostics', $n);
        $this->assertSame('Course A' . $suffix('id_idsuffix', (int) $f->a->id), names::course((int) $f->a->id));
        $this->assertSame('A one' . $suffix('id_cmidsuffix', $f->cma1), names::cm($f->cma1, (int) $f->a->id));
        $this->assertStringStartsWith('Course A', names::publication(['courseid' => (int) $f->a->id, 'cmid' => 0]));
        $this->assertSame(s(get_string('id_coursemissing', 'tool_ltidiagnostics', 999999)), names::course(999999));
        $DB->set_field('course', 'fullname', 'R&D <b>x</b>', ['id' => $f->c->id]);
        names::reset();
        $name = names::course((int) $f->c->id);
        $this->assertStringNotContainsString('<b>', $name);
        $this->assertStringContainsString('R&amp;D', $name);
        $this->assertStringStartsWith('R&D', names::plain($name));
        // A deleted activity (its row gone, the course cache rebuilt; core's delete API differs between 4.5 and 5.2).
        $DB->delete_records('course_modules', ['id' => $f->cmc2]);
        rebuild_course_cache((int) $f->c->id, true);
        names::reset();
        $this->assertSame(s(get_string('id_cmmissing', 'tool_ltidiagnostics', $f->cmc2)), names::cm($f->cmc2, (int) $f->c->id));
    }

    /**
     * Overview: the filter offers the courses of proven use and the activities of the chosen course; "Used in" shows
     * only the use inside the filter, by name and id with the edit pencil; the open link keeps the filter.
     */
    public function test_overview_rendering(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $f = $this->build();
        $this->create_course_publication($this->getDataGenerator()->create_course(['fullname' => 'Course D']));
        // Ids repeat between tests: forget the names and links cached by earlier tests.
        identity::reset();
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/index.php'));
        $PAGE->set_context(\context_system::instance());
        /** @var \tool_ltidiagnostics\output\renderer $out */
        $out = $PAGE->get_renderer('tool_ltidiagnostics');
        $all = (new inventory())->get_connections();
        $scope = scope::make((int) $f->a->id, 0);
        $html = $out->overview_filter($all, ['courseid' => (int) $f->a->id], $scope);
        $this->assertStringContainsString('Course A', $html);
        $this->assertStringContainsString('Course B', $html);
        $this->assertStringContainsString('Course C', $html, 'Course C uses tool type two.');
        $this->assertStringNotContainsString('Course D', $html, 'A course with only an unassigned publication is not offered.');
        $this->assertStringContainsString('A one', $html, 'The activities of the chosen course are offered.');
        $this->assertStringNotContainsString('B one', $html);
        $list = $out->connection_list(inventory::filter_by_scope($all, (int) $f->a->id, 0), false, (int) $f->a->id, 0);
        $this->assertStringContainsString('A one', $list);
        $this->assertStringNotContainsString('B one', $list, '"Used in" shows only the use inside the course.');
        $this->assertStringContainsString('enrol/editinstance.php', $list, 'Publications carry their edit pencil.');
        $open = (new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $f->k1,
            'courseid' => (int) $f->a->id]))->out();
        $this->assertStringContainsString('href="' . $open . '"', $list, 'The open link keeps the course.');
        $this->assertStringNotContainsString('Unused site tool', $list);
    }

    /**
     * The overview renders a bounded number of queries, independent of the number of courses and activities.
     */
    public function test_overview_query_budget(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $f = $this->build();
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/index.php'));
        $PAGE->set_context(\context_system::instance());
        $out = $PAGE->get_renderer('tool_ltidiagnostics');
        $measure = function () use ($DB, $out): int {
            $all = (new inventory())->get_connections();
            names::reset();
            identity::reset();
            $before = $DB->perf_get_reads();
            $out->overview_filter($all, [], scope::make(0, 0));
            $out->connection_list($all, false);
            return $DB->perf_get_reads() - $before;
        };
        // Each round adds connections that are shown with their use (one tool type and one registration per course).
        $add = function (int $n) use ($f): void {
            for ($i = 0; $i < $n; $i++) {
                $course = $this->getDataGenerator()->create_course();
                $type = $this->create_platform_type(['name' => 'Bulk type ' . random_string(4)]);
                $this->create_lti_activity($course, $type, 'Bulk ' . $i);
                $this->create_lti_activity($course, $f->t1, 'Shared ' . $i);
            }
        };
        $add(5);
        $small = $measure();
        $add(10);
        $large = $measure();
        $this->assertLessThan(150, $large, "Overview took $large queries.");
        // Per added connection at most a bounded number of reads (the courses' module info), never one per row and use.
        $this->assertLessThanOrEqual(6 * 10, $large - $small, "From $small to $large reads for 10 more connections.");
    }
}
