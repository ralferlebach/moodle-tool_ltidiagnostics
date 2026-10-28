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

use tool_ltidiagnostics\local\accesslog\parser;

/**
 * Unit tests of the LTI family events (gate CS-G6): recognition by prefix, sanitising, classification, connection
 * mapping, grouping into transactions, correlation by correlation id, observability.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\family_events
 * @covers     \tool_ltidiagnostics\local\correlation
 * @covers     \tool_ltidiagnostics\local\timeline
 * @covers     \tool_ltidiagnostics\local\observability
 */
final class family_events_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * Family members are recognised by prefix, nothing else.
     *
     * @dataProvider component_provider
     * @param string $component Component.
     * @param bool $family Expected.
     */
    public function test_is_family_component(string $component, bool $family): void {
        $this->assertSame($family, family_events::is_family_component($component));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function component_provider(): array {
        return [
            ['local_lticompletionsync', true],
            ['local_ltiidentity', true],
            ['local_ltiaccountmerge', true],
            ['local_ltixsembed', true],
            ['ltisource_scopedidentity', true],
            ['local_ltinewmember', true],
            ['local_lti', false],
            ['ltisource_', false],
            ['tool_ltidiagnostics', false],
            ['mod_lti', false],
            ['enrol_lti', false],
            ['local_other', false],
            ['core', false],
        ];
    }

    /**
     * Only correlation id, connection id, version and code are taken; anything else is dropped; invalid ids are empty.
     */
    public function test_sanitise_other(): void {
        $clean = family_events::sanitise_other(['correlationid' => '3f2a9c1e-0b4d-4e8f-9a1b-2c3d4e5f6a7b',
            'connectionid' => '7', 'version' => 3, 'code' => 'ok', 'jwt' => 'eyJhbGciOi.secret', 'token' => 'abc']);
        $this->assertSame(['correlationid' => '3f2a9c1e-0b4d-4e8f-9a1b-2c3d4e5f6a7b', 'connectionid' => 7, 'version' => 3,
            'code' => 'ok'], $clean);
        $this->assertSame('', family_events::sanitise_other(['correlationid' => str_repeat('a', 65)])['correlationid']);
        $this->assertSame('scriptalert1script', family_events::sanitise_other(
            ['correlationid' => '<script>alert(1)</script>']
        )['correlationid']);
        $this->assertSame(
            ['correlationid' => '', 'connectionid' => 0, 'version' => 0, 'code' => ''],
            family_events::sanitise_other(null)
        );
        $this->assertSame(0, family_events::sanitise_other(['connectionid' => -4])['connectionid']);
        $this->assertSame('', family_events::sanitise_other(['correlationid' => ['x']])['correlationid']);
    }

    /**
     * The eleven standard events of spec 08 section 7 are known, in the right stage and direction.
     */
    public function test_classify_completion_steps(): void {
        $spec = ['source_detected', 'queued', 'sent', 'http_received', 'identity_resolved', 'completion_applied',
            'completion_revoked', 'acknowledged', 'retry_scheduled', 'dead_letter', 'reconciled'];
        $steps = [];
        foreach (array_keys(family_events::COMPLETION_STEPS) as $class) {
            $c = family_events::classify('\local_lticompletionsync\event\\' . $class);
            $this->assertTrue($c['known']);
            $this->assertContains($c['stage'], timeline::STAGES);
            $steps[] = $c['step'];
        }
        $this->assertEqualsCanonicalizing($spec, $steps);
        $this->assertSame('out', family_events::classify('\x\event\message_sent')['direction']);
        $this->assertSame('in', family_events::classify('\x\event\completion_applied')['direction']);
        $this->assertSame('service', family_events::classify('\x\event\http_received')['stage']);
        $unknown = family_events::classify('\local_ltinew\event\something_happened');
        $this->assertFalse($unknown['known']);
        $this->assertSame('something_happened', $unknown['step']);
        $this->assertSame('followup', $unknown['stage']);
    }

    /**
     * Connection ids of the completion sync plugin map to connection keys; nothing else does.
     */
    public function test_connection_key(): void {
        $rows = [
            1 => (object) ['role' => 'receiver', 'ltitypeid' => 5, 'registrationid' => 0, 'deploymentid' => 0],
            2 => (object) ['role' => 'sender', 'ltitypeid' => 0, 'registrationid' => 3, 'deploymentid' => 4],
            3 => (object) ['role' => 'sender', 'ltitypeid' => 0, 'registrationid' => 3, 'deploymentid' => 0],
        ];
        $lookup = function (int $id) use ($rows) {
            return $rows[$id] ?? null;
        };
        $cs = family_events::COMPLETIONSYNC;
        $this->assertSame('platform:5', family_events::connection_key($cs, 1, $lookup));
        $this->assertSame('tool:3:4', family_events::connection_key($cs, 2, $lookup));
        $this->assertSame('', family_events::connection_key($cs, 3, $lookup), 'incomplete row');
        $this->assertSame('', family_events::connection_key($cs, 9, $lookup), 'unknown id');
        $this->assertSame('', family_events::connection_key($cs, 0, $lookup));
        $this->assertSame('', family_events::connection_key('local_ltiother', 1, $lookup), 'other plugin');
    }

    /**
     * Without the completion sync tables the mapping is empty and does not fail.
     */
    public function test_connection_key_without_table(): void {
        $this->resetAfterTest();
        family_events::reset_cache();
        $this->assertSame('', family_events::connection_key(family_events::COMPLETIONSYNC, 999999));
        $this->assertFalse(family_events::emitter_installed('local_ltinotinstalled'));
        family_events::reset_cache();
    }

    /**
     * Events are grouped by correlation id; outcome, retries and the order of steps.
     */
    public function test_transactions(): void {
        $cs = family_events::COMPLETIONSYNC;
        $items = [
            ['time' => 100, 'step' => 'queued', 'correlationid' => 'A', 'component' => $cs, 'source' => 'logstore'],
            ['time' => 110, 'step' => 'sent', 'correlationid' => 'A', 'component' => $cs, 'source' => 'logstore'],
            ['time' => 110, 'step' => 'sent', 'correlationid' => 'A', 'component' => $cs, 'source' => 'observer'],
            ['time' => 120, 'step' => 'acknowledged', 'correlationid' => 'A', 'component' => $cs, 'source' => 'logstore'],
            ['time' => 200, 'step' => 'queued', 'correlationid' => 'B', 'component' => $cs],
            ['time' => 210, 'step' => 'retry_scheduled', 'correlationid' => 'B', 'component' => $cs],
            ['time' => 220, 'step' => 'retry_scheduled', 'correlationid' => 'B', 'component' => $cs],
            ['time' => 230, 'step' => 'dead_letter', 'correlationid' => 'B', 'component' => $cs],
            ['time' => 240, 'step' => 'reconciled', 'correlationid' => 'B', 'component' => $cs],
            ['time' => 300, 'step' => 'http_received', 'correlationid' => 'C', 'component' => $cs],
            ['time' => 305, 'step' => 'completion_applied', 'correlationid' => 'C', 'component' => $cs],
            ['time' => 400, 'step' => 'queued', 'correlationid' => 'D', 'component' => $cs],
            ['time' => 500, 'step' => 'pseudonym', 'correlationid' => '', 'component' => 'ltisource_x'],
        ];
        $tx = family_events::transactions(array_reverse($items));
        $this->assertSame(['D', 'C', 'B', 'A'], array_column($tx, 'correlationid'), 'newest first, no empty id');
        $byid = array_column($tx, null, 'correlationid');
        $this->assertSame(['queued', 'sent', 'acknowledged'], $byid['A']['steps'], 'the same step from two sources once');
        $this->assertSame('acknowledged', $byid['A']['outcome']);
        $this->assertSame(['logstore', 'observer'], $byid['A']['sources']);
        $this->assertSame([100, 120], [$byid['A']['first'], $byid['A']['last']]);
        $this->assertSame('failed', $byid['B']['outcome'], 'a dead letter is not hidden by a later step');
        $this->assertSame(2, $byid['B']['retries']);
        $this->assertSame('applied', $byid['C']['outcome']);
        $this->assertSame('open', $byid['D']['outcome']);
    }

    /**
     * The same correlation id is a direct correlation whatever the time difference; different or empty ids are not.
     */
    public function test_correlation_by_correlation_id(): void {
        $a = ['time' => 1000, 'traceid' => '', 'correlationid' => 'X1', 'refs' => []];
        $b = ['time' => 1000 + 3 * HOURSECS, 'traceid' => '', 'correlationid' => 'X1', 'refs' => []];
        $this->assertSame(correlation::DIRECT, correlation::classify($a, $b));
        $this->assertSame(correlation::UNMATCHED, correlation::classify($a, ['correlationid' => 'X2'] + $b));
        $empty = ['correlationid' => ''];
        $this->assertSame(correlation::TENTATIVE, correlation::classify($empty + $a, $empty + $a));
        $this->assertFalse(correlation::same_correlation(['correlationid' => ''], ['correlationid' => '']));
        $this->assertFalse(correlation::same_correlation([], []));
    }

    /**
     * Timeline edges: a sent message and its acknowledgement three hours later are linked; without the id they are not.
     */
    public function test_edges_follow_the_correlation_id(): void {
        $sent = ['source' => 'logstore', 'time' => 1000, 'stage' => 'service', 'traceid' => '', 'correlationid' => 'X1',
            'refs' => []];
        $ack = ['source' => 'logstore', 'time' => 1000 + 3 * HOURSECS, 'stage' => 'followup', 'traceid' => '',
            'correlationid' => 'X1', 'refs' => []];
        $edges = timeline::edges([$sent, $ack]);
        $this->assertSame([['from' => 0, 'to' => 1, 'confidence' => correlation::DIRECT]], $edges);
        $this->assertSame([], timeline::edges([['correlationid' => ''] + $sent, ['correlationid' => ''] + $ack]));
    }

    /**
     * Observability of the completion sync transaction: not observable without the plugin (the cell names it),
     * recorded with it; a seen time only when an event was seen.
     */
    public function test_observability_of_completion_sync(): void {
        $tool = new connection();
        $tool->role = connection::ROLE_TOOL;
        $tool->key = 'tool:3:4';
        $platform = new connection();
        $platform->role = connection::ROLE_PLATFORM;
        $platform->key = 'platform:5';

        $rows = observability::build($tool, [], [], observability::family_sources(true, true, false));
        $cell = $rows['completion_sync']['request'];
        $this->assertSame(observability::NONE, $cell['level']);
        $this->assertSame(observability::RECORDED, $cell['potential']);
        $this->assertSame(['completionsync'], $cell['needs']);
        $this->assertSame(observability::OUTBOUND, $rows['completion_sync']['direction']);

        $facts = ['family' => ['sent' => 500, 'acknowledged' => 510]];
        $rows = observability::build($tool, $facts, [], observability::family_sources(false, true, true));
        $this->assertSame(observability::RECORDED, $rows['completion_sync']['request']['level']);
        $this->assertSame(500, $rows['completion_sync']['request']['seen']);
        $this->assertSame(510, $rows['completion_sync']['response']['seen']);
        $this->assertSame(['familylog'], $rows['completion_sync']['request']['basis']);
        $this->assertSame(['recordevents'], $rows['completion_sync']['request']['needs']);
        $this->assertTrue($rows['completion_sync']['capable']);

        $enabled = [observability::SRC_ACCESSLOG => false] + observability::family_sources(false, false, true);
        $rows = observability::build($platform, [], [], $enabled);
        $cell = $rows['completion_sync']['request'];
        $this->assertSame(observability::RECORDED, $cell['potential']);
        $this->assertSame(observability::NONE, $cell['level'], 'no log store, no recording, no access log');
        $this->assertEqualsCanonicalizing(['recordevents', 'logstore', 'accesslog'], $cell['needs']);
        $this->assertSame(observability::INBOUND, $rows['completion_sync']['direction']);
        $rows = observability::build($platform, [], [], [observability::SRC_ACCESSLOG => true] + $enabled);
        $this->assertSame(observability::TRANSPORT, $rows['completion_sync']['request']['level'], 'web server log only');
        $this->assertSame(0, $cell['seen']);
    }

    /**
     * The completion endpoint is a declared access log category.
     */
    public function test_completion_endpoint_category(): void {
        $this->assertSame('completion_sync', parser::categorise('/local/lticompletionsync/endpoint.php'));
        $this->assertContains('completion_sync', parser::CATEGORIES);
    }
}
