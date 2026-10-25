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
        $map = ['green' => 'bg-success', 'amber' => 'bg-warning text-dark', 'red' => 'bg-danger', 'grey' => 'bg-secondary'];
        return $map[$light] ?? 'bg-secondary';
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
        foreach ($result['findings'] as $f) {
            $str = $f->strings();
            $buckets[$f->severity][] = [
                'severity' => $f->severity,
                'testid' => $f->testid,
                'title' => $str['title'],
                'layer' => get_string('layer_' . $f->layer, 'tool_ltidiagnostics'),
                'responsible' => get_string('responsible_' . $f->responsible, 'tool_ltidiagnostics'),
                'detail' => $str['detail'],
                'fix' => $str['fix'],
                'risklabel' => get_string('risk_' . $f->risk, 'tool_ltidiagnostics'),
                'verify' => $str['verify'],
                'evidence' => get_string('kind_' . $f->evidencekind, 'tool_ltidiagnostics')
                    . ($f->confidence !== '' ? ' / ' . $f->confidence : ''),
            ];
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
            ],
            'headings' => [
                'progress' => get_string('hc_progress', 'tool_ltidiagnostics'),
                'layers' => get_string('hc_layers', 'tool_ltidiagnostics'),
                'findings' => get_string('hc_findings', 'tool_ltidiagnostics'),
                'nofindings' => get_string('hc_nofindings', 'tool_ltidiagnostics'),
            ],
            'progress' => $progress,
            'layers' => $layers,
            'groups' => $groups,
        ]);
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
        return $this->render_from_template('tool_ltidiagnostics/timeline', [
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
        global $DB;
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
     * @return string
     */
    public function imports_table(array $imports): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_imports', 'tool_ltidiagnostics');
        $table->head = [get_string('time'), get_string('logformat', 'tool_ltidiagnostics'),
            get_string('source', 'tool_ltidiagnostics'),
            get_string('logtimezone', 'tool_ltidiagnostics'), get_string('range', 'tool_ltidiagnostics'),
            get_string('lines', 'tool_ltidiagnostics'), get_string('state', 'tool_ltidiagnostics')];
        foreach ($imports as $i) {
            $range = $i->rangefrom ? userdate($i->rangefrom, '%Y-%m-%d %H:%M', 'UTC') . ' - ' . userdate(
                $i->rangeto,
                '%Y-%m-%d %H:%M',
                'UTC'
            ) . ' UTC' : '-';
            $table->data[] = [userdate($i->timecreated), s($i->kind . ':' . $i->format), s($i->sourcename), s($i->timezone),
                $range, get_string('linessummary', 'tool_ltidiagnostics', $i), s($i->status . ($i->errormessage !== '' ?
                    ' (' . $i->errormessage . ')' : ''))];
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
