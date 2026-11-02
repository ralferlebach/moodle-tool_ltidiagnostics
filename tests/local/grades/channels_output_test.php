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
     * Render the grade tab as the connection page does.
     *
     * @param string $key Connection key.
     * @param array $ctx Context overrides.
     * @return string
     */
    private function render_grades(string $key, array $ctx = []): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key]));
        $PAGE->set_context(\context_system::instance());
        $conn = (new inventory())->get_connection($key);
        $diag = (new grade_diagnosis())->run($conn, ['showscores' => $ctx['showscores'] ?? false]);
        $out = $PAGE->get_renderer('tool_ltidiagnostics', 'grades', RENDERER_TARGET_GENERAL);
        return $out->grades_tab($diag, journal::page($key), $ctx + [
            'key' => $key, 'pageurl' => new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key]),
            'showscores' => false, 'canrepair' => false, 'canresult' => false, 'canoptin' => false,
            'repairstate' => ['site' => false, 'optin' => false, 'capability' => false],
        ]);
    }

    /**
     * Read-only users see no mutating action and no score; tables have captions, row headers and labelled badges.
     */
    public function test_read_only_view_is_accessible_and_inert(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        $html = $this->render_grades($s->keyb);
        $this->assertStringNotContainsString('repair.php', $html, 'No replay link without the right.');
        $this->assertStringNotContainsString('value="checkresult"', $html);
        $this->assertStringNotContainsString('value="optin"', $html);
        foreach (['65.00', '0.65', '100.00'] as $score) {
            $this->assertStringNotContainsString($score, strip_tags($html), 'No score value without viewgrades.');
        }
        foreach (
            ['tool_ltidiagnostics_grades_prereq', 'tool_ltidiagnostics_grades_findings',
                'tool_ltidiagnostics_grades_rows'] as $id
        ) {
            $this->assertMatchesRegularExpression('#<table[^>]*id="' . $id . '"[^>]*>\s*<caption#', $html, $id);
        }
        $this->assertStringContainsString('scope="row"', $html);
        $this->assertDoesNotMatchRegularExpression('#<span class="badge[^"]*">\s*</span>#', $html, 'Every badge has text.');
        $this->assertStringContainsString('data-risks="', $html);
        $this->assertStringContainsString(get_string('gf_gr_lastgrade_blocks', 'tool_ltidiagnostics'), $html);
        $this->assertStringNotContainsString('b-l1', $html);
        $this->assertStringNotContainsString('id=' . $s->users->l1->id, $html);
    }

    /**
     * With the replay switched on and the right, the replay is offered exactly where it is possible.
     */
    public function test_actions_follow_rights_and_targets(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        $html = $this->render_grades($s->keyb, ['canrepair' => true, 'canresult' => true, 'canoptin' => true,
            'showscores' => true, 'repairstate' => ['site' => true, 'optin' => true, 'capability' => true]]);
        $this->assertStringContainsString('link=' . $s->linkb1 . '&amp;user=' . $s->users->l1->id, $html);
        $this->assertStringNotContainsString(
            'link=' . $s->linkb2 . '&amp;user=',
            $html,
            'No replay where the score scope is missing.'
        );
        $this->assertStringNotContainsString('value="checkresult"', $html, 'BUW granted no result scope.');
        $this->assertStringContainsString('value="optout"', $html);
        $a = $this->render_grades($s->keya, ['canrepair' => true, 'canresult' => true, 'showscores' => true,
            'repairstate' => ['site' => true, 'optin' => true, 'capability' => true]]);
        $this->assertStringContainsString('value="checkresult"', $a, 'A granted the result scope.');
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
}
