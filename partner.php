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
 * Partner diagnostic package of a connection: export, import, comparison and two-system live check (DIA-02, DIA-03).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\partner_service;
use tool_ltidiagnostics\local\snapshot;

$key = required_param('key', PARAM_RAW);
$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/admin/tool/ltidiagnostics/partner.php', ['key' => $key]);
admin_externalpage_setup('tool_ltidiagnostics_index', '', null, $pageurl);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:view', $context);
if (connection::parse_key($key) === null || !($conn = (new inventory())->get_connection($key))) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$now = time();

if ($action === 'export') {
    require_sesskey();
    $package = partner_service::export_package($conn, $now);
    $name = 'ltidiag-partner-' . $package['diagnosticid'] . '-' . gmdate('Ymd-His', $now) . '.json';
    send_file(json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $name, 0, 0, true, true, 'application/json');
}
if ($action === 'live') {
    require_sesskey();
    $live = partner_service::live($conn, $now);
    redirect($pageurl, get_string($live === null ? 'partner_nopackage' : 'partner_livedone', 'tool_ltidiagnostics'));
}
$canimport = has_capability('tool/ltidiagnostics:configure', $context);
$form = new \tool_ltidiagnostics\form\partner_form($pageurl, ['key' => $key, 'kind' => 'partner']);
if ($canimport && ($data = $form->get_data())) {
    partner_service::import_package($conn, (string) $data->document, $now);
    redirect($pageurl, get_string('partner_imported', 'tool_ltidiagnostics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

/** @var \tool_ltidiagnostics\output\renderer $output */
$output = $PAGE->get_renderer('tool_ltidiagnostics');
$PAGE->set_title(get_string('partner_title', 'tool_ltidiagnostics'));
echo $output->header();
echo $output->heading(get_string('partner_title', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('partner_intro', 'tool_ltidiagnostics'));
echo html_writer::link(
    new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => 'messages']),
    get_string('backtoconnection', 'tool_ltidiagnostics'),
    ['class' => 'd-block mb-3']
);

if (has_capability('tool/ltidiagnostics:export', $context)) {
    echo $output->heading(get_string('partner_export', 'tool_ltidiagnostics'), 3);
    echo html_writer::tag('p', get_string('partner_export_desc', 'tool_ltidiagnostics'));
    echo $output->single_button(
        new moodle_url($pageurl, ['action' => 'export']),
        get_string('partner_export', 'tool_ltidiagnostics'),
        'post'
    );
}

$inputs = health_check::partner_inputs($conn, $now);
echo $output->heading(get_string('partner_comparison', 'tool_ltidiagnostics'), 3);
if (!$inputs) {
    echo html_writer::tag('p', get_string('partner_nopackage', 'tool_ltidiagnostics'), ['class' => 'alert alert-info',
        'id' => 'tool_ltidiagnostics_nopartner']);
} else {
    echo html_writer::tag('p', get_string('msg_partnerinfo', 'tool_ltidiagnostics', (object) [
        'id' => s($inputs['partnerinfo']['diagnosticid']), 'generated' => s($inputs['partnerinfo']['generated'])]));
    echo $output->findings_table($inputs['partnerfindings'], 'tool_ltidiagnostics_partnerfindings');
    $stored = snapshot::latest_kind($key, \tool_ltidiagnostics\local\partner_package::KIND);
    if (!empty($stored['live'])) {
        echo html_writer::tag('p', get_string('partner_lastlive', 'tool_ltidiagnostics', (object) [
            'time' => userdate((int) $stored['live']['checkedat']), 'code' => s($stored['live']['code']),
            'result' => s(implode(', ', $stored['live']['findings']))]), ['id' => 'tool_ltidiagnostics_partnerlive',
            'data-findings' => implode(' ', $stored['live']['findings'])]);
    }
    if (has_capability('tool/ltidiagnostics:runchecks', $context) && \tool_ltidiagnostics\local\config::get('activechecks')) {
        echo $output->single_button(
            new moodle_url($pageurl, ['action' => 'live']),
            get_string('partner_live', 'tool_ltidiagnostics'),
            'post'
        );
    }
}
if ($canimport) {
    echo $output->heading(get_string('partner_import', 'tool_ltidiagnostics'), 3);
    $form->display();
}
echo $output->footer();
