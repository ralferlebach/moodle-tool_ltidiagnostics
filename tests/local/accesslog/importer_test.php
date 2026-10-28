<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_ltidiagnostics\local\accesslog;

/**
 * Tests of the allow-list and the log importer against the real database.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\accesslog\importer
 * @covers     \tool_ltidiagnostics\local\accesslog\path_allowlist
 */
final class importer_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * Build access log text.
     *
     * @return string
     */
    private function sample(): string {
        $lines = [
            '203.0.113.9 - - [24/Oct/2026:12:00:00 +0000] "POST /mod/lti/auth.php?state=SECRET HTTP/1.1" 302 0 "-" "UA"',
            '203.0.113.9 - - [24/Oct/2026:12:00:01 +0000] "POST /enrol/lti/launch.php HTTP/1.1" 200 10 "-" "UA"',
            '203.0.113.9 - - [24/Oct/2026:12:00:02 +0000] "GET /index.php HTTP/1.1" 200 10 "-" "UA"',
            'this line is not a log line',
            '203.0.113.9 - - [24/Oct/2026:12:00:03 +0000] '
                . '"POST /mod/lti/services.php/2/lineitems/5/lineitem/scores HTTP/1.1" 200 5 "-" "UA"',
        ];
        return implode("\n", $lines) . "\n";
    }

    /**
     * Import from a stream: counts, filtering, redaction.
     */
    public function test_import_counts_and_redaction(): void {
        global $DB;
        $this->resetAfterTest();
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $this->sample());
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['format' => 'combined', 'sourcename' => 'x.log']);
        fclose($fh);
        $this->assertSame('done', $job->status);
        $this->assertSame(5, $job->linesread);
        $this->assertSame(3, $job->linesstored);
        $this->assertSame(1, $job->linesskipped, 'Non LTI paths are not stored.');
        $this->assertSame(1, $job->linesinvalid);
        $rows = $DB->get_records('tool_ltidiagnostics_acc');
        $this->assertCount(3, $rows);
        $dump = json_encode(array_values($rows));
        $this->assertStringNotContainsString('SECRET', $dump);
        $this->assertStringNotContainsString('203.0.113.9', $dump);
        $this->assertStringContainsString('203.0.113.0', $dump);
        $this->assertSame(gmmktime(12, 0, 0, 10, 24, 2026), (int) $job->rangefrom);
        $this->assertSame(gmmktime(12, 0, 3, 10, 24, 2026), (int) $job->rangeto);
        $categories = array_column($rows, 'category');
        $this->assertEqualsCanonicalizing(['oidc', 'launch', 'service_ags_scores'], $categories);
    }

    /**
     * Importing the same file twice stores each line once, while real duplicates in one file are kept.
     */
    public function test_import_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $dir = make_request_directory();
        $line = '203.0.113.9 - - [24/Oct/2026:12:00:01 +0000] "POST /enrol/lti/launch.php HTTP/1.1" 200 10 "-" "UA"' . "\n";
        file_put_contents("$dir/a.log", $line . $line . $this->sample());
        $importer = new importer();
        $first = $importer->import_file("$dir/a.log", ['format' => 'combined']);
        $second = $importer->import_file("$dir/a.log", ['format' => 'combined']);
        $this->assertSame(5, $first->linesstored, 'Two identical lines in one file are two requests.');
        $this->assertSame(0, $second->linesstored);
        $this->assertSame(5, $DB->count_records('tool_ltidiagnostics_acc'));
        $this->assertSame(2, $DB->count_records('tool_ltidiagnostics_imp'));
        $this->assertSame('path', $first->sourcetype);
    }

    /**
     * Rotated and compressed files overlap without duplicates.
     */
    public function test_import_gz_and_overlap(): void {
        global $DB;
        $this->resetAfterTest();
        $dir = make_request_directory();
        file_put_contents("$dir/access.log.1.gz", gzencode($this->sample()));
        file_put_contents("$dir/access.log", $this->sample() . '203.0.113.9 - - [24/Oct/2026:12:05:00 +0000] '
            . '"GET /mod/lti/certs.php HTTP/1.1" 200 10 "-" "UA"' . "\n");
        $importer = new importer();
        $gz = $importer->import_file("$dir/access.log.1.gz", ['format' => 'combined']);
        $this->assertSame(3, $gz->linesstored);
        $plain = $importer->import_file("$dir/access.log", ['format' => 'combined']);
        $this->assertSame(1, $plain->linesstored, 'Only the new line is added.');
        $this->assertSame(4, $DB->count_records('tool_ltidiagnostics_acc'));
    }

    /**
     * The skew option shifts the stored times.
     */
    public function test_import_skew(): void {
        $this->resetAfterTest();
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $this->sample());
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['format' => 'combined', 'skew' => 3600]);
        $this->assertSame(gmmktime(13, 0, 0, 10, 24, 2026), (int) $job->rangefrom);
    }

    /**
     * The time range option keeps only lines inside the range.
     */
    public function test_import_range(): void {
        $this->resetAfterTest();
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $this->sample());
        rewind($fh);
        $job = (new importer())->import_stream($fh, [
            'format' => 'combined', 'from' => gmmktime(12, 0, 1, 10, 24, 2026), 'to' => gmmktime(12, 0, 2, 10, 24, 2026),
        ]);
        $this->assertSame(1, $job->linesstored);
    }

    /**
     * Limits and bad formats.
     */
    public function test_limits_and_bad_format(): void {
        $this->resetAfterTest();
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $this->sample());
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['format' => 'combined', 'maxlines' => 2]);
        $this->assertSame('truncated', $job->status);
        $this->assertSame('maxlines', $job->errormessage);
        $this->assertSame(2, $job->linesread);

        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $this->sample());
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['format' => 'combined', 'maxbytes' => 50]);
        $this->assertSame('truncated', $job->status);
        $this->assertSame('maxbytes', $job->errormessage);

        $fh = fopen('php://memory', 'w+');
        $job = (new importer())->import_stream($fh, ['format' => 'bogus']);
        $this->assertSame('failed', $job->status);
        $this->assertSame('badformat', $job->errormessage);
        $job = (new importer())->import_file('/nonexistent/file.log', ['format' => 'combined']);
        $this->assertSame('failed', $job->status);
    }

    /**
     * Error logs: only LTI related lines are stored.
     */
    public function test_import_error_log(): void {
        global $DB;
        $this->resetAfterTest();
        $text = '2026/10/24 12:00:00 [error] 1#0: *4 upstream timed out, client: 203.0.113.5, server: a, '
            . 'request: "GET /mod/lti/token.php HTTP/1.1", host: "a"' . "\n"
            . '2026/10/24 12:00:01 [error] 1#0: *5 something unrelated happened' . "\n"
            . '2026/10/24 12:00:02 [error] 1#0: *6 LTI platform did not answer' . "\n";
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $text);
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['kind' => 'error', 'format' => 'nginx_error', 'timezone' => 'Europe/Berlin']);
        $this->assertSame(2, $job->linesstored);
        $this->assertSame(1, $job->linesskipped);
        $this->assertSame(gmmktime(10, 0, 0, 10, 24, 2026), (int) $job->rangefrom);
        $this->assertSame(2, $DB->count_records('tool_ltidiagnostics_acc', ['kind' => 'error']));
        $rows = importer::get_rows(0, 0, ['oauth']);
        $this->assertCount(1, $rows);
    }

    /**
     * The row reader filters and limits.
     */
    public function test_get_rows(): void {
        $this->resetAfterTest();
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $this->sample());
        rewind($fh);
        (new importer())->import_stream($fh, ['format' => 'combined']);
        $all = importer::get_rows(0);
        $this->assertCount(3, $all);
        $this->assertSame('oidc', $all[0]->category, 'Rows come in time order.');
        $this->assertCount(1, importer::get_rows(0, 0, ['launch']));
        $this->assertCount(2, importer::get_rows(0, 0, [], 2));
        $this->assertCount(0, importer::get_rows(gmmktime(0, 0, 0, 1, 1, 2030)));
    }

    /**
     * Path allow-list: traversal, symlinks, prefix tricks and non-files.
     */
    public function test_path_allowlist(): void {
        $base = make_request_directory();
        mkdir("$base/logs");
        mkdir("$base/logs-evil");
        mkdir("$base/other");
        file_put_contents("$base/logs/access.log", 'x');
        file_put_contents("$base/logs-evil/access.log", 'x');
        file_put_contents("$base/other/secret.txt", 's');
        symlink("$base/other/secret.txt", "$base/logs/link.log");
        $real = realpath("$base/logs/access.log");

        $this->assertSame($real, path_allowlist::resolve("$base/logs/access.log", ["$base/logs"]));
        $this->assertSame($real, path_allowlist::resolve("$base/logs/access.log", ["$base/logs/"]));
        $this->assertSame($real, path_allowlist::resolve("$base/logs/access.log", ["$base/logs/access.log"]));
        $this->assertSame($real, path_allowlist::resolve("$base/logs/../logs/access.log", ["$base/logs"]));
        $this->assertNull(path_allowlist::resolve("$base/logs/../other/secret.txt", ["$base/logs"]), 'Traversal.');
        $this->assertNull(path_allowlist::resolve("$base/logs/link.log", ["$base/logs"]), 'A symlink out of the directory.');
        $this->assertNull(path_allowlist::resolve("$base/logs-evil/access.log", ["$base/logs"]), 'Prefix trick.');
        $this->assertNull(path_allowlist::resolve("$base/logs", ["$base/logs"]), 'A directory is not a file.');
        $this->assertNull(path_allowlist::resolve("$base/logs/access.log", []), 'Empty allow-list.');
        $this->assertNull(path_allowlist::resolve('', ["$base/logs"]));
        $this->assertNull(path_allowlist::resolve("$base/logs/missing.log", ["$base/logs"]));
        $this->assertNull(path_allowlist::resolve("$base/logs/access.log\0.txt", ["$base/logs"]), 'Null byte.');
        $this->assertNull(path_allowlist::resolve("$base/logs/access.log", ['relative/path', '']), 'Relative entries are ignored.');
        $this->assertNull(path_allowlist::resolve('/etc/passwd', ["$base/logs"]));
    }

    /**
     * Listing of candidate files.
     */
    public function test_list_files(): void {
        $base = make_request_directory();
        file_put_contents("$base/access.log", 'x');
        file_put_contents("$base/access.log.1.gz", 'x');
        file_put_contents("$base/readme.txt", 'x');
        file_put_contents("$base/.hidden.log", 'x');
        $files = path_allowlist::list_files([$base, 'relative']);
        $names = array_map('basename', $files);
        $this->assertEqualsCanonicalizing(['access.log', 'access.log.1.gz'], $names);
    }

    /**
     * Gate 2 finding F3: every category constant fits the column, in install.xml and in the installed database.
     */
    public function test_every_category_fits_the_column(): void {
        global $CFG, $DB;
        $xml = simplexml_load_file($CFG->dirroot . '/admin/tool/ltidiagnostics/db/install.xml');
        $length = 0;
        foreach ($xml->TABLES->TABLE as $table) {
            if ((string) $table['NAME'] !== 'tool_ltidiagnostics_acc') {
                continue;
            }
            foreach ($table->FIELDS->FIELD as $field) {
                if ((string) $field['NAME'] === 'category') {
                    $length = (int) $field['LENGTH'];
                }
            }
        }
        $this->assertSame(parser::CATEGORY_MAXLEN, $length, 'parser::CATEGORY_MAXLEN mirrors install.xml');
        $this->assertSame($length, (int) $DB->get_columns('tool_ltidiagnostics_acc', false)['category']->max_length);
        foreach (parser::CATEGORIES as $category) {
            $this->assertLessThanOrEqual($length, \core_text::strlen($category), $category);
        }
        $this->assertContains('service_ags_lineitems', parser::CATEGORIES);
    }

    /**
     * Import a stream of lines.
     *
     * @param string[] $lines Lines.
     * @return \stdClass Job.
     */
    private function import_lines(array $lines): \stdClass {
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, implode("\n", $lines) . "\n");
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['format' => 'combined', 'sourcename' => 'ags.log']);
        fclose($fh);
        return $job;
    }

    /** @var string[] Real AGS line item requests as Moodle's service endpoint receives them (GET list, POST create). */
    private const AGS_LINES = [
        '198.51.100.20 - - [25/Oct/2026:09:15:02 +0200] "GET /mod/lti/services.php/2/lineitems?type_id=4 HTTP/1.1" 200 412 "-" '
            . '"LTI tool/1.0"',
        '198.51.100.20 - - [25/Oct/2026:09:15:03 +0200] "POST /mod/lti/services.php/2/lineitems?type_id=4 HTTP/1.1" 201 230 "-" '
            . '"LTI tool/1.0"',
        '198.51.100.20 - - [25/Oct/2026:09:15:04 +0200] "GET /mod/lti/services.php/2/lineitems/7/lineitem?type_id=4 HTTP/1.1" '
            . '200 198 "-" "LTI tool/1.0"',
    ];

    /**
     * Regression F3: an access log with AGS line item requests is imported completely (0.1.0 and the first 0.2.0 build
     * aborted with a database write error and left the job "running").
     */
    public function test_ags_lineitems_lines_are_imported(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->import_lines(self::AGS_LINES);
        $this->assertSame('done', $job->status);
        $this->assertSame(3, $job->linesstored);
        $this->assertSame('done', $DB->get_field('tool_ltidiagnostics_imp', 'status', ['id' => $job->id]));
        $categories = $DB->get_fieldset_select('tool_ltidiagnostics_acc', 'category', 'importid = ?', [$job->id]);
        $this->assertSame(['service_ags_lineitems', 'service_ags_lineitems', 'service_ags_lineitems'], $categories);
        $rows = importer::get_rows(0, 0, ['service_ags_lineitems']);
        $this->assertCount(3, $rows);
    }

    /**
     * A database error during the import ends the job as "failed", never "running". Reproduced with the old char(20)
     * column, which is restored afterwards.
     */
    public function test_database_error_ends_the_job_as_failed(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_ltidiagnostics_acc');
        // The index of 0.3.0 on the column must be dropped to change the precision, and is restored afterwards.
        $index = new \xmldb_index('category_time', XMLDB_INDEX_NOTUNIQUE, ['category', 'logtime']);
        $dbman->drop_index($table, $index);
        $dbman->change_field_precision(
            $table,
            new \xmldb_field('category', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null, 'requestid')
        );
        try {
            $job = $this->import_lines(self::AGS_LINES);
        } finally {
            $dbman->change_field_precision(
                $table,
                new \xmldb_field('category', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null, 'requestid')
            );
            $dbman->add_index($table, $index);
        }
        $this->assertSame('failed', $job->status);
        $this->assertSame('dberror', $job->errormessage);
        $stored = $DB->get_record('tool_ltidiagnostics_imp', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame('failed', $stored->status, 'The job is not left running.');
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_imp', ['status' => 'running']));
        $this->assertStringNotContainsString('lineitems', $stored->errormessage, 'No log content in the error message.');
    }

    /**
     * A category that does not fit the column is stored as "other" instead of breaking the import.
     */
    public function test_overlong_category_is_guarded(): void {
        foreach (parser::CATEGORIES as $category) {
            $this->assertSame($category, importer::storable_category($category));
        }
        $this->assertSame('other', importer::storable_category(str_repeat('x', parser::CATEGORY_MAXLEN + 1)));
        $this->assertSame('other', importer::storable_category('service_ags_unknown'));
    }
}
