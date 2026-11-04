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
 * Grade and completion channels in the output (issue #8): separated channels, actions by right, accessible tables,
 * and the redacted support package that agrees with the journal.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\output\grades_renderer
 * @covers     \tool_ltidiagnostics\local\grades\support_package
 */
final class channels_output_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /**
     * Render the grade tab as the connection page does: the rows and the journal come from the sections, whose rights
     * are those of the current user (no context override).
     *
     * @param string $key Connection key.
     * @param array $ctx Context of the tab (repair state).
     * @return string
     */
    private function render_grades(string $key, array $ctx = []): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key]));
        $PAGE->set_context(\context_system::instance());
        $conn = (new inventory())->get_connection($key);
        $scope = \tool_ltidiagnostics\local\scope::make(0, 0);
        $base = \tool_ltidiagnostics\output\sections::grade_ctx($conn);
        $diag = (new grade_diagnosis())->run($conn, ['showscores' => $base['showscores']]);
        $rows = \tool_ltidiagnostics\output\sections::render($conn, 'grades_rows', [], $scope);
        $journal = \tool_ltidiagnostics\output\sections::render($conn, 'grades_journal', [], $scope);
        $out = $PAGE->get_renderer('tool_ltidiagnostics', 'grades', RENDERER_TARGET_GENERAL);
        return $out->grades_tab($diag, $rows['html'], $journal['html'], $base + $ctx + [
            'pageurl' => new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key]),
            'canoptin' => false, 'repairstate' => ['site' => false, 'optin' => false, 'capability' => false],
            'scope' => $scope,
        ]);
    }

    /**
     * A user with only the view capability.
     *
     * @return \stdClass User.
     */
    private function viewer(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/ltidiagnostics:view', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * The support package agrees with the journal, is split by responsibility and holds no personal identifiers.
     */
    public function test_support_package(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        set_config('graderepair', 1, 'tool_ltidiagnostics');
        repair_service::set_opt_in($s->keyb, true);
        $connb = (new inventory())->get_connection($s->keyb);
        $svc = new repair_service(new fake_ags_transport());
        $l1 = (int) $s->users->l1->id;
        $r = $svc->request($connb, $s->linkb1, $l1, $svc->preview($connb, $s->linkb1, $l1)['fingerprint'], 'support', 2);
        $svc->process($r['attempt']->attemptid);

        $pkg = support_package::build($connb, time());
        $journal = journal::page($s->keyb, ['limit' => support_package::MAXATTEMPTS])['rows'];
        $this->assertSame(array_column($journal, 'attemptid'), array_column($pkg['grades']['attempts'], 'attemptid'));
        $this->assertSame(array_column($journal, 'state'), array_column($pkg['grades']['attempts'], 'state'));
        $this->assertNotEmpty($pkg['responsibility']['provider']);
        $this->assertNotEmpty($pkg['responsibility']['consumer']);
        $this->assertContains('booking_unknown', array_column($pkg['responsibility']['unknown'], 'code'));
        foreach (support_package::FORMATS as $format) {
            $text = support_package::export($pkg, $format);
            $this->assertNotSame('', $text);
            foreach (['b-l1', 'a-l1', 'buw.example.org', 'rl-b-1', 'tok-', '"userid"', 'l1@', 'Firstname'] as $secret) {
                $this->assertStringNotContainsString($secret, $text, "$format contains $secret");
            }
            $this->assertStringNotContainsString('"scoregiven"', $text);
        }
        $this->assertStringContainsString(
            get_string('gpkg_resp_unknown', 'tool_ltidiagnostics'),
            support_package::export($pkg, 'md')
        );
        $csv = support_package::export($pkg, 'csv');
        $this->assertStringContainsString((string) $r['attempt']->attemptid, $csv);
    }

    /**
     * Multi-select (issue #32): row checkboxes and "select all shown" exist for users with an analysis right, also
     * without the replay opt-in; the only action then is the read-only result check. Users without that right get no
     * checkboxes at all. The status details are folded away; the counts stay correct.
     */
    public function test_selection_by_right_independent_of_optin(): void {
        $this->resetAfterTest();
        $s = $this->build_grade_scenario();
        $this->setUser($this->viewer());
        $html = $this->render_grades($s->keya);
        $this->assertStringNotContainsString('type="checkbox"', $html, 'No checkbox without a right to use it.');
        $this->assertStringNotContainsString('tool_ltidiagnostics_selection', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('id="tool_ltidiagnostics_counters_details"', $html);

        $user = $this->viewer();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/ltidiagnostics:runchecks', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        $this->assertFalse(repair_service::can_repair($s->keya), 'No replay opt-in in this test.');
        $html = $this->render_grades($s->keya);
        $this->assertStringContainsString('class="ltidiag-pair"', $html);
        $this->assertStringContainsString('id="ltidiag-pair-all"', $html);
        $this->assertStringContainsString('id="tool_ltidiagnostics_selection"', $html);
        $this->assertSame(1, preg_match('~<select[^>]*name="bulkaction"[^>]*>(.*?)</select>~s', $html, $m));
        $this->assertStringContainsString('value="resultcheck"', $m[1]);
        $this->assertStringNotContainsString('value="replay"', $m[1], 'No replay action without the opt-in.');
    }

    /**
     * Scope (issues #29, #30): with a course without the publication the population is empty, the prerequisites list
     * no publication and say that site-wide settings are global; in the publication's course the publication is listed
     * by name with its edit pencil.
     */
    public function test_grade_tab_follows_scope(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Elsewhere']);
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $s->keya]));
        $PAGE->set_context(\context_system::instance());
        $conn = (new inventory())->get_connection($s->keya);
        $out = $PAGE->get_renderer('tool_ltidiagnostics', 'grades', RENDERER_TARGET_GENERAL);
        foreach ([[(int) $other->id, false], [(int) $s->course->id, true]] as [$courseid, $inscope]) {
            \tool_ltidiagnostics\local\identity::reset();
            $scope = \tool_ltidiagnostics\local\scope::make($courseid, 0);
            $base = \tool_ltidiagnostics\output\sections::grade_ctx($conn);
            $diag = (new grade_diagnosis())->run($conn, ['courseid' => $courseid, 'importtasklog' => false]);
            $rows = \tool_ltidiagnostics\output\sections::render($conn, 'grades_rows', [], $scope);
            $html = $out->grades_tab($diag, $rows['html'], '', $base + [
                'pageurl' => new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $s->keya]),
                'canoptin' => false, 'repairstate' => ['site' => false, 'optin' => false, 'capability' => false],
                'scope' => $scope,
            ]);
            $this->assertStringContainsString(get_string('gpre_global_note', 'tool_ltidiagnostics'), $html);
            if ($inscope) {
                $this->assertGreaterThan(0, $rows['total']);
                $this->assertStringContainsString('enrol/editinstance.php', $html, 'The publication has its pencil.');
                $this->assertStringContainsString(get_string('id_idsuffix', 'tool_ltidiagnostics', $s->course->id), $html);
            } else {
                $this->assertSame(0, $rows['total'], 'Nothing of another course.');
                $this->assertStringContainsString(get_string('gpre_nopublication_scope', 'tool_ltidiagnostics'), $html);
            }
        }
    }
}
