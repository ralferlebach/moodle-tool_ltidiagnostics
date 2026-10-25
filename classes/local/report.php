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

namespace tool_ltidiagnostics\local;

/**
 * Assembles the diagnostic report: the data structure behind every on-screen view and every export.
 *
 * Redaction is applied here, once, so that no exporter can forget it. Payloads are never part of a
 * report. In the default mode identifiers are masked; the diagnostic mode reveals them.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /**
     * Build the report.
     *
     * @param connection $c The connection.
     * @param array $result Result of health_check::evaluate().
     * @param array $options reveal (bool), filters (array), timeline (array|null), profile (desired_profile|null),
     *        viewerrole (string), timezone (string), sources (array), now (int).
     * @return array
     */
    public function build(connection $c, array $result, array $options = []): array {
        global $CFG;
        $reveal = !empty($options['reveal']);
        $now = (int) ($options['now'] ?? time());
        $plugin = \core_plugin_manager::instance()->get_plugin_info('tool_ltidiagnostics');
        $endpoints = [];
        foreach ($c->endpoints as $purpose => $url) {
            $endpoints[$purpose] = redactor::url($url, $reveal);
        }
        $matrix = [];
        foreach ($result['matrix'] as $feature => $row) {
            $steps = [];
            foreach ($row['ladder'] as $step => $ev) {
                $steps[$step] = [
                    'state' => $ev->state,
                    'evidencekind' => $ev->kind,
                    'detail' => $ev->detailkey !== '' ? get_string($ev->detailkey, 'tool_ltidiagnostics', $ev->detailparam) : '',
                    'time' => $ev->time ? gmdate('c', $ev->time) : '',
                ];
            }
            $matrix[] = [
                'feature' => $feature,
                'label' => get_string('feature_' . $feature, 'tool_ltidiagnostics'),
                'status' => $row['status'],
                'reason' => get_string('reason_' . $row['reason'], 'tool_ltidiagnostics'),
                'next' => get_string('next_' . $row['next'], 'tool_ltidiagnostics'),
                'notes' => array_map(function ($n) {
                    return get_string('note_' . $n, 'tool_ltidiagnostics');
                }, $row['notes']),
                'steps' => $steps,
            ];
        }
        $findings = [];
        foreach ($result['findings'] as $f) {
            $str = $f->strings();
            $findings[] = [
                'testid' => $f->testid,
                'layer' => $f->layer,
                'severity' => $f->severity,
                'responsible' => $f->responsible,
                'risk' => $f->risk,
                'title' => redactor::text($str['title']),
                'detail' => redactor::text($str['detail']),
                'fix' => redactor::text($str['fix']),
                'verify' => $str['verify'],
                'evidencekind' => $f->evidencekind,
                'evidence' => redactor::text($f->evidence),
                'confidence' => $f->confidence,
                'checkedat' => $f->checkedat ? gmdate('c', $f->checkedat) : '',
            ];
        }
        $layers = [];
        foreach ($result['layers'] as $layer => $info) {
            $layers[$layer] = $info['light'];
        }
        $timeline = null;
        if (!empty($options['timeline'])) {
            $timeline = [
                'itemcount' => count($options['timeline']['items']),
                'edges' => $options['timeline']['edges'],
                'sources' => $options['timeline']['sources'],
                'items' => array_map(function ($i) {
                    return ['time' => gmdate('c', $i['time']), 'source' => $i['source'], 'stage' => $i['stage'],
                        'label' => redactor::text($i['label']), 'confidence' => '', 'traceid' => ''];
                }, $options['timeline']['items']),
            ];
        }
        return [
            'meta' => [
                'plugin' => 'tool_ltidiagnostics',
                'pluginversion' => $plugin ? $plugin->release . ' (' . $plugin->versiondisk . ')' : '',
                'moodleversion' => $CFG->release,
                'generated' => gmdate('c', $now),
                'timezone' => (string) ($options['timezone'] ?? \core_date::get_user_timezone()),
                'viewerrole' => (string) ($options['viewerrole'] ?? ''),
                'identifiersrevealed' => $reveal,
                'payloads' => 'excluded',
                'diagnosticid' => support::diagnostic_id($c->key),
            ],
            'connection' => [
                'role' => $c->role,
                'name' => $reveal ? $c->name : redactor::identifier($c->name, false),
                'ltiversion' => $c->ltiversion,
                'issuer' => redactor::url($c->issuer, $reveal),
                'clientid' => redactor::identifier($c->clientid, $reveal),
                'deploymentid' => redactor::identifier($c->deploymentid, $reveal),
                'status' => $c->state,
                'endpoints' => $endpoints,
            ],
            'filters' => (object) ($options['filters'] ?? []),
            'layers' => $layers,
            'summary' => $result['summary'],
            'matrix' => $matrix,
            'findings' => $findings,
            'delta' => $result['delta'],
            'timeline' => $timeline,
            'sources' => $options['sources'] ?? [],
            'limits' => [get_string('limit_localonly', 'tool_ltidiagnostics'),
                get_string('limit_logsnothttp', 'tool_ltidiagnostics'),
                get_string('limit_scopesnotsuccess', 'tool_ltidiagnostics'),
                get_string('limit_absencenotevidence', 'tool_ltidiagnostics'),
                get_string('limit_remoteinvisible', 'tool_ltidiagnostics')],
        ];
    }
}
