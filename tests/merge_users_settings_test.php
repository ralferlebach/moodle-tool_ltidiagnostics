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

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\local\merge_users_settings;

/**
 * Gate 2 finding F5 and audit round 2026-10-27: every table with a user column is declared to tool_mergeusers and keeps
 * its rows unchanged (history) when two accounts are merged.
 *
 * tool_mergeusers is not installed in the test sites. The callback is tested with a stand-in object and with the
 * configuration merge order of tool_mergeusers reproduced as plain arrays; when the real classes are present (the lead's
 * Gate 2 run) the same assertions run against them as well.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\merge_users_settings
 */
final class merge_users_settings_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var string Hook class of tool_mergeusers. */
    private const HOOK = 'tool_mergeusers\hook\add_settings_before_merging';

    /** @var string[] Default exceptions of tool_mergeusers (classes/local/default_db_config.php, 2026081302). */
    private const DEFAULT_EXCEPTIONS = ['user_preferences', 'user_private_key', 'user_info_data', 'my_pages'];

    /**
     * The callback hands the exclusion of the audit table to the hook.
     */
    public function test_callback_adds_the_audit_exception(): void {
        $hook = new class {
            /** @var array[] Received settings. */
            public $received = [];

            /**
             * Stand-in of add_settings_before_merging::add_raw_settings().
             *
             * @param array $settings Settings.
             */
            public function add_raw_settings(array $settings): void {
                $this->received[] = $settings;
            }
        };
        merge_users_settings::add_settings_before_merging($hook);
        $this->assertCount(1, $hook->received);
        $exceptions = $hook->received[0]['exceptions'];
        $this->assertSame('tool_ltidiagnostics_audit', $exceptions['tool_ltidiagnostics_audit']);
        foreach ($exceptions as $key => $table) {
            // String keys only (see test_merge_order_keeps_default_exceptions).
            $this->assertSame($table, $key);
        }
    }

    /**
     * Reproduce how tool_mergeusers combines its layers (custom, hooks, defaults; array_replace_recursive, the earlier
     * layer wins) and how it reads the exceptions (array_flip of the values).
     *
     * @param array[] $layers Layers, highest priority first.
     * @return string[] Tables that will be skipped.
     */
    private static function skipped_tables(array $layers): array {
        $merged = [];
        foreach ($layers as $layer) {
            $merged = array_replace_recursive($layer, $merged);
        }
        return array_keys(array_flip($merged['exceptions'] ?? []));
    }

    /**
     * The audit table is skipped and the default exceptions of tool_mergeusers stay in place. A plain list entry would
     * have replaced user_preferences: that is why the setting uses a string key.
     */
    public function test_merge_order_keeps_default_exceptions(): void {
        $defaults = ['exceptions' => self::DEFAULT_EXCEPTIONS];
        $skipped = self::skipped_tables([[], merge_users_settings::settings(), $defaults]);
        $this->assertContains('tool_ltidiagnostics_audit', $skipped);
        foreach (self::DEFAULT_EXCEPTIONS as $table) {
            $this->assertContains($table, $skipped);
        }
        // Another plugin with a plain list at index 0 does not remove ours either.
        $skipped = self::skipped_tables([['exceptions' => ['other_table']], merge_users_settings::settings(), $defaults]);
        $this->assertContains('tool_ltidiagnostics_audit', $skipped);
        $this->assertContains('other_table', $skipped);

        // Counter-example that motivated the design: a plain list loses a default exception.
        $naive = self::skipped_tables([[], ['exceptions' => ['tool_ltidiagnostics_audit']], $defaults]);
        $this->assertNotContains('user_preferences', $naive);
        // All tables with a user column stay unchanged (history), next to the defaults.
        $skipped = self::skipped_tables([[], merge_users_settings::settings(), $defaults]);
        foreach (array_keys(merge_users_settings::USER_TABLES) as $table) {
            $this->assertContains($table, $skipped);
        }
        foreach (self::DEFAULT_EXCEPTIONS as $table) {
            $this->assertContains($table, $skipped);
        }
    }

    /**
     * The registration in db/hooks.php is accepted by core's hook manager without any debugging message, whether or not
     * tool_mergeusers is installed, and the callback is listed for the hook class.
     */
    public function test_registration_without_tool_mergeusers_is_silent(): void {
        global $CFG;
        $manager = \core\hook\manager::phpunit_get_instance([
            'tool_ltidiagnostics' => $CFG->dirroot . '/admin/tool/ltidiagnostics/db/hooks.php',
        ]);
        $this->assertDebuggingNotCalled();
        $callbacks = $manager->get_callbacks_for_hook(self::HOOK);
        $this->assertCount(1, $callbacks);
        $this->assertSame('tool_ltidiagnostics\local\merge_users_settings::add_settings_before_merging', $callbacks[0]['callback']);
        $this->assertSame('tool_ltidiagnostics', $callbacks[0]['component']);

        // The site's real manager (all plugins) has it too.
        $site = \core\di::get(\core\hook\manager::class);
        $this->assertNotEmpty($site->get_callbacks_for_hook(self::HOOK));
        $this->assertDebuggingNotCalled();

        if (class_exists(self::HOOK)) {
            // Real tool_mergeusers present: dispatch the real hook through the isolated manager.
            $class = self::HOOK;
            $hook = $manager->dispatch(new $class());
            $this->assertDebuggingNotCalled();
            $this->assertContains('tool_ltidiagnostics_audit', $hook->get_settings()->exceptions);
            $this->assertContains('tool_ltidiagnostics_obs', $hook->get_settings()->exceptions);
        } else {
            // Without it: nothing can dispatch the hook, and the admin hook overview copes with the missing class.
            $this->assertArrayHasKey(self::HOOK, (array) $manager->get_all_callbacks());
            $this->assertSame([], \core\hook\manager::get_replaced_callbacks(self::HOOK));
            $this->assertDebuggingNotCalled();
        }
    }

    /**
     * Every table of install.xml with a column that tool_mergeusers would treat as a user column (its default list plus
     * any *userid / usermodified column) is declared with exactly those columns; the access log table has none. This is
     * what keeps local_ltiaccountmerge's preview from blocking on an undeclared table. Negative control: a schema with an
     * extra user column fails the comparison.
     */
    public function test_every_user_table_is_declared(): void {
        global $CFG;
        $xml = simplexml_load_file($CFG->dirroot . '/admin/tool/ltidiagnostics/db/install.xml');
        $found = self::user_columns($xml);
        $this->assertSame(merge_users_settings::USER_TABLES, $found);
        $this->assertArrayNotHasKey('tool_ltidiagnostics_acc', $found);
        $declared = array_keys(merge_users_settings::settings()['exceptions']);
        sort($declared);
        $tables = array_keys($found);
        sort($tables);
        $this->assertSame($tables, $declared);
        if (method_exists('local_ltiaccountmerge\local\merge_preview', 'declare_tables')) {
            // The merge preview's own reading of the settings (only when that plugin is installed).
            $seen = [];
            $settings = merge_users_settings::settings();
            \local_ltiaccountmerge\local\merge_preview::declare_tables($seen, $settings, 'tool_ltidiagnostics');
            foreach ($tables as $table) {
                $this->assertSame(['tool_ltidiagnostics' => 'tool_ltidiagnostics'], $seen[$table]);
            }
        }

        // Negative control: a new user column in a table would be detected.
        $acc = $xml->xpath('//TABLE[@NAME="tool_ltidiagnostics_acc"]/FIELDS')[0];
        $field = $acc->addChild('FIELD');
        $field->addAttribute('NAME', 'relateduserid');
        $this->assertNotSame(merge_users_settings::USER_TABLES, self::user_columns($xml));
    }

    /**
     * User columns per table, as tool_mergeusers' default field list (and any *userid column) would find them.
     *
     * @param \SimpleXMLElement $xml install.xml
     * @return array Table => columns, in schema order.
     */
    private static function user_columns(\SimpleXMLElement $xml): array {
        $defaults = ['authorid', 'id_user', 'loggeduser', 'reviewerid', 'user', 'user_id', 'usercreated', 'userid',
            'useridfrom', 'useridto', 'usermodified'];
        $found = [];
        foreach ($xml->xpath('//TABLE') as $table) {
            foreach ($table->xpath('FIELDS/FIELD') as $field) {
                $name = (string) $field['NAME'];
                if (in_array($name, $defaults, true) || preg_match('/userid$/', $name)) {
                    $found[(string) $table['NAME']][] = $name;
                }
            }
        }
        return $found;
    }

    /**
     * With the real configuration classes of tool_mergeusers (when installed) the result is the same; without them the
     * array reproduction above is the evidence.
     */
    public function test_real_configuration_classes_when_present(): void {
        if (class_exists('tool_mergeusers\local\settable_db_config')) {
            $config = new \tool_mergeusers\local\settable_db_config();
            $config->merge_with(new \tool_mergeusers\local\db_config(merge_users_settings::settings()));
            $config->merge_with(new \tool_mergeusers\local\db_config(\tool_mergeusers\local\default_db_config::$config));
            $skipped = array_keys(array_flip($config->exceptions));
        } else {
            $skipped = self::skipped_tables([[], merge_users_settings::settings(), ['exceptions' => self::DEFAULT_EXCEPTIONS]]);
        }
        foreach (array_keys(merge_users_settings::USER_TABLES) as $table) {
            $this->assertContains($table, $skipped);
        }
        $this->assertContains('user_preferences', $skipped);
    }
}
