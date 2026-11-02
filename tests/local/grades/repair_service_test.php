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
 * Grade replay (issues #5 and #9): switches, dry run, target binding, unchanged score, timestamps, errors, retries,
 * locking and what must never change.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\grades\repair_service
 * @covers     \tool_ltidiagnostics\local\grades\target_resolver
 * @covers     \tool_ltidiagnostics\task\grade_repair_task
 */
final class repair_service_test extends \lti_advantage_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use \tool_ltidiagnostics\tests\grade_scenario;

    /** @var int Fixed clock. */
    private $now;

    /**
     * Scenario with the replay switched on for the BUW connection.
     *
     * @param bool $enable Switch the module on and opt in both connections.
     * @return \stdClass
     */
    private function setup_replay(bool $enable = true): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->build_grade_scenario();
        if ($enable) {
            set_config('graderepair', 1, 'tool_ltidiagnostics');
            repair_service::set_opt_in($s->keyb, true);
            repair_service::set_opt_in($s->keya, true);
        }
        $this->now = time();
        $s->conna = (new inventory())->get_connection($s->keya);
        $s->connb = (new inventory())->get_connection($s->keyb);
        return $s;
    }

    /**
     * Service with a scripted transport and a fixed clock.
     *
     * @param fake_ags_transport $t Transport.
     * @return repair_service
     */
    private function service(fake_ags_transport $t): repair_service {
        return new repair_service($t, null, fn() => $this->now);
    }

    /**
     * Snapshot of everything a replay must never change.
     *
     * @return array
     */
    private function protected_state(): array {
        global $DB;
        return [
            'grades' => $DB->get_records('grade_grades', null, 'id', 'id, itemid, userid, finalgrade, rawgrade, timemodified,
                overridden, locked'),
            'ltiusers' => $DB->get_records('enrol_lti_users', null, 'id', 'id, userid, toolid, lastgrade, sourceid,
                ltideploymentid'),
            'links' => $DB->get_records('enrol_lti_resource_link', null, 'id'),
            'completion' => $DB->get_records('course_modules_completion', null, 'id'),
        ];
    }

    /**
     * Off by default; a preview writes and sends nothing.
     */
    public function test_disabled_by_default_and_dry_run_is_inert(): void {
        global $DB;
        $s = $this->setup_replay(false);
        $t = new fake_ags_transport();
        $before = [$DB->count_records(journal::TABLE), $DB->count_records('task_adhoc'), $this->protected_state()];
        $p = $this->service($t)->preview($s->connb, $s->linkb1, (int) $s->users->l1->id);
        $this->assertFalse($p['enabled']);
        $this->assertSame([], $p['blockers']);
        $after = [$DB->count_records(journal::TABLE), $DB->count_records('task_adhoc'), $this->protected_state()];
        $this->assertEquals($before, $after);
        $this->assertSame([], $t->calls, 'A preview never contacts the consumer.');
        $r = $this->service($t)->request($s->connb, $s->linkb1, (int) $s->users->l1->id, $p['fingerprint'], 'Test', 2);
        $this->assertFalse($r['ok']);
        $this->assertSame('disabled', $r['reason']);
        // Site switch alone is not enough: the connection must opt in.
        set_config('graderepair', 1, 'tool_ltidiagnostics');
        $this->assertFalse(repair_service::enabled_for($s->keyb));
        $this->assertFalse(repair_service::can_repair($s->keyb));
        // Capability: an administrator without the opt-in cannot, a teacher never.
        repair_service::set_opt_in($s->keyb, true);
        $this->assertTrue(repair_service::can_repair($s->keyb));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(repair_service::can_repair($s->keyb));
    }

    /**
     * The unchanged grade goes to exactly one consumer, line item and learner; nothing else changes.
     */
    public function test_replay_to_exactly_one_target(): void {
        global $DB;
        $s = $this->setup_replay();
        $l1 = (int) $s->users->l1->id;
        $t = new fake_ags_transport();
        $svc = $this->service($t);
        $before = $this->protected_state();
        $p = $svc->preview($s->connb, $s->linkb1, $l1);
        $this->assertSame([], $p['blockers']);
        $this->assertSame('linked_login', $p['display']['mapping']);
        $this->assertSame(65.0, (float) $p['display']['grade']);
        $r = $svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'BUW did not receive the grade', 2);
        $this->assertTrue($r['ok'], $r['reason']);
        $this->assertSame('queued', $r['attempt']->state);
        $this->assertSame([], $t->calls, 'A request only queues.');
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\tool_ltidiagnostics\\task\\grade_repair_task']));

        $this->assertSame('http_accepted', $svc->process($r['attempt']->attemptid));
        $tokens = $t->of('token');
        $this->assertCount(1, $tokens);
        $this->assertSame('https://buw.example.org/mod/lti/token.php', $tokens[0]['url']);
        $this->assertSame('buw-client', $tokens[0]['client']);
        $this->assertSame([repair_service::SCOPE_SCORE], $tokens[0]['scopes']);
        $posts = $t->of('post');
        $this->assertCount(1, $posts);
        $this->assertSame('https://buw.example.org/mod/lti/services.php/2/lineitems/7/lineitem/scores?type_id=1', $posts[0]['url']);
        $this->assertSame('b-l1', $posts[0]['body']['userId'], 'The subject of this consumer, not core\'s sourceid.');
        $this->assertSame(65.0, $posts[0]['body']['scoreGiven']);
        $this->assertSame(100.0, $posts[0]['body']['scoreMaximum']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now), $posts[0]['body']['timestamp']);
        $this->assertSame('FullyGraded', $posts[0]['body']['gradingProgress']);
        $this->assertSame([], $t->of('get'), 'B granted no result scope: nothing to read.');

        $row = journal::get($r['attempt']->attemptid);
        $this->assertSame('http_accepted', $row->state);
        $this->assertSame(200, (int) $row->httpstatus);
        $this->assertSame('unobservable', $row->bookingstate, 'HTTP 2xx is never a booked grade.');
        $this->assertSame($this->now, (int) $row->agstimestamp);
        $this->assertSame(1, (int) $row->stateversion);
        $this->assertLessThan($this->now, (int) $row->gradetime, 'The original date graded is kept apart.');
        $this->assertSame(2, (int) $row->operatorid);
        $this->assertEquals($before, $this->protected_state(), 'Gradebook, lastgrade, links and completion are unchanged.');
        $actions = $DB->get_fieldset_select('tool_ltidiagnostics_audit', 'action', "action LIKE 'graderepair%'");
        $this->assertEqualsCanonicalizing(['graderepair_optin', 'graderepair_optin', 'graderepair_request',
            'graderepair_http_accepted'], $actions);

        // Idempotent: a second task run for the same attempt sends nothing.
        $this->assertSame('http_accepted', $svc->process($r['attempt']->attemptid));
        $this->assertCount(1, $t->of('post'));
    }

    /**
     * 0 points are sent as 0; with the result scope the AGS result is read and compared once.
     */
    public function test_zero_is_sent_and_result_compared(): void {
        $s = $this->setup_replay();
        $l4 = (int) $s->users->l4->id;
        $t = new fake_ags_transport();
        $t->gets[] = ['status' => 200, 'errorclass' => '', 'json' => [['userId' => 'a-l4', 'resultScore' => 0,
            'resultMaximum' => 100, 'timestamp' => gmdate('c', $this->now),
            'scoreOf' => 'https://lms.example.org/mod/lti/services.php/2/lineitems/45/lineitem']]];
        $svc = $this->service($t);
        $p = $svc->preview($s->conna, $s->linka, $l4);
        $r = $svc->request($s->conna, $s->linka, $l4, $p['fingerprint'], 'zero never sent', 2);
        $this->assertSame('http_accepted', $svc->process($r['attempt']->attemptid));
        $this->assertSame(0.0, $t->of('post')[0]['body']['scoreGiven']);
        $gets = $t->of('get');
        $this->assertCount(1, $gets);
        $expected = 'https://lms.example.org/mod/lti/services.php/2/lineitems/45/lineitem/results?type_id=1&user_id=a-l4';
        $this->assertSame($expected, $gets[0]['url']);
        $this->assertSame(results_checker::RESULTS_TYPE, $gets[0]['accept']);
        $this->assertSame('results_match', journal::get($r['attempt']->attemptid)->bookingstate);
    }

    /**
     * The grade changed between preview and send: the request is refused, or the queued attempt aborts.
     */
    public function test_source_changed_between_preview_and_send(): void {
        $s = $this->setup_replay();
        $l1 = (int) $s->users->l1->id;
        $t = new fake_ags_transport();
        $svc = $this->service($t);
        $p = $svc->preview($s->connb, $s->linkb1, $l1);
        $this->set_user_grade_for_resource_local($l1, 70, $s->tool);
        $this->assertSame('source_changed', $svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'x', 2)['reason']);

        $p = $svc->preview($s->connb, $s->linkb1, $l1);
        $r = $svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'x', 2);
        $this->set_user_grade_for_resource_local($l1, 75, $s->tool);
        $this->assertSame('aborted', $svc->process($r['attempt']->attemptid));
        $this->assertSame('source_changed', journal::get($r['attempt']->attemptid)->errorclass);
        $this->assertSame([], $t->calls);
    }

    /**
     * Every ambiguous or unsafe target is refused before anything is sent.
     */
    public function test_blockers(): void {
        global $DB;
        $s = $this->setup_replay();
        $svc = $this->service(new fake_ags_transport());
        $this->assertContains('no_grade', $svc->preview($s->conna, $s->linka, (int) $s->users->l2->id)['blockers']);
        $this->assertContains('scope_missing', $svc->preview($s->connb, $s->linkb2, (int) $s->users->l3->id)['blockers']);
        $cross = $svc->preview($s->conna, $s->linkb1, (int) $s->users->l1->id)['blockers'];
        $this->assertContains('link_not_in_connection', $cross, 'No cross-consumer target.');
        $this->assertContains('user_not_in_link', $svc->preview($s->connb, $s->linkb1, (int) $s->users->l2->id)['blockers']);

        // Mapping: without the auth_lti link of B, core's sourceid belongs to A: refused, never guessed.
        $issuerhash = hash('sha256', self::$issuerb);
        $DB->delete_records('auth_lti_linked_login', ['userid' => $s->users->l1->id, 'issuer256' => $issuerhash]);
        $this->assertContains('mapping_missing', $svc->preview($s->connb, $s->linkb1, (int) $s->users->l1->id)['blockers']);
        get_auth_plugin('lti')->create_user_binding(self::$issuerb, 'b-l1', (int) $s->users->l1->id);
        get_auth_plugin('lti')->create_user_binding(self::$issuerb, 'b-l1-second', (int) $s->users->l1->id);
        $this->assertContains('mapping_ambiguous', $svc->preview($s->connb, $s->linkb1, (int) $s->users->l1->id)['blockers']);

        // URL injection through the claim: another host, a private host, plain http.
        $injections = [
            'https://evil.example.net/mod/lti/services.php/2/lineitems/7/lineitem' => 'endpoint_foreign_host',
            'https://127.0.0.1/lineitem' => 'endpoint_blocked',
            'http://buw.example.org/lineitem' => 'endpoint_blocked',
        ];
        foreach ($injections as $url => $blocker) {
            $DB->set_field('enrol_lti_resource_link', 'lineitemservice', $url, ['id' => $s->linka]);
            $this->assertContains($blocker, $svc->preview($s->conna, $s->linka, (int) $s->users->l4->id)['blockers'], $url);
        }
        // No line item and no line item scope: a line item is never created.
        $DB->set_field('enrol_lti_resource_link', 'lineitemservice', null, ['id' => $s->linka]);
        $DB->set_field('enrol_lti_resource_link', 'lineitemscope', null, ['id' => $s->linka]);
        $nolookup = $svc->preview($s->conna, $s->linka, (int) $s->users->l4->id)['blockers'];
        $this->assertContains('lineitem_lookup_impossible', $nolookup);
        $this->assertSame(0, $DB->count_records(journal::TABLE, ['source' => 'instrumented']));
    }

    /**
     * HTTP and transport errors: classified; 4xx and 409 end the attempt, 429/5xx are retried with backoff up to the
     * limit, then dead; 409 is never retried with a newer timestamp.
     */
    public function test_errors_retries_and_409(): void {
        $s = $this->setup_replay();
        $l1 = (int) $s->users->l1->id;
        $codes = [400 => 'http_400', 401 => 'http_401', 403 => 'http_403', 404 => 'http_404', 409 => 'http_409'];
        foreach ($codes as $code => $class) {
            $t = new fake_ags_transport();
            $t->posts[] = ['status' => $code, 'errorclass' => $class];
            $svc = $this->service($t);
            $this->now += 10;
            $p = $svc->preview($s->connb, $s->linkb1, $l1);
            $r = $svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'x', 2);
            $this->assertSame('http_rejected', $svc->process($r['attempt']->attemptid), $class);
            $row = journal::get($r['attempt']->attemptid);
            $this->assertSame($class, $row->errorclass);
            $this->assertSame($code, (int) $row->httpstatus);
            $this->assertNull(journal::open_attempt($s->linkb1, $l1), 'No automatic retry for ' . $class);
        }
        $fresh = $svc->preview($s->connb, $s->linkb1, $l1)['fingerprint'];
        $retry = $this->service(new fake_ags_transport())->retry($r['attempt']->attemptid, $fresh, 'again', 2);
        $this->assertSame('conflict_needs_partner', $retry['reason']);

        // Transient: 429, 503, timeout, then dead at the limit (4 attempts, backoff 300 s doubling).
        $t = new fake_ags_transport();
        $t->posts = [['status' => 429, 'errorclass' => 'http_429'], ['status' => 503, 'errorclass' => 'http_5xx'],
            ['status' => 0, 'errorclass' => 'timeout'], ['status' => 503, 'errorclass' => 'http_5xx']];
        $svc = $this->service($t);
        $this->now += 10;
        $p = $svc->preview($s->connb, $s->linkb1, $l1);
        $r = $svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'x', 2);
        $attempt = $r['attempt']->attemptid;
        $delays = [];
        for ($i = 1; $i <= 4; $i++) {
            $state = $svc->process($attempt);
            $row = journal::get($attempt);
            if ($state === 'retry_wait') {
                $next = journal::open_attempt($s->linkb1, $l1);
                $this->assertSame($i + 1, (int) $next->attemptno);
                $this->assertSame($row->correlationid, $next->correlationid);
                $delays[] = (int) $next->retryat - $this->now;
                $this->now = (int) $next->retryat;
                $attempt = $next->attemptid;
            }
        }
        $this->assertSame([300, 600, 1200], $delays);
        $this->assertSame('dead', $state);
        $this->assertCount(4, $t->of('post'));
        $this->assertSame('http_5xx', journal::get($attempt)->errorclass);

        // Token refused and token without the score scope.
        $t = new fake_ags_transport();
        $t->tokens[] = ['status' => 401, 'errorclass' => 'token', 'token' => '', 'scopes' => []];
        $svc = $this->service($t);
        $this->now += 10;
        $r = $svc->request($s->connb, $s->linkb1, $l1, $svc->preview($s->connb, $s->linkb1, $l1)['fingerprint'], 'x', 2);
        $this->assertSame('http_rejected', $svc->process($r['attempt']->attemptid));
        $this->assertSame('refused', journal::get($r['attempt']->attemptid)->tokenstatus);
        $this->assertSame([], $t->of('post'));
        $t = new fake_ags_transport();
        $t->tokens[] = ['status' => 200, 'errorclass' => '', 'token' => 'tok', 'scopes' => ['other']];
        $svc = $this->service($t);
        $r = $svc->request($s->connb, $s->linkb1, $l1, $svc->preview($s->connb, $s->linkb1, $l1)['fingerprint'], 'x', 2);
        $svc->process($r['attempt']->attemptid);
        $this->assertSame('scope_missing', journal::get($r['attempt']->attemptid)->errorclass);
    }

    /**
     * Score state updates: one version per replay, strictly increasing whole-second timestamps, never in the future;
     * the same second waits, a clock that went back waits too.
     */
    public function test_timestamps_monotonic_same_second_and_clock_skew(): void {
        $s = $this->setup_replay();
        $l1 = (int) $s->users->l1->id;
        $t = new fake_ags_transport();
        $svc = $this->service($t);
        $send = function () use ($svc, $s, $l1) {
            $p = $svc->preview($s->connb, $s->linkb1, $l1);
            $r = $svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'x', 2);
            return [$r['attempt']->attemptid, $svc->process($r['attempt']->attemptid)];
        };
        [$a1, $state] = $send();
        $this->assertSame('http_accepted', $state);
        // Same second: no request, wait one second (Moodle refuses "greater or equal" with 409).
        [$a2, $state] = $send();
        $this->assertSame('retry_wait', $state);
        $this->assertSame($this->now + 1, (int) journal::get($a2)->retryat);
        $this->assertCount(1, $t->of('post'));
        $this->now++;
        $this->assertSame('http_accepted', $svc->process($a2));
        $this->assertSame(2, (int) journal::get($a2)->stateversion);
        $this->assertSame($this->now, (int) journal::get($a2)->agstimestamp);
        // Clock went back: still no timestamp at or below the last one, and none in the future.
        $this->now -= 100;
        [$a3, $state] = $send();
        $this->assertSame('retry_wait', $state);
        $this->assertCount(2, $t->of('post'));
        $stamps = array_map(fn($p) => strtotime($p['body']['timestamp']), $t->of('post'));
        $this->assertSame($stamps, array_values(array_unique($stamps)));
        $this->assertLessThan($stamps[1], $stamps[0]);
        $this->assertGreaterThan($this->now, $stamps[1] - 1, 'Never issued ahead of the clock that issued it.');
    }

    /**
     * Double click and parallel requests: one open attempt per target, lock contention refused; rate limit.
     */
    public function test_double_click_parallel_and_rate_limit(): void {
        $s = $this->setup_replay();
        $l1 = (int) $s->users->l1->id;
        $svc = $this->service(new fake_ags_transport());
        $p = $svc->preview($s->connb, $s->linkb1, $l1);
        $this->assertTrue($svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'x', 2)['ok']);
        $this->assertSame('already_open', $svc->request($s->connb, $s->linkb1, $l1, $p['fingerprint'], 'x', 2)['reason']);
        // A second process holding the target lock (file locks: a second handle in the same process does contend).
        global $CFG;
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory(repair_service::LOCKTYPE)->get_lock('request_' . $s->linka . '_' .
            (int) $s->users->l4->id, 1);
        $this->assertNotFalse($lock);
        $p4 = $svc->preview($s->conna, $s->linka, (int) $s->users->l4->id);
        $l4 = (int) $s->users->l4->id;
        $this->assertSame('locked', $svc->request($s->conna, $s->linka, $l4, $p4['fingerprint'], 'x', 2)['reason']);
        $lock->release();
        set_config('repairratelimit', 1, 'tool_ltidiagnostics');
        $this->assertSame('rate_limited', $svc->request($s->conna, $s->linka, $l4, $p4['fingerprint'], 'x', 2)['reason']);
        $this->assertSame('purpose_missing', $svc->request($s->conna, $s->linka, $l4, $p4['fingerprint'], ' ', 3)['reason']);
    }

    /**
     * Line item lookup through the line items service: exactly one match is used, none or several end the attempt,
     * and a line item is never created (no POST to the container).
     */
    public function test_lineitem_lookup_never_creates(): void {
        global $DB;
        $s = $this->setup_replay();
        $DB->set_field('enrol_lti_resource_link', 'lineitemservice', null, ['id' => $s->linkb1]);
        $l1 = (int) $s->users->l1->id;
        $item = 'https://buw.example.org/mod/lti/services.php/2/lineitems/9/lineitem?type_id=1';
        $one = ['id' => $item, 'resourceLinkId' => 'rl-b-1'];
        $cases = [
            [[$one, ['id' => 'x', 'resourceLinkId' => 'other']], 'http_accepted'],
            [[], 'http_rejected'],
            [[$one, ['id' => $item . '2', 'resourceLinkId' => 'rl-b-1']], 'http_rejected'],
        ];
        foreach ($cases as [$json, $expected]) {
            $t = new fake_ags_transport();
            $t->gets[] = ['status' => 200, 'errorclass' => '', 'json' => $json];
            $svc = $this->service($t);
            $this->now += 5;
            $r = $svc->request($s->connb, $s->linkb1, $l1, $svc->preview($s->connb, $s->linkb1, $l1)['fingerprint'], 'x', 2);
            $this->assertSame($expected, $svc->process($r['attempt']->attemptid));
            $this->assertStringContainsString('resource_link_id=rl-b-1', $t->of('get')[0]['url']);
            $this->assertSame([repair_service::SCOPE_SCORE, repair_service::SCOPE_LINEITEM_READ], $t->of('token')[0]['scopes']);
            foreach ($t->of('post') as $post) {
                $this->assertStringEndsWith('/lineitems/9/lineitem/scores?type_id=1', $post['url']);
            }
            if ($expected !== 'http_accepted') {
                $this->assertSame([], $t->of('post'));
                $this->assertContains(journal::get($r['attempt']->attemptid)->errorclass, ['lineitem_missing',
                    'lineitem_ambiguous']);
            }
        }
    }

    /**
     * Switching the module off stops a queued replay; no token, no body, no subject is stored anywhere.
     */
    public function test_disable_stops_queue_and_nothing_secret_is_stored(): void {
        global $DB;
        $s = $this->setup_replay();
        $l1 = (int) $s->users->l1->id;
        $t = new fake_ags_transport();
        $svc = $this->service($t);
        $r = $svc->request($s->connb, $s->linkb1, $l1, $svc->preview($s->connb, $s->linkb1, $l1)['fingerprint'], 'x', 2);
        set_config('graderepair', 0, 'tool_ltidiagnostics');
        $this->assertSame('aborted', $svc->process($r['attempt']->attemptid));
        $this->assertSame([], $t->calls);
        set_config('graderepair', 1, 'tool_ltidiagnostics');
        $r = $svc->request($s->connb, $s->linkb1, $l1, $svc->preview($s->connb, $s->linkb1, $l1)['fingerprint'], 'x', 2);
        $svc->process($r['attempt']->attemptid);
        foreach (['tool_ltidiagnostics_gatt', 'tool_ltidiagnostics_gstate', 'tool_ltidiagnostics_audit'] as $table) {
            $dump = json_encode($DB->get_records($table));
            $this->assertStringNotContainsString('tok-', $dump, $table);
            $this->assertStringNotContainsString('"b-l1"', $dump, $table);
            $this->assertStringNotContainsString('buw.example.org', $dump, $table);
        }
        // The ad-hoc task runs the service and never throws.
        $task = new \tool_ltidiagnostics\task\grade_repair_task();
        $task->set_custom_data(['attemptid' => 'not-an-attempt']);
        ob_start();
        $task->execute();
        $this->assertStringContainsString('missing', ob_get_clean());
    }
}
