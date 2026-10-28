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
 * Completion sync transfers of a connection (DIA-05): report, exports and partner trace import.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\transfer_report;
use tool_ltidiagnostics\local\transfer_service;

$key = required_param('key', PARAM_RAW);
$action = optional_param('action', '', PARAM_ALPHA);
$filters = ['from' => 0, 'to' => 0];
foreach (['from', 'to'] as $name) {
    $text = optional_param($name, '', PARAM_TEXT);
    $time = $text !== '' ? strtotime($text . ' UTC') : false;
    $filters[$name] = $time ? (int) $time : 0;
}
$pageurl = new moodle_url('/admin/tool/ltidiagnostics/transfers.php', ['key' => $key]);
admin_externalpage_setup('tool_ltidiagnostics_index', '', null, $pageurl);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:view', $context);
if (connection::parse_key($key) === null || !($conn = (new inventory())->get_connection($key))) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$now = time();

if (in_array($action, ['json', 'md', 'trace'], true)) {
    require_sesskey();
    require_capability('tool/ltidiagnostics:export', $context);
    $id = \tool_ltidiagnostics\local\support::diagnostic_id($key) . '-' . gmdate('Ymd-His', $now);
    if ($action === 'trace') {
        $content = json_encode(transfer_service::export_trace($conn, $filters, $now), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        send_file($content, 'ltidiag-trace-' . $id . '.json', 0, 0, true, true, 'application/json');
    }
    $report = transfer_service::report($conn, $filters, $now);
    \tool_ltidiagnostics\local\audit::log('transfers_export', $key, 'format=' . $action);
    if ($action === 'json') {
        send_file(
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'ltidiag-transfers-' . $id . '.json',
            0,
            0,
            true,
            true,
            'application/json'
        );
    }
    send_file(
        transfer_report::to_markdown($report, $conn, $now),
        'ltidiag-transfers-' . $id . '.md',
        0,
        0,
        true,
        true,
        'text/markdown'
    );
}
$canimport = has_capability('tool/ltidiagnostics:configure', $context);
$form = new \tool_ltidiagnostics\form\partner_form($pageurl, ['key' => $key, 'kind' => 'trace']);
if ($canimport && ($data = $form->get_data())) {
    $count = transfer_service::import_trace($conn, (string) $data->document, $now);
    redirect(
        $pageurl,
        get_string('trace_imported', 'tool_ltidiagnostics', $count),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$report = transfer_service::report($conn, $filters, $now);
\tool_ltidiagnostics\local\audit::log('view_transfers', $key, 'transactions=' . count($report['transactions']));

/** @var \tool_ltidiagnostics\output\renderer $output */
$output = $PAGE->get_renderer('tool_ltidiagnostics');
$PAGE->set_title(get_string('transfers_title', 'tool_ltidiagnostics'));
echo $output->header();
echo $output->heading(get_string('transfers_title', 'tool_ltidiagnostics'));
echo html_writer::link(
    new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => 'messages']),
    get_string('backtoconnection', 'tool_ltidiagnostics'),
    ['class' => 'd-block mb-3']
);
echo $output->transfers($report);
if (has_capability('tool/ltidiagnostics:export', $context)) {
    echo $output->heading(get_string('export', 'tool_ltidiagnostics'), 3);
    foreach (['md', 'json', 'trace'] as $format) {
        echo $output->single_button(
            new moodle_url($pageurl, ['action' => $format] + array_filter($filters)),
            get_string('transfers_export_' . $format, 'tool_ltidiagnostics'),
            'post'
        );
    }
}
if ($canimport) {
    echo $output->heading(get_string('trace_import', 'tool_ltidiagnostics'), 3);
    $form->display();
}
echo $output->footer();
