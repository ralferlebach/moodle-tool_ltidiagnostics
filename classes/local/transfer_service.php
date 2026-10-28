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

namespace tool_ltidiagnostics\local;

/**
 * Actions on completion sync transfer reports (DIA-05), shared by the page and the command line script.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_service {
    /**
     * Build the report of a connection with the newest imported partner trace.
     *
     * @param connection $c Connection.
     * @param array $filters from, to.
     * @param int $now Time.
     * @return array Report.
     */
    public static function report(connection $c, array $filters, int $now): array {
        $context = \context_system::instance();
        require_capability('tool/ltidiagnostics:view', $context);
        $collected = transfer_report::collect($c, $context, $filters, $now);
        $partner = null;
        $stored = snapshot::latest_kind($c->key, transfer_report::KIND);
        if ($stored !== null && !empty($stored['trace'])) {
            try {
                $partner = transfer_report::parse_trace(json_encode($stored['trace']));
            } catch (\moodle_exception $e) {
                $partner = null;
            }
        }
        return transfer_report::build($c, $collected, $partner);
    }

    /**
     * Export the redacted trace of this site for the partner.
     *
     * @param connection $c Connection.
     * @param array $filters from, to.
     * @param int $now Time.
     * @return array Trace.
     */
    public static function export_trace(connection $c, array $filters, int $now): array {
        $context = \context_system::instance();
        require_capability('tool/ltidiagnostics:export', $context);
        require_capability('tool/ltidiagnostics:view', $context);
        $report = transfer_report::build($c, transfer_report::collect($c, $context, $filters, $now), null);
        $trace = transfer_report::export_trace($c, $report, $now);
        audit::log('trace_export', $c->key, 'transactions=' . count($trace['transactions']));
        return $trace;
    }

    /**
     * Import a partner trace.
     *
     * @param connection $c Connection.
     * @param string $json Trace text.
     * @param int $now Time.
     * @return int Number of transactions in the trace.
     */
    public static function import_trace(connection $c, string $json, int $now): int {
        require_capability('tool/ltidiagnostics:configure', \context_system::instance());
        $trace = transfer_report::parse_trace($json);
        snapshot::save_kind($c->key, transfer_report::KIND, ['trace' => $trace], $now);
        audit::log('trace_import', $c->key, 'partner=' . $trace['diagnosticid'] . ',transactions=' . count($trace['transactions']));
        return count($trace['transactions']);
    }
}
