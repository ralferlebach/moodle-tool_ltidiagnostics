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

use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\config;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\inventory;

/**
 * The isolated grade repair sender (issues #5 and #9).
 *
 * Integration decision (documented in docs/GRADES.md): the sender lives in this plugin, in its own namespace, behind
 * three switches that are all off by default - the site setting "graderepair", the per-connection opt-in and the
 * capability tool/ltidiagnostics:repairgrades (nobody but site administrators). The passive diagnosis does not use any
 * of this code. It does not touch local_lticompletionsync.
 *
 * What a replay does: it re-reads the unchanged source grade, checks that nothing changed since the preview, and sends
 * that same score and maximum, as one new score state update with its own monotonic version and a valid current
 * timestamp, to exactly one line item of exactly one consumer for exactly one learner, through Moodle's AGS tooling
 * (registration and tool key of enrol_lti) and the plugin's SSRF guarded transport. It never edits the grade, never
 * resets core's lastgrade, never creates a line item and never sends when the target is ambiguous.
 *
 * Asynchronous: a request only writes a journal row and queues an ad-hoc task. The task holds a lock per target, retries
 * transient failures with exponential backoff up to a limit (then "dead"), and never retries a 409 automatically.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repair_service {
    /** @var string Media type of a line item container. */
    public const LINEITEMS_TYPE = 'application/vnd.ims.lis.v2.lineitemcontainer+json';
    /** @var string Lock type. */
    public const LOCKTYPE = 'tool_ltidiagnostics_graderepair';
    /** @var string Score scope. */
    public const SCOPE_SCORE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
    /** @var string Line item read scope. */
    public const SCOPE_LINEITEM_READ = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly';
    /** @var string Line item scope. */
    public const SCOPE_LINEITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';

    /** @var target_resolver Resolver. */
    private $resolver;
    /** @var ags_transport|null Transport (created lazily). */
    private $transport;
    /** @var callable Clock. */
    private $clock;

    /**
     * Constructor.
     *
     * @param ags_transport|null $transport Transport (tests).
     * @param target_resolver|null $resolver Resolver.
     * @param callable|null $clock Returns the current unix time (tests).
     */
    public function __construct(?ags_transport $transport = null, ?target_resolver $resolver = null, ?callable $clock = null) {
        $this->transport = $transport;
        $this->resolver = $resolver ?? new target_resolver();
        $this->clock = $clock ?? fn() => time();
    }

    /**
     * Whether the sender is switched on site wide.
     *
     * @return bool
     */
    public static function site_enabled(): bool {
        return (int) config::get('graderepair') === 1;
    }

    /**
     * Whether the sender is switched on for a connection (site setting and connection opt-in).
     *
     * @param string $connkey Connection key.
     * @return bool
     */
    public static function enabled_for(string $connkey): bool {
        return self::site_enabled() && in_array($connkey, self::opted_in(), true);
    }

    /**
     * Connections that opted in.
     *
     * @return string[]
     */
    public static function opted_in(): array {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) config::get('graderepairconnections'))),
            fn($k) => connection::parse_key($k) !== null
        ));
    }

    /**
     * Switch the opt-in of a connection (audited).
     *
     * @param string $connkey Connection key.
     * @param bool $on New state.
     */
    public static function set_opt_in(string $connkey, bool $on): void {
        $list = array_diff(self::opted_in(), [$connkey]);
        if ($on) {
            $list[] = $connkey;
        }
        set_config('graderepairconnections', implode(',', array_values($list)), 'tool_ltidiagnostics');
        audit::log($on ? 'graderepair_optin' : 'graderepair_optout', $connkey, '');
    }

    /**
     * Whether the current user may use the sender for a connection.
     *
     * @param string $connkey Connection key.
     * @return bool
     */
    public static function can_repair(string $connkey): bool {
        return self::enabled_for($connkey) && has_capability('tool/ltidiagnostics:repairgrades', \context_system::instance());
    }

    /**
     * Dry run: the exact target, the source grade and every reason not to send. Writes nothing, sends nothing.
     *
     * @param connection $conn Connection.
     * @param int $linkid Resource link row.
     * @param int $userid Local user.
     * @return array Result of target_resolver::resolve() plus "enabled".
     */
    public function preview(connection $conn, int $linkid, int $userid): array {
        $t = $this->resolver->resolve($conn, $linkid, $userid);
        $t['enabled'] = self::enabled_for($conn->key);
        $t['open'] = journal::open_attempt($linkid, $userid);
        return $t;
    }

    /**
     * Request a replay (after the operator confirmed the preview). Queues an ad-hoc task; sends nothing itself.
     *
     * @param connection $conn Connection.
     * @param int $linkid Resource link row.
     * @param int $userid Local user.
     * @param string $fingerprint Fingerprint shown in the confirmed preview.
     * @param string $purpose Reason given by the operator.
     * @param int $operatorid Operator.
     * @param array $options origin (replay, replay_batch, replay_retry), parent (\stdClass journal row being retried).
     * @return array ok (bool), reason (string), attempt (\stdClass|null).
     */
    public function request(
        connection $conn,
        int $linkid,
        int $userid,
        string $fingerprint,
        string $purpose,
        int $operatorid,
        array $options = []
    ): array {
        if (!self::enabled_for($conn->key)) {
            return ['ok' => false, 'reason' => 'disabled', 'attempt' => null];
        }
        if (trim($purpose) === '') {
            return ['ok' => false, 'reason' => 'purpose_missing', 'attempt' => null];
        }
        $now = ($this->clock)();
        if ($this->recent_requests($operatorid, $now) >= config::bounded('repairratelimit')) {
            return ['ok' => false, 'reason' => 'rate_limited', 'attempt' => null];
        }
        $lock = \core\lock\lock_config::get_lock_factory(self::LOCKTYPE)->get_lock('request_' . $linkid . '_' . $userid, 5);
        if (!$lock) {
            return ['ok' => false, 'reason' => 'locked', 'attempt' => null];
        }
        try {
            if (journal::open_attempt($linkid, $userid)) {
                return ['ok' => false, 'reason' => 'already_open', 'attempt' => null];
            }
            $t = $this->resolver->resolve($conn, $linkid, $userid);
            if ($t['blockers']) {
                return ['ok' => false, 'reason' => 'blocked:' . implode(',', $t['blockers']), 'attempt' => null];
            }
            if (!hash_equals($t['fingerprint'], $fingerprint)) {
                return ['ok' => false, 'reason' => 'source_changed', 'attempt' => null];
            }
            $parent = $options['parent'] ?? null;
            $in = $t['internal'];
            $attempt = journal::insert([
                'correlationid' => $parent ? (string) $parent->correlationid : '',
                'parentid' => $parent ? (int) $parent->id : 0,
                'attemptno' => $parent ? (int) $parent->attemptno + 1 : 1,
                'origin' => $options['origin'] ?? journal::ORIGIN_REPLAY,
                'connkey' => $conn->key,
                'deploymentrowid' => $conn->deploymentrowid,
                'resourcelinkrowid' => $linkid,
                'toolid' => $in['toolid'],
                'userid' => $userid,
                'subjecthash' => $in['subjecthash'],
                'lineitemhash' => journal::lineitem_hash($in['lineitem']),
                'scoregiven' => $in['grade'],
                'scoremax' => $in['grademax'],
                'sourceversion' => $fingerprint,
                'gradetime' => $in['dategraded'],
                'state' => 'queued',
                'operatorid' => $operatorid,
                'purpose' => $purpose,
                'batchid' => (string) ($options['batchid'] ?? ''),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            if ($attempt->correlationid === '') {
                journal::update((int) $attempt->id, ['correlationid' => $attempt->attemptid]);
                $attempt->correlationid = $attempt->attemptid;
            }
            audit::log(
                'graderepair_request',
                $conn->key,
                'attempt=' . $attempt->attemptid . ' origin=' . $attempt->origin,
                $operatorid
            );
            self::queue((string) $attempt->attemptid, $now);
            return ['ok' => true, 'reason' => '', 'attempt' => $attempt];
        } finally {
            $lock->release();
        }
    }

    /**
     * Retry a failed replay as a new attempt of the same correlation (after a fresh preview).
     *
     * @param string $attemptid Failed attempt.
     * @param string $fingerprint Fingerprint of the fresh preview.
     * @param string $purpose Reason.
     * @param int $operatorid Operator.
     * @return array As request().
     */
    public function retry(string $attemptid, string $fingerprint, string $purpose, int $operatorid): array {
        $row = journal::get($attemptid);
        if (!$row || $row->source !== 'instrumented' || !in_array($row->state, ['dead', 'http_rejected', 'aborted'], true)) {
            return ['ok' => false, 'reason' => 'not_retryable', 'attempt' => null];
        }
        if ($row->errorclass === 'http_409') {
            // A conflict needs a comparison with the partner first; no escalation with a newer timestamp.
            return ['ok' => false, 'reason' => 'conflict_needs_partner', 'attempt' => null];
        }
        $conn = (new inventory())->get_connection((string) $row->connkey);
        if (!$conn) {
            return ['ok' => false, 'reason' => 'invalidconnection', 'attempt' => null];
        }
        return $this->request(
            $conn,
            (int) $row->resourcelinkrowid,
            (int) $row->userid,
            $fingerprint,
            $purpose,
            $operatorid,
            ['origin' => journal::ORIGIN_RETRY, 'parent' => $row]
        );
    }

    /**
     * Number of replay requests of an operator in the last hour.
     *
     * @param int $operatorid Operator.
     * @param int $now Now.
     * @return int
     */
    private function recent_requests(int $operatorid, int $now): int {
        global $DB;
        return $DB->count_records_select(
            journal::TABLE,
            'operatorid = :o AND source = :s AND timecreated > :t',
            ['o' => $operatorid, 's' => 'instrumented', 't' => $now - HOURSECS]
        );
    }

    /**
     * Queue the ad-hoc task of an attempt.
     *
     * @param string $attemptid Attempt.
     * @param int $runat Earliest run time.
     */
    public static function queue(string $attemptid, int $runat): void {
        $task = new \tool_ltidiagnostics\task\grade_repair_task();
        $task->set_custom_data(['attemptid' => $attemptid]);
        $task->set_next_run_time($runat);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Process one attempt (body of the ad-hoc task).
     *
     * @param string $attemptid Attempt.
     * @return string Final or intermediate state of the attempt.
     */
    public function process(string $attemptid): string {
        $row = journal::get($attemptid);
        if (!$row || $row->source !== 'instrumented') {
            return 'missing';
        }
        $lock = \core\lock\lock_config::get_lock_factory(self::LOCKTYPE)
            ->get_lock('send_' . (int) $row->resourcelinkrowid . '_' . (int) $row->userid, 0);
        if (!$lock) {
            self::queue($attemptid, ($this->clock)() + 30);
            return 'locked';
        }
        try {
            $row = journal::get($attemptid);
            if (!in_array($row->state, ['queued', 'retry_wait'], true)) {
                // Idempotent: a second task for the same attempt does nothing.
                return (string) $row->state;
            }
            return $this->send($row);
        } finally {
            $lock->release();
        }
    }

    /**
     * Send one attempt.
     *
     * @param \stdClass $row Journal row.
     * @return string State.
     */
    private function send(\stdClass $row): string {
        $now = ($this->clock)();
        if (!self::enabled_for((string) $row->connkey)) {
            return $this->finish($row, 'aborted', ['errorclass' => 'blocked_by_policy', 'skipreason' => 'disabled']);
        }
        $conn = (new inventory())->get_connection((string) $row->connkey);
        if (!$conn) {
            return $this->finish($row, 'aborted', ['errorclass' => 'mapping_missing', 'skipreason' => 'invalidconnection']);
        }
        // Re-read the current state; refuse when anything changed since the confirmed preview.
        $t = $this->resolver->resolve($conn, (int) $row->resourcelinkrowid, (int) $row->userid);
        if ($t['blockers']) {
            return $this->finish($row, 'aborted', ['errorclass' => self::blocker_class($t['blockers']),
                'skipreason' => substr(implode(',', $t['blockers']), 0, 30)]);
        }
        if (!hash_equals($t['fingerprint'], (string) $row->sourceversion)) {
            return $this->finish($row, 'aborted', ['errorclass' => 'source_changed', 'skipreason' => 'source_changed']);
        }
        $in = $t['internal'];
        $transport = $this->transport ?? new moodle_ags_transport();
        $registration = self::registration($in['issuer'], $in['clientid']);
        if (!$registration) {
            return $this->finish($row, 'aborted', ['errorclass' => 'token', 'skipreason' => 'registration_invalid']);
        }
        $scopes = [self::SCOPE_SCORE];
        if ($in['lineitem'] === '') {
            $scopes[] = $in['scopes']['lineitem'] ? self::SCOPE_LINEITEM : self::SCOPE_LINEITEM_READ;
        }
        journal::update((int) $row->id, ['state' => 'sending']);
        $token = $transport->token($registration, $scopes);
        if ($token['token'] === '') {
            return $this->failed($row, $token['status'], $token['errorclass'] ?: 'token', 'refused');
        }
        if ($token['scopes'] && !in_array(self::SCOPE_SCORE, $token['scopes'], true)) {
            return $this->failed($row, $token['status'], 'scope_missing', 'scope_missing');
        }

        // Line item: the coupled one from the claim, else exactly one found by its resource link (never created).
        $lineitem = $in['lineitem'];
        if ($lineitem === '') {
            $found = $this->find_lineitem(
                $transport,
                $token['token'],
                $in['lineitems'],
                $in['resourcelinkid'],
                $in['issuer'],
                (string) ($conn->endpoints['oauth'] ?? '')
            );
            if ($found['errorclass'] !== '') {
                return $this->failed($row, $found['status'], $found['errorclass'], 'granted');
            }
            $lineitem = $found['url'];
        }
        $targethash = hash('sha256', $lineitem . "\n" . $in['subjecthash']);

        // Score state update: monotonic version and a current timestamp strictly above the last one issued for the target.
        $state = $this->next_state($targethash, (string) $row->attemptid, $now);
        if ($state === null) {
            // Same second as the previous update of this target: wait one second instead of a future timestamp.
            journal::update((int) $row->id, ['state' => 'retry_wait', 'retryat' => $now + 1, 'tokenstatus' => 'granted']);
            self::queue((string) $row->attemptid, $now + 1);
            return 'retry_wait';
        }
        journal::update((int) $row->id, [
            'lineitemhash' => journal::lineitem_hash($lineitem),
            'agstimestamp' => $state['timestamp'],
            'stateversion' => $state['version'],
            'tokenstatus' => 'granted',
        ]);
        $score = [
            'userId' => $in['sub'],
            'scoreGiven' => (float) $in['grade'],
            'scoreMaximum' => (float) $in['grademax'],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $state['timestamp']),
            'activityProgress' => 'Completed',
            'gradingProgress' => 'FullyGraded',
        ];
        $response = $transport->post_score(self::scores_url($lineitem), $token['token'], $score);
        if ($response['errorclass'] !== '') {
            return $this->failed($row, (int) $response['status'], (string) $response['errorclass'], 'granted');
        }
        $booking = $in['scopes']['result'] ? 'unknown' : 'unobservable';
        $result = $this->finish($row, 'http_accepted', ['httpstatus' => (int) $response['status'], 'errorclass' => '',
            'bookingstate' => $booking]);
        if ($booking === 'unknown' && (int) config::get('repairresultcheck') === 1) {
            (new results_checker($transport))->check_attempt(
                journal::get((string) $row->attemptid),
                $lineitem,
                $in,
                $registration
            );
        }
        return $result;
    }

    /**
     * Find exactly one line item of a resource link through the line items service (read only).
     *
     * @param ags_transport $transport Transport.
     * @param string $token Token.
     * @param string $lineitems Line items URL.
     * @param string $resourcelinkid Platform resource link id.
     * @param string $issuer Issuer.
     * @param string $tokenurl Token URL.
     * @return array url, status, errorclass.
     */
    private function find_lineitem(
        ags_transport $transport,
        string $token,
        string $lineitems,
        string $resourcelinkid,
        string $issuer,
        string $tokenurl
    ): array {
        $url = self::with_query($lineitems, ['resource_link_id' => $resourcelinkid, 'limit' => 100]);
        $r = $transport->get_json($url, $token, self::LINEITEMS_TYPE);
        if ($r['errorclass'] !== '') {
            return ['url' => '', 'status' => $r['status'], 'errorclass' => $r['errorclass']];
        }
        $matches = [];
        foreach ((array) $r['json'] as $item) {
            if (
                is_array($item) && isset($item['id']) && is_string($item['id'])
                    && (string) ($item['resourceLinkId'] ?? '') === $resourcelinkid
            ) {
                $matches[] = $item['id'];
            }
        }
        if (count($matches) !== 1) {
            return ['url' => '', 'status' => $r['status'],
                'errorclass' => $matches ? 'lineitem_ambiguous' : 'lineitem_missing'];
        }
        if (target_resolver::endpoint_problem($matches[0], $issuer, $tokenurl) !== '') {
            return ['url' => '', 'status' => $r['status'], 'errorclass' => 'blocked_by_policy'];
        }
        return ['url' => $matches[0], 'status' => $r['status'], 'errorclass' => ''];
    }

    /**
     * Allocate the next score state of a target, or null when the current second was already used.
     *
     * The AGS timestamp is the time of a score state change of the tool. A re-issue is a new, audited state update with
     * its own version; the timestamp is the current time and strictly greater than any timestamp issued for this line
     * item and learner before (Moodle compares in whole seconds, "greater or equal" is refused with 409). A timestamp in
     * the future is never issued. The state is stored before the request is sent, so a crash cannot reuse a timestamp.
     *
     * @param string $targethash Target.
     * @param string $attemptid Attempt.
     * @param int $now Now.
     * @return array|null version, timestamp.
     */
    public function next_state(string $targethash, string $attemptid, int $now): ?array {
        global $DB;
        $tx = $DB->start_delegated_transaction();
        $s = $DB->get_record('tool_ltidiagnostics_gstate', ['targethash' => $targethash]);
        if ($s && (int) $s->lastissued >= $now) {
            $tx->allow_commit();
            return null;
        }
        if ($s) {
            $s->version = (int) $s->version + 1;
            $s->lastissued = $now;
            $s->lastattemptid = $attemptid;
            $s->timemodified = $now;
            $DB->update_record('tool_ltidiagnostics_gstate', $s);
        } else {
            $s = (object) ['targethash' => $targethash, 'version' => 1, 'lastissued' => $now, 'lastattemptid' => $attemptid,
                'timemodified' => $now];
            $s->id = $DB->insert_record('tool_ltidiagnostics_gstate', $s);
        }
        $tx->allow_commit();
        return ['version' => (int) $s->version, 'timestamp' => $now];
    }

    /**
     * Handle a failed request: retry transient classes with backoff, else end the attempt.
     *
     * @param \stdClass $row Journal row.
     * @param int $status HTTP status.
     * @param string $class Error class.
     * @param string $tokenstatus Token status.
     * @return string State.
     */
    private function failed(\stdClass $row, int $status, string $class, string $tokenstatus): string {
        $now = ($this->clock)();
        $max = config::bounded('repairmaxattempts');
        if (error_classes::retryable($class) && (int) $row->attemptno < $max) {
            $delay = config::bounded('repairbackoff') * (2 ** max(0, (int) $row->attemptno - 1));
            $this->finish($row, 'http_rejected', ['httpstatus' => $status, 'errorclass' => $class,
                'tokenstatus' => $tokenstatus, 'retryat' => $now + $delay]);
            $next = journal::insert([
                'correlationid' => (string) $row->correlationid,
                'parentid' => (int) $row->id,
                'attemptno' => (int) $row->attemptno + 1,
                'origin' => journal::ORIGIN_RETRY,
                'connkey' => (string) $row->connkey,
                'deploymentrowid' => (int) $row->deploymentrowid,
                'resourcelinkrowid' => (int) $row->resourcelinkrowid,
                'toolid' => (int) $row->toolid,
                'userid' => (int) $row->userid,
                'subjecthash' => (string) $row->subjecthash,
                'lineitemhash' => (string) $row->lineitemhash,
                'scoregiven' => $row->scoregiven,
                'scoremax' => $row->scoremax,
                'sourceversion' => (string) $row->sourceversion,
                'gradetime' => (int) $row->gradetime,
                'state' => 'retry_wait',
                'retryat' => $now + $delay,
                'operatorid' => (int) $row->operatorid,
                'purpose' => (string) $row->purpose,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            self::queue((string) $next->attemptid, $now + $delay);
            return 'retry_wait';
        }
        $final = error_classes::retryable($class) ? 'dead' : 'http_rejected';
        return $this->finish($row, $final, ['httpstatus' => $status, 'errorclass' => $class, 'tokenstatus' => $tokenstatus]);
    }

    /**
     * Set a final or intermediate state and audit it.
     *
     * @param \stdClass $row Journal row.
     * @param string $state State.
     * @param array $fields More fields.
     * @return string State.
     */
    private function finish(\stdClass $row, string $state, array $fields): string {
        journal::update((int) $row->id, ['state' => $state] + $fields);
        audit::log('graderepair_' . $state, (string) $row->connkey, 'attempt=' . $row->attemptid . ' class=' .
            ($fields['errorclass'] ?? '') . ' status=' . ($fields['httpstatus'] ?? 0), (int) $row->operatorid);
        return $state;
    }

    /**
     * Error class for blockers found at send time.
     *
     * @param string[] $blockers Blockers.
     * @return string
     */
    private static function blocker_class(array $blockers): string {
        foreach (['mapping_missing', 'mapping_ambiguous'] as $b) {
            if (in_array($b, $blockers, true)) {
                return $b === 'mapping_ambiguous' ? 'mapping_missing' : $b;
            }
        }
        if (in_array('scope_missing', $blockers, true)) {
            return 'scope_missing';
        }
        if (array_intersect(['endpoint_blocked', 'endpoint_foreign_host', 'token_url_blocked'], $blockers)) {
            return 'blocked_by_policy';
        }
        if (array_intersect(['no_service', 'lineitem_lookup_impossible'], $blockers)) {
            return 'lineitem_missing';
        }
        return 'source_invalid';
    }

    /**
     * enrol_lti's registration object (with the tool key) for an issuer and client id.
     *
     * @param string $issuer Issuer.
     * @param string $clientid Client id.
     * @return \Packback\Lti1p3\Interfaces\ILtiRegistration|null
     */
    public static function registration(string $issuer, string $clientid) {
        $db = new \enrol_lti\local\ltiadvantage\lib\issuer_database(
            new \enrol_lti\local\ltiadvantage\repository\application_registration_repository(),
            new \enrol_lti\local\ltiadvantage\repository\deployment_repository()
        );
        return $db->findRegistrationByIssuer($issuer, $clientid);
    }

    /**
     * Scores endpoint of a line item URL ("/scores" appended to the path, query kept).
     *
     * @param string $lineitem Line item URL.
     * @return string
     */
    public static function scores_url(string $lineitem): string {
        return self::append_path($lineitem, '/scores');
    }

    /**
     * Append a path segment before the query.
     *
     * @param string $url URL.
     * @param string $segment Segment starting with "/".
     * @return string
     */
    public static function append_path(string $url, string $segment): string {
        $pos = strpos($url, '?');
        if ($pos === false) {
            return rtrim($url, '/') . $segment;
        }
        return rtrim(substr($url, 0, $pos), '/') . $segment . substr($url, $pos);
    }

    /**
     * Add query parameters to a URL.
     *
     * @param string $url URL.
     * @param array $params Parameters.
     * @return string
     */
    public static function with_query(string $url, array $params): string {
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&');
    }
}
