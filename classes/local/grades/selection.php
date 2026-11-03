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

/**
 * Server side selection of replay targets (issue #22): the marked rows of a page or every match of a filter, bound to
 * one connection, one user session and a time to live, and never larger than the batch limit.
 *
 * A selection only names targets; nothing is sent until its preview was confirmed, and every target is checked again
 * (connection, link, learner, source grade, scopes, endpoint) when it is queued.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selection {
    /** @var string Session key. */
    private const KEY = 'tool_ltidiagnostics_selections';

    /** @var int Maximum number of selections kept per session. */
    private const MAXKEPT = 10;

    /**
     * Parse "linkid:userid" values.
     *
     * @param string[] $values Values.
     * @return array[] Unique pairs (linkid, userid).
     */
    public static function parse(array $values): array {
        $out = [];
        foreach ($values as $v) {
            if (preg_match('/^(\d+):(\d+)$/', (string) $v, $m) && (int) $m[1] > 0 && (int) $m[2] > 0) {
                $out[$m[1] . ':' . $m[2]] = ['linkid' => (int) $m[1], 'userid' => (int) $m[2]];
            }
        }
        return array_values($out);
    }

    /**
     * Store a selection.
     *
     * @param string $connkey Connection key.
     * @param array[] $pairs Targets (linkid, userid).
     * @param string $origin How it was made: rows (marked rows), page (all rows of a page) or matches (every match).
     * @param array $filters Filters of the matches (for the preview text).
     * @param int $now Time.
     * @return array ok (bool), reason, token, count.
     */
    public static function create(string $connkey, array $pairs, string $origin, array $filters, int $now): array {
        global $SESSION;
        $max = \tool_ltidiagnostics\local\config::bounded('repairbatchmax');
        if (!$pairs) {
            return ['ok' => false, 'reason' => 'sel_empty', 'token' => '', 'count' => 0];
        }
        if (count($pairs) > $max) {
            return ['ok' => false, 'reason' => 'sel_toolarge', 'token' => '', 'count' => count($pairs)];
        }
        $all = isset($SESSION->{self::KEY}) && is_array($SESSION->{self::KEY}) ? $SESSION->{self::KEY} : [];
        $ttl = \tool_ltidiagnostics\local\config::bounded('selectionttl');
        $all = array_filter($all, fn($s) => (int) $s['created'] + $ttl >= $now);
        $token = bin2hex(random_bytes(16));
        $all[$token] = ['connkey' => $connkey, 'pairs' => array_values($pairs), 'origin' => $origin,
            'filters' => $filters, 'created' => $now];
        if (count($all) > self::MAXKEPT) {
            $all = array_slice($all, -self::MAXKEPT, null, true);
        }
        $SESSION->{self::KEY} = $all;
        return ['ok' => true, 'reason' => '', 'token' => $token, 'count' => count($pairs)];
    }

    /**
     * A selection of this session, when it exists, belongs to the connection and has not expired.
     *
     * @param string $token Token.
     * @param string $connkey Connection key.
     * @param int $now Time.
     * @return array|null connkey, pairs, origin, filters, created, expires.
     */
    public static function get(string $token, string $connkey, int $now): ?array {
        global $SESSION;
        $s = $SESSION->{self::KEY}[$token] ?? null;
        if (!is_array($s) || $s['connkey'] !== $connkey) {
            return null;
        }
        $expires = (int) $s['created'] + \tool_ltidiagnostics\local\config::bounded('selectionttl');
        if ($expires < $now) {
            self::drop($token);
            return null;
        }
        return $s + ['expires' => $expires];
    }

    /**
     * Forget a selection (after it was confirmed or cancelled).
     *
     * @param string $token Token.
     */
    public static function drop(string $token): void {
        global $SESSION;
        if (isset($SESSION->{self::KEY}[$token])) {
            unset($SESSION->{self::KEY}[$token]);
        }
    }

    /**
     * Batch id of a confirmed selection: a new random UUID, so that a second confirmation of the same selection is a
     * different batch and cannot be mistaken for the first one.
     *
     * @return string
     */
    public static function batch_id(): string {
        return \core\uuid::generate();
    }
}
