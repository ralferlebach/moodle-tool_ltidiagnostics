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

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\external\render_section;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\evidence_query;
use tool_ltidiagnostics\local\observation_store;
use tool_ltidiagnostics\local\scope;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once(__DIR__ . '/fixtures/lti_data.php');

/**
 * Asynchronous sections and the evidence views (issues #21, #23, #25): the AJAX function returns exactly what the page
 * renders for the same state, checks rights and types every parameter; the evidence filters work on the server and keep
 * unattributable entries apart.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\external\render_section
 * @covers     \tool_ltidiagnostics\output\sections
 * @covers     \tool_ltidiagnostics\local\evidence_query
 */
final class sections_evidence_test extends \externallib_advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\lti_data;

    /** @var int Course of the scoped observation. */
    private $courseid = 0;

    /**
     * A platform connection (tool type) with observations: two assigned, one unattributed of the same issuer, one of a
     * different issuer.
     *
     * @return connection
     */
    private function platform_with_observations(): connection {
        $typeid = $this->create_platform_type(['name' => 'Tool X']);
        $conn = (new local\inventory())->get_connection('platform:' . $typeid);
        $this->assertNotNull($conn);
        $course = $this->getDataGenerator()->create_course();
        $base = ['connkey' => $conn->key, 'issuerhash' => $conn->facts['issuerhash'] ?? '', 'timecreated' => time() - 60];
        observation_store::record($base + ['eventtype' => 'launch', 'eventname' => 'launch_out', 'direction' => 'out',
            'courseid' => (int) $course->id]);
        observation_store::record($base + ['eventtype' => 'grade', 'eventname' => 'score_in', 'direction' => 'in']);
        observation_store::record(['connkey' => '', 'issuerhash' => $conn->facts['issuerhash'] ?? '', 'eventtype' => 'launch',
            'eventname' => 'unassigned_launch', 'timecreated' => time() - 30]);
        observation_store::record(['connkey' => 'platform:999', 'eventtype' => 'launch', 'eventname' => 'foreign']);
        $this->courseid = (int) $course->id;
        return $conn;
    }

    /**
     * The evidence filters (service, text, attribution, course) run on the server and their totals match the rows.
     */
    public function test_evidence_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $conn = $this->platform_with_observations();
        $q = new evidence_query();
        $all = $q->page($conn, scope::make(0, 0), 'observer', [], time());
        $labels = array_column($all['rows'], 'label');
        $this->assertNotContains('foreign', $labels, 'Never another connection.');
        $this->assertSame(count($all['rows']), $all['total']);
        $assigned = $q->page($conn, scope::make(0, 0), 'observer', ['assign' => 'assigned'], time());
        $this->assertEqualsCanonicalizing(['launch_out', 'score_in'], array_column($assigned['rows'], 'label'));
        $unassigned = $q->page($conn, scope::make(0, 0), 'observer', ['assign' => 'unassigned'], time());
        foreach ($unassigned['rows'] as $row) {
            $this->assertFalse($row['assigned'], 'Unattributed entries are marked as such.');
        }
        $ags = $q->page($conn, scope::make(0, 0), 'observer', ['service' => 'ags'], time());
        $this->assertSame(['score_in'], array_column($ags['rows'], 'label'));
        $this->assertSame('ags', $ags['rows'][0]['service']);
        $this->assertSame('in', $ags['rows'][0]['direction']);
        $text = $q->page($conn, scope::make(0, 0), 'observer', ['text' => 'LAUNCH_O'], time());
        $this->assertSame(['launch_out'], array_column($text['rows'], 'label'));
        $course = $q->page($conn, scope::make($this->courseid, 0), 'observer', [], time());
        $this->assertSame(['launch_out'], array_column($course['rows'], 'label'));
        $paged = $q->page($conn, scope::make(0, 0), 'observer', ['limit' => 1, 'offset' => 1, 'assign' => 'assigned'], time());
        $this->assertCount(1, $paged['rows']);
        $this->assertSame(2, $paged['total']);
        $tasks = $q->page($conn, scope::make($this->courseid, 0), 'tasks', [], time());
        $this->assertFalse($tasks['scoped'], 'Task runs are system wide; the course scope is not applied silently.');
        $this->assertContains('ev_note_tasks_system', $tasks['notes']);
        $this->assertSame('ags', evidence_query::service_of_type('grade'));
        $this->assertSame('other', evidence_query::service_of_type('nonsense'));
    }

    /**
     * The AJAX function renders the same section as the page, records the view, refuses users without the view
     * capability, unknown sections and unknown connections, and ignores foreign parameters.
     */
    public function test_render_section_external(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $conn = $this->platform_with_observations();
        $result = render_section::execute($conn->key, 'evidence', 'view=observer&service=ags&eperpage=20&evil[]=1&X=2');
        $result = \core_external\external_api::clean_returnvalue(render_section::execute_returns(), $result);
        $this->assertStringContainsString('score_in', $result['html']);
        $this->assertStringNotContainsString('launch_out', $result['html']);
        $this->assertSame(1, $result['total']);
        $this->assertStringContainsString('service=ags', $result['url']);
        $this->assertStringNotContainsString('evil', $result['url']);
        $this->assertTrue($DB->record_exists('tool_ltidiagnostics_audit', ['action' => 'view_evidence', 'detail' => 'ajax']));
        $page = output\sections::render(
            $conn,
            'evidence',
            ['view' => 'observer', 'service' => 'ags', 'eperpage' => '20'],
            scope::make(0, 0)
        );
        $this->assertSame($page['total'], $result['total']);

        foreach (['timeline', 'evidence'] as $section) {
            $r = render_section::execute($conn->key, $section, '');
            $this->assertIsString($r['html']);
        }
        try {
            render_section::execute($conn->key, 'nonsense', '');
            $this->fail('Unknown section accepted.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('section', $e->getMessage());
        }
        try {
            render_section::execute('platform:999999', 'evidence', '');
            $this->fail('Unknown connection accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidconnection', $e->errorcode);
        }
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        render_section::execute($conn->key, 'evidence', '');
    }

    /**
     * Page sizes are limited to 20, 50, 100 and 500; anything else falls back to the default.
     */
    public function test_page_sizes(): void {
        $this->assertSame([20, 50, 100, 500], output\sections::SIZES);
        $this->assertSame(500, output\sections::size(500));
        $this->assertSame(50, output\sections::size(5000));
        $this->assertSame(50, output\sections::size(0));
        $this->assertSame(20, output\sections::size(20));
    }
}
