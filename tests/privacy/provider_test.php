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

namespace tool_ltidiagnostics\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\observation_store;

/**
 * Privacy API tests: metadata, export and deletion against the real tables.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Create stored data for a user in every table that refers to a person.
     *
     * @param \stdClass $user User.
     * @param \stdClass $course Course.
     */
    private function store_data_for(\stdClass $user, \stdClass $course): void {
        global $DB;
        $coursecontext = \context_course::instance($course->id);
        observation_store::record([
            'eventtype' => 'enrolment', 'eventname' => '\core\event\user_enrolment_created', 'userid' => $user->id,
            'courseid' => $course->id, 'contextid' => $coursecontext->id, 'timecreated' => 1792800000,
        ]);
        $this->setUser($user);
        audit::log('viewconnection', 'platform:1', 'detail');
        $DB->insert_record('tool_ltidiagnostics_snap', (object) [
            'connkey' => 'platform:1', 'kind' => 'matrix', 'contenthash' => 'h', 'snapjson' => '{}', 'userid' => $user->id,
            'timecreated' => 1792800000,
        ]);
        $DB->insert_record('tool_ltidiagnostics_imp', (object) [
            'kind' => 'access', 'format' => 'combined', 'sourcetype' => 'upload', 'sourcename' => 'a.log',
            'fingerprint' => 'f' . $user->id, 'timezone' => 'UTC', 'skew' => 0, 'rangefrom' => 0, 'rangeto' => 0,
            'linesread' => 0, 'linesstored' => 0, 'linesskipped' => 0, 'linesinvalid' => 0, 'status' => 'done',
            'errormessage' => '', 'userid' => $user->id, 'timecreated' => 1792800000,
        ]);
        $DB->insert_record('tool_ltidiagnostics_profile', (object) [
            'name' => 'p' . $user->id, 'connkey' => 'platform:' . $user->id, 'profilejson' => '{}', 'usermodified' => $user->id,
            'timecreated' => 1792800000, 'timemodified' => 1792800000,
        ]);
    }

    /**
     * The metadata describes every table that contains personal data, with a language string each.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('tool_ltidiagnostics'));
        $items = $collection->get_collection();
        $tables = array_map(function ($item) {
            return $item->get_name();
        }, $items);
        $this->assertEqualsCanonicalizing([
            'tool_ltidiagnostics_obs', 'tool_ltidiagnostics_audit', 'tool_ltidiagnostics_snap', 'tool_ltidiagnostics_imp',
            'tool_ltidiagnostics_profile', 'tool_ltidiagnostics_acc',
        ], $tables);
        $sm = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($sm->string_exists($item->get_summary(), 'tool_ltidiagnostics'), $item->get_summary());
            foreach ($item->get_privacy_fields() as $field => $stringid) {
                $this->assertTrue($sm->string_exists($stringid, 'tool_ltidiagnostics'), $stringid);
            }
        }
    }

    /**
     * Every database table of the plugin is either described or free of personal data.
     */
    public function test_all_tables_with_user_columns_are_described(): void {
        global $DB;
        $described = ['tool_ltidiagnostics_obs', 'tool_ltidiagnostics_audit', 'tool_ltidiagnostics_snap',
            'tool_ltidiagnostics_imp', 'tool_ltidiagnostics_profile', 'tool_ltidiagnostics_acc'];
        $xmldb = new \xmldb_file(__DIR__ . '/../../db/install.xml');
        $xmldb->loadXMLStructure();
        $tables = array_map(function ($t) {
            return 'tool_ltidiagnostics_' . substr($t->getName(), strlen('tool_ltidiagnostics_'));
        }, $xmldb->getStructure()->getTables());
        $this->assertEqualsCanonicalizing($described, $tables, 'A new table needs a privacy decision.');
        $this->assertNotNull($DB);
    }

    /**
     * Contexts and users are found; a user without data has no context.
     */
    public function test_contexts_and_users(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->store_data_for($user, $course);

        $list = provider::get_contexts_for_userid((int) $user->id);
        $ids = $list->get_contextids();
        $this->assertContains((int) \context_system::instance()->id, array_map('intval', $ids));
        $this->assertContains((int) \context_course::instance($course->id)->id, array_map('intval', $ids));
        $this->assertCount(0, provider::get_contexts_for_userid((int) $other->id));

        $userlist = new userlist(\context_system::instance(), 'tool_ltidiagnostics');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $user->id, array_map('intval', $userlist->get_userids()));
        $this->assertNotContains((int) $other->id, array_map('intval', $userlist->get_userids()));
        $courselist = new userlist(\context_course::instance($course->id), 'tool_ltidiagnostics');
        provider::get_users_in_context($courselist);
        $this->assertSame([(int) $user->id], array_map('intval', $courselist->get_userids()));
    }

    /**
     * Export contains the observations, the audit entries and the authorship counts of the user, and nobody else's.
     */
    public function test_export(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->store_data_for($user, $course);
        $this->store_data_for($other, $course);

        $list = provider::get_contexts_for_userid((int) $user->id);
        $approved = new approved_contextlist($user, 'tool_ltidiagnostics', $list->get_contextids());
        provider::export_user_data($approved);

        $subcontext = [get_string('pluginname', 'tool_ltidiagnostics')];
        $course = \context_course::instance($course->id);
        $coursedata = writer::with_context($course)->get_data(array_merge($subcontext, ['observations']));
        $this->assertCount(1, $coursedata->observations);
        $this->assertSame('enrolment', $coursedata->observations[0]['type']);
        $system = \context_system::instance();
        $audit = writer::with_context($system)->get_data(array_merge($subcontext, ['audit']));
        $this->assertCount(1, $audit->audit);
        $this->assertSame('viewconnection', $audit->audit[0]['action']);
        $authored = writer::with_context($system)->get_data(array_merge($subcontext, ['authorship']));
        $this->assertSame(1, $authored->snapshots);
        $this->assertSame(1, $authored->imports);
        $this->assertSame(1, $authored->profiles);
    }

    /**
     * Deletion for one user removes that user's observations and audit entries and unlinks authored records.
     */
    public function test_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->store_data_for($user, $course);
        $this->store_data_for($other, $course);

        $list = provider::get_contexts_for_userid((int) $user->id);
        provider::delete_data_for_user(new approved_contextlist($user, 'tool_ltidiagnostics', $list->get_contextids()));

        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_obs', ['userid' => $user->id]));
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_obs', ['userid' => $other->id]));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit', ['userid' => $user->id]));
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_audit', ['userid' => $other->id]));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_snap', ['userid' => $user->id]));
        $this->assertSame(2, $DB->count_records('tool_ltidiagnostics_snap'), 'Snapshots are kept, only unlinked.');
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_imp', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_profile', ['usermodified' => $user->id]));
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_profile', ['usermodified' => $other->id]));
        $this->assertCount(0, provider::get_contexts_for_userid((int) $user->id));
    }

    /**
     * Deletion for several users in a context.
     */
    public function test_delete_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $c = $this->getDataGenerator()->create_user();
        foreach ([$a, $b, $c] as $u) {
            $this->store_data_for($u, $course);
        }
        $system = \context_system::instance();
        provider::delete_data_for_users(new approved_userlist($system, 'tool_ltidiagnostics', [$a->id, $b->id]));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit', ['userid' => $a->id]));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit', ['userid' => $b->id]));
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_audit', ['userid' => $c->id]));
        $courselist = new approved_userlist(\context_course::instance($course->id), 'tool_ltidiagnostics', [$c->id]);
        provider::delete_data_for_users($courselist);
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_obs', ['userid' => $c->id]));
        $this->assertSame(
            1,
            $DB->count_records('tool_ltidiagnostics_audit', ['userid' => $c->id]),
            'Course level does not touch system data.'
        );
    }

    /**
     * Deletion for all users in a context.
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->store_data_for($user, $course);
        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_obs'));
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_audit'));
        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit'));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_snap', ['userid' => $user->id]));
    }
}
