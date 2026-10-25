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
        $job->id = $DB->insert_record('tool_ltidiagnostics_imp', $job);

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
        $row->category = $category;
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
}
