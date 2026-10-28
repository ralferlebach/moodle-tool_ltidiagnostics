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

namespace tool_ltidiagnostics\local\accesslog;

/**
 * Read-only, idempotent, bounded import of web server logs into redacted, LTI-relevant rows.
 *
 * Only lines of LTI related URLs are stored (data minimisation); everything else is counted as
 * skipped. Re-importing the same content, or overlapping rotated files, creates no duplicates:
 * every stored line carries a keyed hash of its content and an occurrence counter.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var int Rows per insert batch. */
    private const BATCH = 500;

    /**
     * Import a local file (plain text or gzip).
     *
     * @param string $realpath Real path, already checked against the allow-list.
     * @param array $options See import_stream().
     * @return \stdClass The import job record.
     */
    public function import_file(string $realpath, array $options): \stdClass {
        $options['sourcename'] = basename($realpath);
        $options['sourcetype'] = 'path';
        $options['fingerprint'] = is_readable($realpath) ? (hash_file('sha256', $realpath) ?: '') : '';
        $fh = @gzopen($realpath, 'rb');
        if (!$fh) {
            return $this->fail($options, 'cannotopen');
        }
        try {
            return $this->import_stream($fh, $options, true);
        } finally {
            gzclose($fh);
        }
    }

    /**
     * Import from a PHP stream (plain text) or zlib stream.
     *
     * @param resource $fh Stream.
     * @param array $options kind (access|error), format, timezone, skew (seconds), maxlines, maxbytes,
     *        sourcename, sourcetype, fingerprint, userid, from, to (unix, optional range filter).
     * @param bool $zlib True when $fh was opened with gzopen.
     * @return \stdClass The import job record.
     */
    public function import_stream($fh, array $options, bool $zlib = false): \stdClass {
        global $DB;
        $o = $options + ['kind' => 'access', 'format' => 'combined', 'timezone' => 'UTC', 'skew' => 0, 'maxlines' => 50000,
            'maxbytes' => 52428800, 'sourcename' => '', 'sourcetype' => 'upload', 'fingerprint' => '', 'userid' => 0,
            'from' => 0, 'to' => 0];
        $job = (object) [
            'kind' => $o['kind'] === 'error' ? 'error' : 'access',
            'format' => $o['format'],
            'sourcetype' => $o['sourcetype'],
            'sourcename' => \core_text::substr(clean_param($o['sourcename'], PARAM_FILE), 0, 255),
            'fingerprint' => $o['fingerprint'] !== '' ? $o['fingerprint'] : hash('sha256', uniqid('', true)),
            'timezone' => $o['timezone'],
            'skew' => (int) $o['skew'],
            'rangefrom' => 0,
            'rangeto' => 0,
            'linesread' => 0,
            'linesstored' => 0,
            'linesskipped' => 0,
            'linesinvalid' => 0,
            'status' => 'running',
            'errormessage' => '',
            'userid' => (int) $o['userid'],
            'timecreated' => time(),
        ];
        if (!in_array($job->format, parser::formats($job->kind), true)) {
            return $this->fail($o, 'badformat');
        }
        if (!self::within_rate_limit((int) $o['userid'], (int) $job->timecreated)) {
            // DIA-04: imports are expensive (parsing, hashing, inserts); a person may start only a bounded number per hour.
            return $this->fail($o, 'ratelimited');
        }
        $job->id = $DB->insert_record('tool_ltidiagnostics_imp', $job);
        try {
            return $this->read_into($job, $fh, $o, $zlib);
        } catch (\Throwable $e) {
            // A job must never stay "running": whatever went wrong (a database write, a broken stream), the job ends
            // as failed with a short reason. Rows stored before the error stay attached to the job and can be deleted
            // with it. The exception text is not stored: it may contain parts of the log line.
            $job->status = 'failed';
            $job->errormessage = $e instanceof \dml_exception ? 'dberror' : 'readerror';
            $DB->update_record('tool_ltidiagnostics_imp', $job);
            return $job;
        }
    }

    /**
     * Whether a person may start another import now (setting importratelimit per hour; 0 switches the limit off).
     * Imports from the command line (user 0) are not limited.
     *
     * @param int $userid User id.
     * @param int $now Time.
     * @return bool
     */
    public static function within_rate_limit(int $userid, int $now): bool {
        global $DB;
        $limit = (int) \tool_ltidiagnostics\local\config::get('importratelimit');
        if ($userid <= 0 || $limit <= 0) {
            return true;
        }
        $count = $DB->count_records_select(
            'tool_ltidiagnostics_imp',
            'userid = :u AND timecreated > :t',
            ['u' => $userid, 't' => $now - HOURSECS]
        );
        return $count < $limit;
    }

    /**
     * Aggregated counts of imported access lines per category and status inside a window: one bounded GROUP BY query
     * (indexes category_time and logtime), independent of the number of rows; nothing is loaded into memory.
     *
     * @param int $from Start (unix).
     * @param int $to End (unix, 0 = open).
     * @return array category => [ok, fail, last, lastok, lastfail, codes (status => count), paged]
     */
    public static function summary(int $from, int $to = 0): array {
        global $DB;
        $where = 'kind = :kind AND logtime >= :tfrom';
        $params = ['kind' => 'access', 'tfrom' => $from];
        if ($to > 0) {
            $where .= ' AND logtime <= :tto';
            $params['tto'] = $to;
        }
        $out = [];
        $rs = $DB->get_recordset_sql(
            "SELECT category, httpstatus, COUNT(1) AS n, MAX(logtime) AS last
               FROM {tool_ltidiagnostics_acc}
              WHERE $where
           GROUP BY category, httpstatus",
            $params
        );
        foreach ($rs as $r) {
            $cat = (string) $r->category;
            $code = (int) $r->httpstatus;
            $ok = $code >= 200 && $code < 400;
            $out[$cat] = $out[$cat] ?? ['ok' => 0, 'fail' => 0, 'last' => 0, 'lastok' => 0, 'lastfail' => 0, 'codes' => [],
                'paged' => 0];
            $out[$cat][$ok ? 'ok' : 'fail'] += (int) $r->n;
            $out[$cat]['codes'][$code] = (int) $r->n;
            $out[$cat]['last'] = max($out[$cat]['last'], (int) $r->last);
            $out[$cat][$ok ? 'lastok' : 'lastfail'] = max($out[$cat][$ok ? 'lastok' : 'lastfail'], (int) $r->last);
        }
        $rs->close();
        // Paging of membership and line item lists: the query keys survive the redaction, the values do not.
        $likes = [];
        foreach (['from', 'limit', 'page', 'offset', 'rlid'] as $i => $key) {
            $likes[] = $DB->sql_like('path', ':pq' . $i) . ' OR ' . $DB->sql_like('path', ':pa' . $i);
            $params['pq' . $i] = '%?' . $key . '=%';
            $params['pa' . $i] = '%&' . $key . '=%';
        }
        [$insql, $inparams] = $DB->get_in_or_equal(
            ['service_nrps', 'service_ags_lineitems', 'service_ags_results'],
            SQL_PARAMS_NAMED,
            'pc'
        );
        $rs = $DB->get_recordset_sql(
            "SELECT category, COUNT(1) AS n FROM {tool_ltidiagnostics_acc}
              WHERE $where AND category $insql AND (" . implode(' OR ', $likes) . ")
           GROUP BY category",
            $params + $inparams
        );
        foreach ($rs as $r) {
            if (isset($out[$r->category])) {
                $out[$r->category]['paged'] = (int) $r->n;
            }
        }
        $rs->close();
        return $out;
    }

    /**
     * One page of imported lines, newest first, with keyset pagination (stable under concurrent imports, no OFFSET).
     *
     * @param int $from Start (unix).
     * @param int $to End (unix, 0 = open).
     * @param string[] $categories Restrict to categories (empty = all).
     * @param int $limit Page size (1..500).
     * @param array|null $after Cursor [logtime, id] of the last row of the previous page.
     * @return array rows (stdClass[]), next (cursor or null when this was the last page)
     */
    public static function page(int $from, int $to = 0, array $categories = [], int $limit = 100, ?array $after = null): array {
        global $DB;
        $limit = max(1, min(500, $limit));
        $where = 'logtime >= :tfrom';
        $params = ['tfrom' => $from];
        if ($to > 0) {
            $where .= ' AND logtime <= :tto';
            $params['tto'] = $to;
        }
        if ($categories) {
            [$insql, $inparams] = $DB->get_in_or_equal($categories, SQL_PARAMS_NAMED, 'cat');
            $where .= " AND category $insql";
            $params += $inparams;
        }
        if ($after !== null) {
            $where .= ' AND (logtime < :atime OR (logtime = :atime2 AND id < :aid))';
            $params += ['atime' => (int) $after[0], 'atime2' => (int) $after[0], 'aid' => (int) $after[1]];
        }
        $rows = array_values($DB->get_records_select(
            'tool_ltidiagnostics_acc',
            $where,
            $params,
            'logtime DESC, id DESC',
            '*',
            0,
            $limit + 1
        ));
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = end($rows);
            $next = [(int) $last->logtime, (int) $last->id];
        }
        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * The category as it can be stored. Defence in depth for Gate 2 finding F3: an unknown category or one that does
     * not fit the column would abort the whole import, so it is stored as "other".
     *
     * @param string $category Category from the parser.
     * @return string
     */
    public static function storable_category(string $category): string {
        if (!in_array($category, parser::CATEGORIES, true) || \core_text::strlen($category) > parser::CATEGORY_MAXLEN) {
            return 'other';
        }
        return $category;
    }

    /**
     * Read the stream into an existing job and finish the job.
     *
     * @param \stdClass $job The job record (with id, status running).
     * @param resource $fh Open stream.
     * @param array $o Normalised options.
     * @param bool $zlib True for a gzopen handle.
     * @return \stdClass The finished job.
     */
    private function read_into(\stdClass $job, $fh, array $o, bool $zlib): \stdClass {
        global $DB;
        $seen = [];
        $batch = [];
        $bytes = 0;
        $status = 'done';
        $message = '';
        while (true) {
            $line = $zlib ? gzgets($fh, 65536) : fgets($fh, 65536);
            if ($line === false) {
                break;
            }
            $bytes += strlen($line);
            if ($bytes > (int) $o['maxbytes']) {
                $status = 'truncated';
                $message = 'maxbytes';
                break;
            }
            if ($job->linesread >= (int) $o['maxlines']) {
                $status = 'truncated';
                $message = 'maxlines';
                break;
            }
            if (trim($line) === '') {
                continue;
            }
            $job->linesread++;
            $row = $this->line_to_row($line, $job, $o, $seen);
            if ($row === null) {
                $job->linesinvalid++;
                continue;
            }
            if ($row === false) {
                $job->linesskipped++;
                continue;
            }
            $job->rangefrom = $job->rangefrom ? min($job->rangefrom, $row->logtime) : $row->logtime;
            $job->rangeto = max($job->rangeto, $row->logtime);
            $batch[] = $row;
            if (count($batch) >= self::BATCH) {
                $job->linesstored += $this->flush($batch);
                $batch = [];
            }
        }
        $job->linesstored += $this->flush($batch);
        $job->status = $status;
        $job->errormessage = $message;
        $DB->update_record('tool_ltidiagnostics_imp', $job);
        return $job;
    }

    /**
     * Convert one line into a row.
     *
     * @param string $line Raw line.
     * @param \stdClass $job Job (for kind, format and time zone).
     * @param array $o Options.
     * @param int[] $seen Occurrence counters per content hash (by reference).
     * @return \stdClass|null|false Row; null if the line is invalid; false if it is not relevant.
     */
    private function line_to_row(string $line, \stdClass $job, array $o, array &$seen) {
        if ($job->kind === 'access') {
            $p = parser::parse_access_line($line, $job->format, $job->timezone);
            if ($p === null) {
                return null;
            }
            $category = parser::categorise($p['path']);
            $row = (object) [
                'kind' => 'access', 'method' => $p['method'], 'path' => $p['path'], 'httpstatus' => $p['status'],
                'bytes' => $p['bytes'], 'ipmask' => $p['ip'], 'viaproxy' => (int) $p['viaproxy'],
                'requestid' => $p['requestid'], 'message' => '',
            ];
        } else {
            $p = parser::parse_error_line($line, $job->format, $job->timezone);
            if ($p === null) {
                return null;
            }
            $category = parser::categorise($p['path']);
            if ($category === '' && stripos($p['message'], 'lti') !== false) {
                $category = 'other';
            }
            $row = (object) [
                'kind' => 'error', 'method' => '', 'path' => $p['path'], 'httpstatus' => 0, 'bytes' => 0,
                'ipmask' => $p['ip'], 'viaproxy' => 0, 'requestid' => '', 'message' => $p['message'],
            ];
        }
        if ($category === '') {
            return false;
        }
        $time = $p['time'] + (int) $o['skew'];
        if (($o['from'] && $time < $o['from']) || ($o['to'] && $time > $o['to'])) {
            return false;
        }
        $content = hash_hmac('sha1', $job->kind . '|' . trim($line), get_site_identifier());
        $seen[$content] = ($seen[$content] ?? 0) + 1;
        $row->linehash = sha1($content . '#' . $seen[$content]);
        $row->importid = $job->id;
        $row->logtime = $time;
        $row->category = self::storable_category($category);
        return $row;
    }

    /**
     * Insert rows that are not stored yet.
     *
     * @param \stdClass[] $rows Rows.
     * @return int Number of inserted rows.
     */
    private function flush(array $rows): int {
        global $DB;
        if (!$rows) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map(function ($r) {
            return $r->linehash;
        }, $rows), SQL_PARAMS_NAMED);
        $existing = array_flip($DB->get_fieldset_select('tool_ltidiagnostics_acc', 'linehash', "linehash $insql", $params));
        $new = array_values(array_filter($rows, function ($r) use ($existing) {
            return !isset($existing[$r->linehash]);
        }));
        if ($new) {
            $DB->insert_records('tool_ltidiagnostics_acc', $new);
        }
        return count($new);
    }

    /**
     * Record a failed job without reading anything.
     *
     * @param array $o Options.
     * @param string $reason Reason code.
     * @return \stdClass
     */
    private function fail(array $o, string $reason): \stdClass {
        global $DB;
        $job = (object) [
            'kind' => ($o['kind'] ?? 'access') === 'error' ? 'error' : 'access',
            'format' => (string) ($o['format'] ?? ''),
            'sourcetype' => (string) ($o['sourcetype'] ?? 'upload'),
            'sourcename' => \core_text::substr(clean_param((string) ($o['sourcename'] ?? ''), PARAM_FILE), 0, 255),
            'fingerprint' => (string) ($o['fingerprint'] ?? ''),
            'timezone' => (string) ($o['timezone'] ?? 'UTC'),
            'skew' => (int) ($o['skew'] ?? 0),
            'rangefrom' => 0, 'rangeto' => 0, 'linesread' => 0, 'linesstored' => 0, 'linesskipped' => 0, 'linesinvalid' => 0,
            'status' => 'failed',
            'errormessage' => $reason,
            'userid' => (int) ($o['userid'] ?? 0),
            'timecreated' => time(),
        ];
        $job->id = $DB->insert_record('tool_ltidiagnostics_imp', $job);
        return $job;
    }

    /**
     * Stored access rows in a time window.
     *
     * @param int $from Start (unix).
     * @param int $to End (unix, 0 = open).
     * @param string[] $categories Restrict to categories (empty = all).
     * @param int $limit Maximum rows (max 1000).
     * @return \stdClass[] Oldest first.
     */
    public static function get_rows(int $from, int $to = 0, array $categories = [], int $limit = 500): array {
        global $DB;
        $where = 'logtime >= :tfrom';
        $params = ['tfrom' => $from];
        if ($to > 0) {
            $where .= ' AND logtime <= :tto';
            $params['tto'] = $to;
        }
        if ($categories) {
            [$insql, $inparams] = $DB->get_in_or_equal($categories, SQL_PARAMS_NAMED, 'cat');
            $where .= " AND category $insql";
            $params += $inparams;
        }
        return array_values($DB->get_records_select(
            'tool_ltidiagnostics_acc',
            $where,
            $params,
            'logtime ASC, id ASC',
            '*',
            0,
            max(1, min(1000, $limit))
        ));
    }

    /**
     * Delete one import job together with every log line it stored.
     *
     * The lines are redacted and only LTI related, but they still relate to requests of real people, so an
     * administrator must be able to remove them before the retention period ends.
     *
     * @param int $importid Import id.
     * @return int Number of deleted log lines.
     */
    public static function delete_import(int $importid): int {
        global $DB;
        require_capability('tool/ltidiagnostics:importlogs', \context_system::instance());
        $lines = $DB->count_records('tool_ltidiagnostics_acc', ['importid' => $importid]);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('tool_ltidiagnostics_acc', ['importid' => $importid]);
        $DB->delete_records('tool_ltidiagnostics_imp', ['id' => $importid]);
        $transaction->allow_commit();
        \tool_ltidiagnostics\local\audit::log('deleteimport', '', 'job=' . $importid . ',lines=' . $lines);
        return $lines;
    }
}
