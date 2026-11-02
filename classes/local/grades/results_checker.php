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
use tool_ltidiagnostics\local\connection;

/**
 * Reads the AGS result of one learner on one line item and compares it with what was sent (issue #6).
 *
 * Only with the result.readonly scope that the platform actually granted in the launch claim and in the token, only
 * against the line item of the claim (or the one the replay used) and only through the guarded transport. Without the
 * scope the remote state stays "unobservable"; nothing tries to get around the partner's permissions.
 *
 * A matching AGS result is its own evidence level ("results_match"): the consumer's results service reports the
 * value. It is weaker than a partner receipt from the consumer's gradebook ("receipt_confirmed").
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class results_checker {
    /** @var string Media type of a result container. */
    public const RESULTS_TYPE = 'application/vnd.ims.lis.v2.resultcontainer+json';
    /** @var string Results scope. */
    public const SCOPE_RESULT = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';

    /** @var ags_transport Transport. */
    private $transport;

    /**
     * Constructor.
     *
     * @param ags_transport|null $transport Transport.
     */
    public function __construct(?ags_transport $transport = null) {
        $this->transport = $transport ?? new moodle_ags_transport();
    }

    /**
     * Compare an AGS result with the expected score.
     *
     * Moodle as consumer rescales the score to the maximum of its own grade item, so the ratio is compared, not the raw
     * number; the raw maximum is reported separately.
     *
     * @param array|null $json Result container.
     * @param string $sub Expected AGS userId.
     * @param string $lineitem Line item URL.
     * @param float|null $score Expected score.
     * @param float|null $max Expected maximum.
     * @param int $timestamp Timestamp that was sent (0: not known).
     * @return array verdict (results_match or results_mismatch), mismatches (string[]), resultscore, resultmax, resulttime.
     */
    public static function compare(
        ?array $json,
        string $sub,
        string $lineitem,
        ?float $score,
        ?float $max,
        int $timestamp
    ): array {
        $mine = null;
        foreach ((array) $json as $r) {
            if (is_array($r) && (string) ($r['userId'] ?? '') === $sub) {
                $mine = $r;
                break;
            }
        }
        if ($mine === null) {
            return ['verdict' => 'results_mismatch', 'mismatches' => ['user'], 'resultscore' => null, 'resultmax' => null,
                'resulttime' => 0];
        }
        $mismatches = [];
        $rscore = isset($mine['resultScore']) && is_numeric($mine['resultScore']) ? (float) $mine['resultScore'] : null;
        $rmax = isset($mine['resultMaximum']) && is_numeric($mine['resultMaximum']) ? (float) $mine['resultMaximum'] : 1.0;
        // Moodle's results service answers scoreOf with the line item URL including "?type_id=": compare the paths.
        if (
            isset($mine['scoreOf']) && is_string($mine['scoreOf']) && $lineitem !== ''
                && self::lineitem_path($mine['scoreOf']) !== self::lineitem_path($lineitem)
        ) {
            $mismatches[] = 'lineitem';
        }
        if ($rscore === null) {
            $mismatches[] = 'score';
        } else if ($score !== null && $max) {
            $expected = $score / $max;
            $actual = $rmax > 0 ? $rscore / $rmax : null;
            if ($actual === null || abs($expected - $actual) > 0.0001) {
                $mismatches[] = 'score';
            }
        }
        if ($rmax <= 0) {
            $mismatches[] = 'maximum';
        }
        $rtime = !empty($mine['timestamp']) && is_string($mine['timestamp']) ? (int) strtotime($mine['timestamp']) : 0;
        if ($timestamp && $rtime) {
            if ($rtime < $timestamp) {
                $mismatches[] = 'timestamp_older';
            } else if ($rtime > $timestamp) {
                $mismatches[] = 'timestamp_newer';
            }
        }
        return ['verdict' => $mismatches ? 'results_mismatch' : 'results_match', 'mismatches' => $mismatches,
            'resultscore' => $rscore, 'resultmax' => $rmax, 'resulttime' => $rtime];
    }

    /**
     * Line item URL without query and trailing slash.
     *
     * @param string $url URL.
     * @return string
     */
    public static function lineitem_path(string $url): string {
        $pos = strpos($url, '?');
        return rtrim($pos === false ? $url : substr($url, 0, $pos), '/');
    }

    /**
     * Check the result after an accepted replay and store the booking evidence in its journal row.
     *
     * @param \stdClass $row Journal row (http_accepted).
     * @param string $lineitem Line item URL that was used.
     * @param array $in Internal target data (sub, issuer, scopes).
     * @param mixed $registration Registration.
     * @return array As compare(), plus errorclass.
     */
    public function check_attempt(\stdClass $row, string $lineitem, array $in, $registration): array {
        $r = $this->fetch($lineitem, $in, $registration);
        if ($r['errorclass'] !== '') {
            $booking = in_array($r['errorclass'], ['scope_missing', 'http_401', 'http_403'], true) ? 'unobservable' : 'unknown';
            journal::update((int) $row->id, ['bookingstate' => $booking, 'bookingdetail' => $r['errorclass'],
                'bookingcheckedat' => time()]);
            return $r + ['verdict' => $booking, 'mismatches' => []];
        }
        $c = self::compare(
            $r['json'],
            $in['sub'],
            $lineitem,
            $row->scoregiven === null ? null : (float) $row->scoregiven,
            $row->scoremax === null ? null : (float) $row->scoremax,
            (int) $row->agstimestamp
        );
        journal::update((int) $row->id, ['bookingstate' => $c['verdict'],
            'bookingdetail' => substr(implode(',', $c['mismatches']), 0, 100), 'bookingcheckedat' => time()]);
        audit::log('graderesult_' . $c['verdict'], (string) $row->connkey, 'attempt=' . $row->attemptid);
        return $c + ['errorclass' => ''];
    }

    /**
     * Read-only check of a link and learner on request (also after a core send). Recorded on the latest accepted journal
     * row of that link and learner when there is one.
     *
     * @param connection $conn Connection.
     * @param int $linkid Resource link row.
     * @param int $userid Learner.
     * @return array verdict, mismatches, errorclass, recorded (bool).
     */
    public function check_link(connection $conn, int $linkid, int $userid): array {
        global $DB;
        $t = (new target_resolver())->resolve($conn, $linkid, $userid);
        $in = $t['internal'];
        $out = ['verdict' => 'unobservable', 'mismatches' => [], 'errorclass' => '', 'recorded' => false];
        if (!$in || $in['sub'] === '' || $in['lineitem'] === '') {
            $out['errorclass'] = $in && $in['sub'] === '' ? 'mapping_missing' : 'lineitem_missing';
            return $out;
        }
        if (!$in['scopes']['result']) {
            $out['errorclass'] = 'scope_missing';
            return $out;
        }
        $registration = repair_service::registration($in['issuer'], $in['clientid']);
        if (!$registration) {
            $out['errorclass'] = 'token';
            return $out;
        }
        $rows = $DB->get_records(journal::TABLE, ['resourcelinkrowid' => $linkid, 'userid' => $userid,
            'state' => 'http_accepted'], 'id DESC', '*', 0, 1);
        $row = $rows ? reset($rows) : null;
        if ($row && $row->source === 'instrumented') {
            return $this->check_attempt($row, $in['lineitem'], $in, $registration) + ['recorded' => true];
        }
        $r = $this->fetch($in['lineitem'], $in, $registration);
        if ($r['errorclass'] !== '') {
            return array_merge($out, ['errorclass' => $r['errorclass']]);
        }
        // Compared with the current source grade (core sends do not carry a known timestamp).
        $c = self::compare(
            $r['json'],
            $in['sub'],
            $in['lineitem'],
            $in['grade'] === null ? null : (float) $in['grade'],
            $in['grademax'] === null ? null : (float) $in['grademax'],
            0
        );
        if ($row) {
            journal::update((int) $row->id, ['bookingstate' => $c['verdict'],
                'bookingdetail' => substr(implode(',', $c['mismatches']), 0, 100), 'bookingcheckedat' => time()]);
        }
        audit::log('graderesult_' . $c['verdict'], $conn->key, 'link=' . $linkid);
        return ['verdict' => $c['verdict'], 'mismatches' => $c['mismatches'], 'errorclass' => '', 'recorded' => (bool) $row];
    }

    /**
     * Token with the results scope and GET of the result of one learner.
     *
     * @param string $lineitem Line item URL.
     * @param array $in Internal target data.
     * @param mixed $registration Registration.
     * @return array errorclass, json.
     */
    private function fetch(string $lineitem, array $in, $registration): array {
        if (empty($in['scopes']['result'])) {
            return ['errorclass' => 'scope_missing', 'json' => null];
        }
        $token = $this->transport->token($registration, [self::SCOPE_RESULT]);
        if ($token['token'] === '') {
            return ['errorclass' => $token['errorclass'] ?: 'token', 'json' => null];
        }
        if ($token['scopes'] && !in_array(self::SCOPE_RESULT, $token['scopes'], true)) {
            return ['errorclass' => 'scope_missing', 'json' => null];
        }
        $url = repair_service::with_query(repair_service::append_path($lineitem, '/results'), ['user_id' => $in['sub']]);
        $r = $this->transport->get_json($url, $token['token'], self::RESULTS_TYPE);
        return ['errorclass' => $r['errorclass'], 'json' => $r['json']];
    }
}
