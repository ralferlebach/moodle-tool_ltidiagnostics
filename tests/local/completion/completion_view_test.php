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

namespace tool_ltidiagnostics\local\completion;

use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/lti_data.php');

/**
 * Completion channel view (issue #7) over the real table schema of local_lticompletionsync (commit 932e5fa).
 *
 * The tables are created from a copy of that plugin's install.xml, so the view is tested without the plugin's code; the
 * plugin's actions are offered only when its code and capability exist (tested with both answers).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\completion\completion_view
 */
final class completion_view_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use lti_data;

    /** @var string[] Tables created by this test. */
    private $created = [];

    /**
     * Open the guard frame (this class has its own tearDown).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->start_deprecation_guard();
    }

    /**
     * Drop the tables this test created, then close the guard frame.
     */
    protected function tearDown(): void {
        global $DB;
        foreach ($this->created as $name) {
            $t = new \xmldb_table($name);
            if ($DB->get_manager()->table_exists($t)) {
                $DB->get_manager()->drop_table($t);
            }
        }
        $this->created = [];
        parent::tearDown();
        $this->check_deprecation_guard();
    }

    /**
     * Create the completion sync tables from the fixture (only those that do not exist).
     */
    private function create_tables(): void {
        global $DB;
        $file = new \xmldb_file(__DIR__ . '/../../fixtures/lticompletionsync_tables.xml');
        $this->assertTrue($file->fileExists() && $file->loadXMLStructure());
        foreach ($file->getStructure()->getTables() as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                $DB->get_manager()->create_table($table);
                $this->created[] = $table->getName();
            }
        }
    }

    /**
     * Outbox row.
     *
     * @param int $con Connection.
     * @param array $f Fields.
     */
    private function out(int $con, array $f): void {
        global $DB;
        static $n = 0;
        $n++;
        $DB->insert_record('local_lticompletionsync_out', (object) ($f + [
            'connectionid' => $con, 'resourcelinkid' => 1, 'toolid' => 1, 'userid' => 100 + $n, 'sourcekind' => 'cm',
            'sourceid' => 5, 'desiredstate' => 1, 'version' => 1, 'statetime' => time(), 'correlationid' => 'c-' . $n,
            'status' => 'pending', 'timecreated' => time(), 'timemodified' => time() + $n,
        ]));
    }

    /**
     * Without the plugin's tables the view says so and stays empty.
     */
    public function test_without_plugin(): void {
        $this->resetAfterTest();
        $reg = $this->create_registration();
        $conn = (new inventory())->get_connection('tool:' . $reg->regid . ':' . $reg->depid);
        $v = (new completion_view())->build($conn);
        // In a tree without local_lticompletionsync (CI) the view is empty; where it is installed, it has no connection.
        $this->assertSame(completion_view::installed(), $v['installed']);
        $this->assertSame([], $v['rows']);
        $this->assertSame([], $v['connections']);
        $this->assertSame([], completion_view::actions('sender', [1], false, fn() => true));
    }

    /**
     * Sender side: every delivery state is told apart, revocations are counted, tool-only AGS progress is flagged.
     */
    public function test_sender_states(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_tables();
        $reg = $this->create_registration();
        $conn = (new inventory())->get_connection('tool:' . $reg->regid . ':' . $reg->depid);
        $con = (int) $DB->insert_record('local_lticompletionsync_con', (object) ['role' => 'sender', 'enabled' => 1,
            'registrationid' => $reg->regid, 'deploymentid' => $reg->depid, 'agsprogress' => 1, 'partnerstatus' => 'plugin',
            'timechecked' => time()]);
        $other = (int) $DB->insert_record('local_lticompletionsync_con', (object) ['role' => 'sender', 'enabled' => 1,
            'registrationid' => $reg->regid + 1000, 'deploymentid' => 1]);
        $this->out($con, ['status' => 'pending']);
        $this->out($con, ['status' => 'sent', 'sentversion' => 1]);
        $this->out($con, ['status' => 'retry', 'attempts' => 2, 'nextattempt' => time() + 60, 'lasterror' => 'http_503']);
        $this->out($con, ['status' => 'acked', 'sentversion' => 1, 'ackversion' => 1, 'ackstatus' => 'applied']);
        $this->out($con, ['status' => 'acked', 'version' => 2, 'sentversion' => 2, 'ackversion' => 1, 'ackstatus' => 'applied']);
        $this->out($con, ['status' => 'acked', 'sentversion' => 1, 'ackversion' => 1, 'ackstatus' => 'received']);
        $this->out($con, ['status' => 'acked', 'sentversion' => 1, 'ackversion' => 1, 'ackstatus' => 'rejected',
            'lasterror' => 'conflict_local']);
        $this->out($con, ['status' => 'blocked', 'lasterror' => 'no_mapping']);
        $this->out($con, ['status' => 'dead', 'attempts' => 8]);
        $this->out($con, ['status' => 'acked', 'desiredstate' => 0, 'version' => 3, 'sentversion' => 3, 'ackversion' => 3,
            'ackstatus' => 'applied']);
        $this->out($con, ['status' => 'mystery']);
        $this->out($other, ['status' => 'dead']);
        $v = (new completion_view())->build($conn);
        $this->assertTrue($v['installed']);
        $this->assertSame('sender', $v['role']);
        $this->assertTrue($v['agsprogress']);
        $summary = $v['summary'];
        ksort($summary);
        $expected = ['pending' => 1, 'sent_tool_only' => 1, 'retry' => 1, 'acked_applied' => 2,
            'acked_unapplied' => 2, 'rejected' => 1, 'blocked' => 1, 'dead' => 1, 'revoked' => 1, 'status_unknown' => 1];
        ksort($expected);
        $this->assertSame($expected, $summary);
        $this->assertSame(11, $v['total'], 'Only this connection.');
        $classes = array_count_values(array_column($v['rows'], 'class'));
        $this->assertSame(1, $classes['sent_tool_only']);
        $this->assertSame(1, $classes['rejected']);
        foreach ($v['rows'] as $r) {
            $this->assertMatchesRegularExpression('/^P-[0-9A-F]{8}$/', $r['person']);
            $this->assertTrue(get_string_manager()->string_exists('cclass_' . $r['class'], 'tool_ltidiagnostics'));
        }
        $revoked = array_values(array_filter($v['rows'], fn($r) => $r['revoked']));
        $this->assertCount(1, $revoked);
        $this->assertSame('acked_applied', $revoked[0]['class']);
    }

    /**
     * Receiver side: received, validated, applied, rejected, conflict; no repair action there.
     */
    public function test_receiver_states(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_tables();
        $typeid = $this->create_platform_type();
        $conn = (new inventory())->get_connection('platform:' . $typeid);
        $con = (int) $DB->insert_record('local_lticompletionsync_con', (object) ['role' => 'receiver', 'enabled' => 1,
            'ltitypeid' => $typeid, 'conflictpolicy' => 'local']);
        foreach (['received', 'validated', 'applied', 'applied', 'rejected', 'conflict', 'odd'] as $i => $status) {
            $DB->insert_record('local_lticompletionsync_rcv', (object) ['connectionid' => $con, 'cmid' => 9, 'userid' => 50 + $i,
                'status' => $status, 'remoteversion' => 2, 'appliedversion' => $status === 'applied' ? 2 : 1,
                'correlationid' => 'r' . $i, 'reason' => $status === 'rejected' ? 'stale' : '']);
        }
        $v = (new completion_view())->build($conn);
        $this->assertSame('receiver', $v['role']);
        $this->assertEqualsCanonicalizing(['received' => 1, 'validated' => 1, 'applied' => 2, 'rejected' => 1,
            'conflict' => 1, 'status_unknown' => 1], $v['summary']);
        $this->assertSame([], completion_view::actions('receiver', [$con], true, fn() => true));
    }

    /**
     * Actions only with the plugin's code and capability; they are the plugin's own, for the whole connection.
     */
    public function test_actions_and_reach(): void {
        $this->resetAfterTest();
        $this->assertSame([], completion_view::actions('sender', [7], false, fn() => true), 'No plugin, no button.');
        $this->assertSame([], completion_view::actions('sender', [7], true, fn() => false), 'No capability, no button.');
        $a = completion_view::actions('sender', [7], true, fn() => true);
        $this->assertSame(['probe', 'retrynow', 'requeue', 'reconcile', 'resync'], array_column($a, 'action'));
        foreach ($a as $action) {
            $this->assertStringEndsWith('/local/lticompletionsync/index.php', $action['url']->get_path(false));
            $this->assertSame(7, (int) $action['url']->param('id'));
            $this->assertNull($action['url']->param('userid'), 'There is no per-learner action.');
            $this->assertTrue(get_string_manager()->string_exists($action['reach'], 'tool_ltidiagnostics'));
        }
        $this->assertStringContainsString('Whole connection, all learners', get_string(
            'creach_resync',
            'tool_ltidiagnostics'
        ));
        $this->assertStringContainsString('existing version', get_string('creach_retrynow', 'tool_ltidiagnostics'));
    }

    /**
     * Read only and bounded: no grade table, no journal, constant reads for many rows.
     */
    public function test_read_only_and_bounded(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_tables();
        $reg = $this->create_registration();
        $conn = (new inventory())->get_connection('tool:' . $reg->regid . ':' . $reg->depid);
        $con = (int) $DB->insert_record('local_lticompletionsync_con', (object) ['role' => 'sender', 'enabled' => 1,
            'registrationid' => $reg->regid, 'deploymentid' => $reg->depid]);
        for ($i = 0; $i < 20; $i++) {
            $this->out($con, ['status' => 'sent']);
        }
        $reads = function () use ($conn, $DB): int {
            $b = $DB->perf_get_reads();
            (new completion_view())->build($conn);
            return $DB->perf_get_reads() - $b;
        };
        // Warm up once: with the real plugin installed the first call loads the capability definitions (one-time cache).
        $reads();
        $small = $reads();
        for ($i = 0; $i < 1500; $i++) {
            $this->out($con, ['status' => $i % 2 ? 'acked' : 'dead', 'ackstatus' => 'applied', 'ackversion' => 1]);
        }
        $writes = $DB->perf_get_writes();
        $this->assertSame($small, $reads());
        $this->assertSame($writes, $DB->perf_get_writes(), 'The view writes nothing.');
        $v = (new completion_view())->build($conn, ['offset' => 100]);
        $this->assertCount(completion_view::PAGESIZE, $v['rows']);
        $this->assertSame(1520, $v['total']);
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_gatt'), 'No grade replay or journal for completion.');
    }
}
