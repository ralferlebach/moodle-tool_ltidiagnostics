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

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\finding;
use tool_ltidiagnostics\local\redactor;
use tool_ltidiagnostics\local\status;
use tool_ltidiagnostics\local\timeline;

/**
 * Renderer of tool_ltidiagnostics. Builds template contexts and renders them.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Bootstrap badge classes for a traffic light colour. Colour never carries meaning alone: the label is always shown.
     *
     * @param string $light green, amber, red or grey
     * @return string
     */
    public static function badge_class(string $light): string {
        // The text colour is set explicitly: Moodle 4.5 (Bootstrap 4) and 5.x (Bootstrap 5) differ in the default badge colour.
        $map = ['green' => 'bg-success text-white', 'amber' => 'bg-warning text-dark', 'red' => 'bg-danger text-white',
            'grey' => 'bg-secondary text-dark'];
        return $map[$light] ?? 'bg-secondary text-dark';
    }

    /**
     * The connection list.
     *
     * @param connection[] $connections Connections.
     * @param bool $reveal True when identifiers may be shown.
     * @return string
     */
    public function connection_list(array $connections, bool $reveal): string {
        $rows = [];
        foreach ($connections as $c) {
            $services = [];
            if ($c->role === connection::ROLE_PLATFORM) {
                if (!empty($c->facts['ags'])) {
                    $services[] = get_string('feature_ags', 'tool_ltidiagnostics');
                }
                if (!empty($c->facts['nrps'])) {
                    $services[] = get_string('feature_nrps', 'tool_ltidiagnostics');
                }
                if (!empty($c->facts['deeplinking'])) {
                    $services[] = get_string('feature_deeplinking', 'tool_ltidiagnostics');
                }
            } else {
                $services[] = get_string('toolcount', 'tool_ltidiagnostics', count($c->tools));
            }
            $rows[] = [
                'name' => $c->name,
                'role' => get_string('role_' . $c->role, 'tool_ltidiagnostics'),
                'issuer' => redactor::url($c->issuer, $reveal),
                'clientid' => redactor::identifier($c->clientid, $reveal),
                'deploymentid' => redactor::identifier($c->deploymentid, $reveal),
                'state' => $c->state,
                'services' => implode(', ', $services),
                'url' => (new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $c->key]))->out(false),
                'actionlabel' => get_string('open', 'tool_ltidiagnostics'),
            ];
        }
        return $this->render_from_template('tool_ltidiagnostics/connection_list', [
            'caption' => get_string('connections', 'tool_ltidiagnostics'),
            'empty' => $rows ? '' : get_string('noconnections', 'tool_ltidiagnostics'),
            'headers' => [
                'name' => get_string('name'), 'role' => get_string('role', 'tool_ltidiagnostics'),
                'issuer' => get_string('issuer', 'tool_ltidiagnostics'), 'client' => get_string('clientid', 'tool_ltidiagnostics'),
                'deployment' => get_string('deploymentid', 'tool_ltidiagnostics'),
                'state' => get_string('state', 'tool_ltidiagnostics'), 'services' => get_string('services', 'tool_ltidiagnostics'),
                'action' => get_string('action', 'tool_ltidiagnostics'),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * The capability matrix.
     *
     * @param array $matrix Rows from the matrix builder.
     * @return string
     */
    public function matrix(array $matrix): string {
        $rows = [];
        foreach ($matrix as $feature => $row) {
            $steps = [];
            foreach ($row['ladder'] as $step => $ev) {
                $steps[] = [
                    'step' => $step,
                    'stepname' => get_string('step_' . $step, 'tool_ltidiagnostics'),
                    'state' => get_string('state_' . $ev->state, 'tool_ltidiagnostics'),
                    'kind' => get_string('kind_' . $ev->kind, 'tool_ltidiagnostics'),
                    'detail' => $ev->detailkey !== '' ? get_string($ev->detailkey, 'tool_ltidiagnostics', $ev->detailparam) : '',
                    'time' => $ev->time ? userdate($ev->time) : '',
                ];
            }
            $rows[] = [
                'feature' => $feature,
                'status' => $row['status'],
                'label' => get_string('feature_' . $feature, 'tool_ltidiagnostics'),
                'statuslabel' => get_string('status_' . $row['status'], 'tool_ltidiagnostics'),
                'badge' => self::badge_class($row['light']),
                'reason' => get_string('reason_' . $row['reason'], 'tool_ltidiagnostics'),
                'next' => get_string('next_' . $row['next'], 'tool_ltidiagnostics'),
                'notes' => array_map(function ($n) {
                    return ['text' => get_string('note_' . $n, 'tool_ltidiagnostics')];
                }, $row['notes']),
                'steps' => $steps,
            ];
        }
        return $this->render_from_template('tool_ltidiagnostics/matrix', [
            'caption' => get_string('matrix', 'tool_ltidiagnostics'),
            'headers' => [
                'feature' => get_string('feature', 'tool_ltidiagnostics'), 'status' => get_string('status', 'tool_ltidiagnostics'),
                'reason' => get_string('reasoncol', 'tool_ltidiagnostics'), 'next' => get_string('nextstep', 'tool_ltidiagnostics'),
                'ladder' => get_string('ladder', 'tool_ltidiagnostics'), 'step' => get_string('step', 'tool_ltidiagnostics'),
                'state' => get_string('state', 'tool_ltidiagnostics'), 'kind' => get_string('evidence', 'tool_ltidiagnostics'),
                'detail' => get_string('detail', 'tool_ltidiagnostics'),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * The health check result.
     *
     * @param array $result Result of health_check::evaluate().
     * @return string
     */
    public function healthcheck(array $result): string {
        $progress = [];
        foreach ($result['progress'] as $p) {
            $progress[] = [
                'label' => get_string($p['step'], 'tool_ltidiagnostics'),
                'done' => $p['done'],
                'statelabel' => get_string($p['done'] ? 'progress_done' : 'progress_skipped', 'tool_ltidiagnostics'),
            ];
        }
        $layers = [];
        foreach ($result['layers'] as $layer => $info) {
            $layers[] = [
                'layer' => $layer,
                'light' => $info['light'],
                'label' => get_string('layer_' . $layer, 'tool_ltidiagnostics'),
                'badge' => self::badge_class($info['light']),
                'lightlabel' => get_string('light_' . $info['light'], 'tool_ltidiagnostics'),
            ];
        }
        $buckets = [];
        $root = null;
        foreach ($result['findings'] as $f) {
            $str = $f->strings();
            $entry = $this->finding_context($f, $str);
            if (!empty($result['rootcause']) && $result['rootcause'] === $f) {
                $root = $entry;
            }
            $buckets[$f->severity][] = $entry;
        }
        $groups = [];
        foreach ([finding::BLOCKING, finding::WARNING, finding::NOTCHECKED, finding::INFO, finding::OK] as $sev) {
            if (!empty($buckets[$sev])) {
                $groups[] = ['heading' => get_string('severity_' . $sev, 'tool_ltidiagnostics'), 'findings' => $buckets[$sev]];
            }
        }
        return $this->render_from_template('tool_ltidiagnostics/healthcheck', [
            'labels' => [
                'intervention' => get_string('intervention', 'tool_ltidiagnostics'),
                'risk' => get_string('risk', 'tool_ltidiagnostics'),
                'verification' => get_string('verification', 'tool_ltidiagnostics'),
                'where' => get_string('wherelabel', 'tool_ltidiagnostics'),
                'alt' => get_string('alternativecause', 'tool_ltidiagnostics'),
                'uncertain' => get_string('uncertainlabel', 'tool_ltidiagnostics'),
                'responsible' => get_string('responsible', 'tool_ltidiagnostics'),
                'consequence' => get_string('consequencenote', 'tool_ltidiagnostics'),
            ],
            'headings' => [
                'rootcause' => get_string('hc_rootcause', 'tool_ltidiagnostics'),
                'observability' => get_string('obs_heading', 'tool_ltidiagnostics'),
                'progress' => get_string('hc_progress', 'tool_ltidiagnostics'),
                'layers' => get_string('hc_layers', 'tool_ltidiagnostics'),
                'findings' => get_string('hc_findings', 'tool_ltidiagnostics'),
                'nofindings' => get_string('hc_nofindings', 'tool_ltidiagnostics'),
            ],
            'progress' => $progress,
            'layers' => $layers,
            'rootcause' => $root,
            'norootcause' => $root === null ? get_string('hc_norootcause', 'tool_ltidiagnostics') : '',
            'groups' => $groups,
            'observability' => $this->observability_table($result['observability'] ?? []),
        ]);
    }

    /**
     * Template context of one finding.
     *
     * @param finding $f Finding.
     * @param array $str Strings of the finding.
     * @return array
     */
    private function finding_context(finding $f, array $str): array {
        return [
            'severity' => $f->severity,
            'severitylabel' => get_string('severity_' . $f->severity, 'tool_ltidiagnostics'),
            'testid' => $f->testid,
            'title' => $str['title'],
            'layer' => get_string('layer_' . $f->layer, 'tool_ltidiagnostics'),
            'responsible' => get_string('responsible_' . $f->responsible, 'tool_ltidiagnostics'),
            'detail' => $str['detail'],
            'fix' => $str['fix'],
            'risklabel' => get_string('risk_' . $f->risk, 'tool_ltidiagnostics'),
            'verify' => $str['verify'],
            'where' => $str['where'],
            'uncertain' => $str['uncertain'],
            'alt' => $str['alt'],
            'consequence' => $f->consequence,
            'evidence' => get_string('kind_' . $f->evidencekind, 'tool_ltidiagnostics')
                . ($f->confidence !== '' ? ' / ' . $f->confidence : ''),
            'system' => $f->system,
            'systemlabel' => get_string('system_' . $f->system, 'tool_ltidiagnostics'),
            'certainty' => $f->certainty,
            'certaintylabel' => get_string('certainty_' . $f->certainty, 'tool_ltidiagnostics'),
            'check' => $f->diagnosis()['check'],
        ];
    }

    /**
     * Messages tab (DIA-02): status of every data direction and protocol message, the end-to-end chains and the field
     * matrix of the launch message.
     *
     * @param array $result Result of health_check::evaluate().
     * @return string
     */
    public function messages_tab(array $result): string {
        $t = function (string $id, $a = null): string {
            return get_string($id, 'tool_ltidiagnostics', $a);
        };
        $out = \html_writer::tag('p', $t('msg_intro'), ['class' => 'text-muted']);
        if (!empty($result['partner'])) {
            $out .= \html_writer::tag('p', $t('msg_partnerinfo', (object) [
                'id' => s($result['partner']['diagnosticid']),
                'generated' => s($result['partner']['generated']),
            ]), ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_partnerinfo']);
        }
        $rows = [];
        foreach ($result['messages'] ?? [] as $m) {
            $head = new \html_table_cell($t('msg_' . $m['id']));
            $head->header = true;
            $head->scope = 'row';
            $observed = $t('obslevel_' . $m['observed']) . ($m['seen'] ? ' - ' . userdate($m['seen']) : '');
            if ($m['transportonly']) {
                $observed .= ' ' . $t('msg_transportonly');
            }
            if ($m['paged'] > 0) {
                $observed .= ' ' . $t('msg_paged', $m['paged']);
            }
            $light = self::state_light($m['state']);
            $state = \html_writer::span($t('msgstate_' . $m['state']), 'badge ' . self::badge_class($light));
            if ($m['limit'] !== '') {
                $state .= ' ' . \html_writer::span($t('msglimit_' . $m['limit']), 'small text-muted');
            }
            if ($m['missingscopes']) {
                $state .= ' ' . \html_writer::span($t('msg_missingscopes', s(implode(', ', $m['missingscopes']))), 'small');
            }
            $row = new \html_table_row([$head, $t('msgdir_' . $m['direction']), $t('msgconf_' . $m['configured']), $observed,
                $m['verified'] ? $t('yes') : $t('no'), $t('msgbooked_' . $m['booked']), $state]);
            $row->attributes['data-message'] = $m['id'];
            $row->attributes['data-state'] = $m['state'];
            $row->attributes['data-verified'] = $m['verified'] ? '1' : '0';
            $row->attributes['data-booked'] = $m['booked'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_messages';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('report_messages');
        $table->captionhide = true;
        $table->head = [$t('msg_message'), $t('direction'), $t('msg_configured'), $t('msg_observed'), $t('msg_verified'),
            $t('msg_booked'), $t('msg_state')];
        $table->data = $rows;
        $out .= $this->heading($t('report_messages'), 3) . \html_writer::div(\html_writer::table($table), 'table-responsive');

        $items = '';
        foreach ($result['chains'] ?? [] as $ch) {
            $text = \html_writer::span($t('verdict_' . $ch['verdict']), 'badge '
                . self::badge_class($ch['verdict'] === 'end_to_end' ? 'green' : ($ch['verdict'] === 'failed' ? 'red'
                : ($ch['verdict'] === 'not_configured' ? 'grey' : 'amber')))) . ' ' . $t('chain_' . $ch['id']);
            if ($ch['weakest'] !== '') {
                $text .= ' - ' . $t('msg_' . $ch['weakest']) . ': ' . $t('chainreason_' . $ch['reason']);
            }
            $items .= \html_writer::tag('li', $text, ['data-chain' => $ch['id'], 'data-verdict' => $ch['verdict']]);
        }
        $out .= $this->heading($t('report_chains'), 3) . \html_writer::tag('p', $t('chain_intro'), ['class' => 'text-muted'])
            . \html_writer::tag('ul', $items, ['id' => 'tool_ltidiagnostics_chains', 'class' => 'list-unstyled']);

        $rows = [];
        foreach ($result['fields'] ?? [] as $f) {
            $head = new \html_table_cell(s($f['field']));
            $head->header = true;
            $head->scope = 'row';
            $row = new \html_table_row([$head, $t('fieldcat_' . $f['category']), $t('fieldstate_' . $f['local']),
                $f['partner'] !== '' ? $t('fieldstate_' . $f['partner']) : $t('field_nopartner'),
                $t('fieldagree_' . $f['agreement'])]);
            $row->attributes['data-field'] = $f['field'];
            $row->attributes['data-local'] = $f['local'];
            $row->attributes['data-agreement'] = $f['agreement'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_fields';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('report_fields');
        $table->captionhide = true;
        $table->head = [$t('field'), $t('fieldcategory'), $t('field_local'), $t('field_partner'), $t('field_agreement')];
        $table->data = $rows;
        $out .= $this->heading($t('report_fields'), 3) . \html_writer::tag('p', $t('field_intro'), ['class' => 'text-muted'])
            . \html_writer::div(\html_writer::table($table), 'table-responsive');
        return $out;
    }

    /**
     * Findings as a table with the four parts of a diagnosis: cause, uncertainty, responsible system, safe check.
     *
     * @param \tool_ltidiagnostics\local\finding[] $findings Findings.
     * @param string $id Table id.
     * @return string
     */
    public function findings_table(array $findings, string $id): string {
        $t = function (string $sid, $a = null): string {
            return get_string($sid, 'tool_ltidiagnostics', $a);
        };
        $rows = [];
        foreach ($findings as $f) {
            $d = $f->diagnosis();
            $head = new \html_table_cell(\html_writer::span($t('severity_' . $f->severity), 'badge '
                . self::badge_class($f->severity === 'ok' ? 'green' : ($f->severity === 'blocking' ? 'red' : 'amber')))
                . ' ' . s($d['cause']));
            $head->header = true;
            $head->scope = 'row';
            $row = new \html_table_row([$head, s($d['detail']), $t('system_' . $d['system']), $t('certainty_' . $d['certainty'])
                . ' - ' . s($d['uncertainty']), s($d['check'])]);
            $row->attributes['data-code'] = $f->code;
            $row->attributes['data-system'] = $f->system;
            $row->attributes['data-certainty'] = $f->certainty;
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = $id;
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('hc_findings');
        $table->captionhide = true;
        $table->head = [$t('diag_cause'), $t('detail'), $t('systemlabel'), $t('uncertainlabel'), $t('checklabel')];
        $table->data = $rows;
        return \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * Traffic light of a message state.
     *
     * @param string $state State.
     * @return string green, amber, red or grey
     */
    public static function state_light(string $state): string {
        $map = ['booked' => 'green', 'verified' => 'green', 'observed' => 'amber', 'configured' => 'amber',
            'failed' => 'red', 'not_configured' => 'grey', 'not_observable' => 'grey'];
        return $map[$state] ?? 'grey';
    }

    /**
     * Completion sync transfer report (DIA-05).
     *
     * @param array $report Result of transfer_report::build().
     * @return string
     */
    public function transfers(array $report): string {
        $t = function (string $id, $a = null): string {
            return get_string($id, 'tool_ltidiagnostics', $a);
        };
        $out = \html_writer::tag('p', $t('transfers_intro'), ['class' => 'text-muted']);
        $mode = $t('transfers_mode_' . $report['mode']);
        $out .= \html_writer::tag('p', $t('transfers_side') . ': ' . $t('transfers_side_' . $report['side']) . ' &middot; '
            . $t('transfers_mode') . ': ' . $mode, ['id' => 'tool_ltidiagnostics_transfermode', 'data-mode' => $report['mode']]);
        foreach ($report['limits'] as $limit) {
            $out .= \html_writer::tag('p', $t('transfers_limit_' . $limit), ['class' => 'alert alert-warning',
                'data-limit' => $limit]);
        }
        $rows = [];
        foreach ($report['transactions'] as $tx) {
            $head = new \html_table_cell(s($tx['correlationid']));
            $head->header = true;
            $head->scope = 'row';
            $cells = [$head, s($tx['person'])];
            foreach (\tool_ltidiagnostics\local\transfer_report::STAGES as $stage) {
                $st = $tx['stages'][$stage];
                $text = $t('transfers_state_' . $st['state']);
                if ($st['local'] !== null) {
                    $text .= ' ' . userdate($st['local']['time'], get_string('strftimetime', 'langconfig'))
                        . ($st['local']['code'] !== '' ? ' (' . s($st['local']['code']) . ')' : '');
                    if (!empty($st['local']['native'])) {
                        $text .= ' ' . $t('transfers_native_' . $st['local']['native']);
                    }
                }
                if ($st['partner'] !== null) {
                    $text .= ' / ' . $t('transfers_partnerseen');
                }
                $cell = new \html_table_cell($text);
                $cell->attributes['data-stage'] = $stage;
                $cell->attributes['data-state'] = $st['state'];
                $cells[] = $cell;
            }
            $cells[] = \html_writer::span($t('transfers_verdict_' . $tx['verdict']), 'badge ' . self::badge_class(
                $tx['verdict'] === 'complete' ? 'green' : ($tx['verdict'] === 'failed' ? 'red' : 'amber')
            ));
            $row = new \html_table_row($cells);
            $row->attributes['data-correlationid'] = $tx['correlationid'];
            $row->attributes['data-verdict'] = $tx['verdict'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_transfers';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('transfers_title');
        $table->captionhide = true;
        $table->head = array_merge([$t('transfers_correlation'), $t('transfers_person')], array_map(function ($stage) use ($t) {
            return $t('transfers_stage_' . $stage);
        }, \tool_ltidiagnostics\local\transfer_report::STAGES), [$t('transfers_verdict')]);
        $table->data = $rows;
        if (!$rows) {
            return $out . \html_writer::tag('p', $t('transfers_none'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_notransfers']);
        }
        return $out . \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * The observability table: for each transaction and direction, how much of the request and the response this system
     * can see, and whether anything was seen at all.
     *
     * @param array $rows Result of observability::build().
     * @return string
     */
    public function observability_table(array $rows): string {
        if (!$rows) {
            return '';
        }
        $tr = [];
        foreach (\tool_ltidiagnostics\local\report::observability_rows($rows) as $row) {
            $cells = [];
            foreach (['request', 'response'] as $part) {
                $cell = $row[$part];
                $text = get_string('obslevel_' . $cell['level'], 'tool_ltidiagnostics') . ' - '
                    . ($cell['seen'] !== '' ? get_string('obs_seenat', 'tool_ltidiagnostics', $cell['seen'])
                        : get_string('obs_notseen', 'tool_ltidiagnostics'));
                if ($cell['needs']) {
                    $text .= ' ' . get_string('obs_needs', 'tool_ltidiagnostics', implode(', ', array_map(function ($n) {
                        return get_string('obsneeds_' . $n, 'tool_ltidiagnostics');
                    }, $cell['needs'])));
                }
                $cells[$part] = new \html_table_cell($text);
                $cells[$part]->attributes['data-level'] = $cell['level'];
            }
            $head = new \html_table_cell($row['label']);
            $head->header = true;
            $head->scope = 'row';
            $tr[] = new \html_table_row([$head, get_string('obsdir_' . $row['direction'], 'tool_ltidiagnostics'),
                $cells['request'], $cells['response']]);
            $tr[count($tr) - 1]->attributes['data-transaction'] = $row['transaction'];
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_observability';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('obs_heading', 'tool_ltidiagnostics');
        $table->captionhide = true;
        $table->head = [get_string('obs_transaction', 'tool_ltidiagnostics'), get_string('direction', 'tool_ltidiagnostics'),
            get_string('obs_request', 'tool_ltidiagnostics'), get_string('obs_response', 'tool_ltidiagnostics')];
        $table->data = $tr;
        return \html_writer::tag('p', get_string('obs_intro', 'tool_ltidiagnostics'), ['class' => 'text-muted'])
            . \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * The timeline.
     *
     * @param array $tl Result of timeline::build().
     * @return string
     */
    public function timeline(array $tl): string {
        $links = [];
        foreach ($tl['edges'] as $e) {
            $links[$e['from']][] = ['text' => get_string('stage_' . $tl['items'][$e['to']]['stage'], 'tool_ltidiagnostics')
                . ' (' . $e['confidence'] . ')'];
        }
        $rows = [];
        foreach ($tl['items'] as $i => $item) {
            $rows[] = [
                'time' => userdate($item['time'], '%Y-%m-%d %H:%M:%S', 'UTC'),
                'stagecode' => $item['stage'],
                'stage' => get_string('stage_' . $item['stage'], 'tool_ltidiagnostics'),
                'sourcecode' => $item['source'],
                'source' => get_string('source_' . $item['source'], 'tool_ltidiagnostics'),
                'event' => redactor::text($item['label']),
                'links' => $links[$i] ?? [],
            ];
        }
        $sources = [];
        foreach (['observer', 'logstore', 'accesslog'] as $name) {
            $available = !empty($tl['sources'][$name]);
            $sources[] = [
                'label' => get_string('source_' . $name, 'tool_ltidiagnostics'),
                'available' => $available,
                'statelabel' => get_string($available ? 'available' : 'unavailable', 'tool_ltidiagnostics'),
            ];
        }
        $coverage = [];
        foreach ($tl['coverage'] ?? [] as $name => $cov) {
            $coverage[] = [
                'label' => get_string('source_' . $name, 'tool_ltidiagnostics'),
                'count' => $cov['count'],
                'first' => $cov['first'] ? userdate($cov['first'], '%Y-%m-%d %H:%M:%S', 'UTC') : '-',
                'last' => $cov['last'] ? userdate($cov['last'], '%Y-%m-%d %H:%M:%S', 'UTC') : '-',
                'note' => get_string(
                    $cov['incomplete'] ? 'cov_incomplete' : ($cov['count'] ? 'cov_ok' : 'cov_empty'),
                    'tool_ltidiagnostics'
                ),
                'incomplete' => $cov['incomplete'],
            ];
        }
        $transactions = [];
        foreach ($tl['transactions'] ?? [] as $t) {
            $transactions[] = [
                // Plain values: the template escapes them.
                'correlationid' => $t['correlationid'],
                'component' => $t['component'],
                'connection' => $t['connkey'] !== '' ? $t['connkey'] : get_string('fam_connunknown', 'tool_ltidiagnostics'),
                'first' => userdate($t['first'], '%Y-%m-%d %H:%M:%S', 'UTC'),
                'last' => userdate($t['last'], '%Y-%m-%d %H:%M:%S', 'UTC'),
                'steps' => implode(' > ', $t['steps']),
                'outcomecode' => $t['outcome'],
                'outcome' => get_string('fam_outcome_' . $t['outcome'], 'tool_ltidiagnostics'),
            ];
        }
        return $this->render_from_template('tool_ltidiagnostics/timeline', [
            'hastransactions' => !empty($transactions),
            'transactions' => $transactions,
            'txheaders' => [
                'caption' => get_string('fam_caption', 'tool_ltidiagnostics'),
                'correlationid' => get_string('fam_correlationid', 'tool_ltidiagnostics'),
                'component' => get_string('fam_component', 'tool_ltidiagnostics'),
                'connection' => get_string('fam_connection', 'tool_ltidiagnostics'),
                'first' => get_string('cov_first', 'tool_ltidiagnostics'),
                'last' => get_string('cov_last', 'tool_ltidiagnostics'),
                'steps' => get_string('fam_steps', 'tool_ltidiagnostics'),
                'outcome' => get_string('fam_outcome', 'tool_ltidiagnostics'),
            ],
            'txintro' => get_string('fam_intro', 'tool_ltidiagnostics'),
            'covheaders' => [
                'caption' => get_string('cov_caption', 'tool_ltidiagnostics'),
                'source' => get_string('source', 'tool_ltidiagnostics'),
                'count' => get_string('cov_count', 'tool_ltidiagnostics'),
                'first' => get_string('cov_first', 'tool_ltidiagnostics'),
                'last' => get_string('cov_last', 'tool_ltidiagnostics'), 'note' => get_string('cov_note', 'tool_ltidiagnostics'),
            ],
            'coverage' => $coverage,
            'hascoverage' => !empty($coverage),
            'covintro' => get_string('cov_intro', 'tool_ltidiagnostics'),
            'caption' => get_string('timeline', 'tool_ltidiagnostics'),
            'headers' => [
                'time' => get_string('timeutc', 'tool_ltidiagnostics'), 'stage' => get_string('stage', 'tool_ltidiagnostics'),
                'source' => get_string('source', 'tool_ltidiagnostics'), 'event' => get_string('event', 'tool_ltidiagnostics'),
                'link' => get_string('correlatedwith', 'tool_ltidiagnostics'),
            ],
            'empty' => $rows ? '' : get_string('notimeline', 'tool_ltidiagnostics'),
            'rows' => $rows,
            'sources' => $sources,
        ]);
    }

    /**
     * The evidence tab: every source on its own, never merged into one verdict.
     *
     * @param connection $c Connection.
     * @param array $filters Filters.
     * @param int $now Time.
     * @return string
     */
    public function evidence_tab(connection $c, array $filters, int $now): string {
        $out = '';
        $context = \context_system::instance();

        // How much of each transaction can be seen at all (separate from what was seen).
        $facts = (new \tool_ltidiagnostics\local\fact_collector())->collect($c, $now, $filters);
        global $DB;
        $imports = (int) $DB->count_records('tool_ltidiagnostics_imp', ['status' => 'done']);
        $out .= $this->heading(get_string('obs_heading', 'tool_ltidiagnostics'), 3);
        $out .= $this->observability_table(\tool_ltidiagnostics\local\observability::build(
            $c,
            $facts,
            \tool_ltidiagnostics\local\snapshot::latest_probes($c->key),
            \tool_ltidiagnostics\local\observability::enabled_sources($imports)
        ));

        // Observer rows. No user column: the evidence needs the event, not the person.
        $out .= $this->heading(get_string('ev_observer', 'tool_ltidiagnostics'), 3);
        $rows = \tool_ltidiagnostics\local\observation_store::get_for_connection($c, $filters + ['limit' => 100]);
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_observer', 'tool_ltidiagnostics');
        $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('eventtype', 'tool_ltidiagnostics'),
            get_string('direction', 'tool_ltidiagnostics'), get_string('event', 'tool_ltidiagnostics'),
            get_string('entrypoint', 'tool_ltidiagnostics'), get_string('courseid', 'tool_ltidiagnostics')];
        foreach ($rows as $o) {
            $table->data[] = [userdate($o->timecreated, '%Y-%m-%d %H:%M:%S', 'UTC'), s($o->eventtype), s($o->direction),
                s($o->eventname), s($o->entrypoint), (int) $o->courseid ?: ''];
        }
        $out .= $rows ? \html_writer::table($table) : \html_writer::tag('p', get_string('norows', 'tool_ltidiagnostics'));

        // Logstore.
        $out .= $this->heading(get_string('ev_logstore', 'tool_ltidiagnostics'), 3);
        $log = (new \tool_ltidiagnostics\local\logstore_source())->fetch($context, $filters + ['limit' => 50]);
        if (!$log['available']) {
            $out .= $this->notification(
                get_string('logstore_' . $log['reason'], 'tool_ltidiagnostics'),
                \core\output\notification::NOTIFY_WARNING
            );
        } else {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable table-sm';
            $table->caption = get_string('ev_logstore', 'tool_ltidiagnostics');
            $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('event', 'tool_ltidiagnostics'),
                get_string('courseid', 'tool_ltidiagnostics')];
            foreach ($log['rows'] as $r) {
                $table->data[] = [userdate($r['time'], '%Y-%m-%d %H:%M:%S', 'UTC'), s($r['eventname']), $r['courseid'] ?: ''];
            }
            $out .= $log['rows'] ? \html_writer::table($table) : \html_writer::tag('p', get_string(
                'norows',
                'tool_ltidiagnostics'
            ));
        }

        // Transfer events of LTI family plugins (for example completion sync), with their correlation id.
        $out .= $this->heading(get_string('ev_family', 'tool_ltidiagnostics'), 3);
        $familyrows = [];
        foreach ($log['rows'] as $r) {
            if (!empty($r['family']) && ($r['connkey'] === '' || $r['connkey'] === $c->key)) {
                $familyrows[] = $r;
            }
        }
        if (!$log['available']) {
            $out .= \html_writer::tag('p', get_string('fam_nolog', 'tool_ltidiagnostics'));
        } else if (!$familyrows) {
            $out .= \html_writer::tag('p', get_string(
                \tool_ltidiagnostics\local\family_events::emitter_installed() ? 'fam_none' : 'fam_noemitter',
                'tool_ltidiagnostics'
            ));
        } else {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable table-sm';
            $table->id = 'tool_ltidiagnostics_familyevents';
            $table->caption = get_string('ev_family', 'tool_ltidiagnostics');
            $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('fam_step', 'tool_ltidiagnostics'),
                get_string('fam_component', 'tool_ltidiagnostics'), get_string('fam_correlationid', 'tool_ltidiagnostics'),
                get_string('fam_connection', 'tool_ltidiagnostics'), get_string('fam_code', 'tool_ltidiagnostics')];
            foreach ($familyrows as $r) {
                $table->data[] = [userdate($r['time'], '%Y-%m-%d %H:%M:%S', 'UTC'), s($r['step']), s($r['component']),
                    s($r['correlationid']), $r['connkey'] !== '' ? s($r['connkey']) :
                    get_string('fam_connunknown', 'tool_ltidiagnostics'), s($r['code'])];
            }
            $out .= \html_writer::table($table);
        }

        // Tasks.
        $out .= $this->heading(get_string('ev_tasks', 'tool_ltidiagnostics'), 3);
        $tasks = new \tool_ltidiagnostics\local\task_source();
        $cron = $tasks->cron_status($now);
        $out .= \html_writer::tag('p', get_string(
            'cron_' . $cron['status'],
            'tool_ltidiagnostics',
            $cron['age'] >= 0 ? (int) floor($cron['age'] / 60) : 0
        ));
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_tasks', 'tool_ltidiagnostics');
        $table->head = [get_string('task', 'tool_ltidiagnostics'), get_string('lastrun', 'tool_ltidiagnostics'),
            get_string('nextrun', 'tool_ltidiagnostics'), get_string('state', 'tool_ltidiagnostics')];
        foreach ($tasks->scheduled($now) as $t) {
            $state = $t['disabled'] ? get_string('disabled', 'tool_ltidiagnostics') : ($t['faildelay'] > 0 ?
                get_string('failing', 'tool_ltidiagnostics') : ($t['overdue'] ? get_string('overdue', 'tool_ltidiagnostics') :
                get_string('ok', 'tool_ltidiagnostics')));
            $table->data[] = [s($t['classname']), $t['lastrun'] ? userdate($t['lastrun']) : '-', $t['nextrun'] ?
                userdate($t['nextrun']) : '-', $state];
        }
        foreach ($tasks->adhoc() as $t) {
            $table->data[] = [s($t['classname']) . ' (' . get_string('adhoc', 'tool_ltidiagnostics') . ')', '-', '-',
                get_string('adhocsummary', 'tool_ltidiagnostics', (object) $t)];
        }
        $out .= $table->data ? \html_writer::table($table) : \html_writer::tag('p', get_string('norows', 'tool_ltidiagnostics'));
        $tl = $tasks->task_log(['limit' => 20, 'from' => $filters['from'] ?? 0, 'to' => $filters['to'] ?? 0]);
        if ($tl) {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable table-sm';
            $table->caption = get_string('ev_tasklog', 'tool_ltidiagnostics');
            $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('task', 'tool_ltidiagnostics'),
                get_string('result', 'tool_ltidiagnostics')];
            foreach ($tl as $row) {
                $table->data[] = [userdate($row['start'], '%Y-%m-%d %H:%M:%S', 'UTC'), s($row['classname']),
                    get_string($row['failed'] ? 'failed' : 'ok', 'tool_ltidiagnostics')];
            }
            $out .= \html_writer::table($table);
        }

        // Imported logs.
        $out .= $this->heading(get_string('ev_imports', 'tool_ltidiagnostics'), 3);
        $imports = $DB->get_records('tool_ltidiagnostics_imp', null, 'timecreated DESC, id DESC', '*', 0, 10);
        if (!$imports) {
            $out .= \html_writer::tag('p', get_string('noimports', 'tool_ltidiagnostics'));
        } else {
            $out .= $this->imports_table($imports);
        }
        $out .= \html_writer::tag('p', get_string('limit_absencenotevidence', 'tool_ltidiagnostics'), ['class' => 'text-muted']);
        return $out;
    }

    /**
     * Table of import jobs.
     *
     * @param \stdClass[] $imports Import records.
     * @param bool $candelete True to add a delete link to every row (import page only).
     * @return string
     */
    public function imports_table(array $imports, bool $candelete = false): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_imports', 'tool_ltidiagnostics');
        $table->head = [get_string('time'), get_string('logformat', 'tool_ltidiagnostics'),
            get_string('source', 'tool_ltidiagnostics'),
            get_string('logtimezone', 'tool_ltidiagnostics'), get_string('range', 'tool_ltidiagnostics'),
            get_string('lines', 'tool_ltidiagnostics'), get_string('state', 'tool_ltidiagnostics')];
        if ($candelete) {
            $table->head[] = get_string('action', 'tool_ltidiagnostics');
        }
        foreach ($imports as $i) {
            $range = $i->rangefrom ? userdate($i->rangefrom, '%Y-%m-%d %H:%M', 'UTC') . ' - ' . userdate(
                $i->rangeto,
                '%Y-%m-%d %H:%M',
                'UTC'
            ) . ' UTC' : '-';
            $row = [userdate($i->timecreated), s($i->kind . ':' . $i->format), s($i->sourcename), s($i->timezone),
                $range, get_string('linessummary', 'tool_ltidiagnostics', $i), s($i->status . ($i->errormessage !== '' ?
                    ' (' . $i->errormessage . ')' : ''))];
            if ($candelete) {
                $row[] = \html_writer::link(
                    new \moodle_url('/admin/tool/ltidiagnostics/logimport.php', ['delete' => $i->id, 'sesskey' => sesskey()]),
                    get_string('deleteimport', 'tool_ltidiagnostics'),
                    ['aria-label' => get_string('deleteimport_aria', 'tool_ltidiagnostics', $i->id)]
                );
            }
            $table->data[] = $row;
        }
        return \html_writer::table($table);
    }

    /**
     * The history tab: stored matrix snapshots and the differences between the two newest.
     *
     * @param string $key Connection key.
     * @return string
     */
    public function history_tab(string $key): string {
        global $DB;
        $snaps = \tool_ltidiagnostics\local\snapshot::get_list($key);
        if (count($snaps) < 1) {
            return \html_writer::tag('p', get_string('nosnapshots', 'tool_ltidiagnostics'));
        }
        $out = '';
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('tab_history', 'tool_ltidiagnostics');
        $table->head = [get_string('time'), get_string('hash', 'tool_ltidiagnostics')];
        foreach ($snaps as $snap) {
            $table->data[] = [userdate($snap->timecreated), substr($snap->contenthash, 0, 12)];
        }
        $out .= \html_writer::table($table);
        if (count($snaps) >= 2) {
            $new = json_decode($DB->get_field('tool_ltidiagnostics_snap', 'snapjson', ['id' => $snaps[0]->id]), true);
            $old = json_decode($DB->get_field('tool_ltidiagnostics_snap', 'snapjson', ['id' => $snaps[1]->id]), true);
            $diff = \tool_ltidiagnostics\local\snapshot::compare($old, $new);
            $out .= $this->heading(get_string('snapshotdiff', 'tool_ltidiagnostics'), 3);
            if (!$diff) {
                $out .= \html_writer::tag('p', get_string('nodiff', 'tool_ltidiagnostics'));
            } else {
                $items = '';
                foreach ($diff as $d) {
                    $items .= \html_writer::tag('li', get_string('feature_' . $d['feature'], 'tool_ltidiagnostics') . ': '
                        . get_string('status_' . ($d['from'] ?: 'unknown'), 'tool_ltidiagnostics') . ' &rarr; '
                        . get_string('status_' . $d['to'], 'tool_ltidiagnostics') . ' ('
                        . implode(', ', array_map(function ($step) {
                            return get_string('step_' . $step, 'tool_ltidiagnostics');
                        }, $d['changedsteps'])) . ')');
                }
                $out .= \html_writer::tag('ul', $items);
            }
        }
        return $out;
    }
}
