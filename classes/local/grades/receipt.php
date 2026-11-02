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

use tool_ltidiagnostics\local\active\curl_fetcher;
use tool_ltidiagnostics\local\active\url_guard;
use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\config;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\support;

/**
 * Partner receipt of a grade replay (issue #6): voluntary, privacy minimised evidence from the consumer's gradebook.
 *
 * 1. Provider (tool side): exports a receipt request for one accepted replay attempt: attempt id, hashes of issuer,
 *    client and subject, the line item URL (an endpoint of the consumer) and the score that was sent.
 * 2. Consumer (platform side, also running this plugin): an administrator imports the request. The plugin finds the
 *    grade item behind the line item URL and the learner behind the subject hash (no name, no id leaves the consumer)
 *    and answers with a receipt signed with the consumer's LTI platform key (mod_lti), the key the provider already
 *    trusts for this registration.
 * 3. Provider: imports the receipt; the signature is checked against the platform key set of the registration, the
 *    request hash binds it to exactly this attempt (no correlation by time), then values are compared.
 *
 * No administrator access of one institution to the other is needed or assumed. Without a receipt the booking stays
 * "unknown" or "unobservable".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class receipt {
    /** @var string Schema of a request. */
    public const SCHEMA_REQUEST = 'ltidiag-grade-request-1';
    /** @var string Schema of a receipt. */
    public const SCHEMA_RECEIPT = 'ltidiag-grade-receipt-1';
    /** @var int Maximum document size. */
    public const MAXBYTES = 16384;
    /** @var int Maximum age of a receipt that is accepted (days). */
    public const MAXAGEDAYS = 90;

    /**
     * Canonical hash of a request (binds a receipt to one attempt and its sent values).
     *
     * @param array $r Request fields attemptid, issuerhash, clienthash, lineitem, subjecthash, score.
     * @return string
     */
    public static function request_hash(array $r): string {
        return hash('sha256', json_encode([
            (string) $r['attemptid'], (string) $r['issuerhash'], (string) $r['clienthash'], (string) $r['lineitem'],
            (string) $r['subjecthash'], sprintf('%.5F', (float) $r['score']['given']),
            sprintf('%.5F', (float) $r['score']['maximum']), (string) $r['score']['timestamp'],
        ]));
    }

    /**
     * Receipt request for an accepted replay attempt (provider).
     *
     * @param connection $conn Connection (tool role).
     * @param \stdClass $row Journal row.
     * @param int $now Now.
     * @return array ok (bool), reason (string), document (array).
     */
    public static function request(connection $conn, \stdClass $row, int $now): array {
        if ($row->source !== 'instrumented' || $row->state !== 'http_accepted' || $row->connkey !== $conn->key) {
            return ['ok' => false, 'reason' => 'not_accepted_replay', 'document' => []];
        }
        $t = (new target_resolver())->resolve($conn, (int) $row->resourcelinkrowid, (int) $row->userid);
        $lineitem = (string) ($t['internal']['lineitem'] ?? '');
        if ($lineitem === '' || journal::lineitem_hash($lineitem) !== (string) $row->lineitemhash) {
            return ['ok' => false, 'reason' => 'lineitem_unknown', 'document' => []];
        }
        $doc = [
            'schema' => self::SCHEMA_REQUEST,
            'attemptid' => (string) $row->attemptid,
            'requested' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'provider' => support::diagnostic_id($conn->key),
            'issuerhash' => hash('sha256', $conn->issuer),
            'clienthash' => hash('sha256', $conn->clientid),
            'lineitem' => $lineitem,
            'subjecthash' => (string) $row->subjecthash,
            'score' => [
                'given' => (float) $row->scoregiven,
                'maximum' => (float) $row->scoremax,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->agstimestamp),
            ],
        ];
        $doc['requesthash'] = self::request_hash($doc);
        audit::log('gradereceipt_request', $conn->key, 'attempt=' . $row->attemptid);
        return ['ok' => true, 'reason' => '', 'document' => $doc];
    }

    /**
     * Parse and validate a request document (consumer).
     *
     * @param string $json Document.
     * @return array|null
     */
    public static function parse_request(string $json): ?array {
        if (strlen($json) > self::MAXBYTES) {
            return null;
        }
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['schema'] ?? '') !== self::SCHEMA_REQUEST) {
            return null;
        }
        foreach (['attemptid', 'issuerhash', 'clienthash', 'lineitem', 'subjecthash', 'requesthash'] as $f) {
            if (!isset($d[$f]) || !is_string($d[$f]) || strlen($d[$f]) > 2000) {
                return null;
            }
        }
        if (
            !preg_match('/^[0-9a-f-]{36}$/', $d['attemptid']) || !preg_match('/^[0-9a-f]{64}$/', $d['subjecthash'])
                || !is_array($d['score'] ?? null) || !is_numeric($d['score']['given'] ?? null)
                || !is_numeric($d['score']['maximum'] ?? null) || !is_string($d['score']['timestamp'] ?? null)
        ) {
            return null;
        }
        if (!hash_equals(self::request_hash($d), $d['requesthash'])) {
            return null;
        }
        return $d;
    }

    /**
     * Answer a request on the consumer: find grade item and learner, read the gradebook, sign the receipt.
     *
     * @param string $json Request document.
     * @param int $now Now.
     * @return array ok, reason, document (schema, jwt), payload (unsigned content, for display).
     */
    public static function answer(string $json, int $now): array {
        global $CFG, $DB;
        $req = self::parse_request($json);
        if (!$req) {
            return ['ok' => false, 'reason' => 'invalid_request', 'document' => [], 'payload' => []];
        }
        if (!hash_equals(hash('sha256', $CFG->wwwroot), $req['issuerhash'])) {
            return ['ok' => false, 'reason' => 'not_this_platform', 'document' => [], 'payload' => []];
        }
        $found = ['lineitem' => false, 'user' => false];
        $grade = null;
        $item = self::item_from_url($req['lineitem']);
        if ($item) {
            $found['lineitem'] = true;
            $userid = self::user_from_hash((int) $item->courseid, $req['subjecthash']);
            if ($userid) {
                $found['user'] = true;
                $g = $DB->get_record(
                    'grade_grades',
                    ['itemid' => (int) $item->id, 'userid' => $userid],
                    'id, finalgrade, timemodified, locked, overridden, hidden'
                );
                $grade = [
                    'value' => $g && $g->finalgrade !== null ? (float) $g->finalgrade : null,
                    'maximum' => (float) $item->grademax,
                    'timemodified' => $g ? (int) $g->timemodified : 0,
                    'locked' => (int) ($g && (int) $g->locked > 0) || (int) $item->locked > 0,
                    'overridden' => (int) ($g && (int) $g->overridden > 0),
                    'hidden' => (int) ($g && (int) $g->hidden > 0),
                ];
            }
        }
        $payload = [
            'schema' => self::SCHEMA_RECEIPT,
            'attemptid' => $req['attemptid'],
            'requesthash' => $req['requesthash'],
            'iss' => $CFG->wwwroot,
            'iat' => $now,
            'found' => $found,
            'grade' => $grade,
        ];
        $key = \mod_lti\local\ltiopenid\jwks_helper::get_private_key();
        $jwt = \Firebase\JWT\JWT::encode($payload, $key['key'], 'RS256', $key['kid']);
        audit::log('gradereceipt_answer', '', 'attempt=' . $req['attemptid'] . ' found=' . (int) $found['lineitem'] .
            (int) $found['user']);
        return ['ok' => true, 'reason' => '', 'document' => ['schema' => self::SCHEMA_RECEIPT, 'jwt' => $jwt],
            'payload' => $payload];
    }

    /**
     * Grade item behind a Moodle line item URL of this site (…/mod/lti/services.php/{course}/lineitems/{item}/lineitem).
     *
     * @param string $url Line item URL.
     * @return \stdClass|null grade_items row.
     */
    public static function item_from_url(string $url): ?\stdClass {
        global $CFG, $DB;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host !== strtolower((string) parse_url($CFG->wwwroot, PHP_URL_HOST))) {
            return null;
        }
        if (
            !preg_match(
                '#/mod/lti/services\.php/(\d+)/lineitems/(\d+)/lineitem#',
                (string) parse_url($url, PHP_URL_PATH),
                $m
            )
        ) {
            return null;
        }
        $item = $DB->get_record(
            'grade_items',
            ['id' => (int) $m[2], 'courseid' => (int) $m[1]],
            'id, courseid, grademax, locked, itemtype, itemmodule'
        );
        return $item ?: null;
    }

    /**
     * Learner of a course whose platform subject hashes to the given value (Moodle sends the user id as "sub").
     *
     * @param int $courseid Course.
     * @param string $subjecthash sha256 of issuer, newline and subject.
     * @return int User id or 0.
     */
    public static function user_from_hash(int $courseid, string $subjecthash): int {
        global $CFG;
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return 0;
        }
        foreach (get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, false) as $u) {
            if (hash_equals(journal::subject_hash($CFG->wwwroot, (string) $u->id), $subjecthash)) {
                return (int) $u->id;
            }
        }
        return 0;
    }

    /**
     * Import a receipt on the provider and record the booking evidence.
     *
     * @param connection $conn Connection (tool role).
     * @param string $json Receipt document.
     * @param int $now Now.
     * @param array|null $keyset JWKS (null: fetched from the registration through the guarded fetcher).
     * @return array ok, reason, verdict, mismatches.
     */
    public static function import(connection $conn, string $json, int $now, ?array $keyset = null): array {
        global $DB;
        $fail = fn(string $reason) => ['ok' => false, 'reason' => $reason, 'verdict' => '', 'mismatches' => []];
        if (strlen($json) > self::MAXBYTES) {
            return $fail('invalid_receipt');
        }
        $doc = json_decode($json, true);
        if (!is_array($doc) || ($doc['schema'] ?? '') !== self::SCHEMA_RECEIPT || !is_string($doc['jwt'] ?? null)) {
            return $fail('invalid_receipt');
        }
        $keyset = $keyset ?? self::fetch_keyset($conn);
        if (!$keyset) {
            return $fail('keyset_unavailable');
        }
        // Firebase JWT keeps its clock in static properties: use this call's time and allow 5 minutes of clock skew
        // between the two sites, then restore the global state.
        $leeway = \Firebase\JWT\JWT::$leeway;
        $timestamp = \Firebase\JWT\JWT::$timestamp;
        \Firebase\JWT\JWT::$leeway = 300;
        \Firebase\JWT\JWT::$timestamp = $now;
        try {
            $payload = (array) \Firebase\JWT\JWT::decode($doc['jwt'], \Firebase\JWT\JWK::parseKeySet($keyset, 'RS256'));
        } catch (\Firebase\JWT\BeforeValidException | \Firebase\JWT\ExpiredException $e) {
            return $fail('clock_skew');
        } catch (\Throwable $e) {
            return $fail('signature');
        } finally {
            \Firebase\JWT\JWT::$leeway = $leeway;
            \Firebase\JWT\JWT::$timestamp = $timestamp;
        }
        $payload = json_decode(json_encode($payload), true);
        if (($payload['schema'] ?? '') !== self::SCHEMA_RECEIPT || ($payload['iss'] ?? '') !== $conn->issuer) {
            return $fail('wrong_consumer');
        }
        if ((int) ($payload['iat'] ?? 0) < $now - self::MAXAGEDAYS * DAYSECS || (int) ($payload['iat'] ?? 0) > $now + 300) {
            return $fail('expired');
        }
        $row = journal::get((string) ($payload['attemptid'] ?? ''));
        if (!$row || $row->connkey !== $conn->key || $row->source !== 'instrumented') {
            return $fail('unknown_attempt');
        }
        $req = self::request($conn, $row, $now);
        if (!$req['ok'] || !hash_equals($req['document']['requesthash'], (string) ($payload['requesthash'] ?? ''))) {
            // The receipt belongs to another attempt or to other values than the ones this site sent.
            return $fail('request_mismatch');
        }
        $receipthash = hash('sha256', $doc['jwt']);
        if ($DB->record_exists('tool_ltidiagnostics_grcpt', ['receipthash' => $receipthash])) {
            return $fail('already_imported');
        }
        $c = self::compare($row, $payload);
        $DB->insert_record('tool_ltidiagnostics_grcpt', (object) [
            'attemptid' => (string) $row->attemptid,
            'receipthash' => $receipthash,
            'verdict' => $c['verdict'],
            'mismatches' => substr(implode(',', $c['mismatches']), 0, 100),
            'scoregiven' => $payload['grade']['value'] ?? null,
            'scoremax' => $payload['grade']['maximum'] ?? null,
            'gradetime' => (int) ($payload['grade']['timemodified'] ?? 0),
            'locked' => (int) ($payload['grade']['locked'] ?? 0),
            'overridden' => (int) ($payload['grade']['overridden'] ?? 0),
            'issuedat' => (int) $payload['iat'],
            'userid' => (int) ($GLOBALS['USER']->id ?? 0),
            'timecreated' => $now,
        ]);
        journal::update((int) $row->id, ['bookingstate' => $c['verdict'],
            'bookingdetail' => substr(implode(',', $c['mismatches']), 0, 100), 'bookingcheckedat' => $now]);
        audit::log('gradereceipt_' . $c['verdict'], $conn->key, 'attempt=' . $row->attemptid);
        return ['ok' => true, 'reason' => '', 'verdict' => $c['verdict'], 'mismatches' => $c['mismatches']];
    }

    /**
     * Compare a verified receipt with the attempt.
     *
     * @param \stdClass $row Journal row.
     * @param array $p Receipt payload.
     * @return array verdict (receipt_confirmed or receipt_mismatch), mismatches.
     */
    public static function compare(\stdClass $row, array $p): array {
        $m = [];
        if (empty($p['found']['lineitem'])) {
            $m[] = 'lineitem';
        } else if (empty($p['found']['user'])) {
            $m[] = 'user';
        } else {
            $g = $p['grade'] ?? [];
            $value = isset($g['value']) && is_numeric($g['value']) ? (float) $g['value'] : null;
            $max = (float) ($g['maximum'] ?? 0);
            if ($value === null) {
                $m[] = 'score';
            } else if ($max <= 0) {
                $m[] = 'maximum';
            } else if (abs($value / $max - (float) $row->scoregiven / max(0.00001, (float) $row->scoremax)) > 0.0001) {
                $m[] = 'score';
            }
            $time = (int) ($g['timemodified'] ?? 0);
            if ($time < (int) $row->agstimestamp) {
                $m[] = 'timestamp_older';
            } else if ($time > (int) $row->agstimestamp) {
                $m[] = 'timestamp_newer';
            }
            if (!empty($g['locked'])) {
                $m[] = 'locked';
            }
            if (!empty($g['overridden'])) {
                $m[] = 'overridden';
            }
        }
        return ['verdict' => $m ? 'receipt_mismatch' : 'receipt_confirmed', 'mismatches' => $m];
    }

    /**
     * The platform key set of the registration, fetched through the guarded fetcher.
     *
     * @param connection $conn Connection.
     * @return array|null
     */
    private static function fetch_keyset(connection $conn): ?array {
        $url = (string) ($conn->endpoints['jwks'] ?? '');
        if ($url === '' || url_guard::check($url, (bool) config::get('allowprivatetargets')) !== url_guard::OK) {
            return null;
        }
        $r = (new curl_fetcher())->get($url, (bool) config::get('allowprivatetargets'));
        if ($r->httpstatus !== 200 || $r->blocked !== '') {
            return null;
        }
        $json = json_decode($r->body, true);
        return is_array($json) && isset($json['keys']) ? $json : null;
    }
}
