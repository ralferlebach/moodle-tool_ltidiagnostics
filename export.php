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
 * Download of a diagnostic report (Markdown, JSON or CSV). Payloads are never included.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\diagnostic_mode;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\profile_manager;
use tool_ltidiagnostics\local\report;
use tool_ltidiagnostics\local\report_exporter;
use tool_ltidiagnostics\local\snapshot;
use tool_ltidiagnostics\local\timeline;

require_login(null, false);
require_sesskey();
$context = context_system::instance();
require_capability('tool/ltidiagnostics:export', $context);
require_capability('tool/ltidiagnostics:view', $context);

$key = required_param('key', PARAM_RAW);
$format = required_param('format', PARAM_ALPHA);
if (!in_array($format, report_exporter::FORMATS, true) || connection::parse_key($key) === null) {
    throw new \moodle_exception('invalidaction', 'tool_ltidiagnostics');
}
$conn = (new inventory())->get_connection($key);
if ($conn === null) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$filters = ['courseid' => optional_param('courseid', 0, PARAM_INT), 'cmid' => optional_param('cmid', 0, PARAM_INT), 'from' => 0,
    'to' => 0];
foreach (['from', 'to'] as $name) {
    $time = strtotime(optional_param($name, '', PARAM_TEXT) . ' UTC');
    $filters[$name] = $time ? (int) $time : 0;
}
$now = time();
$reveal = diagnostic_mode::can_reveal();
$result = (new health_check())->run($conn, $now, [
    'profile' => profile_manager::get($key),
    'probes' => snapshot::latest_probes($key),
    'filters' => $filters,
]);
$tl = (new timeline())->build($conn, $context, $filters, $now);
$report = (new report())->build($conn, $result, [
    'reveal' => $reveal,
    'filters' => array_filter($filters),
    'timeline' => $tl,
    'viewerrole' => 'tool/ltidiagnostics:view',
    'sources' => $tl['sources'],
    'now' => $now,
]);
audit::log('export', $key, 'format=' . $format . ',identifiers=' . ($reveal ? 'revealed' : 'masked'));
\tool_ltidiagnostics\event\report_exported::create(['context' => $context, 'other' => ['connection' => $key, 'format' => $format]])
    ->trigger();
[$mime, $ext] = report_exporter::file_info($format);
$filename = 'ltidiagnostics-' . $report['meta']['diagnosticid'] . '-' . gmdate('Ymd-His', $now) . '.' . $ext;
send_file(report_exporter::export($report, $format), $filename, 0, 0, true, true, $mime);
