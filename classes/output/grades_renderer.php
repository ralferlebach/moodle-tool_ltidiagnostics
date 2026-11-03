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
     * @param array $d Result of grade_diagnosis::run() (prerequisites and findings; rows are in the section).
     * @param string $rowshtml Rendered section "resource links and learners".
     * @param string $journalhtml Rendered section "journal".
     * @param array $ctx key, canrepair (bool), repairstate (array site, optin, capability), canresult (bool),
     *     canoptin (bool), scope (scope).
     * @return string
     */
    public function grades_tab(array $d, string $rowshtml, string $journalhtml, array $ctx): string {
        $o = html_writer::tag('p', self::t('grades_intro'), ['class' => 'lead']);
        if (!$d['applicable']) {
            return $o . html_writer::tag('p', self::t('grades_notapplicable'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_grades_na']);
        }
        $o .= html_writer::tag('p', self::t('grades_ladder'), ['class' => 'small text-muted']);
        $o .= $this->prerequisites($d['prerequisites'], $d['resources']);
        $o .= $this->output->heading(self::t('grades_findings'), 3);
        $o .= html_writer::tag('p', self::t('grades_findings_scope', (object) ['used' => count($d['rows']),
            'total' => (int) $d['total']]), ['class' => 'small text-muted', 'id' => 'tool_ltidiagnostics_findings_basis']);
        $o .= $this->findings($d['findings']);
        $o .= $this->output->heading(self::t('grades_rows'), 3, '', 'tool_ltidiagnostics_rows');
        $o .= $rowshtml;
        $o .= $this->output->heading(self::t('grades_journal'), 3, '', 'tool_ltidiagnostics_journal');
        $o .= html_writer::tag('p', self::t('grades_journal_desc'), ['class' => 'small text-muted']);
        $o .= $journalhtml;
        $o .= $this->repair_panel($ctx);
        return $o;
    }

    /**
     * Section "resource links and learners": filter form, counters per dimension, rows, selection, pager.
     *
     * @param array $d Diagnosis.
     * @param array $ctx Grade context plus baseurl, params, filters, perpage, offset, scope.
     * @return string
     */
    public function rows_section(array $d, array $ctx): string {
        $f = $ctx['filters'];
        $o = $this->filter_form($ctx['baseurl'], 'grades_rows', [
            ['fgradesync', self::t('gf_gradesync'), ['' => self::t('gf_any'), '1' => self::t('gpre_gradesync_on'),
                '0' => self::t('gpre_gradesync_off')], $f['gradesync']],
            ['fgrade', self::t('gf_grade'), ['' => self::t('gf_any')] + self::options(
                'gcnt_grade_',
                \tool_ltidiagnostics\local\grades\pair_query::GRADE
            ), $f['grade']],
            ['ftransfer', self::t('gf_transfer'), ['' => self::t('gf_any')] + self::options(
                'gcnt_transfer_',
                \tool_ltidiagnostics\local\grades\pair_query::TRANSFER
            ), $f['transfer']],
            ['fbooking', self::t('gf_booking'), ['' => self::t('gf_any')] + self::options(
                'gcnt_booking_',
                \tool_ltidiagnostics\local\grades\pair_query::BOOKING
            ), $f['booking']],
        ], array_filter(['fuser' => $f['userid'], 'flink' => $f['linkid']]), [
            'search' => !empty($ctx['names']) ? ['fsearch', self::t('gf_search'), $f['usersearch']] : null,
            'perpage' => ['perpage', $ctx['perpage']],
        ]);
        if ($f['userid'] || $f['linkid']) {
            $o .= html_writer::tag('p', self::t('gf_pairfilter') . ' ' . html_writer::link(
                new moodle_url($ctx['baseurl'], array_diff_key($ctx['params'], ['fuser' => 1, 'flink' => 1])),
                self::t('gf_pairfilter_clear'),
                ['class' => 'ltidiag-nav']
            ), ['class' => 'small']);
        }
        $o .= $this->counters($d['counters'], (int) $d['total'], $ctx);
        $o .= html_writer::tag(
            'p',
            self::t('grades_rows_desc', (object) ['total' => $d['total'],
            'from' => $d['total'] ? $ctx['offset'] + 1 : 0, 'to' => $ctx['offset'] + count($d['rows'])]),
            ['id' => 'tool_ltidiagnostics_rows_count', 'data-total' => (int) $d['total']]
        );
        $table = $this->rows($d, $ctx + ['selectable' => true]);
        if (!empty($ctx['canrepair']) && $d['rows']) {
            $max = \tool_ltidiagnostics\local\config::bounded('repairbatchmax');
            $hidden = ['key' => $ctx['key'], 'action' => 'select', 'sesskey' => sesskey(),
                'displayed' => implode(',', array_map(fn($r) => $r['linkid'] . ':' . $r['userid'], $d['rows']))]
                + $ctx['scope']->params() + array_filter(['fgrade' => $f['grade'], 'ftransfer' => $f['transfer'],
                'fbooking' => $f['booking'], 'fgradesync' => $f['gradesync'], 'fuser' => $f['userid'], 'flink' => $f['linkid'],
                'fsearch' => !empty($ctx['names']) ? $f['usersearch'] : '',
                'perpage' => $ctx['perpage'] ?? 0, 'offset' => $ctx['offset'] ?? 0]);
            $inputs = '';
            foreach ($hidden as $name => $value) {
                $inputs .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
            }
            $buttons = html_writer::tag('button', self::t('gsel_rows'), ['type' => 'submit', 'name' => 'selectmode',
                'value' => 'rows', 'class' => 'btn btn-secondary mr-2 me-2 mb-1'])
                . html_writer::tag('button', self::t('gsel_page', count($d['rows'])), ['type' => 'submit',
                'name' => 'selectmode', 'value' => 'page', 'class' => 'btn btn-secondary mr-2 me-2 mb-1']);
            if ($d['total'] <= $max) {
                $buttons .= html_writer::tag('button', self::t('gsel_matches', $d['total']), ['type' => 'submit',
                    'name' => 'selectmode', 'value' => 'matches', 'class' => 'btn btn-secondary mb-1']);
            } else {
                $buttons .= html_writer::span(
                    self::t('gsel_matches_toolarge', (object) ['count' => $d['total'], 'max' => $max]),
                    'small text-muted'
                );
            }
            $o .= $table . html_writer::tag('form', $inputs . html_writer::div(html_writer::tag(
                'p',
                self::t('gsel_desc'),
                ['class' => 'small text-muted mb-1']
            ) . $buttons, 'mb-2'), ['method' => 'post',
                'action' => (new moodle_url('/admin/tool/ltidiagnostics/repair.php'))->out(false),
                'id' => 'tool_ltidiagnostics_selection']);
        } else {
            $o .= $table;
        }
        $o .= $this->offset_pager($ctx['baseurl'], $ctx['params'], 'offset', $ctx['offset'], $ctx['perpage'], (int) $d['total']);
        return $o;
    }

    /**
     * Counters of the population per dimension (disjoint inside a dimension; dimensions are not summed).
     *
     * @param array $c Counters.
     * @param int $total Total of the population.
     * @param array $ctx Context (baseurl, params).
     * @return string
     */
    private function counters(array $c, int $total, array $ctx): string {
        $o = '';
        $filtername = ['grade' => 'fgrade', 'transfer' => 'ftransfer', 'booking' => 'fbooking'];
        $cells = [];
        foreach (['grade', 'transfer', 'booking'] as $dim) {
            $rows = [];
            foreach ($c[$dim] as $value => $n) {
                $h = new html_table_cell(html_writer::link(
                    new moodle_url($ctx['baseurl'], [$filtername[$dim] => $value] +
                    array_diff_key($ctx['params'], ['offset' => 1])),
                    self::t('gcnt_' . $dim . '_' . $value),
                    ['class' => 'ltidiag-nav']
                ));
                $h->header = true;
                $h->scope = 'row';
                $row = new html_table_row([$h, (string) $n]);
                $row->attributes['data-dim'] = $dim;
                $row->attributes['data-value'] = $value;
                $rows[] = $row;
            }
            $table = new html_table();
            $table->id = 'tool_ltidiagnostics_cnt_' . $dim;
            $table->attributes['class'] = 'generaltable table-sm mb-1';
            $table->caption = self::t('gcnt_' . $dim);
            $table->head = [self::t('gcnt_value'), self::t('gcount')];
            $table->data = $rows;
            $cells[] = html_writer::div(html_writer::table($table) . html_writer::tag(
                'p',
                self::t('gcnt_' . $dim . '_src'),
                ['class' => 'small text-muted']
            ), 'col-md-4');
        }
        $o .= html_writer::div(implode('', $cells), 'row', ['id' => 'tool_ltidiagnostics_counters', 'data-total' => $total]);
        $o .= html_writer::tag(
            'p',
            self::t('gcnt_note', userdate(time(), get_string('strftimedatetimeshort'))),
            ['class' => 'small text-muted']
        );
        return $o;
    }

    /**
     * Section "journal": filters, rows, keyset pager.
     *
     * @param array $page Result of journal::page().
     * @param array $ctx Grade context plus baseurl, params, perpage, before, scope.
     * @return string
     */
    public function journal_section(array $page, array $ctx): string {
        $p = $ctx['params'];
        $o = $this->filter_form($ctx['baseurl'], 'grades_journal', [
            ['jorigin', self::t('gj_origin'), ['' => self::t('gf_any'), 'core' => self::t('gj_origin_core'),
                'replay' => self::t('gj_origin_replay')], $p['jorigin'] ?? ''],
            ['jtransfer', self::t('gj_transfer'), ['' => self::t('gf_any')] + self::options(
                'gcnt_transfer_',
                ['skipped', 'pending', 'accepted', 'failed']
            ), $p['jtransfer'] ?? ''],
            ['jattr', self::t('gj_attr'), ['' => self::t('gf_any'), 'attributed' => self::t('gj_attr_attributed'),
                'unattributed' => self::t('gj_attr_unattributed')], $p['jattr'] ?? ''],
        ], array_filter(['jlink' => $p['jlink'] ?? 0, 'juser' => $p['juser'] ?? 0]), [
            'perpage' => ['jperpage', $ctx['perpage']],
        ]);
        if (!empty($p['jlink']) || !empty($p['juser'])) {
            $o .= html_writer::tag('p', self::t('gj_pairfilter', (object) [
                'link' => (int) ($p['jlink'] ?? 0),
                'person' => !empty($p['juser']) ? s(\tool_ltidiagnostics\local\identity::person(
                    $ctx['key'],
                    (int) $p['juser'],
                    !empty($ctx['names'])
                )) : self::t('gf_any'),
            ]) . ' ' . html_writer::link(
                new moodle_url($ctx['baseurl'], array_diff_key($p, ['jlink' => 1, 'juser' => 1])),
                self::t('gf_pairfilter_clear'),
                ['class' => 'ltidiag-nav']
            ), ['class' => 'small',
                'id' => 'tool_ltidiagnostics_journal_pair']);
        }
        if (!$ctx['scope']->is_empty()) {
            $o .= html_writer::tag('p', self::t('gj_scope'), ['class' => 'small text-muted']);
        }
        $o .= html_writer::tag(
            'p',
            self::t('gj_count', (object) ['shown' => count($page['rows']), 'total' => $page['total']]),
            ['id' => 'tool_ltidiagnostics_journal_count', 'data-total' => (int) $page['total']]
        );
        $o .= $this->journal($page['rows'], $ctx);
        $links = [];
        if (!empty($ctx['before'])) {
            $links[] = html_writer::link(
                new moodle_url($ctx['baseurl'], $p),
                self::t('gj_newest'),
                ['class' => 'btn btn-secondary btn-sm mr-2 me-2 ltidiag-nav']
            );
        }
        if ($page['next']) {
            $links[] = html_writer::link(
                new moodle_url($ctx['baseurl'], $p + ['jbefore' => $page['next']]),
                self::t('grades_older'),
                ['class' => 'btn btn-secondary btn-sm ltidiag-nav']
            );
        }
        if ($links) {
            $o .= html_writer::div(implode('', $links), 'mb-3');
        }
        return $o;
    }

    /**
     * Options of a select from string ids.
     *
     * @param string $prefix String prefix.
     * @param string[] $values Values.
     * @return array value => label
     */
    private static function options(string $prefix, array $values): array {
        $out = [];
        foreach ($values as $v) {
            $out[$v] = self::t($prefix . $v);
        }
        return $out;
    }

    /**
     * GET filter form of a section (works without JavaScript; the AMD module submits it asynchronously).
     *
     * @param moodle_url $base Page URL with scope.
     * @param string $section Section.
     * @param array $selects Each [name, label, options, value].
     * @param array $hidden Extra hidden values.
     * @param array $extra search ([name, label, value] or null), perpage ([name, value]).
     * @return string
     */
    public function filter_form(moodle_url $base, string $section, array $selects, array $hidden, array $extra): string {
        $fields = '';
        foreach ($base->params() + $hidden as $name => $value) {
            $fields .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        foreach ($selects as [$name, $label, $options, $value]) {
            $id = 'ltidiag-' . $section . '-' . $name;
            $fields .= html_writer::div(
                html_writer::label($label, $id, false, ['class' => 'mr-1 me-1']) .
                html_writer::select($options, $name, $value, false, ['id' => $id, 'class' => 'custom-select form-select']),
                'mr-2 me-2 mb-2 d-inline-block'
            );
        }
        if (!empty($extra['search'])) {
            [$name, $label, $value] = $extra['search'];
            $id = 'ltidiag-' . $section . '-' . $name;
            $fields .= html_writer::div(html_writer::label($label, $id, false, ['class' => 'mr-1 me-1']) .
                html_writer::empty_tag('input', ['type' => 'search', 'name' => $name, 'id' => $id, 'value' => $value,
                    'class' => 'form-control d-inline-block w-auto']), 'mr-2 me-2 mb-2 d-inline-block');
        }
        if (!empty($extra['perpage'])) {
            [$name, $value] = $extra['perpage'];
            $id = 'ltidiag-' . $section . '-' . $name;
            $options = [];
            foreach (sections::SIZES as $size) {
                $options[$size] = (string) $size;
            }
            $fields .= html_writer::div(
                html_writer::label(self::t('perpage'), $id, false, ['class' => 'mr-1 me-1']) .
                html_writer::select($options, $name, $value, false, ['id' => $id, 'class' => 'custom-select form-select']),
                'mr-2 me-2 mb-2 d-inline-block'
            );
        }
        $fields .= html_writer::tag('button', self::t('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary mb-2']);
        $fields .= ' ' . html_writer::link($base, self::t('filter_reset'), ['class' => 'ltidiag-nav mb-2 d-inline-block']);
        return html_writer::tag('form', $fields, ['method' => 'get', 'action' => $base->out_omit_querystring(),
            'class' => 'ltidiag-filter mb-2', 'role' => 'search', 'aria-label' => self::t('filter_label_' . $section)]);
    }

    /**
     * Offset pager with the page parameters.
     *
     * @param moodle_url $base Base URL.
     * @param array $params Parameters.
     * @param string $name Offset parameter.
     * @param int $offset Offset.
     * @param int $limit Limit.
     * @param int $total Total.
     * @return string
     */
    public function offset_pager(moodle_url $base, array $params, string $name, int $offset, int $limit, int $total): string {
        $links = [];
        $params = array_diff_key($params, [$name => 1]);
        if ($offset > 0) {
            $prev = max(0, $offset - $limit);
            $links[] = html_writer::link(
                new moodle_url($base, $params + ($prev ? [$name => $prev] : [])),
                self::t('gprevpage'),
                ['class' => 'btn btn-secondary btn-sm mr-2 me-2 ltidiag-nav']
            );
        }
        if ($offset + $limit < $total) {
            $links[] = html_writer::link(
                new moodle_url($base, $params + [$name => $offset + $limit]),
                self::t('gnextpage'),
                ['class' => 'btn btn-secondary btn-sm ltidiag-nav']
            );
        }
        return $links ? html_writer::tag('nav', implode('', $links), ['class' => 'mb-3',
            'aria-label' => self::t('pager_label')]) : '';
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
        $table->captionhide = true;
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
        $select = !empty($ctx['canrepair']) && !empty($ctx['selectable']);
        $userids = array_map(fn($r) => (int) $r['userid'], $d['rows']);
        if (!empty($ctx['names'])) {
            \tool_ltidiagnostics\local\identity::preload($userids);
        }
        foreach ($d['rows'] as $r) {
            $link = $d['links'][$r['linkid']];
            $person = !empty($ctx['names']) ? \tool_ltidiagnostics\local\identity::person($ctx['key'], (int) $r['userid'], true)
                : $r['person'];
            $h = new html_table_cell(!empty($ctx['names']) ? \tool_ltidiagnostics\local\identity::person_html(
                $ctx['key'],
                (int) $r['userid']
            ) : s($r['person']));
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
                $evtext .= ' ' . html_writer::span(self::t('ghttpstatus', (int) $ev['httpstatus']), 'small');
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
                    self::t('greplay_preview_for', s($person)),
                    ['class' => 'btn btn-secondary btn-sm mb-1']
                );
            }
            if (!empty($ctx['canresult']) && !empty($link['scopes']['result'])) {
                $actions[] = $this->output->single_button(
                    new moodle_url(
                        '/admin/tool/ltidiagnostics/repair.php',
                        ['key' => $ctx['key'], 'link' => $r['linkid'], 'user' => $r['userid'], 'action' => 'checkresult']
                    ),
                    self::t('gcheckresult_for', $person),
                    'post',
                    ['class' => 'mb-1']
                );
            }
            if (!empty($ctx['baseurl'])) {
                $actions[] = html_writer::link(
                    new moodle_url(
                        $ctx['baseurl'],
                        ['jlink' => $r['linkid'], 'juser' => $r['userid']],
                        'tool_ltidiagnostics_journal'
                    ),
                    self::t('gjournal_for', s($person)),
                    ['class' => 'd-block small', 'data-ltidiag-target' => 'grades_journal',
                        'data-ltidiag-pair' => $r['linkid'] . ':' . $r['userid']]
                );
            }
            $res = $d['resources'][$link['toolid']] ?? null;
            $where = $res ? \tool_ltidiagnostics\local\identity::cm_html((int) $res['cmid'])
                ?: \tool_ltidiagnostics\local\identity::course_html((int) $res['courseid']) : '';
            $cells = [];
            if ($select) {
                // The selection form stands after the table (rows hold forms of their own, and forms must not nest); the
                // checkboxes belong to it through the form attribute.
                $cells[] = html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'pairs[]',
                    'form' => 'tool_ltidiagnostics_selection',
                    'value' => $r['linkid'] . ':' . $r['userid'], 'class' => 'ltidiag-pair',
                    'id' => 'ltidiag-pair-' . $r['linkid'] . '-' . $r['userid'],
                    'aria-label' => self::t('gsel_mark', (object) ['person' => $person, 'link' => $link['resourcelinkid']])]);
            }
            $row = new html_table_row(array_merge($cells, [
                $h,
                ($where !== '' ? $where . html_writer::empty_tag('br') : '') .
                self::t('gplatformlink', s($link['resourcelinkid'])) . html_writer::empty_tag('br') . html_writer::span(
                    self::scope_text($link),
                    'small text-muted'
                ),
                self::badge(self::t('gpred_' . $r['prediction']), self::prediction_light($r['prediction'])),
                $sourcetext,
                self::t('gmap_' . $r['mapping']),
                $evtext,
                implode(' ', $risks) ?: '-',
                implode(' ', $actions) ?: '-',
            ]));
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
        if ($select) {
            array_unshift($table->head, html_writer::empty_tag('input', ['type' => 'checkbox', 'class' => 'ltidiag-pair-all',
                'id' => 'ltidiag-pair-all', 'aria-label' => self::t('gsel_markall')]));
        }
        $table->data = $rows;
        if (!$rows) {
            return html_writer::tag('p', self::t('grades_norows'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_grades_norows']);
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
        if (!empty($ctx['names'])) {
            // One query for the names of the page, not one per row.
            \tool_ltidiagnostics\local\identity::preload(array_map(fn($j) => (int) $j->userid, $rows));
        }
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
                $detail[] = self::t('ghttpstatus', (int) $j->httpstatus);
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
                self::t('gattempt_label', (object) ['id' => substr((string) $j->attemptid, 0, 8),
                    'no' => (int) $j->attemptno]),
                self::label('gorigin_', $j->origin) . ' / ' . self::label('gsrc_', $j->source),
                (int) $j->resourcelinkrowid ? (string) (int) $j->resourcelinkrowid : self::t('gunattributed'),
                !empty($ctx['names']) ? \tool_ltidiagnostics\local\identity::person_html(
                    (string) ($j->connkey ?: $ctx['key']),
                    (int) $j->userid
                ) : s(\tool_ltidiagnostics\local\grades\grade_diagnosis::pseudonym(
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
        $table->id = $ctx['tableid'] ?? 'tool_ltidiagnostics_grades_journal';
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
     * Preview of a selection (issue #22): every target with its line item, score, mapping and the reason why it would be
     * queued or not; nothing has been sent.
     *
     * @param array $previews "linkid:userid" => preview (with linkid, userid).
     * @param array $selection Selection (pairs, origin, created, expires).
     * @param array $ctx key, showscores.
     * @return string
     */
    public function bulk_preview(array $previews, array $selection, array $ctx): string {
        $o = $this->output->heading(self::t('gbulk_title'), 3);
        $o .= html_writer::tag('p', self::t('gbulk_desc', (object) [
            'count' => count($previews),
            'origin' => self::t('gsel_origin_' . $selection['origin']),
            'expires' => userdate($selection['expires'], get_string('strftimedatetimeshort')),
        ]), ['id' => 'tool_ltidiagnostics_bulk_summary']);
        $rows = [];
        $ready = 0;
        \tool_ltidiagnostics\local\identity::preload(array_map(fn($p) => (int) $p['userid'], $previews));
        foreach ($previews as $id => $p) {
            $h = new html_table_cell(\tool_ltidiagnostics\local\identity::person_html($ctx['key'], (int) $p['userid']));
            $h->header = true;
            $h->scope = 'row';
            $d = $p['display'] ?? [];
            if ($p['blockers']) {
                $state = self::badge(self::t('gbulk_blocked'), 'red') . ' ' . s(implode(', ', array_map(
                    fn($b) => self::label('gblock_', $b),
                    $p['blockers']
                )));
            } else if (!empty($p['open'])) {
                $state = self::badge(self::t('gbulk_open'), 'amber');
            } else {
                $state = self::badge(self::t('gbulk_ready'), 'green');
                $ready++;
            }
            $score = '-';
            if (!empty($ctx['showscores']) && $d && $d['grade'] !== null) {
                $score = s(format_float((float) $d['grade'], 2) . ' / ' . format_float((float) $d['grademax'], 2));
            } else if ($d) {
                $score = self::t('gsource_' . $d['gradestate']);
            }
            $row = new html_table_row([$h, $d ? s($d['resourcelinkid']) : (string) (int) $p['linkid'],
                $d && $d['lineitem'] !== '' ? s($d['lineitem']) : self::t('gpv_lineitem_none'), $score,
                $d ? self::t('gmap_' . ($d['mapping'] ?: 'unknown')) : '-', $state]);
            $row->attributes['data-target'] = $id;
            $row->attributes['data-ready'] = (!$p['blockers'] && empty($p['open'])) ? '1' : '0';
            $rows[] = $row;
        }
        $table = new html_table();
        $table->id = 'tool_ltidiagnostics_bulk_preview';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = self::t('gbulk_title');
        $table->head = [self::t('gperson'), self::t('glink'), self::t('gpv_lineitem'), self::t('gpv_score'),
            self::t('gmapping'), self::t('gbulk_state')];
        $table->data = $rows;
        $o .= html_writer::div(html_writer::table($table), 'table-responsive');
        $o .= html_writer::tag(
            'p',
            self::t('gbulk_effect', (object) ['ready' => $ready, 'skipped' => count($previews) - $ready]),
            ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_bulk_effect', 'data-ready' => $ready]
        );
        return $o;
    }

    /**
     * Status of a confirmed selection: every attempt with its own state and booking.
     *
     * @param \stdClass[] $rows Journal rows of the batch.
     * @param array $ctx key, showscores.
     * @return string
     */
    public function batch_status(array $rows, array $ctx): string {
        if (!$rows) {
            return html_writer::tag('p', self::t('gbatch_none'), ['class' => 'alert alert-info']);
        }
        $counts = [];
        foreach ($rows as $j) {
            $counts[$j->state] = ($counts[$j->state] ?? 0) + 1;
        }
        $items = [];
        foreach ($counts as $state => $n) {
            $items[] = self::t('gstate_' . $state) . ': ' . $n;
        }
        $o = html_writer::tag('p', s(implode(' · ', $items)), ['id' => 'tool_ltidiagnostics_batch_counts']);
        $o .= html_writer::tag('p', self::t('gbatch_note'), ['class' => 'small text-muted']);
        return $o . $this->journal($rows, $ctx + ['tableid' => 'tool_ltidiagnostics_batch']);
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
        $add(self::t('gorigin'), self::t('gorigin_try', (object) ['origin' => self::label('gorigin_', $row->origin),
            'no' => (int) $row->attemptno]));
        $add(self::t('gstate'), self::t('gstate_' . $row->state) . ((int) $row->httpstatus ? ' (' . self::t(
            'ghttpstatus',
            (int) $row->httpstatus
        ) . ')' : '') . ($row->errorclass !== '' ? ' - ' . self::label('gerr_', $row->errorclass) : ''));
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
                $items[] = self::t('gtry', (int) $c->attemptno) . ' ' . self::t('gstate_' . $c->state) . ' ' .
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
     * The only output of the completion tab without a working local_lticompletionsync (issues #17, #24): one short
     * note, no check, no action, no transfer page, no invented link. The grade channel stays fully usable.
     *
     * @param string $state State of the plugin (absent, codeonly, needsupgrade, incomplete).
     * @return string
     */
    public function completion_unavailable(string $state): string {
        return html_writer::tag('p', self::t('compl_state_' . $state), ['class' => 'alert alert-secondary',
            'id' => 'tool_ltidiagnostics_compl_unavailable', 'data-state' => $state]);
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
        $names = \tool_ltidiagnostics\local\identity::can_see_names();
        if ($names) {
            \tool_ltidiagnostics\local\identity::preload(array_map(fn($r) => (int) ($r['userid'] ?? 0), $v['rows']));
        }
        foreach ($v['rows'] as $r) {
            $h = new html_table_cell($names && !empty($r['userid']) ? \tool_ltidiagnostics\local\identity::person_html(
                (string) $pageurl->param('key'),
                (int) $r['userid']
            ) : s($r['person']));
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
