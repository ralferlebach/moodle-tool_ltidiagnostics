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

namespace tool_ltidiagnostics\output;

use html_table;
use html_table_cell;
use html_table_row;
use html_writer;
use moodle_url;

/**
 * Output of the two channels: grades (standard AGS) and completion (local_lticompletionsync).
 *
 * Accessibility as in the main renderer: every table has a caption, row headers carry scope, badges carry text, every
 * action button has a visible label that names its target.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades_renderer extends \plugin_renderer_base {
    /**
     * String of this plugin.
     *
     * @param string $id Id.
     * @param mixed $a Argument.
     * @return string
     */
    private static function t(string $id, $a = null): string {
        return get_string($id, 'tool_ltidiagnostics', $a);
    }

    /**
     * String of a family when it exists, else the raw code (escaped).
     *
     * @param string $prefix Prefix.
     * @param string $code Code.
     * @return string
     */
    private static function label(string $prefix, string $code): string {
        if ($code === '') {
            return '-';
        }
        $sm = get_string_manager();
        return $sm->string_exists($prefix . $code, 'tool_ltidiagnostics') ? self::t($prefix . $code) : s($code);
    }

    /**
     * Badge with text.
     *
     * @param string $text Text.
     * @param string $light green, amber, red, grey.
     * @return string
     */
    private static function badge(string $text, string $light): string {
        return html_writer::span($text, 'badge ' . renderer::badge_class($light));
    }

    /**
     * Light of an evidence level.
     *
     * @param string $level Level.
     * @return string
     */
    public static function evidence_light(string $level): string {
        $map = ['remote_booked' => 'green', 'results_match' => 'green', 'http_accepted_unconfirmed' => 'amber',
            'remote_unobservable' => 'amber', 'http_attempt' => 'amber', 'error' => 'red', 'remote_mismatch' => 'red',
            'skipped' => 'grey', 'not_attempted' => 'grey'];
        return $map[$level] ?? 'grey';
    }

    /**
     * Light of a prediction.
     *
     * @param string $prediction Prediction.
     * @return string
     */
    public static function prediction_light(string $prediction): string {
        if ($prediction === 'would_send') {
            return 'green';
        }
        if (in_array($prediction, ['no_grade', 'gated', 'lastgrade_unchanged'], true)) {
            return 'amber';
        }
        return 'red';
    }

    /**
     * Grade channel tab of a tool side connection.
     *
     * @param array $d Result of grade_diagnosis::run().
     * @param array $journal Result of journal::page().
     * @param array $ctx key, canrepair (bool), repairstate (array site, optin, capability), canresult (bool),
     *     canoptin (bool), pageurl (moodle_url), showscores (bool).
     * @return string
     */
    public function grades_tab(array $d, array $journal, array $ctx): string {
        $o = html_writer::tag('p', self::t('grades_intro'), ['class' => 'lead']);
        if (!$d['applicable']) {
            return $o . html_writer::tag('p', self::t('grades_notapplicable'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_grades_na']);
        }
        $o .= html_writer::tag('p', self::t('grades_ladder'), ['class' => 'small text-muted']);
        $o .= $this->prerequisites($d['prerequisites'], $d['resources']);
        $o .= $this->output->heading(self::t('grades_findings'), 3);
        $o .= $this->findings($d['findings']);
        $o .= $this->output->heading(self::t('grades_rows'), 3);
        $o .= html_writer::tag('p', self::t('grades_rows_desc', (object) ['total' => $d['total'],
            'from' => $d['total'] ? $d['offset'] + 1 : 0, 'to' => $d['offset'] + count($d['rows'])]));
        $o .= $this->rows($d, $ctx);
        $o .= $this->pager($ctx['pageurl'], $d['offset'], $d['limit'], $d['total']);
        $o .= $this->output->heading(self::t('grades_journal'), 3);
        $o .= html_writer::tag('p', self::t('grades_journal_desc'), ['class' => 'small text-muted']);
        $o .= $this->journal($journal['rows'], $ctx);
        if ($journal['next']) {
            $o .= html_writer::link(
                new moodle_url($ctx['pageurl'], ['before' => $journal['next']]),
                self::t('grades_older'),
                ['class' => 'btn btn-secondary btn-sm']
            );
        }
        $o .= $this->repair_panel($ctx);
        return $o;
    }

    /**
     * Prerequisites of core's grade sync.
     *
     * @param array $p Prerequisites.
     * @param array $resources Resources.
     * @return string
     */
    public function prerequisites(array $p, array $resources): string {
        $yesno = fn(bool $b) => self::badge($b ? self::t('yes') : self::t('no'), $b ? 'green' : 'red');
        $rows = [];
        $add = function (string $label, string $value) use (&$rows) {
            $h = new html_table_cell(s($label));
            $h->header = true;
            $h->scope = 'row';
            $rows[] = new html_table_row([$h, $value]);
        };
        $add(self::t('gpre_authlti'), $yesno((bool) $p['authlti']));
        $add(self::t('gpre_enrollti'), $yesno((bool) $p['enrollti']));
        if ($p['scheduled'] === null) {
            $add(self::t('gpre_scheduled'), self::badge(self::t('gpre_missing'), 'red'));
        } else {
            $s = $p['scheduled'];
            $add(self::t('gpre_scheduled'), ($s['disabled'] ? self::badge(self::t('gpre_disabled'), 'red') . ' ' : '') .
                self::t('gpre_runs', (object) [
                    'last' => $s['lastrun'] ? userdate($s['lastrun']) : self::t('never'),
                    'next' => $s['nextrun'] ? userdate($s['nextrun']) : '-',
                ]) . ($s['faildelay'] ? ' ' . self::badge(self::t('gpre_failing'), 'amber') : ''));
        }
        foreach ($resources as $rid => $r) {
            $queue = $p['adhoc'][$rid] ?? ['queued' => 0, 'failed' => 0];
            $last = $p['lastruns'][$rid] ?? null;
            $flags = [
                $r['enabled'] ? self::t('gpre_published') : self::t('gpre_unpublished'),
                $r['gradesync'] ? self::t('gpre_gradesync_on') : self::t('gpre_gradesync_off'),
                $r['gradesynccompletion'] ? self::t('gpre_gating_on') : self::t('gpre_gating_off'),
                self::t('gpre_kind_' . ($r['kind'] ?: 'unknown')),
            ];
            $add(self::t('gpre_resource', $rid), s(implode(' · ', $flags)) . html_writer::empty_tag('br') .
                self::t('gpre_queue', (object) ['queued' => $queue['queued'], 'failed' => $queue['failed'],
                    'last' => $last ? userdate($last['time']) . ($last['failed'] ? ' (' . self::t('gpre_runfailed') . ')' : '')
                        : self::t('gpre_nolog')]));
        }
        $add(self::t('gpre_tasklog'), $p['tasklogmode'] ? self::t('yes') : self::badge(self::t('no'), 'amber'));
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_grades_prereq';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('grades_prereq');
        $table->head = [self::t('gpre_item'), self::t('gpre_value')];
        $table->data = $rows;
        return $this->output->heading(self::t('grades_prereq'), 3) . html_writer::div(
            html_writer::table($table),
            'table-responsive'
        ) . html_writer::tag(
            'p',
            self::t('gpre_observed', userdate($p['observedat'])),
            ['class' => 'small text-muted']
        );
    }

    /**
     * Aggregated findings: cause, count, responsible system, certainty, limit, next step.
     *
     * @param array[] $findings Findings.
     * @return string
     */
    public function findings(array $findings): string {
        if (!$findings) {
            return html_writer::tag('p', self::t('grades_nofindings'), ['class' => 'alert alert-success',
                'id' => 'tool_ltidiagnostics_grades_nofindings']);
        }
        $rows = [];
        $light = ['blocking' => 'red', 'warning' => 'amber', 'info' => 'grey'];
        foreach ($findings as $f) {
            $h = new html_table_cell(self::badge(self::t('severity_' . $f['severity']), $light[$f['severity']] ?? 'grey') .
                ' ' . s(self::t('gf_' . $f['code'])));
            $h->header = true;
            $h->scope = 'row';
            $row = new html_table_row([$h, (string) $f['count'], self::t('gresp_' . $f['responsible']),
                self::label('gsrc_', $f['source']), self::t('certainty_' . $f['certainty']) . ' - ' .
                self::t('g' . $f['limit']), self::t('gf_' . $f['code'] . '_next')]);
            $row->attributes['data-code'] = $f['code'];
            $rows[] = $row;
        }
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_grades_findings';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('grades_findings');
        $table->captionhide = true;
        $table->head = [self::t('diag_cause'), self::t('gcount'), self::t('gresp'), self::t('gsource'),
            self::t('uncertainlabel'), self::t('gnext')];
        $table->data = $rows;
        return html_writer::div(html_writer::table($table), 'table-responsive');
    }

    /**
     * Rows per resource link and learner.
     *
     * @param array $d Diagnosis.
     * @param array $ctx Context.
     * @return string
     */
    public function rows(array $d, array $ctx): string {
        $rows = [];
        foreach ($d['rows'] as $r) {
            $link = $d['links'][$r['linkid']];
            $h = new html_table_cell(s($r['person']));
            $h->header = true;
            $h->scope = 'row';
            $src = $r['source'];
            $sourcetext = $ctx['showscores'] && $src['grade'] !== null
                ? s(format_float((float) $src['grade'], 2) . ' / ' . format_float((float) $src['max'], 2))
                : self::t('gsource_' . $src['state']);
            if ($src['dategraded']) {
                $sourcetext .= html_writer::empty_tag('br') . html_writer::span(userdate(
                    $src['dategraded'],
                    get_string('strftimedatetimeshort')
                ), 'small text-muted');
            }
            foreach (['locked', 'overridden', 'hidden'] as $flag) {
                if (!empty($src[$flag])) {
                    $sourcetext .= ' ' . self::badge(self::t('gflag_' . $flag), 'grey');
                }
            }
            $ev = $r['evidence'];
            $evtext = self::badge(self::t('gev_' . $ev['level']), self::evidence_light($ev['level']));
            if ($ev['time']) {
                $evtext .= html_writer::empty_tag('br') . html_writer::span(
                    self::label('gsrc_', $ev['source']) . ', ' .
                    userdate($ev['time'], get_string('strftimedatetimeshort')) . ', ' . self::t('certainty_' . $ev['certainty']),
                    'small text-muted'
                );
            }
            if ($ev['httpstatus']) {
                $evtext .= ' ' . html_writer::span('HTTP ' . (int) $ev['httpstatus'], 'small');
            }
            if ($ev['errorclass'] !== '') {
                $evtext .= ' ' . html_writer::span(self::label('gerr_', $ev['errorclass']), 'small');
            }
            if ($ev['skipreason'] !== '') {
                $evtext .= ' ' . html_writer::span(self::label('gskip_', $ev['skipreason']), 'small');
            }
            $risks = array_map(fn($k) => self::badge(self::label('grisk_', $k), 'amber'), $r['risks']);
            $actions = [];
            if (!empty($ctx['canrepair']) && $r['replayable']) {
                $actions[] = html_writer::link(
                    new moodle_url('/admin/tool/ltidiagnostics/repair.php', ['key' => $ctx['key'],
                    'link' => $r['linkid'], 'user' => $r['userid']]),
                    self::t('greplay_preview_for', $r['person']),
                    ['class' => 'btn btn-secondary btn-sm mb-1']
                );
            }
            if (!empty($ctx['canresult']) && !empty($link['scopes']['result'])) {
                $actions[] = $this->output->single_button(
                    new moodle_url(
                        '/admin/tool/ltidiagnostics/repair.php',
                        ['key' => $ctx['key'], 'link' => $r['linkid'], 'user' => $r['userid'], 'action' => 'checkresult']
                    ),
                    self::t('gcheckresult_for', $r['person']),
                    'post',
                    ['class' => 'mb-1']
                );
            }
            $row = new html_table_row([
                $h,
                s($link['resourcelinkid']) . html_writer::empty_tag('br') . html_writer::span(
                    self::scope_text($link),
                    'small text-muted'
                ),
                self::badge(self::t('gpred_' . $r['prediction']), self::prediction_light($r['prediction'])),
                $sourcetext,
                self::t('gmap_' . $r['mapping']),
                $evtext,
                implode(' ', $risks) ?: '-',
                implode(' ', $actions) ?: '-',
            ]);
            $row->attributes['data-prediction'] = $r['prediction'];
            $row->attributes['data-evidence'] = $ev['level'];
            $row->attributes['data-risks'] = implode(' ', $r['risks']);
            $row->attributes['data-link'] = (string) $r['linkid'];
            $rows[] = $row;
        }
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_grades_rows';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('grades_rows');
        $table->captionhide = true;
        $table->head = [self::t('gperson'), self::t('glink'), self::t('gprediction'), self::t('gsourcegrade'),
            self::t('gmapping'), self::t('gevidence'), self::t('grisks'), self::t('actions')];
        $table->data = $rows;
        if (!$rows) {
            return html_writer::tag('p', self::t('grades_norows'), ['class' => 'alert alert-info']);
        }
        return html_writer::div(html_writer::table($table), 'table-responsive');
    }

    /**
     * Short scope summary of a link.
     *
     * @param array $link Link.
     * @return string
     */
    private static function scope_text(array $link): string {
        $parts = [];
        foreach (['score', 'lineitem', 'lineitemreadonly', 'result'] as $s) {
            $parts[] = self::t('gscope_' . $s) . ': ' . ($link['scopes'][$s] ? self::t('yes') : self::t('no'));
        }
        $parts[] = self::t('gscope_endpoint') . ': ' . ($link['haslineitem'] ? self::t('gscope_lineitem_url')
            : ($link['haslineitems'] ? self::t('gscope_lineitems_url') : self::t('no')));
        return s(implode(' · ', $parts));
    }

    /**
     * Attempt journal.
     *
     * @param \stdClass[] $rows Rows.
     * @param array $ctx Context.
     * @return string
     */
    public function journal(array $rows, array $ctx): string {
        if (!$rows) {
            return html_writer::tag('p', self::t('grades_nojournal'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_grades_nojournal']);
        }
        $data = [];
        foreach ($rows as $j) {
            $h = new html_table_cell(userdate((int) $j->timecreated, get_string('strftimedatetimeshort')));
            $h->header = true;
            $h->scope = 'row';
            $score = '-';
            if ($ctx['showscores'] && $j->scoregiven !== null) {
                $score = s(format_float((float) $j->scoregiven, 2) . ' / ' . format_float((float) $j->scoremax, 2));
            }
            $state = self::badge(self::t('gstate_' . $j->state), in_array($j->state, ['http_accepted'], true) ? 'amber'
                : (in_array($j->state, ['http_rejected', 'dead', 'aborted'], true) ? 'red' : 'grey'));
            $detail = [];
            if ((int) $j->httpstatus) {
                $detail[] = 'HTTP ' . (int) $j->httpstatus;
            }
            if ($j->errorclass !== '') {
                $detail[] = self::label('gerr_', $j->errorclass);
            }
            if ($j->skipreason !== '') {
                $detail[] = self::label('gskip_', $j->skipreason);
            }
            if ((int) $j->retryat && $j->state !== 'http_accepted') {
                $detail[] = self::t('gretryat', userdate((int) $j->retryat, get_string('strftimedatetimeshort')));
            }
            $actions = [];
            if ($j->source === 'instrumented') {
                $actions[] = html_writer::link(new moodle_url('/admin/tool/ltidiagnostics/repair.php', ['key' => $ctx['key'],
                    'attempt' => $j->attemptid]), self::t('gattempt_detail_for', substr((string) $j->attemptid, 0, 8)));
            }
            $row = new html_table_row([
                $h,
                html_writer::tag('code', s(substr((string) $j->attemptid, 0, 8))) . ' #' . (int) $j->attemptno,
                self::label('gorigin_', $j->origin) . ' / ' . self::label('gsrc_', $j->source),
                (int) $j->resourcelinkrowid ? (string) (int) $j->resourcelinkrowid : self::t('gunattributed'),
                s(\tool_ltidiagnostics\local\grades\grade_diagnosis::pseudonym(
                    (string) ($j->connkey ?: $ctx['key']),
                    (int) $j->userid
                )),
                $score,
                $state . ' ' . s(implode(', ', $detail)),
                self::badge(self::t('gbook_' . $j->bookingstate), in_array(
                    $j->bookingstate,
                    ['results_match', 'receipt_confirmed'],
                    true
                ) ? 'green' : (strpos($j->bookingstate, 'mismatch') !== false
                    ? 'red' : 'grey')),
                implode(' ', $actions) ?: '-',
            ]);
            $row->attributes['data-state'] = $j->state;
            $row->attributes['data-source'] = $j->source;
            $row->attributes['data-booking'] = $j->bookingstate;
            $data[] = $row;
        }
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_grades_journal';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('grades_journal');
        $table->captionhide = true;
        $table->head = [self::t('time'), self::t('gattempt'), self::t('gorigin'), self::t('glink'), self::t('gperson'),
            self::t('gscore'), self::t('gstate'), self::t('gbooking'), self::t('actions')];
        $table->data = $data;
        return html_writer::div(html_writer::table($table), 'table-responsive');
    }

    /**
     * State of the repair module and the opt-in switch.
     *
     * @param array $ctx Context.
     * @return string
     */
    public function repair_panel(array $ctx): string {
        $s = $ctx['repairstate'];
        $o = $this->output->heading(self::t('greplay_module'), 3);
        $o .= html_writer::tag('p', self::t('greplay_module_desc'));
        $items = [
            self::t('greplay_site') . ': ' . ($s['site'] ? self::t('yes') : self::t('no')),
            self::t('greplay_optin') . ': ' . ($s['optin'] ? self::t('yes') : self::t('no')),
            self::t('greplay_capability') . ': ' . ($s['capability'] ? self::t('yes') : self::t('no')),
        ];
        $o .= html_writer::alist(array_map('s', $items), ['id' => 'tool_ltidiagnostics_repairstate',
            'data-active' => (int) ($s['site'] && $s['optin'] && $s['capability'])]);
        if (!empty($ctx['canoptin'])) {
            $o .= $this->output->single_button(
                new moodle_url('/admin/tool/ltidiagnostics/repair.php', ['key' => $ctx['key'],
                'action' => $s['optin'] ? 'optout' : 'optin']),
                self::t($s['optin'] ? 'greplay_optout' : 'greplay_optin_do'),
                'post'
            );
        }
        return $o;
    }

    /**
     * Preview of one replay (dry run) and the confirmation form.
     *
     * @param array $p Result of repair_service::preview().
     * @param string $formhtml Rendered confirmation form ('' when not allowed).
     * @return string
     */
    public function preview(array $p, string $formhtml): string {
        $o = $this->output->heading(self::t('greplay_preview'), 3);
        $o .= html_writer::tag('p', self::t('greplay_preview_desc'));
        $d = $p['display'];
        $rows = [];
        $add = function (string $label, string $value) use (&$rows) {
            $h = new html_table_cell(s($label));
            $h->header = true;
            $h->scope = 'row';
            $rows[] = new html_table_row([$h, $value]);
        };
        if ($d) {
            $add(self::t('gpv_registration'), s($d['registration']) . ' (' . s($d['issuer']) . ', ' . s($d['clientid']) . ')');
            $add(self::t('gpv_deployment'), s($d['deployment']));
            $add(self::t('gpv_resourcelink'), s($d['resourcelinkid']));
            $add(self::t('gpv_lineitem'), $d['lineitem'] !== '' ? s($d['lineitem'])
                : self::t($d['lineitemlookup'] ? 'gpv_lineitem_lookup' : 'gpv_lineitem_none'));
            $add(self::t('gpv_person'), s($d['person']) . ' (' . self::t('gmap_' . ($d['mapping'] ?: 'unknown')) . ')');
            $add(self::t('gpv_score'), $d['grade'] === null ? self::t('gsource_' . $d['gradestate'])
                : s(format_float((float) $d['grade'], 5) . ' / ' . format_float((float) $d['grademax'], 5)));
            $add(self::t('gpv_dategraded'), $d['dategraded'] ? userdate($d['dategraded']) : '-');
            $add(self::t('gpv_scope'), s(implode(', ', array_keys(array_filter($d['scopes'])))));
            $add(self::t('gpv_timestamp'), self::t('gpv_timestamp_desc'));
            $add(self::t('gpv_limits'), self::t($d['resultcheck'] ? 'gpv_limits_results' : 'gpv_limits_none'));
        }
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_replay_preview';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('greplay_preview');
        $table->captionhide = true;
        $table->head = [self::t('gpre_item'), self::t('gpre_value')];
        $table->data = $rows;
        $o .= html_writer::div(html_writer::table($table), 'table-responsive');
        if ($p['blockers']) {
            $o .= html_writer::tag('div', html_writer::tag('strong', self::t('greplay_blocked')) . html_writer::alist(
                array_map(fn($b) => self::label('gblock_', $b), $p['blockers'])
            ), ['class' => 'alert alert-danger',
                'id' => 'tool_ltidiagnostics_replay_blockers', 'data-blockers' => implode(' ', $p['blockers'])]);
        }
        if ($p['warnings']) {
            $o .= html_writer::tag(
                'div',
                html_writer::alist(array_map(fn($w) => self::label('gwarn_', $w), $p['warnings'])),
                ['class' => 'alert alert-warning']
            );
        }
        if (!empty($p['open'])) {
            $o .= html_writer::tag(
                'p',
                self::t('greplay_open', substr((string) $p['open']->attemptid, 0, 8)),
                ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_replay_open']
            );
        }
        return $o . $formhtml;
    }

    /**
     * Detail of one attempt with its chain (retries share the correlation id).
     *
     * @param \stdClass $row Journal row.
     * @param \stdClass[] $chain Rows of the correlation.
     * @param \stdClass[] $receipts Imported receipts.
     * @param bool $showscores Show score values.
     * @return string
     */
    public function attempt(\stdClass $row, array $chain, array $receipts, bool $showscores): string {
        $o = $this->output->heading(self::t('gattempt_heading', substr((string) $row->attemptid, 0, 8)), 3);
        $rows = [];
        $add = function (string $label, string $value) use (&$rows) {
            $h = new html_table_cell(s($label));
            $h->header = true;
            $h->scope = 'row';
            $rows[] = new html_table_row([$h, $value]);
        };
        $add(self::t('gattempt'), html_writer::tag('code', s($row->attemptid)));
        $add(self::t('gcorrelation'), html_writer::tag('code', s($row->correlationid)));
        $add(self::t('gorigin'), self::label('gorigin_', $row->origin) . ' #' . (int) $row->attemptno);
        $add(self::t('gstate'), self::t('gstate_' . $row->state) . ((int) $row->httpstatus ? ' (HTTP ' . (int) $row->httpstatus .
            ')' : '') . ($row->errorclass !== '' ? ' - ' . self::label('gerr_', $row->errorclass) : ''));
        $add(self::t('gtoken'), self::label('gtoken_', $row->tokenstatus));
        if ($showscores && $row->scoregiven !== null) {
            $add(self::t('gscore'), s(format_float((float) $row->scoregiven, 5) . ' / ' . format_float((float) $row->scoremax, 5)));
        }
        $add(self::t('ggradetime'), (int) $row->gradetime ? userdate((int) $row->gradetime) : '-');
        $add(self::t('gagstimestamp'), (int) $row->agstimestamp ? userdate((int) $row->agstimestamp) . ' (' .
            self::t('gstateversion', (int) $row->stateversion) . ')' : '-');
        $add(self::t('gbooking'), self::t('gbook_' . $row->bookingstate) . ($row->bookingdetail !== '' ? ' (' .
            s(implode(', ', array_map(fn($c) => self::label('gmis_', $c), explode(',', $row->bookingdetail)))) . ')' : ''));
        $add(self::t('gpurpose'), s($row->purpose));
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_attempt';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('gattempt_heading', substr((string) $row->attemptid, 0, 8));
        $table->captionhide = true;
        $table->head = [self::t('gpre_item'), self::t('gpre_value')];
        $table->data = $rows;
        $o .= html_writer::div(html_writer::table($table), 'table-responsive');
        if (count($chain) > 1) {
            $items = [];
            foreach ($chain as $c) {
                $items[] = '#' . (int) $c->attemptno . ' ' . self::t('gstate_' . $c->state) . ' ' .
                    userdate((int) $c->timecreated, get_string('strftimedatetimeshort')) .
                    ($c->errorclass !== '' ? ' - ' . self::label('gerr_', $c->errorclass) : '');
            }
            $o .= $this->output->heading(self::t('gchain'), 4) . html_writer::alist(
                array_map('s', $items),
                ['id' => 'tool_ltidiagnostics_attempt_chain']
            );
        }
        if ($receipts) {
            $items = [];
            foreach ($receipts as $r) {
                $items[] = self::t('gbook_' . $r->verdict) . ' ' . userdate((int) $r->timecreated) .
                    ($r->mismatches !== '' ? ' (' . implode(', ', array_map(
                        fn($c) => self::label('gmis_', $c),
                        explode(',', $r->mismatches)
                    )) . ')' : '');
            }
            $o .= $this->output->heading(self::t('greceipts'), 4) . html_writer::alist(array_map('s', $items));
        }
        return $o;
    }

    /**
     * Completion channel tab (issue #7).
     *
     * @param array $v Result of completion_view::build().
     * @param \moodle_url $pageurl Page URL (paging).
     * @return string
     */
    public function completion_tab(array $v, moodle_url $pageurl): string {
        $o = html_writer::tag('p', self::t('compl_intro'), ['class' => 'lead']);
        if (!$v['installed']) {
            return $o . html_writer::tag('p', self::t('compl_notinstalled'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_compl_notinstalled']);
        }
        if (!$v['connections']) {
            return $o . html_writer::tag('p', self::t('compl_noconnection'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_compl_noconnection']);
        }
        foreach ($v['connections'] as $c) {
            $parts = [self::t('compl_conn', (int) $c->id), $c->enabled ? self::t('compl_enabled') : self::t('compl_disabled')];
            if ($v['role'] === 'sender') {
                $parts[] = self::t('compl_partner', self::label('cpartner_', (string) $c->partnerstatus) .
                    ((int) $c->timechecked ? ', ' . userdate((int) $c->timechecked, get_string('strftimedatetimeshort')) : ''));
            } else {
                $parts[] = self::t('compl_policy', self::label('cpolicy_', (string) $c->conflictpolicy));
            }
            $o .= html_writer::tag('p', s(implode(' · ', $parts)), ['class' => 'mb-1']);
        }
        if ($v['agsprogress']) {
            $o .= html_writer::tag('p', self::t('compl_agsprogress'), ['class' => 'alert alert-warning',
                'id' => 'tool_ltidiagnostics_compl_agsprogress']);
        }
        // Summary per class.
        $rows = [];
        $classes = $v['role'] === 'sender' ? array_merge(
            \tool_ltidiagnostics\local\completion\completion_view::CLASSES,
            ['revoked']
        ) : \tool_ltidiagnostics\local\completion\completion_view::RECEIVER_CLASSES;
        foreach ($classes as $class) {
            $h = new html_table_cell(self::t('cclass_' . $class));
            $h->header = true;
            $h->scope = 'row';
            $row = new html_table_row([$h, (string) ($v['summary'][$class] ?? 0), self::t('cclass_' . $class . '_desc')]);
            $row->attributes['data-class'] = $class;
            $rows[] = $row;
        }
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_compl_summary';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('compl_summary');
        $table->head = [self::t('compl_class'), self::t('gcount'), self::t('detail')];
        $table->data = $rows;
        $o .= html_writer::div(html_writer::table($table), 'table-responsive');

        // Rows.
        $data = [];
        foreach ($v['rows'] as $r) {
            $h = new html_table_cell(s($r['person']));
            $h->header = true;
            $h->scope = 'row';
            if ($v['role'] === 'sender') {
                $cells = [$h, (string) $r['resourcelinkid'], s($r['source']),
                    self::t($r['desiredstate'] ? 'compl_state_done' : 'compl_state_open') .
                    ($r['revoked'] ? ' ' . self::badge(self::t('cclass_revoked'), 'amber') : ''),
                    self::t('compl_versions', (object) ['version' => $r['version'], 'sent' => $r['sentversion'],
                        'ack' => $r['ackversion']]),
                    self::badge(self::t('cclass_' . $r['class']), $r['class'] === 'acked_applied' ? 'green'
                        : (in_array($r['class'], ['rejected', 'blocked', 'dead'], true) ? 'red' : 'amber')) .
                    ($r['ackstatus'] !== '' ? ' ' . s($r['ackstatus']) : '') .
                    ($r['lasterror'] !== '' ? ' - ' . s($r['lasterror']) : ''),
                    s((string) $r['attempts']) . ($r['nextattempt'] ? ', ' . userdate(
                        $r['nextattempt'],
                        get_string('strftimedatetimeshort')
                    ) : ''),
                    $r['agsstatus'] !== '' ? self::t('compl_ags_limited', s($r['agsstatus'])) : '-',
                    html_writer::tag('code', s(substr($r['correlationid'], 0, 12)))];
            } else {
                $cells = [$h, (string) $r['cmid'], self::t($r['remotestate'] ? 'compl_state_done' : 'compl_state_open'),
                    self::t('compl_rversions', (object) ['remote' => $r['remoteversion'], 'applied' => $r['appliedversion']]),
                    self::badge(self::t('cclass_' . $r['class']), $r['class'] === 'applied' ? 'green'
                        : (in_array($r['class'], ['rejected', 'conflict'], true) ? 'red' : 'amber')) .
                    ($r['reason'] !== '' ? ' - ' . s($r['reason']) : ''),
                    html_writer::tag('code', s(substr($r['correlationid'], 0, 12)))];
            }
            $row = new html_table_row($cells);
            $row->attributes['data-class'] = $r['class'];
            $data[] = $row;
        }
        if ($data) {
            $table = new html_table();
            $table->id = 'tool_ltidiagnostics_compl_rows';
            $table->attributes['class'] = 'generaltable table-sm';
            $table->caption = self::t('compl_rows');
            $table->head = $v['role'] === 'sender'
                ? [self::t('gperson'), self::t('glink'), self::t('compl_source'), self::t('compl_desired'),
                    self::t('compl_version'), self::t('compl_class'), self::t('compl_attempts'), self::t('compl_ags'),
                    self::t('gcorrelation')]
                : [self::t('gperson'), self::t('compl_cm'), self::t('compl_desired'), self::t('compl_version'),
                    self::t('compl_class'), self::t('gcorrelation')];
            $table->data = $data;
            $o .= html_writer::div(html_writer::table($table), 'table-responsive');
            $o .= $this->pager(
                $pageurl,
                $v['offset'],
                \tool_ltidiagnostics\local\completion\completion_view::PAGESIZE,
                $v['total']
            );
        }
        $o .= html_writer::tag('p', self::t('compl_native'), ['class' => 'small text-muted']);
        $o .= html_writer::link(
            new moodle_url('/admin/tool/ltidiagnostics/transfers.php', ['key' => $pageurl->param('key')]),
            self::t('transfers_title'),
            ['class' => 'btn btn-secondary mb-3']
        );

        // Actions of the completion plugin.
        $o .= $this->output->heading(self::t('compl_actions'), 3);
        if (!$v['actions']) {
            $o .= html_writer::tag(
                'p',
                self::t($v['role'] === 'sender' ? 'compl_noactions' : 'compl_noactions_receiver'),
                ['class' => 'text-muted', 'id' => 'tool_ltidiagnostics_compl_noactions']
            );
            return $o;
        }
        $o .= html_writer::tag('p', self::t('compl_actions_desc'));
        $rows = [];
        foreach ($v['actions'] as $a) {
            $h = new html_table_cell($this->output->single_button($a['url'], get_string(
                'action_' . $a['action'],
                'local_lticompletionsync'
            ) . ' (' . self::t('compl_conn', $a['connectionid']) . ')', 'post'));
            $h->header = true;
            $h->scope = 'row';
            $row = new html_table_row([$h, self::t($a['reach'])]);
            $row->attributes['data-action'] = $a['action'];
            $rows[] = $row;
        }
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_compl_actions';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('compl_actions');
        $table->captionhide = true;
        $table->head = [self::t('actions'), self::t('compl_reach')];
        $table->data = $rows;
        return $o . html_writer::div(html_writer::table($table), 'table-responsive');
    }

    /**
     * Previous/next links for offset paging.
     *
     * @param moodle_url $url Page URL.
     * @param int $offset Offset.
     * @param int $limit Limit.
     * @param int $total Total.
     * @return string
     */
    private function pager(moodle_url $url, int $offset, int $limit, int $total): string {
        $links = [];
        if ($offset > 0) {
            $links[] = html_writer::link(
                new moodle_url($url, ['offset' => max(0, $offset - $limit)]),
                self::t('gprevpage'),
                ['class' => 'btn btn-secondary btn-sm mr-2 me-2']
            );
        }
        if ($offset + $limit < $total) {
            $links[] = html_writer::link(
                new moodle_url($url, ['offset' => $offset + $limit]),
                self::t('gnextpage'),
                ['class' => 'btn btn-secondary btn-sm']
            );
        }
        return $links ? html_writer::div(implode('', $links), 'mb-3') : '';
    }
}
