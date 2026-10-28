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

use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/lti_data.php');
require_once(__DIR__ . '/../fixtures/family_events.php');
require_once(__DIR__ . '/../fixtures/family_events_ltisource.php');

/**
 * Integration (gate CS-G6): real log store rows written by fixture events shaped like the transfer events of
 * local_lticompletionsync appear in the log store source, the timeline, the facts, the evidence tab and the observer.
 * The completion sync plugin is not needed (the fixture component is local_ltidiagfixture).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\logstore_source
 * @covers     \tool_ltidiagnostics\local\timeline
 * @covers     \tool_ltidiagnostics\local\fact_collector
 * @covers     \tool_ltidiagnostics\observer
 * @covers     \tool_ltidiagnostics\output\renderer
 */
final class family_logstore_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    use lti_data;

    /** @var string Correlation id of a delivered transfer. */
    private const DELIVERED = '3f2a9c1e-0b4d-4e8f-9a1b-2c3d4e5f6a7b';
    /** @var string Correlation id of a transfer that was given up. */
    private const DEAD = '9b8a7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';

    /**
     * Enable the standard log store without buffer.
     */
    private function enable_standard_logstore(): void {
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * Write the transfer events of two transfers and two unrelated events.
     *
     * @return int User id of the learner.
     */
    private function write_events(): int {
        $user = $this->getDataGenerator()->create_user();
        \local_ltidiagfixture\event\message_queued::fire(self::DELIVERED, $user->id);
        \local_ltidiagfixture\event\message_sent::fire(self::DELIVERED, $user->id, ['jwt' => 'eyJhbGciOiJSUzI1NiJ9.secret']);
        \local_ltidiagfixture\event\message_acknowledged::fire(self::DELIVERED, $user->id, ['code' => 'ok']);
        \local_ltidiagfixture\event\message_queued::fire(self::DEAD, $user->id);
        \local_ltidiagfixture\event\retry_scheduled::fire(self::DEAD, $user->id);
        \local_ltidiagfixture\event\dead_letter::fire(self::DEAD, $user->id, ['code' => 'http_500']);
        \ltisource_diagfixture\event\pseudonym_rotated::create(['context' => \context_system::instance()])->trigger();
        // Not a family event and not in the fixed list: must not be read.
        \core\event\dashboard_viewed::create(['context' => \context_user::instance($user->id)])->trigger();
        return (int) $user->id;
    }

    /**
     * Family events are read by component prefix, with only the allowed values from "other".
     */
    public function test_logstore_reads_family_events(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_standard_logstore();
        $userid = $this->write_events();

        $result = (new logstore_source())->fetch(\context_system::instance());
        $this->assertTrue($result['available']);
        $family = array_values(array_filter($result['rows'], function ($r) {
            return $r['family'];
        }));
        $this->assertCount(7, $family);
        $this->assertSame([], array_filter($result['rows'], function ($r) {
            return $r['eventname'] === '\core\event\dashboard_viewed';
        }), 'Events outside the list and the family are not read.');
        $sent = array_values(array_filter($family, function ($r) {
            return $r['step'] === 'sent';
        }))[0];
        $this->assertSame('local_ltidiagfixture', $sent['component']);
        $this->assertSame(self::DELIVERED, $sent['correlationid']);
        $this->assertSame(7, $sent['connectionid']);
        $this->assertSame(1, $sent['version']);
        $this->assertSame('service', $sent['stage']);
        $this->assertSame('out', $sent['direction']);
        $this->assertSame('', $sent['connkey'], 'Only the completion sync component maps connection ids.');
        $this->assertSame($userid, $sent['userid']);
        $this->assertStringNotContainsString('eyJ', json_encode($result['rows']), 'No other value of "other" is taken over.');
        $rotated = array_values(array_filter($family, function ($r) {
            return $r['component'] === 'ltisource_diagfixture';
        }));
        $this->assertCount(1, $rotated);
        $this->assertSame('', $rotated[0]['correlationid']);
        $this->assertFalse($result['familytruncated']);

        // Switching the family off reads the fixed list only.
        $core = (new logstore_source())->fetch(\context_system::instance(), ['family' => false]);
        $this->assertSame([], array_filter($core['rows'], function ($r) {
            return $r['family'];
        }));
    }

    /**
     * Every query is bounded by time and by the row limit: a window in the past finds nothing, the default window ends
     * now, and a reader receives two queries, each with the time condition and the limit.
     */
    public function test_queries_are_bounded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_standard_logstore();
        $this->write_events();
        $source = new logstore_source();
        // One clock read: a second boundary between building the filter and asserting must not flake the test.
        $to = time() - 2 * DAYSECS;
        $old = $source->fetch(\context_system::instance(), ['to' => $to]);
        $this->assertSame([], $old['rows']);
        $this->assertSame($to - logstore_source::DEFAULT_WINDOW, $old['window']['from']);
        $now = $source->fetch(\context_system::instance());
        $this->assertEqualsWithDelta(time() - logstore_source::DEFAULT_WINDOW, $now['window']['from'], 5);
        $limited = $source->fetch(\context_system::instance(), ['limit' => 2]);
        $this->assertTrue($limited['familytruncated']);
        $this->assertLessThanOrEqual(4, count($limited['rows']), 'At most "limit" rows per query.');

        $calls = new \ArrayObject();
        $reader = new class ($calls) {
            /** @var \ArrayObject Calls. */
            private $calls;

            /**
             * Constructor.
             *
             * @param \ArrayObject $calls Calls.
             */
            public function __construct(\ArrayObject $calls) {
                $this->calls = $calls;
            }

            /**
             * Logging.
             *
             * @return bool
             */
            public function is_logging(): bool {
                return true;
            }

            /**
             * Record the query.
             *
             * @param string $where Where.
             * @param array $params Params.
             * @param string $sort Sort.
             * @param int $from Offset.
             * @param int $num Limit.
             * @return array
             */
            public function get_events_select($where, array $params, $sort, $from, $num): array {
                $this->calls[] = [$where, $params, $sort, $num];
                return [];
            }
        };
        $double = new class (['r' => $reader]) extends logstore_source {
            /** @var array Readers. */
            private $fake;

            /**
             * Constructor.
             *
             * @param array $fake Readers.
             */
            public function __construct(array $fake) {
                $this->fake = $fake;
            }

            /**
             * Readers.
             *
             * @return array
             */
            public function get_readers(): array {
                return $this->fake;
            }
        };
        $double->fetch(\context_system::instance(), ['limit' => 50]);
        $this->assertCount(2, $calls);
        foreach ($calls as [$where, $params, $sort, $num]) {
            $this->assertStringContainsString('timecreated >= :tfrom', $where);
            $this->assertGreaterThan(0, $params['tfrom']);
            $this->assertSame('timecreated DESC', $sort);
            $this->assertSame(50, $num);
        }
        $this->assertStringContainsString('component', $calls[1][0]);
        // Escaped prefix plus at least one more character: "local_lti" itself or "ltisource_" alone never match.
        $this->assertContains('local\_lti_%', $calls[1][1]);
        $this->assertContains('ltisource\__%', $calls[1][1]);
    }

    /**
     * The timeline of a connection shows the transfers, grouped by correlation id, linked across hours, with outcome.
     */
    public function test_timeline_shows_transfers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_standard_logstore();
        $this->write_events();
        $reg = $this->create_registration();
        $c = (new inventory())->get_connection("tool:{$reg->regid}:{$reg->depid}");
        $tl = (new timeline())->build($c, \context_system::instance(), [], time());

        $tx = array_column($tl['transactions'], null, 'correlationid');
        $this->assertArrayHasKey(self::DELIVERED, $tx);
        $this->assertSame(['queued', 'sent', 'acknowledged'], $tx[self::DELIVERED]['steps']);
        $this->assertSame('acknowledged', $tx[self::DELIVERED]['outcome']);
        $this->assertSame('failed', $tx[self::DEAD]['outcome']);
        $this->assertSame(1, $tx[self::DEAD]['retries']);
        $this->assertCount(2, $tx, 'An event without correlation id is no transfer.');

        $family = array_filter($tl['items'], function ($i) {
            return !empty($i['family']);
        });
        $this->assertCount(7, $family);
        $direct = array_filter($tl['edges'], function ($e) use ($tl) {
            return $e['confidence'] === correlation::DIRECT
                && ($tl['items'][$e['from']]['correlationid'] ?? '') === self::DELIVERED;
        });
        $this->assertNotEmpty($direct, 'The steps of one transfer are linked by the correlation id.');

        $html = $this->get_renderer()->timeline($tl);
        $this->assertStringContainsString('id="tool_ltidiagnostics_transactions"', $html);
        $this->assertStringContainsString(self::DELIVERED, $html);
        $this->assertStringContainsString('data-outcome="failed"', $html);
        $this->assertStringContainsString('queued &gt; sent &gt; acknowledged', $html);
        $this->assertStringNotContainsString('&amp;gt;', $html, 'Escaped once (found on the stack: double escaping).');
    }

    /**
     * The facts of a tool connection contain the newest sent and acknowledged times; the evidence tab lists the events.
     */
    public function test_facts_and_evidence(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_standard_logstore();
        $this->write_events();
        $reg = $this->create_registration();
        $c = (new inventory())->get_connection("tool:{$reg->regid}:{$reg->depid}");
        $facts = (new fact_collector())->collect($c, time());
        $this->assertGreaterThan(0, $facts['family']['sent']);
        $this->assertGreaterThan(0, $facts['family']['acknowledged']);
        $this->assertGreaterThan(0, $facts['family']['dead_letter']);
        $this->assertArrayNotHasKey('completion_applied', $facts['family']);

        $platform = (new inventory())->get_connection('platform:' . $this->create_platform_type());
        $pfacts = (new fact_collector())->collect($platform, time());
        $this->assertArrayNotHasKey('sent', $pfacts['family'], 'Outbound steps of an unresolved connection do not count '
            . 'for a platform connection.');

        $html = $this->get_renderer()->evidence_tab($c, [], time());
        $this->assertStringContainsString('id="tool_ltidiagnostics_familyevents"', $html);
        $this->assertStringContainsString(self::DEAD, $html);
        $this->assertStringContainsString('http_500', $html);
    }

    /**
     * The own observer records a family transfer event with its correlation id, and nothing for a family event without.
     */
    public function test_observer_records_correlation_id(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('recordevents', 1, 'tool_ltidiagnostics');
        $event = \local_ltidiagfixture\event\http_received::create(['context' => \context_system::instance(),
            'other' => ['correlationid' => self::DELIVERED, 'connectionid' => 7, 'version' => 2, 'secret' => 'x']]);
        $data = \tool_ltidiagnostics\observer::build($event);
        $this->assertSame(observation_store::FAMILY_EVENT, $data['eventtype']);
        $this->assertSame('in', $data['direction']);
        $this->assertSame(self::DELIVERED, $data['correlationid']);
        \tool_ltidiagnostics\observer::handle($event);
        $row = $DB->get_record('tool_ltidiagnostics_obs', ['correlationid' => self::DELIVERED], '*', MUST_EXIST);
        $this->assertSame('local_ltidiagfixture', $row->component);
        $this->assertSame('["connectionid","correlationid","secret","version"]', $row->fieldnames, 'Names only, no values.');

        $rotated = \ltisource_diagfixture\event\pseudonym_rotated::create(['context' => \context_system::instance()]);
        $this->assertNull(\tool_ltidiagnostics\observer::build($rotated));
    }

    /**
     * The observer registration on the base class of the completion sync events is accepted when that plugin is absent:
     * events.php only names the class, nothing loads it.
     */
    public function test_observer_registration_is_inert(): void {
        global $CFG;
        $observers = [];
        include($CFG->dirroot . '/admin/tool/ltidiagnostics/db/events.php');
        $names = array_column($observers, 'eventname');
        $this->assertContains('\local_lticompletionsync\event\sync_event', $names);
        $this->assertCount(count(array_unique($names)), $names);
        $this->assertSame([], array_filter($names, function ($n) {
            return strpos($n, '*') !== false || $n === '\core\event\base';
        }), 'No catch-all observer.');
    }

    /**
     * The renderer.
     *
     * @return \tool_ltidiagnostics\output\renderer
     */
    private function get_renderer(): \tool_ltidiagnostics\output\renderer {
        global $PAGE;
        $PAGE->set_url('/admin/tool/ltidiagnostics/connection.php');
        return $PAGE->get_renderer('tool_ltidiagnostics');
    }
}
