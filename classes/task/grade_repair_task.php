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

use tool_ltidiagnostics\local\grades\repair_service;

/**
 * Sends one confirmed grade replay attempt (issue #5). Queued only by repair_service.
 *
 * The task never throws for a failed transfer: failures are journal states with their class, transient ones are
 * re-queued by the service with backoff and a hard attempt limit, so core's own ad-hoc retry cannot create an endless
 * loop.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_repair_task extends \core\task\adhoc_task {
    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_graderepair', 'tool_ltidiagnostics');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $attemptid = is_object($data) && isset($data->attemptid) ? clean_param((string) $data->attemptid, PARAM_ALPHANUMEXT)
            : '';
        if ($attemptid === '') {
            mtrace('tool_ltidiagnostics: grade replay task without attempt id, nothing done.');
            return;
        }
        try {
            $state = (new repair_service())->process($attemptid);
        } catch (\Throwable $e) {
            // Never re-run automatically on an unexpected error: end the attempt with its class.
            $row = \tool_ltidiagnostics\local\grades\journal::get($attemptid);
            if ($row && in_array($row->state, ['queued', 'sending', 'retry_wait'], true)) {
                \tool_ltidiagnostics\local\grades\journal::update((int) $row->id, ['state' => 'aborted',
                    'errorclass' => 'task_failure']);
            }
            $state = 'aborted';
            mtrace('tool_ltidiagnostics: grade replay task failure (' . get_class($e) . ').');
        }
        mtrace("tool_ltidiagnostics: grade replay attempt $attemptid: $state.");
    }
}
