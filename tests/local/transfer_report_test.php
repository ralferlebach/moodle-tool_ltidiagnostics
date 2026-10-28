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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/family_events.php');

/**
 * Completion sync transaction reports (audit item DIA-05): the path source event -> outbox -> task -> HTTP/JWT ->
 * receiver -> native completion -> ACK / retry / revocation per correlation id, one-sided and with a partner trace,
 * masked persons, the no-partner mode labelled as limited, failed transfers, and the validation of imported traces.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\transfer_report
 * @covers     \tool_ltidiagnostics\local\transfer_service
 */
final class transfer_report_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var int Fixed time. */
    private const NOW = 1790000000;

    /**
     * Connection.
     *
     * @param string $role Role.
     * @return connection
     */
    private function conn(string $role): connection {
        $c = new connection();
        $c->role = $role;
        $c->key = $role === connection::ROLE_PLATFORM ? 'platform:3' : 'tool:1:1';
        return $c;
    }

    /**
     * Item.
     *
     * @param string $cid Correlation id.
     * @param string $step Step.
     * @param int $offset Seconds after NOW.
     * @param string $code Code.
     * @param int $userid User.
     * @return array
     */
    private function item(string $cid, string $step, int $offset, string $code = '', int $userid = 42): array {
        return ['time' => self::NOW + $offset, 'step' => $step, 'correlationid' => $cid, 'code' => $code, 'contextid' => 99,
            'userid' => $userid, 'source' => 'logstore'];
    }

    /**
     * Native completion stub.
     *
     * @param int $state State to report.
     * @return callable
     */
    private function native(int $state): callable {
        return function (int $contextid, int $userid, int $time) use ($state): ?array {
            return ['state' => $state, 'time' => $time];
        };
    }

    /**
     * Sender items of a successful transfer.
     *
     * @param string $cid Correlation id.
     * @return array[]
     */
    private function sender_items(string $cid): array {
        return [$this->item($cid, 'source_detected', 0), $this->item($cid, 'queued', 1), $this->item($cid, 'sent', 5, 'attempt:1'),
            $this->item($cid, 'acknowledged', 6, 'applied')];
    }

    /**
     * Receiver items of a successful transfer.
     *
     * @param string $cid Correlation id.
     * @return array[]
     */
    private function receiver_items(string $cid): array {
        return [$this->item($cid, 'http_received', 7), $this->item($cid, 'identity_resolved', 7, 'direct'),
            $this->item($cid, 'completion_applied', 8, 'applied')];
    }

    /**
     * One side only: the sender sees its half; the report is "limited" and names the partner stages as not observable.
     * The task run that contains the send is linked by time containment ("strong"), never by guess.
     */
    public function test_sender_one_sided_is_limited(): void {
        $collected = ['items' => $this->sender_items('c1'), 'taskruns' => [['start' => self::NOW + 4, 'end' => self::NOW + 6,
            'classname' => '\local_lticompletionsync\task\dispatch_task', 'failed' => false]],
            'logstore' => ['available' => true, 'truncated' => false]];
        $r = transfer_report::build($this->conn(connection::ROLE_TOOL), $collected, null, $this->native(1));
        $this->assertSame('sender', $r['side']);
        $this->assertSame('one_sided', $r['mode']);
        $this->assertSame(['partner_side_not_observable'], $r['limits']);
        $tx = $r['transactions'][0];
        $this->assertSame('limited_one_side', $tx['verdict']);
        $this->assertSame('seen', $tx['stages']['task']['state']);
        $this->assertSame(correlation::STRONG, $tx['stages']['task']['local']['confidence']);
        $this->assertSame('not_observable', $tx['stages']['receiver']['state']);
        $this->assertSame('not_observable', $tx['stages']['native_completion']['state']);
        // No task run around the send: the task stage stays missing (no invented correlation).
        $collected['taskruns'] = [['start' => self::NOW + 100, 'end' => self::NOW + 101, 'classname' => 'x', 'failed' => false]];
        $r2 = transfer_report::build($this->conn(connection::ROLE_TOOL), $collected, null, $this->native(1));
        $this->assertSame('missing', $r2['transactions'][0]['stages']['task']['state']);
    }

    /**
     * Both sides: the receiver imports the trace of the sender; the same correlation id joins the halves; the native
     * completion record is checked; the clock difference of the two sites is estimated from the HTTP stage.
     */
    public function test_two_sided_complete(): void {
        $sender = transfer_report::build($this->conn(connection::ROLE_TOOL), ['items' => $this->sender_items('c2'),
            'taskruns' => [], 'logstore' => ['available' => true]], null, $this->native(1));
        $trace = transfer_report::parse_trace(json_encode(transfer_report::export_trace(
            $this->conn(connection::ROLE_TOOL),
            $sender,
            self::NOW
        )));
        $r = transfer_report::build($this->conn(connection::ROLE_PLATFORM), ['items' => $this->receiver_items('c2'),
            'taskruns' => [], 'logstore' => ['available' => true]], $trace, $this->native(1));
        $this->assertSame('two_sided', $r['mode']);
        $this->assertSame([], $r['limits']);
        $tx = $r['transactions'][0];
        $this->assertSame('complete', $tx['verdict']);
        $this->assertSame('match', $tx['stages']['native_completion']['local']['native']);
        $this->assertNotNull($tx['stages']['outbox']['partner']);
        $this->assertSame(2, $tx['skew']);
        // The native record says "not complete": the transfer fails although the receiver reported "applied".
        $bad = transfer_report::build($this->conn(connection::ROLE_PLATFORM), ['items' => $this->receiver_items('c2'),
            'taskruns' => [], 'logstore' => ['available' => true]], $trace, $this->native(0));
        $this->assertSame('failed', $bad['transactions'][0]['verdict']);
        $this->assertSame('mismatch', $bad['transactions'][0]['stages']['native_completion']['local']['native']);
    }

    /**
     * Failed transfers: dead letter on the sender, rejection on the receiver; retries are counted.
     */
    public function test_failed_transfers(): void {
        $items = [$this->item('c3', 'queued', 0), $this->item('c3', 'sent', 1), $this->item('c3', 'retry_scheduled', 2, 'http:503'),
            $this->item('c3', 'sent', 60), $this->item('c3', 'retry_scheduled', 61, 'http:503'),
            $this->item('c3', 'dead_letter', 200, 'max_attempts')];
        $r = transfer_report::build($this->conn(connection::ROLE_TOOL), ['items' => $items, 'taskruns' => [],
            'logstore' => ['available' => true]], null, $this->native(1));
        $tx = $r['transactions'][0];
        $this->assertSame('failed', $tx['verdict']);
        $this->assertSame(2, $tx['retries']);
        $this->assertSame('failed', $tx['stages']['ack']['state']);
        $rejected = [$this->item('c4', 'http_received', 0), $this->item('c4', 'reconciled', 1, 'rejected:identity_unresolved')];
        $r2 = transfer_report::build($this->conn(connection::ROLE_PLATFORM), ['items' => $rejected, 'taskruns' => [],
            'logstore' => ['available' => true]], null, $this->native(0));
        $this->assertSame('failed', $r2['transactions'][0]['verdict']);
    }

    /**
     * No-partner mode of local_lticompletionsync (AGS progress only): always labelled as limited.
     */
    public function test_no_partner_mode_is_limited(): void {
        $items = [$this->item('c5', 'queued', 0), $this->item('c5', 'sent', 1),
            $this->item('c5', 'acknowledged', 2, 'tool_only_no_confirmation')];
        $r = transfer_report::build($this->conn(connection::ROLE_TOOL), ['items' => $items, 'taskruns' => [],
            'logstore' => ['available' => true]], null, $this->native(1));
        $this->assertSame('limited_no_partner_mode', $r['transactions'][0]['verdict']);
    }

    /**
     * Persons are masked: no user id in the report or the trace; the mask is stable per connection and differs between
     * connections. Revocation is a stage of its own.
     */
    public function test_masking_and_revocation(): void {
        $this->resetAfterTest();
        $items = array_merge($this->receiver_items('c6'), [$this->item('c6', 'completion_revoked', 50)]);
        $r = transfer_report::build($this->conn(connection::ROLE_PLATFORM), ['items' => $items, 'taskruns' => [],
            'logstore' => ['available' => true]], null, $this->native(0));
        $tx = $r['transactions'][0];
        $this->assertMatchesRegularExpression('/^P-[0-9A-F]{8}$/', $tx['person']);
        $this->assertSame(transfer_report::mask_person(42, 'platform:3'), $tx['person']);
        $this->assertNotSame(transfer_report::mask_person(42, 'platform:4'), $tx['person']);
        $this->assertSame('seen', $tx['stages']['revocation']['state']);
        $this->assertSame('match', $tx['stages']['revocation']['local']['native'], 'Revoked: native record not complete.');
        $trace = json_encode(transfer_report::export_trace($this->conn(connection::ROLE_PLATFORM), $r, self::NOW));
        $this->assertStringNotContainsString('"userid"', $trace);
        $this->assertStringNotContainsString($tx['person'], $trace, 'The masked person does not leave the site either.');
    }

    /**
     * Imported traces are validated: wrong schema and size are refused; unknown stages, bad ids and odd codes are dropped.
     */
    public function test_trace_validation(): void {
        $trace = ['schema' => transfer_report::SCHEMA, 'role' => 'tool', 'diagnosticid' => 'LTD-1', 'transactions' => [
            'good-1' => ['stages' => ['outbox' => ['time' => 5, 'step' => 'queued', 'code' => 'x<script>'],
                'evil' => ['time' => 1]]],
            'bad id with spaces' => ['stages' => []],
        ]];
        $p = transfer_report::parse_trace(json_encode($trace));
        $this->assertSame(['good-1'], array_keys($p['transactions']));
        $this->assertSame(['outbox'], array_keys($p['transactions']['good-1']['stages']));
        $this->assertSame('xscript', $p['transactions']['good-1']['stages']['outbox']['code']);
        foreach (['{"schema":"x","role":"tool"}', str_repeat(' ', transfer_report::MAXBYTES + 1)] as $bad) {
            try {
                transfer_report::parse_trace($bad);
                $this->fail('Refused expected');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidpartnertrace', $e->errorcode);
            }
        }
    }

    /**
     * Integration: real log store rows of transfer events (fixture component with the completion sync contract), real
     * task log rows, the service with an imported partner trace, the Markdown export and the audit.
     */
    public function test_collect_from_real_log_store(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $user = $this->getDataGenerator()->create_user();
        \local_ltidiagfixture\event\message_queued::fire('cid-real', (int) $user->id);
        \local_ltidiagfixture\event\message_sent::fire('cid-real', (int) $user->id, ['code' => 'attempt:1']);
        \local_ltidiagfixture\event\message_acknowledged::fire('cid-real', (int) $user->id, ['detail' => 'applied']);
        $now = time();
        $DB->insert_record('task_log', (object) ['type' => 1, 'component' => 'local_lticompletionsync',
            'classname' => '\local_lticompletionsync\task\dispatch_task', 'userid' => 0, 'timestart' => $now - 5,
            'timeend' => $now + 5, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 0, 'output' => '', 'hostname' => 'h', 'pid' => 1]);
        $c = $this->conn(connection::ROLE_TOOL);
        $collected = transfer_report::collect($c, \context_system::instance(), [], $now + 10);
        $steps = array_column($collected['items'], 'step');
        sort($steps);
        $this->assertSame(['acknowledged', 'queued', 'sent'], $steps);
        $codes = array_column($collected['items'], 'code', 'step');
        $this->assertSame('applied', $codes['acknowledged'], 'The "detail" of completion sync is read as the code.');
        $this->assertCount(1, $collected['taskruns']);
        $report = transfer_report::build($c, $collected);
        $this->assertSame('limited_one_side', $report['transactions'][0]['verdict']);
        $this->assertSame('seen', $report['transactions'][0]['stages']['task']['state']);
        $md = transfer_report::to_markdown($report, $c, $now);
        $this->assertStringContainsString('cid-real', $md);
        $this->assertStringNotContainsString((string) $user->id . ' |', $md);
        // Through the service: import a partner trace, then the report is two-sided.
        $partner = ['schema' => transfer_report::SCHEMA, 'role' => 'platform', 'diagnosticid' => 'LTD-P',
            'transactions' => ['cid-real' => ['stages' => ['http_jwt' => ['time' => $now, 'step' => 'http_received', 'code' => ''],
                'receiver' => ['time' => $now, 'step' => 'identity_resolved', 'code' => 'direct'],
                'native_completion' => ['time' => $now, 'step' => 'completion_applied', 'code' => 'applied',
                'native' => 'match']]]]];
        $this->assertSame(1, transfer_service::import_trace($c, json_encode($partner), $now));
        $two = transfer_service::report($c, [], $now + 10);
        $this->assertSame('two_sided', $two['mode']);
        $this->assertSame('complete', $two['transactions'][0]['verdict']);
        $this->assertTrue($DB->record_exists('tool_ltidiagnostics_audit', ['action' => 'trace_import']));
    }
}
