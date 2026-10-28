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
 * Connection report: capability matrix, guided health check, timeline, evidence, history.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\diagnostic_mode;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\probe_runner;
use tool_ltidiagnostics\local\profile_manager;
use tool_ltidiagnostics\local\snapshot;
use tool_ltidiagnostics\local\timeline;

$key = required_param('key', PARAM_RAW);
$tab = optional_param('tab', 'matrix', PARAM_ALPHA);
$tabs = ['matrix', 'health', 'messages', 'timeline', 'evidence', 'history'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'matrix';
}
$filters = [
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'cmid' => optional_param('cmid', 0, PARAM_INT),
    'from' => 0,
    'to' => 0,
];
foreach (['from', 'to'] as $name) {
    $text = optional_param($name, '', PARAM_TEXT);
    $time = $text !== '' ? strtotime($text . ' UTC') : false;
    $filters[$name] = $time ? (int) $time : 0;
}

$pageurl = new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => $tab]);
admin_externalpage_setup('tool_ltidiagnostics_index', '', null, $pageurl);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:view', $context);

if (connection::parse_key($key) === null) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$conn = (new inventory())->get_connection($key);
if ($conn === null) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$now = time();
$reveal = diagnostic_mode::can_reveal();
$profile = profile_manager::get($key);

// Active checks (POST with sesskey through the form API).
$endpoints = probe_runner::probeable_endpoints($conn);
$checkform = new \tool_ltidiagnostics\form\check_form($pageurl, ['key' => $key, 'endpoints' => $endpoints, 'reveal' => $reveal]);
if ($tab === 'health' && has_capability('tool/ltidiagnostics:runchecks', $context) && ($data = $checkform->get_data())) {
    $selected = [];
    foreach (array_keys($endpoints) as $endpoint) {
        if (!empty($data->{'probe_' . $endpoint})) {
            $selected[] = $endpoint;
        }
    }
    $probes = (new probe_runner())->run(
        $conn,
        $selected,
        (string) ($data->customurl ?? ''),
        (string) ($data->expectedkid ?? ''),
        $now
    );
    snapshot::save_probes($key, $probes, $now);
    $result = (new health_check())->run($conn, $now, ['profile' => $profile, 'probes' => $probes, 'filters' => $filters]);
    snapshot::take($key, $result['matrix'], $now);
    redirect($pageurl, get_string('checksdone', 'tool_ltidiagnostics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

audit::log('view_' . $tab, $key, $reveal ? 'identifiers=revealed' : 'identifiers=masked');
if ($reveal) {
    audit::log('viewunmasked', $key, 'tab=' . $tab);
}
\tool_ltidiagnostics\event\report_viewed::create([
    'context' => $context,
    'other' => ['connection' => $key, 'tab' => $tab],
])->trigger();

/** @var \tool_ltidiagnostics\output\renderer $output */
$output = $PAGE->get_renderer('tool_ltidiagnostics');
$PAGE->set_title(get_string('connectionreport', 'tool_ltidiagnostics'));
echo $output->header();
echo $output->heading(s($reveal ? $conn->name : \tool_ltidiagnostics\local\redactor::identifier($conn->name)));
echo html_writer::tag('p', get_string('connectionsummary', 'tool_ltidiagnostics', (object) [
    'role' => get_string('role_' . $conn->role, 'tool_ltidiagnostics'),
    'lti' => s($conn->ltiversion),
    'state' => s($conn->state),
    'id' => \tool_ltidiagnostics\local\support::diagnostic_id($key),
]));

$tabobjects = [];
foreach ($tabs as $t) {
    $tabobjects[] = new tabobject($t, new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => $t] +
        array_filter($filters)), get_string('tab_' . $t, 'tool_ltidiagnostics'));
}
echo $output->tabtree($tabobjects, $tab);

// Filters (GET, read only).
echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/admin/tool/ltidiagnostics/connection.php'))
    ->out_omit_querystring(), 'class' => 'form-inline mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'key', 'value' => $key]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);
foreach (['courseid' => 'number', 'cmid' => 'number', 'from' => 'date', 'to' => 'date'] as $name => $type) {
    $value = $filters[$name] ? ($type === 'date' ? gmdate('Y-m-d', $filters[$name]) : $filters[$name]) : '';
    echo html_writer::tag('label', get_string('filter_' . $name, 'tool_ltidiagnostics'), ['for' => 'id_f_' . $name,
        'class' => 'mr-1']);
    echo html_writer::empty_tag('input', ['type' => $type, 'name' => $name, 'id' => 'id_f_' . $name, 'value' => $value,
        'class' => 'form-control mr-2']);
}
echo html_writer::tag('button', get_string('filter', 'tool_ltidiagnostics'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$storedprobes = snapshot::latest_probes($key);
$exportbase = ['key' => $key, 'sesskey' => sesskey()];

if ($tab === 'matrix') {
    $facts = (new \tool_ltidiagnostics\local\fact_collector())->collect($conn, $now, $filters);
    $matrix = (new \tool_ltidiagnostics\local\matrix_builder())->build($conn, $facts, $storedprobes);
    echo $output->matrix($matrix);
    echo html_writer::tag('p', get_string('matrix_limits', 'tool_ltidiagnostics'), ['class' => 'text-muted']);
} else if ($tab === 'health') {
    $result = (new health_check())->run($conn, $now, ['profile' => $profile, 'probes' => $storedprobes, 'filters' => $filters]);
    if (has_capability('tool/ltidiagnostics:runchecks', $context) && \tool_ltidiagnostics\local\config::get('activechecks')) {
        echo $output->heading(get_string('runchecks', 'tool_ltidiagnostics'), 3);
        echo html_writer::tag('p', get_string('runchecks_desc', 'tool_ltidiagnostics'));
        $checkform->display();
    }
    if ($storedprobes) {
        echo html_writer::tag('p', get_string('probesfrom', 'tool_ltidiagnostics', userdate(max(array_column(
            $storedprobes,
            'checkedat'
        )))), ['class' => 'text-muted']);
    }
    echo $output->healthcheck($result);
    if ($profile) {
        echo $output->heading(get_string('profile_in_use', 'tool_ltidiagnostics', s($profile->name)), 4);
    }
    echo html_writer::link(
        new moodle_url('/admin/tool/ltidiagnostics/profile.php', ['key' => $key]),
        get_string('editprofile', 'tool_ltidiagnostics'),
        ['class' => 'btn btn-secondary']
    );
} else if ($tab === 'messages') {
    $result = (new health_check())->run($conn, $now, ['profile' => $profile, 'probes' => $storedprobes, 'filters' => $filters]);
    echo $output->messages_tab($result);
} else if ($tab === 'timeline') {
    $tl = (new timeline())->build($conn, $context, $filters, $now);
    echo $output->timeline($tl);
} else if ($tab === 'evidence') {
    echo $output->evidence_tab($conn, $filters, $now);
} else if ($tab === 'history') {
    echo $output->history_tab($key);
}

// Two-site diagnosis: partner package (DIA-02/03) and completion sync transfers (DIA-05).
echo html_writer::div(
    html_writer::link(
        new moodle_url('/admin/tool/ltidiagnostics/partner.php', ['key' => $key]),
        get_string('partner_title', 'tool_ltidiagnostics'),
        ['class' => 'btn btn-secondary mr-2 me-2', 'id' => 'ltidiag-partnerlink']
    )
    . html_writer::link(
        new moodle_url('/admin/tool/ltidiagnostics/transfers.php', ['key' => $key] + array_filter($filters)),
        get_string('transfers_title', 'tool_ltidiagnostics'),
        ['class' => 'btn btn-secondary', 'id' => 'ltidiag-transferslink']
    ),
    'my-3'
);

if (has_capability('tool/ltidiagnostics:export', $context)) {
    echo $output->heading(get_string('export', 'tool_ltidiagnostics'), 3);
    foreach (\tool_ltidiagnostics\local\report_exporter::FORMATS as $format) {
        echo html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/export.php', $exportbase + ['format' => $format] +
            array_filter($filters)), get_string('export_' . $format, 'tool_ltidiagnostics'), ['class' => 'btn btn-secondary mr-2']);
    }
}
echo $output->footer();
