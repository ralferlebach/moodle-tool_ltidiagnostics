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
 * Server side filtered population of (consumer resource link, learner) pairs of one connection (issue #21).
 *
 * Every filter is evaluated in SQL, so the page, the total and the counters always describe the same population. The
 * counters are defined per dimension and are disjoint inside a dimension (they add up to the total); dimensions overlap
 * by nature and are never summed together:
 *
 * - source grade (gradebook of this site, now): present (> 0), zero (0 is a valid grade), none (NULL or no grade row);
 * - latest journal entry of the pair (any origin): none, skipped, pending (eligible, queued, sending, retry_wait),
 *   accepted (HTTP 2xx), failed (rejected, dead, aborted);
 * - consumer booking of that entry: confirmed (AGS result or signed receipt matches), mismatch, unknown (accepted, no
 *   confirmation; this is not "to be sent"), none (no accepted transfer).
 *
 * There is no "open work" figure: "not confirmed" does not mean "to be sent".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pair_query {
    /** @var string[] Source grade filter values. */
    public const GRADE = ['present', 'zero', 'none'];

    /** @var string[] Transfer filter values. */
    public const TRANSFER = ['none', 'skipped', 'pending', 'accepted', 'failed'];

    /** @var string[] Booking filter values. */
    public const BOOKING = ['confirmed', 'mismatch', 'unknown', 'none'];

    /** @var string[] Journal states per transfer class. */
    private const TRANSFERSTATES = [
        'skipped' => ['skipped'],
        'pending' => ['eligible', 'queued', 'sending', 'retry_wait'],
        'accepted' => ['http_accepted'],
        'failed' => ['http_rejected', 'dead', 'aborted'],
    ];

    /** @var string[] Booking states per booking class. */
    private const BOOKINGSTATES = [
        'confirmed' => ['results_match', 'receipt_confirmed'],
        'mismatch' => ['results_mismatch', 'receipt_mismatch'],
        'unknown' => ['unknown', 'unobservable'],
    ];

    /**
     * Normalised filter values from raw input (unknown values are dropped, never guessed).
     *
     * @param array $raw Raw values (grade, transfer, booking, linkid, userid, gradesync).
     * @return array
     */
    public static function clean(array $raw): array {
        $out = [
            'grade' => in_array($raw['grade'] ?? '', self::GRADE, true) ? $raw['grade'] : '',
            'transfer' => in_array($raw['transfer'] ?? '', self::TRANSFER, true) ? $raw['transfer'] : '',
            'booking' => in_array($raw['booking'] ?? '', self::BOOKING, true) ? $raw['booking'] : '',
            'linkid' => max(0, (int) ($raw['linkid'] ?? 0)),
            'userid' => max(0, (int) ($raw['userid'] ?? 0)),
            'gradesync' => in_array((string) ($raw['gradesync'] ?? ''), ['0', '1'], true) ? (string) $raw['gradesync'] : '',
            'usersearch' => \core_text::substr(trim((string) ($raw['usersearch'] ?? '')), 0, 100),
        ];
        return $out;
    }

    /**
     * SQL of the filtered population.
     *
     * The resource links are selected by deployment and published resource (a few ids), never by a list of link ids: a
     * deployment can have thousands of links, more than MSSQL (2 100) or Oracle (1 000 per IN list) accept as parameters.
     *
     * @param string $connkey Connection key.
     * @param int $dep Deployment row id of the connection.
     * @param int[] $toolids Published resources in scope.
     * @param array $items Tool id => grade item id (0: no item).
     * @param array $f Clean filters.
     * @return array [from+where SQL, params]
     */
    private static function sql(string $connkey, int $dep, array $toolids, array $items, array $f): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_values(array_map('intval', $toolids)), SQL_PARAMS_NAMED, 'pqtl');
        $params['pqdep'] = $dep;
        $case = '0';
        if ($items) {
            // Integer literals (cast here, from this site's tables): bound parameters in a CASE have no type on PostgreSQL.
            $parts = [];
            foreach ($items as $toolid => $itemid) {
                $parts[] = 'WHEN ' . (int) $toolid . ' THEN ' . (int) $itemid;
            }
            $case = 'CASE lu.toolid ' . implode(' ', $parts) . ' ELSE 0 END';
        }
        $params['pqck'] = $connkey;
        // The newest journal row per link and learner comes from one grouped derived table of this connection's journal
        // (a correlated subquery per pair let PostgreSQL pick very slow plans on large journals).
        $from = "FROM {enrol_lti_user_resource_link} url
                 JOIN {enrol_lti_resource_link} rl ON rl.id = url.resourcelinkid
                 JOIN {enrol_lti_users} lu ON lu.id = url.ltiuserid
                 JOIN {user} u ON u.id = lu.userid
            LEFT JOIN {grade_grades} gg ON gg.userid = lu.userid AND gg.itemid = $case
            LEFT JOIN (SELECT g2.resourcelinkrowid, g2.userid, MAX(g2.id) AS maxid
                         FROM {" . journal::TABLE . "} g2
                        WHERE g2.connkey = :pqck AND g2.resourcelinkrowid > 0
                     GROUP BY g2.resourcelinkrowid, g2.userid) gl
                   ON gl.resourcelinkrowid = url.resourcelinkid AND gl.userid = lu.userid
            LEFT JOIN {" . journal::TABLE . "} g ON g.id = gl.maxid";
        $where = ['rl.ltideploymentid = :pqdep', "rl.resourceid $insql"];
        if ($f['linkid']) {
            $where[] = 'url.resourcelinkid = :pqlink';
            $params['pqlink'] = $f['linkid'];
        }
        if ($f['userid']) {
            $where[] = 'lu.userid = :pquser';
            $params['pquser'] = $f['userid'];
        }
        if ($f['usersearch'] !== '') {
            // Name search: only offered to users who may see names (the caller checks the capability).
            $like = [];
            foreach (['firstname', 'lastname', 'email', 'idnumber'] as $i => $field) {
                $like[] = $DB->sql_like('u.' . $field, ':pqs' . $i, false, false);
                $params['pqs' . $i] = '%' . $DB->sql_like_escape($f['usersearch']) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
        if ($f['grade'] === 'present') {
            $where[] = 'gg.finalgrade IS NOT NULL AND gg.finalgrade <> 0';
        } else if ($f['grade'] === 'zero') {
            $where[] = 'gg.finalgrade = 0';
        } else if ($f['grade'] === 'none') {
            $where[] = 'gg.finalgrade IS NULL';
        }
        if ($f['transfer'] === 'none') {
            $where[] = 'g.id IS NULL';
        } else if ($f['transfer'] !== '') {
            [$s, $p] = $DB->get_in_or_equal(self::TRANSFERSTATES[$f['transfer']], SQL_PARAMS_NAMED, 'pqts');
            $where[] = "g.state $s";
            $params += $p;
        }
        if ($f['booking'] === 'none') {
            $where[] = "(g.id IS NULL OR g.state <> 'http_accepted')";
        } else if ($f['booking'] !== '') {
            [$s, $p] = $DB->get_in_or_equal(self::BOOKINGSTATES[$f['booking']], SQL_PARAMS_NAMED, 'pqbs');
            $where[] = "g.state = 'http_accepted' AND g.bookingstate $s";
            $params += $p;
        }
        return [$from . ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * One page of the filtered population, its total and the counters per dimension.
     *
     * @param string $connkey Connection key.
     * @param int $dep Deployment row id.
     * @param int[] $toolids Published resources in scope.
     * @param array $items Tool id => grade item id.
     * @param array $filters Clean filters.
     * @param int $offset Offset.
     * @param int $limit Limit.
     * @return array pairs (\stdClass[]), total (int), counters (dimension => value => count)
     */
    public static function page(
        string $connkey,
        int $dep,
        array $toolids,
        array $items,
        array $filters,
        int $offset,
        int $limit
    ): array {
        global $DB;
        $empty = ['pairs' => [], 'total' => 0, 'counters' => self::empty_counters()];
        if (!$dep || !$toolids) {
            return $empty;
        }
        [$fromwhere, $params] = self::sql($connkey, $dep, $toolids, $items, $filters);
        $total = (int) $DB->count_records_sql("SELECT COUNT(1) $fromwhere", $params);
        $rows = $DB->get_records_sql("SELECT url.id AS mapid, url.resourcelinkid AS resourcelinkrowid, lu.id AS ltiuserid,
                                             lu.userid, lu.toolid, lu.lastgrade, lu.ltideploymentid, lu.sourceid
                                        $fromwhere
                                    ORDER BY url.id ASC", $params, $offset, $limit);
        foreach ($rows as $r) {
            $r->sourceidhash = $r->sourceid === null || $r->sourceid === '' ? '' : hash('sha256', (string) $r->sourceid);
            unset($r->sourceid);
        }
        $counters = self::empty_counters();
        $rs = $DB->get_recordset_sql("SELECT CASE WHEN gg.finalgrade IS NULL THEN 'none'
                                                  WHEN gg.finalgrade = 0 THEN 'zero' ELSE 'present' END AS dim,
                                             COUNT(1) AS n
                                        $fromwhere
                                    GROUP BY CASE WHEN gg.finalgrade IS NULL THEN 'none'
                                                  WHEN gg.finalgrade = 0 THEN 'zero' ELSE 'present' END", $params);
        foreach ($rs as $r) {
            $counters['grade'][$r->dim] = (int) $r->n;
        }
        $rs->close();
        $rs = $DB->get_recordset_sql("SELECT g.state, g.bookingstate, COUNT(1) AS n
                                        $fromwhere
                                    GROUP BY g.state, g.bookingstate", $params);
        foreach ($rs as $r) {
            $transfer = self::transfer_class($r->state === null ? null : (string) $r->state);
            $counters['transfer'][$transfer] += (int) $r->n;
            $booking = $transfer === 'accepted' ? self::booking_class((string) $r->bookingstate) : 'none';
            $counters['booking'][$booking] += (int) $r->n;
        }
        $rs->close();
        return ['pairs' => array_values($rows), 'total' => $total, 'counters' => $counters];
    }

    /**
     * All pairs of the filtered population, bounded (for the server side selection of every match).
     *
     * @param string $connkey Connection key.
     * @param int $dep Deployment row id.
     * @param int[] $toolids Published resources in scope.
     * @param array $items Tool id => grade item id.
     * @param array $filters Clean filters.
     * @param int $max Maximum; more matches give an empty list and the real total.
     * @return array [pairs (linkid, userid), total]
     */
    public static function all(string $connkey, int $dep, array $toolids, array $items, array $filters, int $max): array {
        global $DB;
        if (!$dep || !$toolids) {
            return [[], 0];
        }
        [$fromwhere, $params] = self::sql($connkey, $dep, $toolids, $items, $filters);
        $total = (int) $DB->count_records_sql("SELECT COUNT(1) $fromwhere", $params);
        if ($total > $max) {
            return [[], $total];
        }
        $rows = $DB->get_records_sql("SELECT url.id, url.resourcelinkid, lu.userid $fromwhere ORDER BY url.id", $params, 0, $max);
        return [array_values(array_map(fn($r) => ['linkid' => (int) $r->resourcelinkid, 'userid' => (int) $r->userid], $rows)),
            $total];
    }

    /**
     * Counters with every value at 0.
     *
     * @return array
     */
    public static function empty_counters(): array {
        return [
            'grade' => array_fill_keys(self::GRADE, 0),
            'transfer' => array_fill_keys(self::TRANSFER, 0),
            'booking' => array_fill_keys(self::BOOKING, 0),
        ];
    }

    /**
     * Transfer class of a journal state.
     *
     * @param string|null $state State (null: no entry).
     * @return string
     */
    public static function transfer_class(?string $state): string {
        if ($state === null) {
            return 'none';
        }
        foreach (self::TRANSFERSTATES as $class => $states) {
            if (in_array($state, $states, true)) {
                return $class;
            }
        }
        return 'pending';
    }

    /**
     * Booking class of an accepted transfer.
     *
     * @param string $booking Booking state.
     * @return string
     */
    public static function booking_class(string $booking): string {
        foreach (self::BOOKINGSTATES as $class => $states) {
            if (in_array($booking, $states, true)) {
                return $class;
            }
        }
        return 'unknown';
    }
}
