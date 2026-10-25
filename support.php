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
 * Support view: pseudonymised diagnostic ids and an error summary without identifiers.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\profile_manager;
use tool_ltidiagnostics\local\snapshot;
use tool_ltidiagnostics\local\support;

$id = optional_param('id', '', PARAM_ALPHANUMEXT);
$url = new moodle_url('/admin/tool/ltidiagnostics/support.php', ['id' => $id]);
admin_externalpage_setup('tool_ltidiagnostics_support', '', null, $url);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:viewsupport', $context);

$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo $output->heading(get_string('support', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('support_desc', 'tool_ltidiagnostics'));
$connections = (new inventory())->get_connections();
$items = '';
foreach ($connections as $c) {
    $diag = support::diagnostic_id($c->key);
    $items .= html_writer::tag('li', html_writer::link(new moodle_url($url, ['id' => $diag]), $diag) . ' (' .
        get_string('role_' . $c->role, 'tool_ltidiagnostics') . ')');
}
echo html_writer::tag('ul', $items);
foreach ($connections as $c) {
    if ($id === '' || support::diagnostic_id($c->key) !== $id) {
        continue;
    }
    \tool_ltidiagnostics\local\audit::log('view_support', $c->key);
    $result = (new health_check())->run($c, time(), ['profile' => profile_manager::get($c->key),
        'probes' => snapshot::latest_probes($c->key)]);
    $summary = support::summary($c->key, $result);
    echo $output->heading($summary['diagnosticid'], 3);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->caption = get_string('layers', 'tool_ltidiagnostics');
    $table->head = [get_string('layer', 'tool_ltidiagnostics'), get_string('status', 'tool_ltidiagnostics')];
    foreach ($summary['layers'] as $layer => $light) {
        $table->data[] = [get_string('layer_' . $layer, 'tool_ltidiagnostics'), get_string(
            'light_' . $light,
            'tool_ltidiagnostics'
        )];
    }
    echo html_writer::table($table);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->caption = get_string('hc_findings', 'tool_ltidiagnostics');
    $table->head = [get_string('severity', 'tool_ltidiagnostics'), get_string('layer', 'tool_ltidiagnostics'),
        get_string('code', 'tool_ltidiagnostics')];
    foreach ($summary['findings'] as $f) {
        $table->data[] = [get_string('severity_' . $f['severity'], 'tool_ltidiagnostics'), get_string(
            'layer_' . $f['layer'],
            'tool_ltidiagnostics'
        ), s($f['code'])];
    }
    echo html_writer::table($table);
}
echo $output->footer();
