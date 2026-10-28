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

namespace tool_ltidiagnostics\task;

use tool_ltidiagnostics\local\config;
use tool_ltidiagnostics\local\diagnostic_mode;
use tool_ltidiagnostics\local\observation_store;

/**
 * Applies the retention rules and switches an expired diagnostic mode off.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_task extends \core\task\scheduled_task {
    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup', 'tool_ltidiagnostics');
    }

    /**
     * Run the task.
     */
    public function execute() {
        global $DB;
        $now = time();
        $days = function (string $setting): int {
            return max(1, (int) config::get($setting));
        };
        // Retention per data class (DIA-04). Observations follow "retentiondays"; imported web server lines and their
        // jobs "retentionaccdays"; audit rows, snapshots and imported partner data their own settings.
        $deleted = observation_store::purge_older_than($now - $days('retentiondays') * DAYSECS);
        $acc = $now - $days('retentionaccdays') * DAYSECS;
        $lines = $DB->count_records_select('tool_ltidiagnostics_acc', 'logtime < ?', [$acc]);
        $DB->delete_records_select('tool_ltidiagnostics_acc', 'logtime < ?', [$acc]);
        $DB->delete_records_select('tool_ltidiagnostics_imp', 'timecreated < ?', [$acc]);
        $partnerkinds = [\tool_ltidiagnostics\local\partner_package::KIND, \tool_ltidiagnostics\local\transfer_report::KIND];
        [$insql, $params] = $DB->get_in_or_equal($partnerkinds);
        $DB->delete_records_select(
            'tool_ltidiagnostics_snap',
            "kind $insql AND timecreated < ?",
            array_merge($params, [$now - $days('retentionpartnerdays') * DAYSECS])
        );
        $DB->delete_records_select('tool_ltidiagnostics_snap', 'timecreated < ?', [$now - $days('retentionsnapdays') * DAYSECS]);
        $DB->delete_records_select('tool_ltidiagnostics_audit', 'timecreated < ?', [$now - $days('retentionauditdays') * DAYSECS]);
        diagnostic_mode::expire();
        mtrace("tool_ltidiagnostics: removed $deleted observations and $lines imported log lines past their retention.");
    }
}
