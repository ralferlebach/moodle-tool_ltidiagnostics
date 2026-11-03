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
 * Audit log of views, exports and actions on diagnostic data (privacy officers, issues #15, #27).
 *
 * Shows who did what, by real name with a profile link, with localised action names, the connection by its readable
 * name, and filters for person, kind of action, connection and period. Totals and rows share one server-side query.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\navigation;
use tool_ltidiagnostics\local\scope;
use tool_ltidiagnostics\output\sections;

$filters = [
    'auser' => trim(optional_param('auser', '', PARAM_TEXT)),
    'agroup' => optional_param('agroup', '', PARAM_ALPHA),
    'connkey' => optional_param('connkey', '', PARAM_RAW),
    'afrom' => optional_param('afrom', '', PARAM_RAW),
    'ato' => optional_param('ato', '', PARAM_RAW),
];
if (connection::parse_key($filters['connkey']) === null) {
    $filters['connkey'] = '';
}
if (!isset(audit::GROUPS[$filters['agroup']])) {
    $filters['agroup'] = '';
}
$perpage = sections::size(optional_param('aperpage', 0, PARAM_INT));
$offset = max(0, optional_param('aoffset', 0, PARAM_INT));
$base = array_filter($filters + ['aperpage' => $perpage === 50 ? 0 : $perpage]);
$url = new moodle_url('/admin/tool/ltidiagnostics/audit.php', $base + array_filter(['aoffset' => $offset]));
admin_externalpage_setup('tool_ltidiagnostics_audit', '', null, $url);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:viewprivacy', $context);
$canview = has_capability('tool/ltidiagnostics:view', $context);
$t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);

// Period in the viewer's time zone, validated exactly like the scope bar.
$period = scope::make(0, 0, trim($filters['afrom']), trim($filters['ato']));
$errors = array_map(fn($e) => $t($e), $period->errors);
$from = $period->from;
$to = $period->to;
$page = audit::page(['usersearch' => $filters['auser'], 'group' => $filters['agroup'], 'connkey' => $filters['connkey'],
    'from' => $from, 'to' => $to], $offset, $perpage);

$connections = [];
foreach ((new inventory())->get_connections() as $c) {
    $connections[$c->key] = $c;
}

$output = $PAGE->get_renderer('tool_ltidiagnostics');
$grades = $PAGE->get_renderer('tool_ltidiagnostics', 'grades');
echo $output->header();
echo navigation::back_link();
echo $output->heading(get_string('auditlog', 'tool_ltidiagnostics'));
echo html_writer::tag('p', $t('auditlog_desc', (int) \tool_ltidiagnostics\local\config::get('retentiondays')));
foreach ($errors as $error) {
    echo html_writer::tag('p', s($error), ['class' => 'alert alert-warning']);
}

// Filters (GET, work without JavaScript).
$groups = ['' => $t('audit_group_all')];
foreach (array_keys(audit::GROUPS) as $g) {
    $groups[$g] = $t('audit_group_' . $g);
}
$conns = ['' => $t('audit_conn_all')];
foreach ($connections as $k => $c) {
    // Option labels are not escaped by html_writer::select(); connection names come from the partner (dynamic registration).
    $conns[$k] = s(navigation::label($c));
}
$sizes = [];
foreach (sections::SIZES as $size) {
    $sizes[$size] = $size;
}
$field = function (string $label, string $id, string $control): string {
    return html_writer::div(
        html_writer::label($label, $id, false, ['class' => 'mr-1 me-1']) . $control,
        'd-inline-block mr-3 me-3 mb-2'
    );
};
$form = $field($t('audit_user'), 'id_auser', html_writer::empty_tag('input', ['type' => 'search', 'name' => 'auser',
        'id' => 'id_auser', 'value' => $filters['auser'], 'class' => 'form-control d-inline-block w-auto']))
    . $field($t('audit_group'), 'id_agroup', html_writer::select(
        $groups,
        'agroup',
        $filters['agroup'],
        false,
        ['id' => 'id_agroup', 'class' => 'custom-select form-select d-inline-block w-auto']
    ))
    . $field($t('connection'), 'id_connkey', html_writer::select(
        $conns,
        'connkey',
        $filters['connkey'],
        false,
        ['id' => 'id_connkey', 'class' => 'custom-select form-select d-inline-block w-auto']
    ))
    . $field($t('scope_from'), 'id_afrom', html_writer::empty_tag('input', ['type' => 'date', 'name' => 'afrom',
        'id' => 'id_afrom', 'value' => $filters['afrom'], 'class' => 'form-control d-inline-block w-auto']))
    . $field($t('scope_to'), 'id_ato', html_writer::empty_tag('input', ['type' => 'date', 'name' => 'ato',
        'id' => 'id_ato', 'value' => $filters['ato'], 'class' => 'form-control d-inline-block w-auto']))
    . $field($t('perpage'), 'id_aperpage', html_writer::select(
        $sizes,
        'aperpage',
        $perpage,
        false,
        ['id' => 'id_aperpage', 'class' => 'custom-select form-select d-inline-block w-auto']
    ))
    . html_writer::tag('button', $t('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary mr-2 me-2 mb-2'])
    . html_writer::link(
        new moodle_url('/admin/tool/ltidiagnostics/audit.php'),
        $t('filter_reset'),
        ['class' => 'btn btn-link mb-2']
    );
echo html_writer::tag('form', $form, ['method' => 'get', 'action' => $url->out_omit_querystring(), 'role' => 'search',
    'aria-label' => $t('filter_label_audit'), 'id' => 'tool_ltidiagnostics_audit_filter', 'class' => 'mb-2']);
echo html_writer::tag(
    'p',
    $t('audit_count', (object) ['shown' => count($page['rows']), 'total' => $page['total']]),
    ['id' => 'tool_ltidiagnostics_audit_count']
);

$table = new html_table();
$table->id = 'tool_ltidiagnostics_audit';
$table->attributes['class'] = 'generaltable table-sm';
$table->caption = get_string('auditlog', 'tool_ltidiagnostics');
$table->head = [get_string('time'), get_string('user'), $t('action'), $t('connection'), $t('detail')];
foreach ($page['rows'] as $e) {
    $when = new html_table_cell(userdate($e->timecreated, get_string('strftimedatetimeshort', 'langconfig')));
    $when->header = true;
    $when->scope = 'row';
    if (!empty($e->firstname) || !empty($e->lastname)) {
        $who = html_writer::link(new moodle_url('/user/profile.php', ['id' => $e->userid]), s(fullname($e)));
    } else {
        $who = $t('id_userunknown_id', (int) $e->userid);
    }
    $action = s(audit::action_label($e->action)) . ' ' . html_writer::tag('code', s($e->action), ['class' => 'small text-muted']);
    if ($e->connkey === '') {
        $where = $t('audit_noconn');
    } else if (isset($connections[$e->connkey])) {
        $label = s(navigation::label($connections[$e->connkey]));
        $where = $canview ? html_writer::link(new moodle_url(
            '/admin/tool/ltidiagnostics/connection.php',
            ['key' => $e->connkey]
        ), $label) : $label;
    } else {
        $where = $t('audit_conngone', s($e->connkey));
    }
    $table->data[] = new html_table_row([$when, $who, $action, $where, s($e->detail)]);
}
echo $table->data ? html_writer::div(html_writer::table($table), 'table-responsive')
    : html_writer::tag('p', $t('norows'), ['class' => 'alert alert-info']);
echo $grades->offset_pager($url, $base, 'aoffset', $offset, $perpage, $page['total']);
echo $output->footer();
