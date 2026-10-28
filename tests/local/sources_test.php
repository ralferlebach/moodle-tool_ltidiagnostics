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

use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/lti_data.php');

/**
 * Evidence adapters against real Moodle APIs: logstore reader, task inventory, task log.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\logstore_source
 * @covers     \tool_ltidiagnostics\local\task_source
 */
final class sources_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    use lti_data;

    /**
     * Enable the standard log store for the test.
     */
    private function enable_standard_logstore(): void {
        $this->preventResetByRollback(); // The standard log store writes after the transaction ends.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * The real standard log store is found as a reader and returns LTI related events only.
     */
    public function test_logstore_reader_returns_lti_events(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_standard_logstore();
        $course = $this->getDataGenerator()->create_course();
        $typeid = $this->create_platform_type();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'typeid' => $typeid]);
        $cm = get_coursemodule_from_instance('lti', $lti->id);
        \mod_lti\event\course_module_viewed::create([
            'objectid' => $lti->id, 'context' => \context_module::instance($cm->id),
        ])->trigger();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id, 'context' => \context_module::instance($page->cmid),
        ])->trigger();

        $source = new logstore_source();
        $readers = $source->get_readers();
        $this->assertArrayHasKey('logstore_standard', $readers);
        $described = $source->describe();
        $this->assertSame('logstore_standard', $described[0]['name']);
        $this->assertTrue($described[0]['logging']);

        $result = $source->fetch(\context_system::instance());
        $this->assertTrue($result['available']);
        $names = array_column($result['rows'], 'eventname');
        $this->assertContains('\mod_lti\event\course_module_viewed', $names);
        $this->assertNotContains('\mod_page\event\course_module_viewed', $names);
        foreach ($result['rows'] as $row) {
            $this->assertSame(
                ['source', 'reader', 'readers', 'time', 'eventname', 'component', 'courseid', 'contextid', 'objectid', 'userid',
                    'family', 'correlationid'],
                array_keys($row),
                'Rows carry ids only.'
            );
        }
    }

    /**
     * User events of non-LTI accounts are not returned.
     */
    public function test_logstore_filters_user_events_to_lti_accounts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_standard_logstore();
        $manual = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $lti = $this->getDataGenerator()->create_user(['auth' => 'lti']);
        $rows = (new logstore_source())->fetch(\context_system::instance())['rows'];
        $created = array_filter($rows, function ($r) {
            return $r['eventname'] === '\core\event\user_created';
        });
        $ids = array_column($created, 'objectid');
        $this->assertContains((int) $lti->id, $ids);
        $this->assertNotContains((int) $manual->id, $ids);
    }

    /**
     * Filters: course, time range and paging.
     */
    public function test_logstore_filters_and_paging(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_standard_logstore();
        $course = $this->getDataGenerator()->create_course();
        $typeid = $this->create_platform_type();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'typeid' => $typeid]);
        $cm = get_coursemodule_from_instance('lti', $lti->id);
        for ($i = 0; $i < 3; $i++) {
            \mod_lti\event\course_module_viewed::create([
                'objectid' => $lti->id, 'context' => \context_module::instance($cm->id),
            ])->trigger();
        }
        $source = new logstore_source();
        $all = $source->fetch(\context_system::instance(), ['courseid' => $course->id]);
        $this->assertCount(3, array_filter($all['rows'], function ($r) {
            return $r['eventname'] === '\mod_lti\event\course_module_viewed';
        }));
        $none = $source->fetch(\context_system::instance(), ['courseid' => $course->id + 100]);
        $this->assertSame([], $none['rows']);
        $future = $source->fetch(\context_system::instance(), ['from' => time() + DAYSECS]);
        $this->assertSame([], $future['rows']);
        $page = $source->fetch(\context_system::instance(), ['courseid' => $course->id, 'limit' => 2]);
        $this->assertCount(2, $page['rows']);
        $next = $source->fetch(\context_system::instance(), ['courseid' => $course->id, 'limit' => 2, 'offset' => 2]);
        $this->assertCount(1, $next['rows'], 'The third view is on the second page.');
    }

    /**
     * Without a reader or without logging the adapter degrades with a reason and no exception.
     */
    public function test_logstore_degrades_without_reader(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $source = new logstore_source();
        $this->assertSame([], $source->describe());
        $result = $source->fetch(\context_system::instance());
        $this->assertFalse($result['available']);
        $this->assertSame('noreader', $result['reason']);
        $this->assertSame([], $result['rows']);
    }

    /**
     * A reader that is present but not logging is reported as such.
     */
    public function test_logstore_present_but_not_logging(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('logguests', 1, 'logstore_standard');
        set_config('loglifetime', 0, 'logstore_standard');
        get_log_manager(true);
        $source = new logstore_source();
        $readers = $source->describe();
        if ($readers && !$readers[0]['logging']) {
            $this->assertSame('notlogging', $source->fetch(\context_system::instance())['reason']);
        } else {
            $this->assertTrue($source->fetch(\context_system::instance())['available']);
        }
    }

    /**
     * Reading the log needs the capability.
     */
    public function test_logstore_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        (new logstore_source())->fetch(\context_system::instance());
    }

    /**
     * Relevance rules of the task inventory.
     */
    public function test_task_relevance(): void {
        $this->assertTrue(task_source::is_relevant('\enrol_lti\task\sync_grades'));
        $this->assertTrue(task_source::is_relevant('mod_lti\task\clean_access_tokens'));
        $this->assertTrue(task_source::is_relevant('\local_ltisomething\task\x', 'local_ltisomething'));
        $this->assertTrue(task_source::is_relevant('\tool_ltidiagnostics\task\cleanup_task'));
        $this->assertFalse(task_source::is_relevant('\core\task\cache_cron_task'));
        $this->assertFalse(task_source::is_relevant('\mod_assign\task\cron_task', 'mod_assign'));
    }

    /**
     * The real scheduled tasks of the LTI plugins are inventoried, overdue and failing ones are flagged.
     */
    public function test_scheduled_inventory_from_real_tasks(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $source = new task_source();
        $list = $source->scheduled($now);
        $names = array_column($list, 'classname');
        $this->assertContains('\enrol_lti\task\sync_grades', $names);
        $this->assertContains('\enrol_lti\task\sync_members', $names);
        $this->assertContains('\tool_ltidiagnostics\task\cleanup_task', $names);
        $this->assertNotContains('\core\task\session_cleanup_task', $names);

        $DB->set_field('task_scheduled', 'nextruntime', $now - 3 * HOURSECS, ['classname' => '\enrol_lti\task\sync_grades']);
        // The stored next run time depends on when the test site was installed: pin it for the task that must not be overdue.
        $DB->set_field('task_scheduled', 'nextruntime', $now + HOURSECS, ['classname' => '\enrol_lti\task\sync_members']);
        $DB->set_field('task_scheduled', 'faildelay', 120, ['classname' => '\enrol_lti\task\sync_members']);
        $list = $source->scheduled($now);
        $byname = array_column($list, null, 'classname');
        $this->assertTrue($byname['\enrol_lti\task\sync_grades']['overdue']);
        $this->assertFalse($byname['\enrol_lti\task\sync_members']['overdue']);
        $this->assertSame(120, $byname['\enrol_lti\task\sync_members']['faildelay']);
        $DB->set_field('task_scheduled', 'disabled', 1, ['classname' => '\enrol_lti\task\sync_grades']);
        $byname = array_column($source->scheduled($now), null, 'classname');
        $this->assertFalse($byname['\enrol_lti\task\sync_grades']['overdue'], 'A disabled task is not overdue.');
    }

    /**
     * Ad-hoc queue: relevant tasks are counted, failures and due runs are distinguished.
     */
    public function test_adhoc_queue(): void {
        global $DB;
        $this->resetAfterTest();
        require_once(__DIR__ . '/../fixtures/demo_adhoc_task.php');
        $task = new \tool_ltidiagnostics\fixtures\demo_adhoc_task();
        $task->set_component('tool_ltidiagnostics');
        \core\task\manager::queue_adhoc_task($task);
        $other = new \tool_ltidiagnostics\fixtures\demo_adhoc_task();
        $other->set_component('tool_ltidiagnostics');
        $other->set_custom_data(['x' => 1]);
        \core\task\manager::queue_adhoc_task($other);
        $unrelated = new \core\task\asynchronous_backup_task();
        $unrelated->set_custom_data(['backupid' => 'x']);
        \core\task\manager::queue_adhoc_task($unrelated);

        $adhoc = (new task_source())->adhoc();
        $classes = array_column($adhoc, 'classname');
        $this->assertContains('tool_ltidiagnostics\fixtures\demo_adhoc_task', array_map(function ($c) {
            return ltrim($c, '\\');
        }, $classes));
        foreach ($adhoc as $row) {
            $this->assertStringNotContainsString('asynchronous_backup_task', $row['classname']);
        }
        $demo = array_values(array_filter($adhoc, function ($r) {
            return strpos($r['classname'], 'demo_adhoc_task') !== false;
        }))[0];
        $this->assertSame(2, (int) $demo['count']);
        $this->assertSame(0, (int) $demo['failed']);

        $DB->set_field('task_adhoc', 'faildelay', 60, ['classname' => '\tool_ltidiagnostics\fixtures\demo_adhoc_task']);
        $demo = array_values(array_filter((new task_source())->adhoc(), function ($r) {
            return strpos($r['classname'], 'demo_adhoc_task') !== false;
        }))[0];
        $this->assertSame(2, (int) $demo['failed']);
    }

    /**
     * Task log rows of LTI tasks are returned, filtered and redacted.
     */
    public function test_task_log(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $row = function (string $class, string $component, int $result, string $output, int $start) use ($DB) {
            return $DB->insert_record('task_log', (object) [
                'type' => 0, 'component' => $component, 'classname' => $class, 'userid' => 0, 'timestart' => $start,
                'timeend' => $start + 2, 'dbreads' => 1, 'dbwrites' => 1, 'result' => $result, 'output' => $output,
                'hostname' => 'h', 'pid' => 1,
            ]);
        };
        $row('enrol_lti\task\sync_grades', 'enrol_lti', 0, 'ok', $now - 100);
        $row('enrol_lti\task\sync_members', 'enrol_lti', 1, 'Failed: Bearer abcdefghijklmnop for user a@b.example', $now - 50);
        $row('core\task\cache_cron_task', 'core', 1, 'unrelated failure', $now - 40);
        $row('mod_lti\task\clean_access_tokens', 'mod_lti', 0, str_repeat('x', 1000), $now - 10);

        $source = new task_source();
        $all = $source->task_log();
        $this->assertCount(3, $all);
        $this->assertSame('\enrol_lti\task\sync_members', '\\' . ltrim($all[1]['classname'], '\\'));
        $failed = $source->task_log(['onlyfailed' => true]);
        $this->assertCount(1, $failed);
        $this->assertTrue($failed[0]['failed']);
        $this->assertStringNotContainsString('abcdefghijklmnop', $failed[0]['output']);
        $this->assertStringNotContainsString('a@b.example', $failed[0]['output']);
        $this->assertLessThanOrEqual(200, \core_text::strlen($all[0]['output']));
        $this->assertCount(1, $source->task_log(['from' => $now - 20]));
        $this->assertCount(2, $source->task_log(['limit' => 2]));
        $this->assertCount(1, $source->task_log(['limit' => 2, 'offset' => 2]));
    }

    /**
     * Cron age classification.
     */
    public function test_cron_status(): void {
        $this->resetAfterTest();
        $source = new task_source();
        $now = 1792800000;
        set_config('lastcronstart', 0, 'tool_task');
        $this->assertSame('never', $source->cron_status($now)['status']);
        set_config('lastcronstart', $now - 120, 'tool_task');
        $this->assertSame('ok', $source->cron_status($now)['status']);
        set_config('lastcronstart', $now - 7200, 'tool_task');
        $stale = $source->cron_status($now);
        $this->assertSame('stale', $stale['status']);
        $this->assertSame(7200, $stale['age']);
    }

    /**
     * The whole health check degrades safely when no log, no task data and no access log exist.
     */
    public function test_health_check_degrades_without_any_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $typeid = $this->create_platform_type();
        $connection = (new inventory())->get_connection("platform:$typeid");
        $result = (new health_check())->run($connection, time());
        $codes = array_column(array_map(function ($f) {
            return ['code' => $f->code];
        }, $result['findings']), 'code');
        $this->assertContains('obs_noreader', $codes);
        $this->assertContains('obs_noaccesslog', $codes);
        $this->assertContains('bg_cron_never', $codes);
        $this->assertSame('not_observable', $result['matrix']['nrps']['ladder']['observed']->state);
    }
}
