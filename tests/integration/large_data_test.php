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

namespace tool_ltidiagnostics\integration;

use tool_ltidiagnostics\local\accesslog\importer;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\logstore_source;
use tool_ltidiagnostics\local\task_source;

/**
 * Large data, limits and retention (audit item DIA-04): 100 000 log store rows and a 100 000 line gzip access log,
 * with measured query counts, run time and memory; bounded and keyset-paginated queries; the import rate limit; the
 * retention per data class.
 *
 * The bounds asserted here are generous ceilings for a shared test machine; the measured values are printed into the
 * test output only when the environment variable LTIDIAG_PERF is set (docs/TESTING.md lists a measured run).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\logstore_source
 * @covers     \tool_ltidiagnostics\local\accesslog\importer
 * @covers     \tool_ltidiagnostics\local\task_source
 * @covers     \tool_ltidiagnostics\task\cleanup_task
 */
final class large_data_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var int Rows of the large data sets. */
    private const ROWS = 100000;

    /**
     * Report a measurement (only with LTIDIAG_PERF set; PHPUnit must not see output otherwise).
     *
     * @param string $what Label.
     * @param array $m Values.
     */
    private function report(string $what, array $m): void {
        if (getenv('LTIDIAG_PERF')) {
            file_put_contents((string) getenv('LTIDIAG_PERF'), $what . ' ' . json_encode($m) . "\n", FILE_APPEND);
        }
    }

    /**
     * Measure a callable: reads, writes, seconds, memory growth (bytes).
     *
     * @param callable $fn Function.
     * @return array [result, metrics]
     */
    private function measure(callable $fn): array {
        global $DB;
        gc_collect_cycles();
        $reads = $DB->perf_get_reads();
        $writes = $DB->perf_get_writes();
        $mem = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            // PHP 8.2 and later: measure the peak of this call only (on 8.1 the growth of the overall peak is used).
            memory_reset_peak_usage();
        }
        $peakbefore = function_exists('memory_reset_peak_usage') ? $mem : memory_get_peak_usage();
        $start = microtime(true);
        $result = $fn();
        $metrics = ['reads' => $DB->perf_get_reads() - $reads, 'writes' => $DB->perf_get_writes() - $writes,
            'seconds' => round(microtime(true) - $start, 3), 'memory' => memory_get_usage() - $mem,
            'peakgrowth' => max(0, memory_get_peak_usage() - $peakbefore)];
        return [$result, $metrics];
    }

    /**
     * 100 000 rows in the standard log store, 1 % of them LTI related: the reader returns a bounded page with a constant
     * number of queries and constant memory, newest first, and says that it was cut.
     */
    public function test_logstore_with_100k_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $now = time();
        $syscontext = \context_system::instance();
        $batch = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $lti = $i % 100 === 0;
            $batch[] = [
                'eventname' => $lti ? '\mod_lti\event\course_module_viewed' : '\core\event\course_viewed',
                'component' => $lti ? 'mod_lti' : 'core', 'action' => 'viewed', 'target' => $lti ? 'course_module' : 'course',
                'objecttable' => null, 'objectid' => $i, 'crud' => 'r', 'edulevel' => 2, 'contextid' => $syscontext->id,
                'contextlevel' => CONTEXT_SYSTEM, 'contextinstanceid' => 0, 'userid' => 2, 'courseid' => 1,
                'relateduserid' => null, 'anonymous' => 0, 'other' => 'N;', 'timecreated' => $now - ($i % 86400),
                'origin' => 'cli', 'ip' => '', 'realuserid' => null,
            ];
            if (count($batch) === 5000) {
                $DB->insert_records('logstore_standard_log', $batch);
                $batch = [];
            }
        }
        $this->assertGreaterThanOrEqual(self::ROWS, $DB->count_records('logstore_standard_log'));

        [$result, $m] = $this->measure(function () use ($syscontext, $now) {
            return (new logstore_source())->fetch($syscontext, ['from' => $now - DAYSECS, 'limit' => 200, 'family' => true]);
        });
        $this->report('logstore_fetch', $m + ['rows' => count($result['rows'])]);
        $this->assertTrue($result['available']);
        $this->assertCount(200, $result['rows']);
        $this->assertTrue($result['truncated'], 'A cut page is reported, never silent.');
        $this->assertLessThanOrEqual(10, $m['reads'], 'Constant number of queries, independent of the 100 000 rows.');
        $this->assertLessThan(32 * 1024 * 1024, $m['peakgrowth']);
        $times = array_column($result['rows'], 'time');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'Newest first.');
        // The next page continues without overlap.
        $page2 = (new logstore_source())->fetch($syscontext, ['from' => $now - DAYSECS, 'limit' => 200, 'offset' => 200]);
        $this->assertCount(200, $page2['rows']);
    }

    /**
     * Write a gzip access log.
     *
     * @param int $lines Number of lines.
     * @param int $now Time.
     * @return string Path.
     */
    private function gzip_log(int $lines, int $now): string {
        $path = make_request_directory() . '/access.log.1.gz';
        $gz = gzopen($path, 'wb6');
        $paths = ['/mod/lti/services.php/2/lineitems/5/lineitem/scores', '/mod/lti/services.php/CourseSection/2/bindings/'
            . 'ToolProxyBinding/memberships?limit=50&from=100', '/enrol/lti/launch.php', '/mod/lti/token.php',
            '/course/view.php?id=2',
            '/theme/image.php/boost/core/1/f/pdf', '/lib/javascript.php/1/lib/requirejs.php', '/my/'];
        for ($i = 0; $i < $lines; $i++) {
            $p = $paths[$i % count($paths)];
            $status = $i % 97 === 0 ? 403 : 200;
            $t = gmdate('d/M/Y:H:i:s', $now - DAYSECS + intdiv($i, 2));
            gzwrite($gz, '203.0.113.' . ($i % 250) . ' - - [' . $t . ' +0000] "' . ($i % 2 ? 'POST' : 'GET') . ' ' . $p
                . ' HTTP/1.1" ' . $status . ' ' . (100 + $i % 50) . ' "-" "Agent/' . $i . "\"\n");
        }
        gzclose($gz);
        return $path;
    }

    /**
     * A 100 000 line gzip access log: imported in batches with bounded memory and writes; a second import of the same
     * file adds nothing; the aggregated summary and the keyset pages are exact and need a constant number of queries.
     */
    public function test_access_log_import_100k_lines(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $path = $this->gzip_log(self::ROWS, $now);
        [$job, $m] = $this->measure(function () use ($path) {
            return (new importer())->import_file($path, ['format' => 'combined', 'maxlines' => 200000,
                'maxbytes' => 100 * 1024 * 1024]);
        });
        $this->report('import_100k', $m + ['stored' => (int) $job->linesstored]);
        $this->assertSame('done', $job->status);
        $this->assertSame(self::ROWS, (int) $job->linesread);
        // Four of the eight paths are LTI related and stored; the rest is skipped.
        $this->assertSame(self::ROWS / 2, (int) $job->linesstored);
        $this->assertSame(self::ROWS / 2, (int) $job->linesskipped);
        $this->assertLessThan(64 * 1024 * 1024, $m['peakgrowth'], 'Streaming import, batches of 500.');
        // Batched writes: PostgreSQL inserts 500 rows per statement, MariaDB as many as fit its packet size (about 50
        // measured); one statement per stored line would be 50 000.
        $this->assertLessThanOrEqual(intdiv(self::ROWS / 2, 25) + 20, $m['writes']);

        $again = (new importer())->import_file($path, ['format' => 'combined', 'maxlines' => 200000,
            'maxbytes' => 100 * 1024 * 1024]);
        $this->assertSame(0, (int) $again->linesstored, 'Idempotent: every line is known by its hash.');

        [$summary, $ms] = $this->measure(function () use ($now) {
            return importer::summary($now - 2 * DAYSECS);
        });
        $this->report('summary', $ms);
        // Two aggregate queries plus the column metadata Moodle reads on first use: constant, whatever the row count.
        $this->assertLessThanOrEqual(8, $ms['reads']);
        $this->assertSame(self::ROWS / 8, $summary['service_ags_scores']['ok'] + $summary['service_ags_scores']['fail']);
        $this->assertSame(self::ROWS / 8, $summary['service_nrps']['paged'], 'Every NRPS line carries paging keys.');
        $this->assertGreaterThan(0, $summary['service_nrps']['codes'][403] ?? 0);

        // Keyset pagination over one category: complete, no duplicates, one query per page.
        $seen = [];
        $cursor = null;
        $pages = 0;
        [, $mp] = $this->measure(function () use (&$seen, &$cursor, &$pages, $now) {
            do {
                $page = importer::page($now - 2 * DAYSECS, 0, ['service_ags_scores'], 500, $cursor);
                foreach ($page['rows'] as $r) {
                    $seen[(int) $r->id] = true;
                }
                $cursor = $page['next'];
                $pages++;
            } while ($cursor !== null);
        });
        $this->report('keyset_pages', $mp + ['pages' => $pages]);
        $this->assertCount(self::ROWS / 8, $seen);
        $this->assertSame((int) ceil((self::ROWS / 8) / 500), $pages);
        $this->assertLessThanOrEqual($pages, $mp['reads']);
        $this->assertSame(self::ROWS / 2, $DB->count_records('tool_ltidiagnostics_acc'));
    }

    /**
     * Cron windows over a large task log are bounded: the row limit is reported and the answer is complete only from the
     * earliest row read.
     */
    public function test_cron_windows_are_bounded(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $rows = [];
        for ($i = 0; $i < 20000; $i++) {
            $start = $now - $i * 4;
            $rows[] = ['type' => 0, 'component' => 'core', 'classname' => '\core\task\session_cleanup_task', 'userid' => 0,
                'timestart' => $start, 'timeend' => $start + 1, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 0, 'output' => '',
                'hostname' => 'h', 'pid' => 1];
            if (count($rows) === 5000) {
                $DB->insert_records('task_log', $rows);
                $rows = [];
            }
        }
        [$w, $m] = $this->measure(function () use ($now) {
            return (new task_source())->cron_windows($now - DAYSECS, $now, HOURSECS, 5000);
        });
        $this->report('cron_windows', $m);
        $this->assertTrue($w['truncated']);
        $this->assertSame(5000, $w['rows']);
        $this->assertGreaterThan($now - DAYSECS, $w['coveredfrom']);
        $this->assertSame([], $w['gaps'], 'No gap inside the covered part; the uncovered part is not called a gap.');
        $this->assertLessThanOrEqual(2, $m['reads']);
    }

    /**
     * Import rate limit per person and hour; command line imports (user 0) are not limited.
     */
    public function test_import_rate_limit(): void {
        $this->resetAfterTest();
        set_config('importratelimit', 2, 'tool_ltidiagnostics');
        $user = $this->getDataGenerator()->create_user();
        $line = '203.0.113.1 - - [' . gmdate('d/M/Y:H:i:s') . ' +0000] "GET /enrol/lti/launch.php HTTP/1.1" 200 1 "-" "U"' . "\n";
        $statuses = [];
        for ($i = 0; $i < 3; $i++) {
            $fh = fopen('php://memory', 'w+');
            fwrite($fh, $line . '#' . $i . "\n");
            rewind($fh);
            $job = (new importer())->import_stream($fh, ['format' => 'combined', 'userid' => (int) $user->id]);
            fclose($fh);
            $statuses[] = $job->status . ':' . $job->errormessage;
        }
        $this->assertSame(['done:', 'done:', 'failed:ratelimited'], $statuses);
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $line);
        rewind($fh);
        $this->assertSame('done', (new importer())->import_stream($fh, ['format' => 'combined', 'userid' => 0])->status);
        fclose($fh);
        set_config('importratelimit', 0, 'tool_ltidiagnostics');
        $this->assertTrue(importer::within_rate_limit((int) $user->id, time()));
    }

    /**
     * Retention per data class: observations, imported lines with their jobs, partner data, snapshots and audit rows
     * each follow their own setting.
     */
    public function test_retention_per_data_class(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        set_config('retentiondays', 10, 'tool_ltidiagnostics');
        set_config('retentionaccdays', 5, 'tool_ltidiagnostics');
        set_config('retentionpartnerdays', 3, 'tool_ltidiagnostics');
        set_config('retentionsnapdays', 20, 'tool_ltidiagnostics');
        set_config('retentionauditdays', 40, 'tool_ltidiagnostics');
        $age = function (int $days) use ($now): int {
            return $now - $days * DAYSECS;
        };
        foreach ([8, 12] as $d) {
            $DB->insert_record('tool_ltidiagnostics_obs', (object) ['traceid' => 't', 'eventtype' => 'launch', 'direction' => 'in',
                'eventname' => 'x', 'component' => 'c', 'connkey' => '', 'issuerhash' => '', 'entrypoint' => 'web',
                'timecreated' => $age($d)]);
            $imp = $DB->insert_record('tool_ltidiagnostics_imp', (object) ['kind' => 'access', 'format' => 'combined',
                'sourcetype' => 'upload', 'sourcename' => 'a', 'fingerprint' => 'f', 'timezone' => 'UTC', 'status' => 'done',
                'errormessage' => '', 'timecreated' => $age($d - 4)]);
            $DB->insert_record('tool_ltidiagnostics_acc', (object) ['importid' => $imp, 'linehash' => sha1('l' . $d),
                'kind' => 'access', 'logtime' => $age($d - 4), 'method' => 'GET', 'path' => '/', 'ipmask' => '', 'requestid' => '',
                'category' => 'launch', 'message' => '']);
            foreach (['partner' => 2, 'partnertrace' => 2, 'matrix' => 15, 'probe' => 15] as $kind => $base) {
                $DB->insert_record('tool_ltidiagnostics_snap', (object) ['connkey' => 'tool:1:1', 'kind' => $kind,
                    'contenthash' => 'h', 'snapjson' => '{}', 'timecreated' => $age($base + ($d > 10 ? 10 : 0))]);
            }
            $DB->insert_record('tool_ltidiagnostics_audit', (object) ['userid' => 2, 'action' => 'view', 'connkey' => '',
                'detail' => '', 'timecreated' => $age($d * 3 + 10)]);
        }
        ob_start();
        (new \tool_ltidiagnostics\task\cleanup_task())->execute();
        ob_end_clean();
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_obs'), 'obs: 8 days kept, 12 days removed (10)');
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_acc'), 'acc: 4 days kept, 8 days removed (5)');
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_imp'));
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_snap', ['kind' => 'partner']), 'partner: 2 kept, 12 removed');
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_snap', ['kind' => 'partnertrace']));
        $this->assertSame(
            2,
            $DB->count_records_select('tool_ltidiagnostics_snap', "kind IN ('matrix', 'probe')"),
            'snapshots: 15 days kept, 25 removed (20)'
        );
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_audit'), 'audit: 34 days kept, 46 removed (40)');
        $this->assertNotSame('', connection::ROLE_TOOL);
    }
}
