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
 * Connection report: function check, messages and fields, connection diagnosis, grades, completion sync, timeline,
 * evidence sources and history, with one scope (course, activity, period) above the tabs.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\completion\completion_view;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\diagnostic_mode;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\probe_runner;
use tool_ltidiagnostics\local\profile_manager;
use tool_ltidiagnostics\local\scope;
use tool_ltidiagnostics\local\snapshot;
use tool_ltidiagnostics\output\sections;

$key = required_param('key', PARAM_RAW);
$tab = optional_param('tab', 'matrix', PARAM_ALPHA);
// Tab order (issue #14): function check, messages and fields, connection diagnosis, grades, completion sync, timeline,
// evidence sources, history.
$tabs = ['matrix', 'messages', 'health', 'grades', 'completion', 'timeline', 'evidence', 'history'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'matrix';
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
$scope = scope::from_request();
$filters = $scope->filters();
$pageurl->params($scope->params());
$PAGE->set_url($pageurl);
$now = time();
$reveal = diagnostic_mode::can_reveal();
$profile = profile_manager::get($key);
$completionstate = completion_view::state();

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
$PAGE->set_title(\tool_ltidiagnostics\local\navigation::label($conn));
$PAGE->requires->js_call_amd('tool_ltidiagnostics/sections', 'init', []);
echo $output->header();
echo $output->connection_header($conn);
echo $output->scope_bar($conn, $scope, $tab);
$scoped = ($scope->courseid || $scope->cmid) && !$scope->invalid;
if ($scoped && !inventory::in_scope($conn, (int) $scope->courseid, (int) $scope->cmid)) {
    // Fail closed (issue #29): the scoped views of this connection are empty, and the page says why.
    echo $output->notification(get_string('scope_conn_notused', 'tool_ltidiagnostics'), \core\output\notification::NOTIFY_INFO);
}

$tabobjects = [];
foreach ($tabs as $t) {
    // The completion tab exists only with a working local_lticompletionsync (issues #17, #24); a direct link still
    // explains why there is nothing to check.
    if ($t === 'completion' && $completionstate !== 'present' && $tab !== 'completion') {
        continue;
    }
    $tabobjects[] = new tabobject($t, new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => $t] +
        $scope->params()), get_string('tab_' . $t, 'tool_ltidiagnostics'));
}
echo html_writer::div($output->tabtree($tabobjects, $tab), '', ['id' => 'tool_ltidiagnostics_tabs']);
echo html_writer::div('', 'sr-only visually-hidden', ['id' => 'tool_ltidiagnostics_live', 'aria-live' => 'polite',
    'role' => 'status']);

$storedprobes = snapshot::latest_probes($key);
$exportbase = ['key' => $key, 'sesskey' => sesskey()];
$q = sections::request_params();

if ($tab === 'matrix') {
    echo $output->scope_note('partial', $scope);
    $facts = (new \tool_ltidiagnostics\local\fact_collector())->collect($conn, $now, $filters);
    $matrix = (new \tool_ltidiagnostics\local\matrix_builder())->build($conn, $facts, $storedprobes);
    echo $output->matrix($matrix, $conn, $scope);
} else if ($tab === 'messages') {
    echo $output->scope_note('partial', $scope);
    $result = (new health_check())->run($conn, $now, ['profile' => $profile, 'probes' => $storedprobes, 'filters' => $filters]);
    echo $output->messages_tab($result, [
        'message' => optional_param('message', '', PARAM_ALPHANUMEXT),
        'state' => optional_param('mstate', '', PARAM_ALPHAEXT),
        'baseurl' => new moodle_url($pageurl),
    ]);
} else if ($tab === 'health') {
    echo $output->scope_note('partial', $scope);
    $result = (new health_check())->run($conn, $now, ['profile' => $profile, 'probes' => $storedprobes, 'filters' => $filters]);
    echo $output->services_table($result['services']);
    echo $output->healthcheck($result);
    echo $output->probes_table($storedprobes);
    if (has_capability('tool/ltidiagnostics:runchecks', $context) && \tool_ltidiagnostics\local\config::get('activechecks')) {
        echo $output->heading(get_string('runchecks', 'tool_ltidiagnostics'), 3);
        echo html_writer::tag('p', get_string('runchecks_desc', 'tool_ltidiagnostics'));
        $checkform->display();
    }
    if ($profile) {
        echo $output->heading(get_string('profile_in_use', 'tool_ltidiagnostics', s($profile->name)), 3);
    }
    $actions = [html_writer::link(
        new moodle_url('/admin/tool/ltidiagnostics/profile.php', ['key' => $key]),
        get_string('editprofile', 'tool_ltidiagnostics'),
        ['class' => 'btn btn-secondary mr-2 me-2 mb-1']
    )];
    // Partner evidence belongs to the connection diagnosis, not to every tab (issue #14).
    $actions[] = html_writer::link(
        new moodle_url('/admin/tool/ltidiagnostics/partner.php', ['key' => $key]),
        get_string('partner_contextual', 'tool_ltidiagnostics'),
        ['class' => 'btn btn-secondary mb-1', 'id' => 'ltidiag-partnerlink']
    );
    echo html_writer::div(implode('', $actions), 'my-3');
} else if ($tab === 'grades') {
    // Grade channel (issues #3 to #6, #8, #21 to #23).
    $gout = $PAGE->get_renderer('tool_ltidiagnostics', 'grades');
    if ($conn->role === connection::ROLE_TOOL) {
        echo $output->scope_note('applies', $scope);
        $ctx = sections::grade_ctx($conn);
        // Findings are aggregated over the scoped population, at most one maximum page (the text says when it is cut).
        $diag = (new \tool_ltidiagnostics\local\grades\grade_diagnosis())->run($conn, [
            'limit' => \tool_ltidiagnostics\local\grades\grade_diagnosis::MAXPAGE, 'reveal' => false,
            'showscores' => $ctx['showscores'], 'now' => $now,
            'courseid' => $scope->courseid, 'cmid' => $scope->cmid, 'invalidscope' => $scope->invalid,
        ]);
        $rows = sections::render($conn, 'grades_rows', $q, $scope);
        $journal = sections::render($conn, 'grades_journal', $q, $scope);
        $repairstate = [
            'site' => \tool_ltidiagnostics\local\grades\repair_service::site_enabled(),
            'optin' => in_array($key, \tool_ltidiagnostics\local\grades\repair_service::opted_in(), true),
            'capability' => has_capability('tool/ltidiagnostics:repairgrades', $context),
        ];
        echo $gout->grades_tab(
            $diag,
            sections::wrap('grades_rows', $key, $rows['html']),
            sections::wrap('grades_journal', $key, $journal['html']),
            $ctx + [
                'canoptin' => $repairstate['site'] && $repairstate['capability']
                    && has_capability('tool/ltidiagnostics:configure', $context),
                'repairstate' => $repairstate,
                'scope' => $scope,
            ]
        );
    } else {
        echo html_writer::tag('p', get_string('grades_consumer', 'tool_ltidiagnostics'), ['class' => 'lead']);
        if (has_capability('tool/ltidiagnostics:export', $context)) {
            echo html_writer::link(
                new moodle_url('/admin/tool/ltidiagnostics/receipt.php', ['key' => $key]),
                get_string('greceipt_answer_title', 'tool_ltidiagnostics'),
                ['class' => 'btn btn-secondary', 'id' => 'tool_ltidiagnostics_receiptlink']
            );
        }
    }
} else if ($tab === 'completion') {
    // Completion channel (issues #7, #17, #24): only with a working local_lticompletionsync; actions stay in that plugin.
    $gout = $PAGE->get_renderer('tool_ltidiagnostics', 'grades');
    if ($completionstate !== 'present') {
        echo $gout->completion_unavailable($completionstate);
    } else {
        echo $output->scope_note('applies', $scope);
        $coptions = ['courseid' => $scope->courseid, 'cmid' => $scope->cmid, 'invalidscope' => $scope->invalid];
        $cpage = max(0, optional_param('cpage', 0, PARAM_INT));
        $view = (new completion_view())->build($conn, ['offset' => $cpage * completion_view::PAGESIZE] + $coptions);
        // A page number behind the last page shows the last page (issue #31).
        $clamped = sections::clamp_page($cpage, completion_view::PAGESIZE, (int) $view['total']);
        if ($clamped !== $cpage) {
            $view = (new completion_view())->build($conn, ['offset' => $clamped * completion_view::PAGESIZE] + $coptions);
        }
        echo $gout->completion_tab($view, new moodle_url($pageurl));
    }
} else if ($tab === 'timeline') {
    echo $output->scope_note('applies', $scope);
    $section = sections::render($conn, 'timeline', $q, $scope);
    echo sections::wrap('timeline', $key, $section['html']);
} else if ($tab === 'evidence') {
    echo $output->scope_note('applies', $scope);
    $facts = (new \tool_ltidiagnostics\local\fact_collector())->collect($conn, $now, $filters);
    global $DB;
    $imports = (int) $DB->count_records('tool_ltidiagnostics_imp', ['status' => 'done']);
    echo $output->heading(get_string('obs_heading', 'tool_ltidiagnostics'), 3);
    echo $output->observability_table(\tool_ltidiagnostics\local\observability::build(
        $conn,
        $facts,
        $storedprobes,
        \tool_ltidiagnostics\local\observability::enabled_sources($imports)
    ));
    echo $output->heading(get_string('ev_sources', 'tool_ltidiagnostics'), 3);
    $section = sections::render($conn, 'evidence', $q, $scope);
    echo sections::wrap('evidence', $key, $section['html']);
} else if ($tab === 'history') {
    echo $output->scope_note('systemwide', $scope);
    echo $output->history_tab($key, optional_param('hpage', 0, PARAM_INT), $scope->params());
}

if (has_capability('tool/ltidiagnostics:export', $context)) {
    echo $output->heading(get_string('export', 'tool_ltidiagnostics'), 3);
    echo html_writer::tag('p', get_string('export_scope', 'tool_ltidiagnostics'), ['class' => 'small text-muted']);
    foreach (\tool_ltidiagnostics\local\report_exporter::FORMATS as $format) {
        echo html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/export.php', $exportbase + ['format' => $format] +
            $scope->params()), get_string('export_' . $format, 'tool_ltidiagnostics'), ['class' => 'btn btn-secondary mr-2 me-2']);
    }
    if ($tab === 'grades' || ($tab === 'completion' && $completionstate === 'present')) {
        echo $output->heading(get_string('gpkg_export', 'tool_ltidiagnostics'), 4);
        echo html_writer::tag('p', get_string('gpkg_export_desc', 'tool_ltidiagnostics'), ['class' => 'small text-muted']);
        foreach (\tool_ltidiagnostics\local\grades\support_package::FORMATS as $format) {
            echo html_writer::link(
                new moodle_url('/admin/tool/ltidiagnostics/package.php', $exportbase + ['format' => $format] +
                    $scope->params()),
                get_string('gpkg_export_' . $format, 'tool_ltidiagnostics'),
                ['class' => 'btn btn-secondary mr-2 me-2',
                'id' => 'tool_ltidiagnostics_pkg_' . $format]
            );
        }
    }
}
echo $output->footer();
