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

use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\scope;
use tool_ltidiagnostics\local\snapshot;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/lti/tests/local/ltiadvantage/lti_advantage_testcase.php');
require_once(__DIR__ . '/../fixtures/grade_scenario.php');

/**
 * Every view part of the reworked report renders with data, in English and German, without a debugging message (a
 * missing string is one), without placeholder values and with labelled controls (issues #11 to #26).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\output\renderer
 * @covers     \tool_ltidiagnostics\output\grades_renderer
 * @covers     \tool_ltidiagnostics\output\sections
 */
final class render_smoke_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /**
     * Render everything for one connection.
     *
     * @param string $key Connection key.
     * @param scope $scope Scope.
     * @return string HTML.
     */
    private function render_all(string $key, scope $scope): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key]));
        $PAGE->set_context(\context_system::instance());
        $conn = (new inventory())->get_connection($key);
        $out = $PAGE->get_renderer('tool_ltidiagnostics');
        $gout = $PAGE->get_renderer('tool_ltidiagnostics', 'grades');
        $now = time();
        $html = $out->connection_header($conn) . $out->scope_bar($conn, $scope, 'grades');
        foreach (['applies', 'partial', 'systemwide'] as $kind) {
            $html .= $out->scope_note($kind, $scope);
        }
        $facts = (new \tool_ltidiagnostics\local\fact_collector())->collect($conn, $now, []);
        $probes = [
            'jwks' => ['purpose' => 'jwks', 'code' => 'ok', 'checkedat' => $now, 'url' => 'https://a.example.org/jwks',
                'httpstatus' => 200, 'dns' => ['192.0.2.1'], 'peer' => '192.0.2.1', 'viaproxy' => 0, 'skew' => null,
                'certexpiry' => null, 'jwks' => null, 'framing' => null, 'method' => 'GET', 'executor' => 'this_site'],
            'oauth' => ['purpose' => 'oauth', 'code' => 'http_client_error', 'httpstatus' => 405, 'checkedat' => $now,
                'url' => 'https://a.example.org/token', 'viaproxy' => 1, 'skew' => null, 'certexpiry' => null,
                'jwks' => null, 'framing' => null, 'errno' => 0],
            'auth' => ['purpose' => 'auth', 'code' => 'firewall_drop_suspected', 'checkedat' => $now, 'errno' => 28,
                'url' => 'https://a.example.org/auth', 'skew' => null, 'certexpiry' => null, 'jwks' => null, 'framing' => null],
        ];
        $matrix = (new \tool_ltidiagnostics\local\matrix_builder())->build($conn, $facts, $probes);
        $html .= $out->matrix($matrix, $conn, $scope);
        $result = (new \tool_ltidiagnostics\local\health_check())->run($conn, $now, ['probes' => $probes]);
        $html .= $out->services_table($result['services']) . $out->healthcheck($result) . $out->probes_table($probes);
        $html .= $out->messages_tab($result, ['baseurl' => new \moodle_url(
            '/admin/tool/ltidiagnostics/connection.php',
            ['key' => $key, 'tab' => 'messages']
        )]);
        $html .= $out->messages_tab($result, ['message' => 'ags_score', 'state' => '',
            'baseurl' => new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => 'messages'])]);
        foreach (['grades_rows', 'grades_journal', 'timeline'] as $section) {
            $html .= sections::render($conn, $section, ['perpage' => '20'], $scope)['html'];
        }
        foreach (\tool_ltidiagnostics\local\evidence_query::VIEWS as $view) {
            $html .= sections::render($conn, 'evidence', ['view' => $view], $scope)['html'];
        }
        $html .= sections::render($conn, 'grades_rows', ['fgrade' => 'zero', 'ftransfer' => 'skipped'], $scope)['html'];
        $html .= sections::render($conn, 'grades_journal', ['jorigin' => 'core', 'jattr' => 'unattributed'], $scope)['html'];
        $html .= $out->history_tab($key);
        foreach (['absent', 'codeonly', 'needsupgrade', 'incomplete'] as $state) {
            $html .= $gout->completion_unavailable($state);
        }
        return $html;
    }

    /**
     * Data provider: languages.
     *
     * @return array
     */
    public static function languages(): array {
        return ['English' => ['en'], 'German' => ['de']];
    }

    /**
     * The whole report renders without debugging, in both languages, with and without a scope, and before and after
     * snapshots exist.
     *
     * @dataProvider languages
     * @param string $lang Language.
     */
    public function test_everything_renders(string $lang): void {
        global $CFG, $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();
        if ($lang === 'de') {
            // The plugin ships its German strings; the core pack may be missing in the test site, then English core
            // strings appear, which is fine for this test.
            // Install a minimal German language pack (only langconfig), so that the plugin's own German strings are used.
            make_writable_directory($CFG->dataroot . '/lang/de');
            file_put_contents($CFG->dataroot . '/lang/de/langconfig.php', "<?php\n\$string['thislanguage'] = 'Deutsch';\n"
                . "\$string['parentlanguage'] = '';\n");
            get_string_manager()->reset_caches();
            $SESSION->forcelang = 'de';
            $this->assertSame('Funktionscheck', get_string('tab_matrix', 'tool_ltidiagnostics'));
        }
        $s = $this->build_grade_scenario();
        // With the replay possible, the rows carry the selection and the per-row result check (own forms).
        set_config('graderepair', 1, 'tool_ltidiagnostics');
        \tool_ltidiagnostics\local\grades\repair_service::set_opt_in($s->keya, true);
        \tool_ltidiagnostics\local\grades\repair_service::set_opt_in($s->keyb, true);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $s->course->id]);
        foreach ([$s->keya, $s->keyb] as $key) {
            $html = $this->render_all($key, scope::make(0, 0));
            $html .= $this->render_all($key, scope::make((int) $s->course->id, (int) $assign->cmid, '2026-01-01', '2026-12-31'));
            $conn = (new inventory())->get_connection($key);
            $facts = (new \tool_ltidiagnostics\local\fact_collector())->collect($conn, time(), []);
            $matrix = (new \tool_ltidiagnostics\local\matrix_builder())->build($conn, $facts, []);
            snapshot::take($key, $matrix, time() - 100);
            snapshot::take($key, $matrix, time());
            $html .= $this->render_all($key, scope::make(0, 0));
            $this->assertDebuggingNotCalled();
            $this->assertStringNotContainsString('[[', $html, 'No missing string marker.');
            $this->assertStringNotContainsString('{$a', $html, 'No unreplaced placeholder.');
            $this->assertDoesNotMatchRegularExpression('#<span class="badge[^"]*">\s*</span>#', $html, 'Every badge has text.');
            // Forms never nest (a nested form is dropped by the browser and its fields go to the outer form).
            $depth = 0;
            preg_match_all('#<(/?)form\b#i', $html, $tags, PREG_SET_ORDER);
            foreach ($tags as $tag) {
                $depth += $tag[1] === '/' ? -1 : 1;
                $this->assertLessThanOrEqual(1, $depth, 'Nested form.');
            }
            // Every visible form control has a label or an aria-label.
            preg_match_all('#<(?:input|select)\b([^>]*)>#', $html, $controls);
            foreach ($controls[1] as $attrs) {
                if (preg_match('#type="(hidden|submit)"#', $attrs)) {
                    continue;
                }
                $labelled = strpos($attrs, 'aria-label=') !== false;
                if (!$labelled && preg_match('#id="([^"]+)"#', $attrs, $id)) {
                    $labelled = strpos($html, 'for="' . $id[1] . '"') !== false;
                }
                $this->assertTrue($labelled, 'Unlabelled control: ' . $attrs);
            }
        }
    }
}
