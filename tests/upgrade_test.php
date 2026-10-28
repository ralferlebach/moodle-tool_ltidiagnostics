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

namespace tool_ltidiagnostics;

/**
 * The upgrade step 0.1.0 -> 0.2.0 (audit item FAM-01): data of a 0.1.0 site survives, the step is idempotent, and the
 * version metadata says 0.2.0.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_tool_ltidiagnostics_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var int Version of the 0.1.0 release. */
    private const V010 = 2026102401;

    /**
     * Put the installed version back to 0.1.0 (the schema of 0.1.0 equals the current one) and load the upgrade code.
     */
    private function become_010(): void {
        global $CFG;
        set_config('version', self::V010, 'tool_ltidiagnostics');
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/ltidiagnostics/db/upgrade.php');
    }

    /**
     * Run the upgrade as Moodle would, from a given version.
     *
     * @param int $from Version we upgrade from.
     * @return bool
     */
    private function upgrade(int $from = self::V010): bool {
        // The savepoint helper of core may print its timing line.
        ob_start();
        try {
            return xmldb_tool_ltidiagnostics_upgrade($from);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Fill every table with one row as a 0.1.0 site would have it.
     *
     * @return array Table => row id
     */
    private function seed_010_data(): array {
        global $DB;
        $now = time();
        $ids = [];
        $ids['tool_ltidiagnostics_obs'] = $DB->insert_record('tool_ltidiagnostics_obs', (object) [
            'traceid' => 'abc', 'eventtype' => 'launch', 'direction' => 'in', 'eventname' => '\x\y', 'component' => 'enrol_lti',
            'connkey' => 'tool:3:4', 'issuerhash' => str_repeat('a', 64), 'courseid' => 2, 'cmid' => 0, 'contextid' => 1,
            'userid' => 5, 'objectid' => 0, 'entrypoint' => 'lti_launch', 'fieldnames' => 'a,b', 'timecreated' => $now,
        ]);
        $ids['tool_ltidiagnostics_audit'] = $DB->insert_record('tool_ltidiagnostics_audit', (object) [
            'userid' => 2, 'action' => 'view_matrix', 'connkey' => 'tool:3:4', 'detail' => 'identifiers=masked',
            'timecreated' => $now,
        ]);
        $ids['tool_ltidiagnostics_profile'] = $DB->insert_record('tool_ltidiagnostics_profile', (object) [
            'name' => 'Grades back', 'connkey' => 'tool:3:4', 'profilejson' => '{"schema":1,"name":"Grades back"}',
            'usermodified' => 2, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $ids['tool_ltidiagnostics_snap'] = $DB->insert_record('tool_ltidiagnostics_snap', (object) [
            'connkey' => 'tool:3:4', 'kind' => 'matrix', 'contenthash' => str_repeat('b', 64), 'snapjson' => '{"ags":"configured"}',
            'userid' => 2, 'timecreated' => $now,
        ]);
        $ids['tool_ltidiagnostics_imp'] = $DB->insert_record('tool_ltidiagnostics_imp', (object) [
            'kind' => 'access', 'format' => 'combined', 'sourcetype' => 'path', 'sourcename' => 'access.log',
            'fingerprint' => str_repeat('c', 64), 'timezone' => 'UTC', 'skew' => 0, 'rangefrom' => $now - 10, 'rangeto' => $now,
            'linesread' => 3, 'linesstored' => 1, 'linesskipped' => 1, 'linesinvalid' => 1, 'status' => 'done',
            'errormessage' => '', 'userid' => 2, 'timecreated' => $now,
        ]);
        $ids['tool_ltidiagnostics_acc'] = $DB->insert_record('tool_ltidiagnostics_acc', (object) [
            'importid' => $ids['tool_ltidiagnostics_imp'], 'linehash' => str_repeat('d', 40), 'kind' => 'access',
            'logtime' => $now, 'method' => 'POST', 'path' => '/enrol/lti/launch.php', 'httpstatus' => 200, 'bytes' => 10,
            'ipmask' => '203.0.113.0', 'viaproxy' => 0, 'requestid' => '', 'category' => 'launch', 'message' => '',
        ]);
        return $ids;
    }

    /**
     * Release metadata says 0.3.0 and the Moodle version is newer than the 0.1.0 build.
     */
    public function test_version_metadata(): void {
        global $CFG;
        $plugin = new \stdClass();
        require($CFG->dirroot . '/admin/tool/ltidiagnostics/version.php');
        $this->assertSame('0.3.0', $plugin->release);
        $this->assertSame('tool_ltidiagnostics', $plugin->component);
        $this->assertGreaterThan(self::V010, $plugin->version);
        $this->assertSame(2026102700, $plugin->version);
        $this->assertSame(MATURITY_ALPHA, $plugin->maturity);
        $this->assertSame([405, 503], $plugin->supported);
        $info = \core_plugin_manager::instance()->get_plugin_info('tool_ltidiagnostics');
        $this->assertSame('0.3.0', $info->release);
    }

    /**
     * Upgrading from 0.1.0 keeps every row and every setting, adds the audit entry for a site that allowed private
     * targets, repairs nonsensical limits, and a second run changes nothing.
     */
    public function test_upgrade_from_010_keeps_data_and_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->become_010();
        $ids = $this->seed_010_data();
        set_config('allowprivatetargets', 1, 'tool_ltidiagnostics');
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        set_config('retentiondays', 0, 'tool_ltidiagnostics');
        set_config('diagmodemaxminutes', -5, 'tool_ltidiagnostics');
        set_config('logallowlist', "/var/log/nginx\n", 'tool_ltidiagnostics');
        $before = [];
        foreach (array_keys($ids) as $table) {
            $before[$table] = array_values($DB->get_records($table));
        }

        $this->assertTrue($this->upgrade());

        foreach ($before as $table => $rows) {
            if ($table === 'tool_ltidiagnostics_audit') {
                continue;
            }
            $this->assertEquals($rows, array_values($DB->get_records($table)), "$table must be untouched.");
        }
        $this->assertSame(1, $DB->count_records('tool_ltidiagnostics_audit', ['action' => 'view_matrix']));
        $upgradeaudit = $DB->get_records('tool_ltidiagnostics_audit', ['action' => 'upgrade_allowprivate']);
        $this->assertCount(1, $upgradeaudit);
        $this->assertSame('kept=1,capability=runprivatechecks', reset($upgradeaudit)->detail);
        $this->assertSame('1', get_config('tool_ltidiagnostics', 'allowprivatetargets'), 'The setting itself is kept.');
        $this->assertSame('1', get_config('tool_ltidiagnostics', 'activechecks'));
        $this->assertSame("/var/log/nginx\n", get_config('tool_ltidiagnostics', 'logallowlist'));
        $this->assertSame('30', get_config('tool_ltidiagnostics', 'retentiondays'));
        $this->assertSame('240', get_config('tool_ltidiagnostics', 'diagmodemaxminutes'));

        // Second run: no change at all.
        $counts = [];
        foreach (array_keys($ids) as $table) {
            $counts[$table] = $DB->count_records($table);
        }
        $this->become_010();
        $this->assertTrue($this->upgrade());
        foreach ($counts as $table => $count) {
            $this->assertSame($count, $DB->count_records($table), "$table after the second run");
        }
        $this->assertSame('30', get_config('tool_ltidiagnostics', 'retentiondays'));
    }

    /**
     * A site that never allowed private targets gets no audit entry; valid limits are not touched.
     */
    public function test_upgrade_leaves_good_settings_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->become_010();
        set_config('allowprivatetargets', 0, 'tool_ltidiagnostics');
        set_config('retentiondays', 90, 'tool_ltidiagnostics');
        set_config('diagmodemaxminutes', 60, 'tool_ltidiagnostics');
        $this->assertTrue($this->upgrade());
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit', ['action' => 'upgrade_allowprivate']));
        $this->assertSame('90', get_config('tool_ltidiagnostics', 'retentiondays'));
        $this->assertSame('60', get_config('tool_ltidiagnostics', 'diagmodemaxminutes'));
    }

    /**
     * A site that misses a table (installed from a development build) gets it back; the other tables keep their rows.
     */
    public function test_upgrade_recreates_a_missing_table(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->become_010();
        $ids = $this->seed_010_data();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_ltidiagnostics_snap');
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table));
        $this->assertTrue($this->upgrade());
        $this->assertTrue($dbman->table_exists($table));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_snap'));
        $this->assertTrue($DB->record_exists('tool_ltidiagnostics_obs', ['id' => $ids['tool_ltidiagnostics_obs']]));
        $this->assertTrue($DB->record_exists('tool_ltidiagnostics_acc', ['id' => $ids['tool_ltidiagnostics_acc']]));
    }

    /**
     * Upgrading from the current version does nothing.
     */
    public function test_upgrade_from_current_version_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();
        $this->become_010();
        set_config('allowprivatetargets', 1, 'tool_ltidiagnostics');
        set_config('retentiondays', 0, 'tool_ltidiagnostics');
        $this->assertTrue($this->upgrade(2026102700));
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit', ['action' => 'upgrade_allowprivate']));
        $this->assertSame('0', get_config('tool_ltidiagnostics', 'retentiondays'));
    }

    /**
     * The new capability exists after installation, is not granted to any role by default and carries a risk flag.
     */
    public function test_new_capability_is_defined(): void {
        global $DB;
        $cap = $DB->get_record('capabilities', ['name' => 'tool/ltidiagnostics:runprivatechecks'], '*', MUST_EXIST);
        $this->assertSame('write', $cap->captype);
        $this->assertSame((int) RISK_CONFIG, (int) $cap->riskbitmask);
    }

    /**
     * Step 2026102502 (Gate 2 finding F3): a site with the 0.1.0/0.2.0 column char(20) gets char(40), so the category
     * "service_ags_lineitems" fits; jobs left "running" by the old error are closed as failed, a fresh running job is
     * left alone; a second run changes nothing.
     */
    public function test_upgrade_widens_category_and_closes_stuck_jobs(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_ltidiagnostics_acc');
        $narrow = new \xmldb_field('category', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null, 'requestid');
        // A 0.2.0 site has no index on the column yet (it comes with 0.3.0).
        $index = new \xmldb_index('category_time', XMLDB_INDEX_NOTUNIQUE, ['category', 'logtime']);
        $dbman->drop_index($table, $index);
        $dbman->change_field_precision($table, $narrow);
        try {
            $this->assertSame(20, (int) $DB->get_columns('tool_ltidiagnostics_acc', false)['category']->max_length);
            $job = ['kind' => 'access', 'format' => 'combined', 'sourcetype' => 'upload', 'sourcename' => 'a.log',
                'fingerprint' => 'f', 'timezone' => 'UTC', 'skew' => 0, 'rangefrom' => 0, 'rangeto' => 0, 'linesread' => 0,
                'linesstored' => 0, 'linesskipped' => 0, 'linesinvalid' => 0, 'status' => 'running', 'errormessage' => '',
                'userid' => 2];
            $stuck = $DB->insert_record('tool_ltidiagnostics_imp', (object) ($job + ['timecreated' => time() - 2 * HOURSECS]));
            $fresh = $DB->insert_record('tool_ltidiagnostics_imp', (object) ($job + ['timecreated' => time()]));
            $this->become_010();
            set_config('version', 2026102501, 'tool_ltidiagnostics');
            $this->assertTrue($this->upgrade(2026102501));
            $this->assertSame(40, (int) $DB->get_columns('tool_ltidiagnostics_acc', false)['category']->max_length);
            $this->assertSame('failed', $DB->get_field('tool_ltidiagnostics_imp', 'status', ['id' => $stuck]));
            $this->assertSame('interrupted', $DB->get_field('tool_ltidiagnostics_imp', 'errormessage', ['id' => $stuck]));
            $this->assertSame('running', $DB->get_field('tool_ltidiagnostics_imp', 'status', ['id' => $fresh]));
            set_config('version', 2026102501, 'tool_ltidiagnostics');
            $this->assertTrue($this->upgrade(2026102501), 'second run');
            $this->assertSame(40, (int) $DB->get_columns('tool_ltidiagnostics_acc', false)['category']->max_length);
        } finally {
            // The schema is not reset between tests: always leave the installed definition behind.
            $wide = new \xmldb_field('category', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null, 'requestid');
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            $dbman->change_field_precision($table, $wide);
            $dbman->add_index($table, $index);
        }
    }

    /**
     * Step 2026102600 (release 0.2.1, gate CS-G6): the observation table gets the correlation id column and its index;
     * existing observations are kept; a second run changes nothing.
     */
    public function test_upgrade_adds_correlation_id(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_ltidiagnostics_obs');
        $field = new \xmldb_field('correlationid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'timecreated');
        $index = new \xmldb_index('correlationid', XMLDB_INDEX_NOTUNIQUE, ['correlationid']);
        $ids = $this->seed_010_data();
        $dbman->drop_index($table, $index);
        $dbman->drop_field($table, $field);
        try {
            $this->assertFalse($dbman->field_exists($table, $field));
            $this->become_010();
            set_config('version', 2026102502, 'tool_ltidiagnostics');
            $this->assertTrue($this->upgrade(2026102502));
            $this->assertTrue($dbman->field_exists($table, $field));
            $this->assertTrue($dbman->index_exists($table, $index));
            $this->assertTrue($DB->record_exists('tool_ltidiagnostics_obs', ['id' => $ids['tool_ltidiagnostics_obs']]));
            $obsid = $ids['tool_ltidiagnostics_obs'];
            $this->assertNull($DB->get_field('tool_ltidiagnostics_obs', 'correlationid', ['id' => $obsid]));
            set_config('version', 2026102502, 'tool_ltidiagnostics');
            $this->assertTrue($this->upgrade(2026102502), 'second run');
        } finally {
            // The schema is not reset between tests: always leave the installed definition behind.
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
    }

    /**
     * Step 2026102700 (release 0.3.0, audit DIA-04): the indexes for bounded queries and the import rate limit are added,
     * the retention of imported lines starts with the old common value, other new settings get their defaults, settings
     * that exist are kept, data is kept, and a second run changes nothing.
     */
    public function test_upgrade_from_021(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $acc = new \xmldb_table('tool_ltidiagnostics_acc');
        $imp = new \xmldb_table('tool_ltidiagnostics_imp');
        $accindex = new \xmldb_index('category_time', XMLDB_INDEX_NOTUNIQUE, ['category', 'logtime']);
        $impindex = new \xmldb_index('user_time', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        $ids = $this->seed_010_data();
        $dbman->drop_index($acc, $accindex);
        $dbman->drop_index($imp, $impindex);
        $names = ['retentionaccdays', 'retentionsnapdays', 'retentionpartnerdays', 'importratelimit', 'privatetargetallowlist'];
        foreach ($names as $name) {
            unset_config($name, 'tool_ltidiagnostics');
        }
        set_config('retentiondays', 14, 'tool_ltidiagnostics');
        set_config('retentionauditdays', 99, 'tool_ltidiagnostics');
        try {
            $this->become_010();
            set_config('version', 2026102600, 'tool_ltidiagnostics');
            $this->assertTrue($this->upgrade(2026102600));
            $this->assertTrue($dbman->index_exists($acc, $accindex));
            $this->assertTrue($dbman->index_exists($imp, $impindex));
            $this->assertSame('14', get_config('tool_ltidiagnostics', 'retentionaccdays'), 'Same retention as before.');
            $this->assertSame('99', get_config('tool_ltidiagnostics', 'retentionauditdays'), 'An existing setting is kept.');
            $this->assertSame('365', get_config('tool_ltidiagnostics', 'retentionsnapdays'));
            $this->assertSame('30', get_config('tool_ltidiagnostics', 'retentionpartnerdays'));
            $this->assertSame('10', get_config('tool_ltidiagnostics', 'importratelimit'));
            $this->assertSame('', get_config('tool_ltidiagnostics', 'privatetargetallowlist'));
            foreach ($ids as $table => $id) {
                $this->assertTrue($DB->record_exists($table, ['id' => $id]), $table);
            }
            set_config('retentionaccdays', 7, 'tool_ltidiagnostics');
            set_config('version', 2026102600, 'tool_ltidiagnostics');
            $this->assertTrue($this->upgrade(2026102600), 'second run');
            $this->assertSame('7', get_config('tool_ltidiagnostics', 'retentionaccdays'), 'The second run changes nothing.');
        } finally {
            // The schema is not reset between tests: always leave the installed definition behind.
            if (!$dbman->index_exists($acc, $accindex)) {
                $dbman->add_index($acc, $accindex);
            }
            if (!$dbman->index_exists($imp, $impindex)) {
                $dbman->add_index($imp, $impindex);
            }
        }
    }
}
