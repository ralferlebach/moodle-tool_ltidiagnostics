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
 * Grade channel actions of a tool side connection: replay preview and confirmation (single, batch, retry), opt-in,
 * AGS result check, attempt detail, partner receipt request and import (issues #5, #6, #8, #9).
 *
 * Nothing on this page sends a score on a GET request. Every state changing action is a POST with sesskey and needs its
 * own capability; a replay additionally needs the site setting, the opt-in of the connection and the explicit
 * confirmation of a dry run, and is then only queued.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\grades\grade_diagnosis;
use tool_ltidiagnostics\local\grades\journal;
use tool_ltidiagnostics\local\grades\receipt;
use tool_ltidiagnostics\local\grades\repair_service;
use tool_ltidiagnostics\local\grades\results_checker;
use tool_ltidiagnostics\local\inventory;

$key = required_param('key', PARAM_RAW);
$linkid = optional_param('link', 0, PARAM_INT);
$userid = optional_param('user', 0, PARAM_INT);
$attemptid = optional_param('attempt', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$sel = optional_param('sel', '', PARAM_ALPHANUM);
$batchview = optional_param('batchview', '', PARAM_ALPHANUMEXT);

$pageurl = new moodle_url('/admin/tool/ltidiagnostics/repair.php', array_filter(['key' => $key, 'link' => $linkid,
    'user' => $userid, 'attempt' => $attemptid, 'sel' => $sel, 'batchview' => $batchview]));
admin_externalpage_setup('tool_ltidiagnostics_index', '', null, $pageurl);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:view', $context);
if (connection::parse_key($key) === null || !($conn = (new inventory())->get_connection($key))) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$gradesurl = new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => 'grades']);
$showscores = has_capability('tool/ltidiagnostics:viewgrades', $context);
$now = time();
$service = new repair_service();

// POST actions without a form.
if ($action === 'optin' || $action === 'optout') {
    require_sesskey();
    require_capability('tool/ltidiagnostics:configure', $context);
    require_capability('tool/ltidiagnostics:repairgrades', $context);
    if ($conn->role !== connection::ROLE_TOOL || ($action === 'optin' && !repair_service::site_enabled())) {
        throw new \moodle_exception('greplay_notavailable', 'tool_ltidiagnostics');
    }
    repair_service::set_opt_in($key, $action === 'optin');
    redirect($gradesurl, get_string('greplay_' . $action . '_done', 'tool_ltidiagnostics'));
}
if ($action === 'checkresult') {
    require_sesskey();
    require_capability('tool/ltidiagnostics:runchecks', $context);
    $r = (new results_checker())->check_link($conn, $linkid, $userid);
    $message = $r['errorclass'] !== '' ? get_string(
        'gcheckresult_failed',
        'tool_ltidiagnostics',
        get_string_manager()->string_exists('gerr_' . $r['errorclass'], 'tool_ltidiagnostics')
        ? get_string('gerr_' . $r['errorclass'], 'tool_ltidiagnostics') : $r['errorclass']
    )
        : get_string('gcheckresult_done', 'tool_ltidiagnostics', get_string('gbook_' . $r['verdict'], 'tool_ltidiagnostics'));
    redirect($gradesurl, $message);
}
if ($action === 'receiptrequest') {
    require_sesskey();
    require_capability('tool/ltidiagnostics:export', $context);
    $row = journal::get($attemptid);
    $req = $row ? receipt::request($conn, $row, $now) : ['ok' => false, 'reason' => 'unknown_attempt'];
    if (!$req['ok']) {
        redirect(
            $pageurl,
            get_string('greceipt_reqfail', 'tool_ltidiagnostics', $req['reason']),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    send_file(json_encode($req['document'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'ltidiag-grade-request-' .
        substr($attemptid, 0, 8) . '.json', 0, 0, true, true, 'application/json');
}

$output = $PAGE->get_renderer('tool_ltidiagnostics', 'grades');
$PAGE->set_title(get_string('greplay_title', 'tool_ltidiagnostics'));

// Attempt detail, retry, receipt.
if ($attemptid !== '') {
    $row = journal::get($attemptid);
    if (!$row || $row->connkey !== $key) {
        throw new \moodle_exception('invalidrecord', 'error');
    }
    $receiptform = null;
    if (has_capability('tool/ltidiagnostics:configure', $context)) {
        $receiptform = new \tool_ltidiagnostics\form\receipt_form($pageurl, ['key' => $key, 'kind' => 'greceipt',
            'attempt' => $attemptid]);
        if ($data = $receiptform->get_data()) {
            $r = receipt::import($conn, (string) $data->document, $now);
            redirect(
                $pageurl,
                $r['ok'] ? get_string(
                    'greceipt_imported',
                    'tool_ltidiagnostics',
                    get_string('gbook_' . $r['verdict'], 'tool_ltidiagnostics')
                )
                : get_string('greceipt_rejected', 'tool_ltidiagnostics', $r['reason']),
                null,
                $r['ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
            );
        }
    }
    $retryform = null;
    $preview = null;
    $retryable = $row->source === 'instrumented' && in_array($row->state, ['dead', 'http_rejected', 'aborted'], true)
        && $row->errorclass !== 'http_409' && repair_service::can_repair($key);
    if ($retryable) {
        $preview = $service->preview($conn, (int) $row->resourcelinkrowid, (int) $row->userid);
        if (!$preview['blockers'] && !$preview['open']) {
            $retryform = new \tool_ltidiagnostics\form\replay_form($pageurl, ['key' => $key, 'attempt' => $attemptid,
                'mode' => 'retry', 'targets' => $preview['fingerprint'],
                'scopetext' => get_string('greplay_scope_one', 'tool_ltidiagnostics', $preview['display']['person'])]);
            if ($retryform->is_cancelled()) {
                redirect($pageurl);
            }
            if ($data = $retryform->get_data()) {
                require_capability('tool/ltidiagnostics:repairgrades', $context);
                $r = $service->retry($attemptid, (string) $data->targets, (string) $data->purpose, (int) $USER->id);
                redirect($r['ok'] ? new moodle_url('/admin/tool/ltidiagnostics/repair.php', ['key' => $key,
                    'attempt' => $r['attempt']->attemptid]) : $pageurl, $r['ok'] ? get_string(
                        'greplay_queued',
                        'tool_ltidiagnostics'
                    ) : get_string('greplay_refused', 'tool_ltidiagnostics', $r['reason']));
            }
        }
    }
    echo $output->header();
    echo $output->heading(get_string('greplay_title', 'tool_ltidiagnostics'));
    echo html_writer::link($gradesurl, get_string('backtoconnection', 'tool_ltidiagnostics'), ['class' => 'd-block mb-3']);
    $chain = array_values($DB->get_records(journal::TABLE, ['correlationid' => $row->correlationid], 'id ASC', '*', 0, 50));
    $receipts = array_values($DB->get_records('tool_ltidiagnostics_grcpt', ['attemptid' => $attemptid], 'id DESC'));
    echo $output->attempt($row, $chain, $receipts, $showscores);
    if ($row->state === 'http_accepted' && $row->source === 'instrumented') {
        echo $OUTPUT->heading(get_string('greceipt_heading', 'tool_ltidiagnostics'), 4);
        echo html_writer::tag('p', get_string('greceipt_desc', 'tool_ltidiagnostics'));
        if (has_capability('tool/ltidiagnostics:export', $context)) {
            echo $OUTPUT->single_button(
                new moodle_url($pageurl, ['action' => 'receiptrequest']),
                get_string('greceipt_export', 'tool_ltidiagnostics'),
                'post'
            );
        }
        if ($receiptform) {
            $receiptform->display();
        }
    }
    if ($retryform) {
        echo $OUTPUT->heading(get_string('greplay_retry', 'tool_ltidiagnostics'), 4);
        echo $output->preview($preview, '');
        $retryform->display();
    } else if ($row->source === 'instrumented' && $row->errorclass === 'http_409') {
        echo html_writer::tag('p', get_string('greplay_409', 'tool_ltidiagnostics'), ['class' => 'alert alert-warning',
            'id' => 'tool_ltidiagnostics_409']);
    }
    echo $output->footer();
    die;
}

if ($action === 'select') {
    // Selection of targets (issues #22, #32): marked rows, all rows of the page or every match of the filters, for the
    // read-only AGS result check or for the grade replay. Only the selection is stored; nothing is read from or sent to
    // the consumer before the preview of the action was confirmed.
    require_sesskey();
    $bulkaction = optional_param('bulkaction', 'replay', PARAM_ALPHA) === 'resultcheck' ? 'resultcheck' : 'replay';
    require_capability(
        $bulkaction === 'resultcheck' ? 'tool/ltidiagnostics:runchecks' : 'tool/ltidiagnostics:repairgrades',
        $context
    );
    if ($conn->role !== connection::ROLE_TOOL) {
        throw new \moodle_exception('greplay_notavailable', 'tool_ltidiagnostics');
    }
    $scope = \tool_ltidiagnostics\local\scope::from_request();
    // Back to the same rows (filters, page size, page) when the selection cannot be made.
    $keep = array_filter([
        'fgrade' => optional_param('fgrade', '', PARAM_ALPHA),
        'ftransfer' => optional_param('ftransfer', '', PARAM_ALPHA),
        'fbooking' => optional_param('fbooking', '', PARAM_ALPHA),
        'fgradesync' => optional_param('fgradesync', '', PARAM_ALPHANUM),
        'fuser' => optional_param('fuser', 0, PARAM_INT),
        'flink' => optional_param('flink', 0, PARAM_INT),
        'fsearch' => trim(optional_param('fsearch', '', PARAM_TEXT)),
        'perpage' => optional_param('perpage', 0, PARAM_INT),
        'gpage' => optional_param('gpage', 0, PARAM_INT),
    ]);
    $back = new moodle_url($gradesurl, $scope->params() + $keep, 'tool_ltidiagnostics_rows');
    $mode = optional_param('selectmode', 'rows', PARAM_ALPHA);
    if ($mode === 'matches') {
        $filters = \tool_ltidiagnostics\local\grades\pair_query::clean([
            'grade' => optional_param('fgrade', '', PARAM_ALPHA),
            'transfer' => optional_param('ftransfer', '', PARAM_ALPHA),
            'booking' => optional_param('fbooking', '', PARAM_ALPHA),
            'gradesync' => optional_param('fgradesync', '', PARAM_RAW),
            'userid' => optional_param('fuser', 0, PARAM_INT),
            'linkid' => optional_param('flink', 0, PARAM_INT),
            // The name search is part of the population only for users who may see names (as on the page).
            'usersearch' => \tool_ltidiagnostics\local\identity::can_see_names()
                ? trim(optional_param('fsearch', '', PARAM_TEXT)) : '',
        ]);
        $max = \tool_ltidiagnostics\local\config::bounded('repairbatchmax');
        [$pairs, $total] = (new grade_diagnosis())->matching_pairs($conn, $scope, $filters, $max);
        if ($total > $max) {
            redirect(
                $back,
                get_string('gsel_toolarge', 'tool_ltidiagnostics', (object) ['count' => $total, 'max' => $max]),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
    } else {
        $filters = [];
        // Values are "linkid:userid"; selection::parse() keeps only well-formed positive integer pairs.
        $values = $mode === 'page' ? explode(',', optional_param('displayed', '', PARAM_RAW))
            : optional_param_array('pairs', [], PARAM_RAW);
        $pairs = \tool_ltidiagnostics\local\grades\selection::parse(array_map('strval', (array) $values));
    }
    $r = \tool_ltidiagnostics\local\grades\selection::create($key, $pairs, $mode, $filters, $now, $bulkaction);
    if (!$r['ok']) {
        redirect(
            $back,
            get_string('g' . $r['reason'], 'tool_ltidiagnostics', (object) ['count' => $r['count'],
            'max' => \tool_ltidiagnostics\local\config::bounded('repairbatchmax')]),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    redirect(new moodle_url('/admin/tool/ltidiagnostics/repair.php', ['key' => $key, 'sel' => $r['token']]));
}

// Preview and confirmation of a single or batch replay.
if ($conn->role !== connection::ROLE_TOOL) {
    throw new \moodle_exception('greplay_notavailable', 'tool_ltidiagnostics');
}
$canrepair = repair_service::can_repair($key);
$back = html_writer::link($gradesurl, get_string('backtoconnection', 'tool_ltidiagnostics'), ['class' => 'd-block mb-3']);

if ($batchview !== '') {
    // Status of one confirmed selection: every attempt of the batch with its own state; HTTP 2xx is not "booked".
    $rows = array_values($DB->get_records(
        journal::TABLE,
        ['connkey' => $key, 'batchid' => $batchview],
        'id ASC',
        '*',
        0,
        \tool_ltidiagnostics\local\config::bounded('repairbatchmax') * \tool_ltidiagnostics\local\config::bounded(
            'repairmaxattempts'
        )
    ));
    echo $output->header();
    echo $output->heading(get_string('gbatch_title', 'tool_ltidiagnostics'));
    echo $back;
    echo $output->batch_status($rows, ['key' => $key, 'showscores' => $showscores]);
    echo html_writer::link($pageurl, get_string('gbatch_refresh', 'tool_ltidiagnostics'), ['class' => 'btn btn-secondary mt-2',
        'id' => 'tool_ltidiagnostics_batch_refresh']);
    echo $output->footer();
    die;
}

$formhtml = '';
$previews = [];
$selection = null;
if ($sel !== '') {
    $selection = \tool_ltidiagnostics\local\grades\selection::get($sel, $key, $now);
    if (!$selection) {
        redirect($gradesurl, get_string('gsel_expired', 'tool_ltidiagnostics'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (($selection['action'] ?? 'replay') === 'resultcheck') {
        // Read-only AGS result check of a selection (issue #32): preview of the targets, then one GET per target.
        require_capability('tool/ltidiagnostics:runchecks', $context);
        $results = null;
        if (optional_param('confirmcheck', 0, PARAM_BOOL)) {
            require_sesskey();
            \tool_ltidiagnostics\local\grades\selection::drop($sel);
            // The bulk check itself is audited with its size; each verdict is audited by the checker as well.
            \tool_ltidiagnostics\local\audit::log('graderesult_bulkcheck', $key, 'targets=' . count($selection['pairs']));
            $checker = new \tool_ltidiagnostics\local\grades\results_checker();
            $results = [];
            foreach ($selection['pairs'] as $pair) {
                $results[] = $pair + $checker->check_link($conn, (int) $pair['linkid'], (int) $pair['userid']);
            }
        }
        echo $output->header();
        echo $output->heading(get_string('gsel_check_title', 'tool_ltidiagnostics'));
        echo $back;
        echo $output->bulk_resultcheck($selection, $results, ['key' => $key, 'sel' => $sel, 'pageurl' => $pageurl]);
        echo $output->footer();
        die;
    }
    foreach ($selection['pairs'] as $pair) {
        $previews[$pair['linkid'] . ':' . $pair['userid']] = $service->preview($conn, $pair['linkid'], $pair['userid']) +
            ['linkid' => $pair['linkid'], 'userid' => $pair['userid']];
    }
    $targets = [];
    foreach ($previews as $id => $p) {
        if (!$p['blockers'] && !$p['open']) {
            $targets[$id] = $p['fingerprint'];
        }
    }
    if ($canrepair && $targets) {
        $form = new \tool_ltidiagnostics\form\replay_form($pageurl, ['key' => $key, 'sel' => $sel, 'mode' => 'bulk',
            'targets' => json_encode($targets), 'scopetext' => get_string(
                'greplay_scope_batch',
                'tool_ltidiagnostics',
                count($targets)
            )]);
    }
} else {
    $p = $service->preview($conn, $linkid, $userid);
    $previews[$linkid . ':' . $userid] = $p + ['linkid' => $linkid, 'userid' => $userid];
    if ($canrepair && !$p['blockers'] && !$p['open']) {
        $form = new \tool_ltidiagnostics\form\replay_form($pageurl, ['key' => $key, 'link' => $linkid, 'user' => $userid,
            'mode' => 'single', 'targets' => $p['fingerprint'], 'scopetext' => get_string(
                'greplay_scope_one',
                'tool_ltidiagnostics',
                $p['display']['person']
            )]);
    }
}
if (!empty($form)) {
    if ($form->is_cancelled()) {
        if ($sel !== '') {
            \tool_ltidiagnostics\local\grades\selection::drop($sel);
        }
        redirect($gradesurl);
    }
    if ($data = $form->get_data()) {
        require_capability('tool/ltidiagnostics:repairgrades', $context);
        if ($data->mode === 'bulk') {
            // The selection is consumed: a second submission of the same form finds nothing to send.
            \tool_ltidiagnostics\local\grades\selection::drop($sel);
            $batchid = \tool_ltidiagnostics\local\grades\selection::batch_id();
            $targets = (array) json_decode((string) $data->targets, true);
            $ok = 0;
            $refused = 0;
            foreach ($targets as $id => $fp) {
                if (!isset($previews[$id])) {
                    continue;
                }
                $r = $service->request(
                    $conn,
                    (int) $previews[$id]['linkid'],
                    (int) $previews[$id]['userid'],
                    (string) $fp,
                    (string) $data->purpose,
                    (int) $USER->id,
                    ['origin' => journal::ORIGIN_BATCH, 'batchid' => $batchid]
                );
                if ($r['ok']) {
                    $ok++;
                } else {
                    $refused++;
                }
            }
            \tool_ltidiagnostics\local\audit::log('graderepair_batch', $key, 'batch=' . $batchid . ' queued=' . $ok .
                ' refused=' . $refused);
            redirect(
                new moodle_url('/admin/tool/ltidiagnostics/repair.php', ['key' => $key, 'batchview' => $batchid]),
                get_string('greplay_batch_queued', 'tool_ltidiagnostics', (object) ['ok' => $ok, 'refused' => $refused])
            );
        }
        $r = $service->request($conn, $linkid, $userid, (string) $data->targets, (string) $data->purpose, (int) $USER->id);
        redirect($r['ok'] ? new moodle_url('/admin/tool/ltidiagnostics/repair.php', ['key' => $key,
            'attempt' => $r['attempt']->attemptid]) : $pageurl, $r['ok'] ? get_string('greplay_queued', 'tool_ltidiagnostics')
            : get_string('greplay_refused', 'tool_ltidiagnostics', $r['reason']), null, $r['ok']
            ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
    }
    $formhtml = $form->render();
}

echo $output->header();
echo $output->heading(get_string('greplay_title', 'tool_ltidiagnostics'));
echo $back;
if (!$canrepair) {
    echo html_writer::tag('p', get_string('greplay_dryrun_only', 'tool_ltidiagnostics'), ['class' => 'alert alert-info',
        'id' => 'tool_ltidiagnostics_dryrun_only']);
}
if ($selection) {
    echo $output->bulk_preview($previews, $selection, ['key' => $key, 'showscores' => $showscores]);
    echo $formhtml;
} else {
    foreach ($previews as $p) {
        echo $output->preview($p, $formhtml);
    }
}
echo $output->footer();
