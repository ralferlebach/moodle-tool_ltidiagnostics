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

/**
 * Upgrade steps for tool_ltidiagnostics.
 *
 * Ship this file from the start: Moodle calls the function on every version bump, and the
 * validation of the Moodle plugins directory rejects a plugin without it.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the tool_ltidiagnostics plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_tool_ltidiagnostics_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102401) {
        // Development builds were installed before the tables existed. Create every table of
        // install.xml that is missing; tables that already exist are left untouched.
        tool_ltidiagnostics_create_missing_tables($dbman);
        upgrade_plugin_savepoint(true, 2026102401, 'tool', 'ltidiagnostics');
    }

    if ($oldversion < 2026102501) {
        // Release 0.2.0. Every step is idempotent: running it twice changes nothing.
        // 1. A site that skipped 0.1.0 or was repaired by hand may lack tables.
        tool_ltidiagnostics_create_missing_tables($dbman);

        // 2. Checks inside private networks need the new capability tool/ltidiagnostics:runprivatechecks (created by
        // Moodle from db/access.php). The setting itself stays as it was; the change is recorded once so that the
        // audit shows that a site with the exception enabled was upgraded to the stricter rule.
        if (
            (int) get_config('tool_ltidiagnostics', 'allowprivatetargets') === 1
                && !$DB->record_exists('tool_ltidiagnostics_audit', ['action' => 'upgrade_allowprivate'])
        ) {
            $DB->insert_record('tool_ltidiagnostics_audit', (object) [
                'userid' => 0,
                'action' => 'upgrade_allowprivate',
                'connkey' => '',
                'detail' => 'kept=1,capability=runprivatechecks',
                'timecreated' => time(),
            ]);
        }

        // 3. Repair settings that 0.1.0 accepted but that make no sense (zero or negative retention would delete
        // evidence at once, an empty diagnostic mode limit would never expire).
        foreach (['retentiondays' => 30, 'diagmodemaxminutes' => 240] as $name => $default) {
            $value = get_config('tool_ltidiagnostics', $name);
            if ($value !== false && (int) $value < 1) {
                set_config($name, $default, 'tool_ltidiagnostics');
            }
        }
        upgrade_plugin_savepoint(true, 2026102501, 'tool', 'ltidiagnostics');
    }

    if ($oldversion < 2026102502) {
        // Gate 2 finding F3: the category "service_ags_lineitems" (21 characters) did not fit char(20), so every
        // import with an AGS line item request failed and its job stayed "running". Widen the column to 40.
        $table = new xmldb_table('tool_ltidiagnostics_acc');
        $field = new xmldb_field('category', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null, 'requestid');
        // The index of 0.3.0 on the column blocks a change of its precision; it is dropped here and (re)created by
        // step 2026102700, so a repaired or re-run upgrade on a newer schema works as well.
        $index = new xmldb_index('category_time', XMLDB_INDEX_NOTUNIQUE, ['category', 'logtime']);
        $hadindex = $dbman->table_exists($table) && $dbman->index_exists($table, $index);
        if ($hadindex) {
            $dbman->drop_index($table, $index);
        }
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }
        if ($hadindex) {
            $dbman->add_index($table, $index);
        }
        // Jobs that were left "running" by that error (older than one hour) are closed as failed.
        $DB->execute(
            "UPDATE {tool_ltidiagnostics_imp} SET status = :failed, errormessage = :msg
              WHERE status = :running AND timecreated < :cutoff",
            ['failed' => 'failed', 'msg' => 'interrupted', 'running' => 'running', 'cutoff' => time() - HOURSECS]
        );
        upgrade_plugin_savepoint(true, 2026102502, 'tool', 'ltidiagnostics');
    }

    if ($oldversion < 2026102600) {
        // Release 0.2.1 (gate CS-G6): observations of LTI family transfer events keep their correlation id.
        $table = new xmldb_table('tool_ltidiagnostics_obs');
        $field = new xmldb_field('correlationid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'timecreated');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('correlationid', XMLDB_INDEX_NOTUNIQUE, ['correlationid']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026102600, 'tool', 'ltidiagnostics');
    }

    if ($oldversion < 2026102700) {
        // Release 0.3.0 (audit 2026-10-27, DIA-04): bounded queries over large imports and an import rate limit need
        // two indexes; retention is now set per data class.
        $table = new xmldb_table('tool_ltidiagnostics_acc');
        $index = new xmldb_index('category_time', XMLDB_INDEX_NOTUNIQUE, ['category', 'logtime']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $table = new xmldb_table('tool_ltidiagnostics_imp');
        $index = new xmldb_index('user_time', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // Until 0.2.1 one setting (retentiondays) governed observations, imported log lines and import jobs, while
        // audit rows and snapshots were kept for a fixed year. The new per-class settings start with exactly that
        // behaviour, so nothing is deleted earlier or kept longer because of the upgrade. Settings that already
        // exist are never overwritten (idempotent).
        $retention = (int) get_config('tool_ltidiagnostics', 'retentiondays');
        $defaults = [
            'retentionaccdays' => $retention > 0 ? $retention : 30,
            'retentionauditdays' => 365,
            'retentionsnapdays' => 365,
            'retentionpartnerdays' => 30,
            'importratelimit' => 10,
            'privatetargetallowlist' => '',
        ];
        foreach ($defaults as $name => $value) {
            if (get_config('tool_ltidiagnostics', $name) === false) {
                set_config($name, $value, 'tool_ltidiagnostics');
            }
        }
        upgrade_plugin_savepoint(true, 2026102700, 'tool', 'ltidiagnostics');
    }

    // Never declare DEFAULT="" on a CHAR NOT NULL column: XMLDB emits a debugging message for it,
    // and moodle-plugin-ci treats any debugging message during PHPUnit initialisation as a failure.
    return true;
}

/**
 * Create every table of install.xml that does not exist yet. Existing tables are left untouched.
 *
 * @param database_manager $dbman The database manager.
 */
function tool_ltidiagnostics_create_missing_tables(database_manager $dbman): void {
    $xmldbfile = new xmldb_file(__DIR__ . '/install.xml');
    if ($xmldbfile->fileExists() && $xmldbfile->loadXMLStructure()) {
        foreach ($xmldbfile->getStructure()->getTables() as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }
    }
}
