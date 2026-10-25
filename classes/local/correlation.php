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

/**
 * Cross-source correlation with explicit confidence classes.
 *
 * direct: the same trace id. strong: at least two stable shared references and the same time window.
 * tentative: only the time window. unmatched: nothing links them. A client IP address is never a
 * reference; it is not even part of the compared data.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class correlation {
    /** @var string Confidence: same trace id. */
    public const DIRECT = 'direct';
    /** @var string Confidence: several stable shared references. */
    public const STRONG = 'strong';
    /** @var string Confidence: time window only. */
    public const TENTATIVE = 'tentative';
    /** @var string Confidence: unrelated. */
    public const UNMATCHED = 'unmatched';

    /** @var string[] Keys that are never used as a correlation reference, whatever the caller passes. */
    public const NEVER_REFERENCES = ['ip', 'ipmask', 'remoteaddr', 'clientip', 'useragent'];

    /**
     * Classify the relation of two normalised items.
     *
     * An item is an array with: time (int), traceid (string, may be empty), refs (array of stable references such as
     * issuerhash, courseid, cmid, userid, objectid, taskid, eventid).
     *
     * @param array $a First item.
     * @param array $b Second item.
     * @param int $window Time window in seconds that applies after clock skew correction.
     * @param int $skew Seconds to add to the times of $b to compensate for a known clock difference.
     * @return string One of the class constants.
     */
    public static function classify(array $a, array $b, int $window = 120, int $skew = 0): string {
        $ta = (string) ($a['traceid'] ?? '');
        $tb = (string) ($b['traceid'] ?? '');
        if ($ta !== '' && $ta === $tb) {
            return self::DIRECT;
        }
        $near = abs(((int) $a['time']) - ((int) $b['time'] + $skew)) <= $window;
        if (!$near) {
            return self::UNMATCHED;
        }
        $shared = 0;
        foreach ((array) ($a['refs'] ?? []) as $key => $value) {
            if (in_array($key, self::NEVER_REFERENCES, true)) {
                continue;
            }
            if ($value !== '' && $value !== 0 && $value !== null && isset($b['refs'][$key]) && $b['refs'][$key] === $value) {
                $shared++;
            }
        }
        return $shared >= 2 ? self::STRONG : self::TENTATIVE;
    }

    /**
     * Estimate the clock difference between two sources from directly correlated pairs.
     *
     * @param array[] $pairs Each [itema, itemb] known to be the same transaction.
     * @return int|null Median of (b - a) in seconds, or null without pairs.
     */
    public static function estimate_skew(array $pairs): ?int {
        $diffs = [];
        foreach ($pairs as $pair) {
            if (self::classify($pair[0], $pair[1], PHP_INT_MAX) === self::DIRECT) {
                $diffs[] = (int) $pair[1]['time'] - (int) $pair[0]['time'];
            }
        }
        if (!$diffs) {
            return null;
        }
        sort($diffs);
        return (int) $diffs[intdiv(count($diffs), 2)];
    }

    /**
     * Rank of a confidence class (higher is stronger).
     *
     * @param string $confidence Class.
     * @return int
     */
    public static function rank(string $confidence): int {
        $order = [self::UNMATCHED => 0, self::TENTATIVE => 1, self::STRONG => 2, self::DIRECT => 3];
        return $order[$confidence] ?? 0;
    }
}
