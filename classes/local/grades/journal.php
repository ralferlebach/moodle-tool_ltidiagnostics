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
 * Revision-proof journal of grade transfer attempts (issue #4).
 *
 * Two sources, never mixed up:
 *  - task_log: core's enrol_lti grade sync, read passively from the task log. Core has no hook for an outgoing score,
 *    so such a row states what core printed (skip reason, "sent", "failed" with a status line) and nothing more. Its
 *    line item and subject are unknown (empty hashes).
 *  - instrumented: an own grade replay (issue #5). Every step is written by the sender itself.
 *
 * The journal is separate from local_lticompletionsync's outbox and acknowledgements (completion is a different
 * channel). Rows hold codes, hashes and numbers, never a token, a JWT, a body or a name.
 *
 * State sequence: eligible -> skipped(reason) | queued -> sending -> http_accepted | http_rejected | retry_wait -> ...
 * -> dead; aborted when a precondition failed before anything was sent. Booking evidence is a separate dimension
 * (bookingstate) because an HTTP 2xx never proves that the consumer booked the score.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class journal {
    /** @var string Table. */
    public const TABLE = 'tool_ltidiagnostics_gatt';

    /** @var string[] States. */
    public const STATES = [
        'eligible', 'skipped', 'queued', 'sending', 'http_accepted', 'http_rejected', 'retry_wait', 'dead', 'aborted',
    ];
    /** @var string[] States of an attempt that is still in progress (a second one for the same target is refused). */
    public const OPEN_STATES = ['queued', 'sending', 'retry_wait'];

    /** @var string[] Booking evidence levels, weakest first. */
    public const BOOKING = [
        'unknown', 'unobservable', 'results_mismatch', 'receipt_mismatch', 'results_match', 'receipt_confirmed',
    ];

    /** @var string Origin: core task (passive). */
    public const ORIGIN_CORE = 'core_task';
    /** @var string Origin: single replay. */
    public const ORIGIN_REPLAY = 'replay';
    /** @var string Origin: batch replay. */
    public const ORIGIN_BATCH = 'replay_batch';
    /** @var string Origin: retry of a replay. */
    public const ORIGIN_RETRY = 'replay_retry';

    /** @var int Maximum page size. */
    public const MAXPAGE = 200;

    /**
     * A new attempt id.
     *
     * @return string UUID.
     */
    public static function new_id(): string {
        return \core\uuid::generate();
    }

    /**
     * Hash of an AGS subject (issuer and LTI user id). Used instead of the identifier itself.
     *
     * @param string $issuer Issuer.
     * @param string $sub LTI subject.
     * @return string
     */
    public static function subject_hash(string $issuer, string $sub): string {
        return $sub === '' ? '' : hash('sha256', $issuer . "\n" . $sub);
    }

    /**
     * Hash of a line item URL.
     *
     * @param string $url URL.
     * @return string
     */
    public static function lineitem_hash(string $url): string {
        return $url === '' ? '' : hash('sha256', $url);
    }

    /**
     * Insert a row. Missing fields get their defaults.
     *
     * @param array $data Fields.
     * @return \stdClass The stored row.
     */
    public static function insert(array $data): \stdClass {
        global $DB;
        $now = time();
        $attemptid = $data['attemptid'] ?? self::new_id();
        $row = (object) array_merge([
            'attemptid' => $attemptid,
            'correlationid' => $attemptid,
            'parentid' => 0,
            'attemptno' => 1,
            'origin' => self::ORIGIN_REPLAY,
            'source' => 'instrumented',
            'sourcekey' => $attemptid,
            'connkey' => '',
            'deploymentrowid' => 0,
            'resourcelinkrowid' => 0,
            'toolid' => 0,
            'userid' => 0,
            'subjecthash' => '',
            'lineitemhash' => '',
            'scoregiven' => null,
            'scoremax' => null,
            'sourceversion' => '',
            'gradetime' => 0,
            'agstimestamp' => 0,
            'stateversion' => 0,
            'state' => 'queued',
            'skipreason' => '',
            'httpstatus' => 0,
            'tokenstatus' => 'none',
            'errorclass' => '',
            'retryat' => 0,
            'evidence' => 'instrumented',
            'bookingstate' => 'unknown',
            'bookingdetail' => '',
            'bookingcheckedat' => 0,
            'operatorid' => 0,
            'purpose' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ], $data);
        $row->purpose = \core_text::substr(\tool_ltidiagnostics\local\redactor::text((string) $row->purpose), 0, 255);
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Update fields of a row.
     *
     * @param int $id Row id.
     * @param array $fields Fields.
     */
    public static function update(int $id, array $fields): void {
        global $DB;
        $fields['id'] = $id;
        $fields['timemodified'] = time();
        $DB->update_record(self::TABLE, (object) $fields);
    }

    /**
     * One row by attempt id.
     *
     * @param string $attemptid Attempt id.
     * @return \stdClass|null
     */
    public static function get(string $attemptid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['attemptid' => $attemptid]) ?: null;
    }

    /**
     * Whether an attempt for the same target is still open.
     *
     * @param int $resourcelinkrowid Resource link row.
     * @param int $userid Local user.
     * @return \stdClass|null The open attempt.
     */
    public static function open_attempt(int $resourcelinkrowid, int $userid): ?\stdClass {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::OPEN_STATES, SQL_PARAMS_NAMED, 's');
        $params += ['rl' => $resourcelinkrowid, 'u' => $userid, 'src' => 'instrumented'];
        $rows = $DB->get_records_select(
            self::TABLE,
            "resourcelinkrowid = :rl AND userid = :u AND source = :src AND state $insql",
            $params,
            'id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Keyset page of the journal of a connection, newest first.
     *
     * @param string $connkey Connection key.
     * @param array $filters Optional: resourcelinkrowid, userid, source, state, before (row id for the next page), limit.
     * @return array rows (\stdClass[]), next (row id for the next page or 0).
     */
    public static function page(string $connkey, array $filters = []): array {
        global $DB;
        $limit = max(1, min(self::MAXPAGE, (int) ($filters['limit'] ?? 50)));
        $where = 'connkey = :connkey';
        $params = ['connkey' => $connkey];
        foreach (['resourcelinkrowid', 'userid'] as $f) {
            if (!empty($filters[$f])) {
                $where .= " AND $f = :$f";
                $params[$f] = (int) $filters[$f];
            }
        }
        foreach (['source', 'state'] as $f) {
            if (!empty($filters[$f])) {
                $where .= " AND $f = :$f";
                $params[$f] = (string) $filters[$f];
            }
        }
        if (!empty($filters['before'])) {
            $where .= ' AND id < :before';
            $params['before'] = (int) $filters['before'];
        }
        $rows = array_values($DB->get_records_select(self::TABLE, $where, $params, 'id DESC', '*', 0, $limit + 1));
        $next = 0;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (int) end($rows)->id;
        }
        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * Rows by id, in chunks (bounded statements for any number of ids).
     *
     * @param int[] $ids Row ids.
     * @return \stdClass[] Rows keyed by id.
     */
    public static function rows_by_ids(array $ids): array {
        global $DB;
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            $out += $DB->get_records_list(self::TABLE, 'id', $chunk);
        }
        return $out;
    }

    /**
     * Latest row per (resource link, user) of a connection for many users at once (one query).
     *
     * @param string $connkey Connection key.
     * @param int[] $linkids Resource link row ids.
     * @param int[] $userids Local user ids (at most source_reader::MAXUSERS).
     * @param string $source Optional: only this source.
     * @param bool $transfersonly Ignore skips (the last transfer outcome is not erased by a later skip).
     * @return array Keyed "linkid:userid" => \stdClass.
     */
    public static function latest(
        string $connkey,
        array $linkids,
        array $userids,
        string $source = '',
        bool $transfersonly = false
    ): array {
        global $DB;
        if (!$linkids || !$userids) {
            return [];
        }
        [$lsql, $lparams] = $DB->get_in_or_equal(array_map('intval', $linkids), SQL_PARAMS_NAMED, 'l');
        [$usql, $uparams] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'u');
        $srcsql = $transfersonly ? " AND g2.state <> 'skipped'" : '';
        $params = $lparams + $uparams + ['connkey2' => $connkey];
        if ($source !== '') {
            $srcsql .= ' AND g2.source = :src2';
            $params['src2'] = $source;
        }
        // Two bounded steps instead of a join on the aggregate: the planner cannot pick a nested loop that repeats the
        // aggregate per row (seen on PostgreSQL right after a bulk insert, before the statistics were updated).
        $maxids = $DB->get_fieldset_sql("SELECT MAX(g2.id)
                                           FROM {" . self::TABLE . "} g2
                                          WHERE g2.connkey = :connkey2 AND g2.resourcelinkrowid $lsql AND g2.userid $usql $srcsql
                                       GROUP BY g2.resourcelinkrowid, g2.userid", $params);
        $out = [];
        foreach (self::rows_by_ids($maxids) as $r) {
            if ((string) $r->connkey !== $connkey || ($source !== '' && (string) $r->source !== $source)) {
                continue;
            }
            $out[(int) $r->resourcelinkrowid . ':' . (int) $r->userid] = $r;
        }
        return $out;
    }

    /**
     * Counts per state and source of a connection (for summaries). One grouped query.
     *
     * @param string $connkey Connection key.
     * @return array[] source => state => count.
     */
    public static function counts(string $connkey): array {
        global $DB;
        $out = [];
        $rs = $DB->get_recordset_sql("SELECT source, state, COUNT(1) AS n
                                        FROM {" . self::TABLE . "}
                                       WHERE connkey = :connkey
                                    GROUP BY source, state", ['connkey' => $connkey]);
        foreach ($rs as $r) {
            $out[(string) $r->source][(string) $r->state] = (int) $r->n;
        }
        $rs->close();
        return $out;
    }

    /**
     * Delete closed rows older than the retention (passive and replay rows share "retentiongradedays"). Open attempts stay.
     *
     * @param int $before Unix time.
     * @return int Deleted rows.
     */
    public static function purge(int $before): int {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::OPEN_STATES, SQL_PARAMS_NAMED, 's', false);
        $params['t'] = $before;
        $where = "timecreated < :t AND state $insql";
        $n = $DB->count_records_select(self::TABLE, $where, $params);
        $DB->delete_records_select(self::TABLE, $where, $params);
        return $n;
    }
}
