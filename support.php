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
 * Support view (issues #15, #27): connections by readable name, local id and diagnostic id, their most urgent finding
 * and next safe step, and the way from a ticket to the connection, the person, the grade journal and the audit - each
 * link only for users who may open its target.
 *
 * Identities are graded by capability, not by role name: viewsupport shows connection identities (LTI configuration of
 * this site), viewidentities additionally learners' names. Pseudonyms stay for documents that leave the site.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\identity;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\navigation;
use tool_ltidiagnostics\local\profile_manager;
use tool_ltidiagnostics\local\snapshot;
use tool_ltidiagnostics\local\support;

$key = optional_param('key', '', PARAM_RAW);
$search = trim(optional_param('search', '', PARAM_TEXT));
$pagenum = max(0, optional_param('spage', 0, PARAM_INT));
$url = new moodle_url('/admin/tool/ltidiagnostics/support.php', array_filter(['key' => $key, 'search' => $search,
    'spage' => $pagenum]));
admin_externalpage_setup('tool_ltidiagnostics_support', '', null, $url);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:viewsupport', $context);
$canview = has_capability('tool/ltidiagnostics:view', $context);
$canprivacy = has_capability('tool/ltidiagnostics:viewprivacy', $context);
$cannames = identity::can_see_names();
$t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);

/**
 * Most urgent finding of a connection and its next step.
 *
 * @param connection $c Connection.
 * @return array light, title, fix, code, layer
 */
function tool_ltidiagnostics_support_case(connection $c): array {
    $result = (new health_check())->run($c, time(), ['profile' => profile_manager::get($c->key),
        'probes' => snapshot::latest_probes($c->key)]);
    $f = $result['rootcause'];
    if ($f === null) {
        foreach ($result['findings'] as $candidate) {
            if (in_array($candidate->severity, ['blocking', 'warning'], true)) {
                $f = $candidate;
                break;
            }
        }
    }
    if ($f === null) {
        return ['light' => 'green', 'title' => get_string('support_nofinding', 'tool_ltidiagnostics'), 'fix' => '',
            'code' => '', 'layer' => '', 'result' => $result];
    }
    $str = $f->strings();
    return ['light' => $f->severity === 'blocking' ? 'red' : 'amber', 'title' => $str['title'], 'fix' => $str['fix'],
        'code' => $f->code, 'layer' => $f->layer, 'result' => $result];
}

$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo navigation::back_link();
echo $output->heading(get_string('support', 'tool_ltidiagnostics'));
echo html_writer::tag('p', $t('support_desc2'));

if ($key !== '') {
    // Detail of one case.
    $c = connection::parse_key($key) !== null ? (new inventory())->get_connection($key) : null;
    if (!$c) {
        throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
    }
    \tool_ltidiagnostics\local\audit::log('view_support', $c->key);
    $case = tool_ltidiagnostics_support_case($c);
    echo html_writer::link(
        new moodle_url('/admin/tool/ltidiagnostics/support.php', array_filter(['search' => $search])),
        $t('support_backtolist'),
        ['class' => 'd-block mb-2', 'id' => 'tool_ltidiagnostics_support_back']
    );
    echo $output->heading(s(navigation::label($c)), 3, '', 'tool_ltidiagnostics_support_case');
    echo html_writer::tag('p', $t('support_caseline', (object) ['role' => $t('role_' . $c->role),
        'id' => support::diagnostic_id($c->key)]));
    // Ticket journey: connection -> course/activity -> person -> attempt -> evidence -> next step.
    $steps = [];
    $steps[] = $t('support_step_finding', (object) ['title' => $case['title'], 'fix' => $case['fix'] ?: '-']);
    if ($canview) {
        $steps[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $c->key,
            'tab' => 'health']), $t('support_go_diagnosis'));
        $steps[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $c->key,
            'tab' => 'matrix']), $t('support_go_connection'));
        if ($c->role === connection::ROLE_TOOL) {
            $steps[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $c->key,
                'tab' => 'grades'], 'tool_ltidiagnostics_journal'), $t('support_go_journal'));
        }
        $steps[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $c->key,
            'tab' => 'evidence']), $t('support_go_evidence'));
    }
    if ($canprivacy) {
        $steps[] = html_writer::link(
            new moodle_url('/admin/tool/ltidiagnostics/audit.php', ['connkey' => $c->key]),
            $t('support_go_audit')
        );
    }
    echo html_writer::tag(
        'ol',
        implode('', array_map(fn($s) => html_writer::tag('li', $s), $steps)),
        ['id' => 'tool_ltidiagnostics_support_journey']
    );
    if ($canview && $cannames && $c->role === connection::ROLE_TOOL) {
        // Person search: names are personal data, only with viewidentities; the search runs on the grade tab's filter.
        $form = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'key', 'value' => $c->key])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'grades'])
            . html_writer::label($t('support_person'), 'id_support_person', false, ['class' => 'mr-1 me-1'])
            . html_writer::empty_tag('input', ['type' => 'search', 'name' => 'fsearch', 'id' => 'id_support_person',
                'class' => 'form-control d-inline-block w-auto mr-1 me-1'])
            . html_writer::tag('button', $t('support_person_find'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
        echo html_writer::tag('form', $form, ['method' => 'get', 'action' => (new moodle_url(
            '/admin/tool/ltidiagnostics/connection.php'
        ))->out_omit_querystring(), 'class' => 'mb-3', 'role' => 'search', 'id' => 'tool_ltidiagnostics_support_person']);
    }
    $summary = support::summary($c->key, $case['result']);
    $table = new html_table();
    $table->id = 'tool_ltidiagnostics_support_layers';
    $table->attributes['class'] = 'generaltable table-sm';
    $table->caption = $t('layers');
    $table->head = [$t('layer'), $t('status')];
    foreach ($summary['layers'] as $layer => $light) {
        $h = new html_table_cell($t('layer_' . $layer));
        $h->header = true;
        $h->scope = 'row';
        $table->data[] = new html_table_row([$h, $t('light_' . $light)]);
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    $table = new html_table();
    $table->id = 'tool_ltidiagnostics_support_findings';
    $table->attributes['class'] = 'generaltable table-sm';
    $table->caption = $t('hc_findings');
    $table->head = [$t('severity'), $t('layer'), $t('diag_cause'), $t('nextstep'), $t('code')];
    foreach ($case['result']['findings'] as $f) {
        if (!in_array($f->severity, ['blocking', 'warning'], true)) {
            continue;
        }
        $str = $f->strings();
        $h = new html_table_cell($t('severity_' . $f->severity));
        $h->header = true;
        $h->scope = 'row';
        $table->data[] = new html_table_row([$h, $t('layer_' . $f->layer), s($str['title']), s($str['fix']),
            html_writer::tag('code', s($f->code))]);
    }
    echo $table->data ? html_writer::div(html_writer::table($table), 'table-responsive')
        : html_writer::tag('p', $t('support_nofinding'), ['class' => 'alert alert-success']);
    echo $output->footer();
    die;
}

// List of cases: search by name, local id or diagnostic id; ten per page (each case runs the connection diagnosis).
$form = html_writer::label($t('support_search'), 'id_support_search', false, ['class' => 'mr-1 me-1'])
    . html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'id' => 'id_support_search',
        'value' => $search, 'class' => 'form-control d-inline-block w-auto mr-1 me-1'])
    . html_writer::tag('button', $t('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::tag('form', $form, ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'mb-3',
    'role' => 'search']);
$connections = (new inventory())->get_connections();
if ($search !== '') {
    $needle = core_text::strtolower($search);
    $connections = array_values(array_filter($connections, function (connection $c) use ($needle) {
        return strpos(core_text::strtolower(navigation::label($c)), $needle) !== false
            || strpos(core_text::strtolower(support::diagnostic_id($c->key)), $needle) !== false
            || strpos(core_text::strtolower($c->issuer), $needle) !== false;
    }));
}
$total = count($connections);
$perpage = 10;
$pagenum = \tool_ltidiagnostics\output\sections::clamp_page($pagenum, $perpage, $total);
$page = array_slice($connections, $pagenum * $perpage, $perpage);
$table = new html_table();
$table->id = 'tool_ltidiagnostics_support_cases';
$table->attributes['class'] = 'generaltable table-sm';
$table->caption = $t('support_cases');
$table->head = [$t('connection'), $t('role'), $t('support_diagid'), $t('support_lastfinding'), $t('nextstep'),
    $t('actions')];
foreach ($page as $c) {
    $case = tool_ltidiagnostics_support_case($c);
    $h = new html_table_cell(s(navigation::label($c)));
    $h->header = true;
    $h->scope = 'row';
    $caseurl = new moodle_url('/admin/tool/ltidiagnostics/support.php', array_filter(['key' => $c->key, 'search' => $search]));
    $actions = [html_writer::link($caseurl, $t('support_case'), ['aria-label' => $t('support_open_case', $c->name)])];
    if ($canview) {
        $actions[] = html_writer::link(
            new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $c->key]),
            $t('open'),
            ['aria-label' => $t('open_for', $c->name)]
        );
    }
    $row = new html_table_row([$h, $t('role_' . $c->role), html_writer::tag('code', support::diagnostic_id($c->key)),
        html_writer::span($case['title'], 'badge ' . \tool_ltidiagnostics\output\renderer::badge_class($case['light'])),
        s($case['fix'] ?: '-'), implode(html_writer::empty_tag('br'), $actions)]);
    $row->attributes['data-key'] = $c->key;
    $row->attributes['data-code'] = $case['code'];
    $table->data[] = $row;
}
// The core paging bar above and below the list (issue #31).
$pager = $PAGE->get_renderer('tool_ltidiagnostics', 'grades')->pager(
    new moodle_url('/admin/tool/ltidiagnostics/support.php'),
    array_filter(['search' => $search]),
    'spage',
    $pagenum,
    $perpage,
    $total
);
echo $pager;
echo $table->data ? html_writer::div(html_writer::table($table), 'table-responsive')
    : html_writer::tag('p', $t('noconnections'), ['class' => 'alert alert-info']);
echo $pager;
echo $output->footer();
