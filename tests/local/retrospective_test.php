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

namespace tool_ltidiagnostics\local;

use tool_ltidiagnostics\local\accesslog\importer;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/lti_data.php');

/**
 * Retrospective analysis (audit item DIA-03): several log stores, source coverage, task log retention, no correlation
 * from time alone, deletion of imported logs.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\logstore_source
 * @covers     \tool_ltidiagnostics\local\task_source
 * @covers     \tool_ltidiagnostics\local\timeline
 * @covers     \tool_ltidiagnostics\local\accesslog\importer
 */
final class retrospective_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    use lti_data;

    /**
     * Enable the standard store and the database store at once. The database store is pointed at the table of the
     * standard store, the technique that core itself uses to test it.
     */
    private function enable_two_stores(): void {
        global $CFG;
        $this->preventResetByRollback();
        $settings = [
            'dbdriver' => $CFG->dblibrary . '/' . $CFG->dbtype, 'dbhost' => $CFG->dbhost, 'dbuser' => $CFG->dbuser,
            'dbpass' => $CFG->dbpass, 'dbname' => $CFG->dbname, 'dbtable' => $CFG->prefix . 'logstore_standard_log',
            'dbpersist' => !empty($CFG->dboptions['dbpersist']) ? 1 : 0,
            'dbsocket' => $CFG->dboptions['dbsocket'] ?? '', 'dbport' => $CFG->dboptions['dbport'] ?? '',
            'dbschema' => $CFG->dboptions['dbschema'] ?? '', 'dbcollation' => $CFG->dboptions['dbcollation'] ?? '',
            'dbhandlesoptions' => !empty($CFG->dboptions['dbhandlesoptions']) ? $CFG->dboptions['dbhandlesoptions'] : false,
        ];
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'logstore_database');
        }
        set_config('enabled_stores', 'logstore_standard,logstore_database', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('buffersize', 0, 'logstore_database');
        set_config('logguests', 1, 'logstore_standard');
        set_config('logguests', 1, 'logstore_database');
        get_log_manager(true);
    }

    /**
     * Two real, enabled log stores are both read and the same event is reported once with both readers named.
     */
    public function test_two_real_logstores_are_merged_without_duplicates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_two_stores();
        $course = $this->getDataGenerator()->create_course();
        $typeid = $this->create_platform_type();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'typeid' => $typeid]);
        $cm = get_coursemodule_from_instance('lti', $lti->id);
        \mod_lti\event\course_module_viewed::create([
            'objectid' => $lti->id, 'context' => \context_module::instance($cm->id),
        ])->trigger();

        $source = new logstore_source();
        $this->assertEqualsCanonicalizing(['logstore_standard', 'logstore_database'], array_keys($source->get_readers()));
        $result = $source->fetch(\context_system::instance());
        $this->assertTrue($result['available']);
        $this->assertEqualsCanonicalizing(['logstore_standard', 'logstore_database'], array_keys($result['readers']));
        foreach ($result['readers'] as $name => $info) {
            $this->assertTrue($info['ok'], $name);
            $this->assertGreaterThan(0, $info['rows'], $name);
        }
        $views = array_values(array_filter($result['rows'], function ($r) {
            return $r['eventname'] === '\mod_lti\event\course_module_viewed';
        }));
        $this->assertNotEmpty($views);
        $identities = array_map(function ($r) {
            return implode('|', [$r['eventname'], $r['time'], $r['contextid'], $r['objectid'], $r['userid']]);
        }, $result['rows']);
        // Both stores write to the same table here, so the table holds one physical row per store and each reader sees
        // both. What matters: no row is counted once per reader.
        $perreader = $result['readers']['logstore_standard']['rows'];
        $this->assertSame($perreader, $result['readers']['logstore_database']['rows']);
        $this->assertCount($perreader, $identities, 'An event held by two stores is listed once, not once per store.');
        foreach ($result['rows'] as $row) {
            $this->assertEqualsCanonicalizing(['logstore_standard', 'logstore_database'], $row['readers']);
        }
        $this->assertGreaterThan(0, $result['duplicates']);
        $this->assertContains('logstore_database', $views[0]['readers']);
        $this->assertContains('logstore_standard', $views[0]['readers']);
    }

    /**
     * Build a source with scripted readers.
     *
     * @param array $readers Name => reader double.
     * @return logstore_source
     */
    private function source_with(array $readers): logstore_source {
        return new class ($readers) extends logstore_source {
            /** @var array Readers. */
            private $fake;

            /**
             * Constructor.
             *
             * @param array $fake Readers.
             */
            public function __construct(array $fake) {
                $this->fake = $fake;
            }

            /**
             * Fake readers.
             *
             * @return array
             */
            public function get_readers(): array {
                return $this->fake;
            }
        };
    }

    /**
     * A reader double.
     *
     * @param bool $logging Whether it logs.
     * @param array|null $events Event data rows, or null to throw.
     * @return object
     */
    private function reader(bool $logging, ?array $events) {
        return new class ($logging, $events) {
            /** @var bool Logging. */
            private $logging;
            /** @var array|null Events. */
            private $events;

            /**
             * Constructor.
             *
             * @param bool $logging Logging.
             * @param array|null $events Events.
             */
            public function __construct(bool $logging, ?array $events) {
                $this->logging = $logging;
                $this->events = $events;
            }

            /**
             * Whether logging.
             *
             * @return bool
             */
            public function is_logging(): bool {
                return $this->logging;
            }

            /**
             * Events.
             *
             * @param string $where Where.
             * @param array $params Params.
             * @param string $sort Sort.
             * @param int $from Offset.
             * @param int $num Limit.
             * @return array
             */
            public function get_events_select($where, array $params, $sort, $from, $num): array {
                if ($this->events === null) {
                    throw new \dml_exception('dmlreadexception');
                }
                // Like a real store: the family query (component prefix) and the fixed event list select disjoint rows.
                $familyquery = strpos($where, 'component') !== false;
                $events = array_values(array_filter($this->events, function (array $data) use ($familyquery) {
                    return \tool_ltidiagnostics\local\family_events::is_family_component($data['component']) === $familyquery;
                }));
                return array_map(function (array $data) {
                    return new class ($data) {
                        /** @var string Event name. */
                        public $eventname;
                        /** @var int Object id. */
                        public $objectid;
                        /** @var array Data. */
                        private $data;

                        /**
                         * Constructor.
                         *
                         * @param array $data Data.
                         */
                        public function __construct(array $data) {
                            $this->data = $data;
                            $this->eventname = $data['eventname'];
                            $this->objectid = $data['objectid'];
                        }

                        /**
                         * Data.
                         *
                         * @return array
                         */
                        public function get_data(): array {
                            return $this->data;
                        }
                    };
                }, array_slice($events, 0, $num));
            }
        };
    }

    /**
     * Event data of the LTI view event.
     *
     * @param int $time Time.
     * @param int $object Object id.
     * @return array
     */
    private static function view(int $time, int $object = 5): array {
        return ['eventname' => '\mod_lti\event\course_module_viewed', 'component' => 'mod_lti', 'timecreated' => $time,
            'courseid' => 2, 'contextid' => 30, 'objectid' => $object, 'userid' => 3, 'relateduserid' => null];
    }

    /**
     * A failing store does not hide the others; the failure is reported per store.
     */
    public function test_one_failing_reader_is_reported_not_fatal(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->source_with([
            'logstore_standard' => $this->reader(true, [self::view(1000)]),
            'logstore_other' => $this->reader(true, null),
        ]);
        $result = $source->fetch(\context_system::instance());
        $this->assertTrue($result['available']);
        $this->assertCount(1, $result['rows']);
        $this->assertTrue($result['readers']['logstore_standard']['ok']);
        $this->assertFalse($result['readers']['logstore_other']['ok']);
        $this->assertSame('readererror', $result['readers']['logstore_other']['reason']);

        $allbad = $this->source_with(['a' => $this->reader(true, null), 'b' => $this->reader(true, null)]);
        $result = $allbad->fetch(\context_system::instance());
        $this->assertFalse($result['available']);
        $this->assertSame('readererror', $result['reason']);
        $this->assertSame([], $result['rows']);
    }

    /**
     * Stores that do not log are skipped; distinct events of different stores are all kept and sorted.
     */
    public function test_readers_are_merged_and_sorted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->source_with([
            'a' => $this->reader(true, [self::view(1000, 1), self::view(3000, 2)]),
            'b' => $this->reader(true, [self::view(2000, 3), self::view(3000, 2)]),
            'off' => $this->reader(false, [self::view(9999, 9)]),
        ]);
        $result = $source->fetch(\context_system::instance());
        $this->assertSame([3000, 2000, 1000], array_column($result['rows'], 'time'));
        $this->assertSame(1, $result['duplicates']);
        $this->assertArrayNotHasKey('off', $result['readers']);
        $this->assertSame(['a', 'b'], $result['rows'][0]['readers']);
    }

    /**
     * A store that returned as many rows as the page allows is reported as truncated.
     */
    public function test_truncation_is_reported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $events = [];
        for ($i = 1; $i <= 5; $i++) {
            $events[] = self::view(1000 + $i, $i);
        }
        $source = $this->source_with(['a' => $this->reader(true, $events)]);
        $this->assertTrue($source->fetch(\context_system::instance(), ['limit' => 5])['truncated']);
        $this->assertFalse($source->fetch(\context_system::instance(), ['limit' => 50])['truncated']);
    }

    /**
     * Task log coverage: with a retention of N runs per task, a task that has N entries has lost older ones.
     */
    public function test_task_log_retention_is_detected(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->task_logretainruns = 3;
        $source = new task_source();
        $this->assertSame(
            ['retainruns' => 3, 'count' => 0, 'oldest' => 0, 'newest' => 0, 'saturated' => false],
            $source->coverage()
        );
        $now = time();
        foreach ([300, 200] as $age) {
            $DB->insert_record('task_log', (object) [
                'type' => 0, 'component' => 'enrol_lti', 'classname' => 'enrol_lti\task\sync_grades', 'userid' => 0,
                'timestart' => $now - $age, 'timeend' => $now - $age + 1, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 0,
                'output' => '', 'hostname' => 'h', 'pid' => 1,
            ]);
        }
        $coverage = $source->coverage();
        $this->assertSame(2, $coverage['count']);
        $this->assertFalse($coverage['saturated']);
        $this->assertSame($now - 300, $coverage['oldest']);
        $DB->insert_record('task_log', (object) [
            'type' => 0, 'component' => 'enrol_lti', 'classname' => 'enrol_lti\task\sync_grades', 'userid' => 0,
            'timestart' => $now - 100, 'timeend' => $now - 99, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 1,
            'output' => '', 'hostname' => 'h', 'pid' => 1,
        ]);
        $coverage = $source->coverage();
        $this->assertTrue($coverage['saturated'], 'Three runs with a retention of three: older runs are gone.');
        $this->assertSame($now - 100, $coverage['newest']);
    }

    /**
     * Source coverage marks a source as incomplete at its page limit or when it is known to drop old data.
     */
    public function test_timeline_coverage(): void {
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = ['source' => 'observer', 'time' => 100 + $i];
        }
        $items[] = ['source' => 'logstore', 'time' => 500];
        $items[] = ['source' => 'tasklog', 'time' => 700];
        $limits = ['observer' => 3, 'accesslog' => 10, 'logstore' => 10, 'tasklog' => 10];
        $cov = timeline::coverage($items, $limits, ['tasklog' => true]);
        $this->assertTrue($cov['observer']['incomplete'], 'At the page limit.');
        $this->assertSame(100, $cov['observer']['first']);
        $this->assertSame(102, $cov['observer']['last']);
        $this->assertFalse($cov['logstore']['incomplete']);
        $this->assertTrue($cov['tasklog']['incomplete'], 'Known retention loss.');
        $this->assertSame(0, $cov['accesslog']['count']);
        $this->assertFalse($cov['accesslog']['incomplete'], 'An empty source is empty, not incomplete.');
    }

    /**
     * Time proximity alone never creates a link between sources.
     */
    public function test_no_correlation_from_time_alone(): void {
        $items = [
            ['source' => 'accesslog', 'time' => 1000, 'stage' => 'launch', 'traceid' => '', 'refs' => []],
            ['source' => 'observer', 'time' => 1001, 'stage' => 'service', 'traceid' => '', 'refs' => ['courseid' => 2]],
            ['source' => 'logstore', 'time' => 1002, 'stage' => 'followup', 'traceid' => '', 'refs' => ['courseid' => 2]],
        ];
        $this->assertSame([], timeline::edges($items), 'One shared reference and the same minute are only tentative.');
        $this->assertSame(
            correlation::TENTATIVE,
            correlation::classify($items[1], $items[2]),
            'The classification says so explicitly.'
        );
        // A client address, however equal, is never a reference.
        $a = ['time' => 1, 'traceid' => '', 'refs' => ['ip' => '203.0.113.0', 'ipmask' => '203.0.113.0', 'userid' => 5]];
        $b = ['time' => 2, 'traceid' => '', 'refs' => ['ip' => '203.0.113.0', 'ipmask' => '203.0.113.0', 'userid' => 5]];
        $this->assertSame(correlation::TENTATIVE, correlation::classify($a, $b));
        // Two stable references or a trace id do link.
        $items[2]['refs'] = ['courseid' => 2, 'userid' => 3];
        $items[1]['refs'] = ['courseid' => 2, 'userid' => 3];
        $this->assertCount(1, timeline::edges($items));
        $items[0]['traceid'] = 'abc';
        $items[1]['traceid'] = 'abc';
        $this->assertCount(2, timeline::edges($items));
        // Far apart in time with a trace id: still the same transaction (clock drift, slow follow-up).
        $items[1]['time'] = 1000 + 600;
        $edges = timeline::edges($items);
        $this->assertContains('direct', array_column($edges, 'confidence'));
    }

    /**
     * Clock drift between two systems is estimated from directly correlated pairs and then compensates the window.
     */
    public function test_clock_drift_compensation(): void {
        $pairs = [
            [['time' => 1000, 'traceid' => 'a', 'refs' => []], ['time' => 1042, 'traceid' => 'a', 'refs' => []]],
            [['time' => 2000, 'traceid' => 'b', 'refs' => []], ['time' => 2043, 'traceid' => 'b', 'refs' => []]],
            [['time' => 3000, 'traceid' => 'c', 'refs' => []], ['time' => 3041, 'traceid' => 'c', 'refs' => []]],
        ];
        $skew = correlation::estimate_skew($pairs);
        $this->assertSame(42, $skew);
        $a = ['time' => 5000, 'traceid' => '', 'refs' => ['courseid' => 1, 'userid' => 2]];
        $b = ['time' => 5000 + 400, 'traceid' => '', 'refs' => ['courseid' => 1, 'userid' => 2]];
        $this->assertSame(correlation::UNMATCHED, correlation::classify($a, $b, 120));
        $b['time'] = 5000 + 150;
        $this->assertSame(
            correlation::UNMATCHED,
            correlation::classify($a, $b, 120),
            'Without compensation the drift hides the match.'
        );
        $this->assertSame(correlation::STRONG, correlation::classify($a, $b, 120, -$skew));
        $this->assertNull(correlation::estimate_skew([]));
    }

    /**
     * Deleting an import removes its job and its stored log lines, needs the capability and is audited.
     */
    public function test_import_can_be_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, '203.0.113.9 - - [24/Oct/2026:12:00:01 +0000] "POST /enrol/lti/launch.php HTTP/1.1" 200 10 "-" "UA"' . "\n");
        fwrite($fh, '203.0.113.9 - - [24/Oct/2026:12:00:02 +0000] "GET /mod/lti/certs.php HTTP/1.1" 200 10 "-" "UA"' . "\n");
        rewind($fh);
        $job = (new importer())->import_stream($fh, ['format' => 'combined', 'sourcename' => 'x.log']);
        fclose($fh);
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, '203.0.113.9 - - [24/Oct/2026:13:00:01 +0000] "POST /enrol/lti/launch.php HTTP/1.1" 200 10 "-" "UA"' . "\n");
        rewind($fh);
        $other = (new importer())->import_stream($fh, ['format' => 'combined', 'sourcename' => 'y.log']);
        fclose($fh);
        $this->assertSame(3, $DB->count_records('tool_ltidiagnostics_acc'));

        $this->assertSame(2, importer::delete_import((int) $job->id));
        $this->assertFalse($DB->record_exists('tool_ltidiagnostics_imp', ['id' => $job->id]));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_acc', ['importid' => $job->id]));
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_acc', ['importid' => $other->id]), 'Other imports stay.');
        $audit = $DB->get_record('tool_ltidiagnostics_audit', ['action' => 'deleteimport'], '*', MUST_EXIST);
        $this->assertStringContainsString('lines=2', $audit->detail);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        importer::delete_import((int) $other->id);
    }
}
