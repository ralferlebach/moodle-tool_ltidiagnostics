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

namespace tool_ltidiagnostics\local\grades;

use tool_ltidiagnostics\local\completion\completion_view;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\support;

/**
 * Redacted support package of the two channels for the administration of the partner institution (issue #8).
 *
 * Built from exactly the data the grade and completion tabs show (diagnosis, attempt journal, completion view), so the
 * package and the protocols agree. Separated by responsibility: what the provider has to do, what the consumer has to
 * check, and what is unknown from here. Contains pseudonyms, codes, times, versions and correlation ids: no names, no user
 * ids, no tokens, no scores, no raw URLs.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class support_package {
    /** @var string Schema. */
    public const SCHEMA = 'ltidiag-support-2';
    /** @var string[] Formats. */
    public const FORMATS = ['json', 'md', 'csv'];
    /** @var string Markdown code delimiter (a backtick). */
    private const TICK = "\x60";
    /** @var int Journal rows in a package. */
    public const MAXATTEMPTS = 200;

    /**
     * Build the package.
     *
     * @param connection $conn Connection.
     * @param int $now Now.
     * @return array
     */
    public static function build(connection $conn, int $now): array {
        $pkg = [
            'schema' => self::SCHEMA,
            'diagnosticid' => support::diagnostic_id($conn->key),
            'role' => $conn->role,
            'generated' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'responsibility' => ['provider' => [], 'consumer' => [], 'both' => [], 'unknown' => []],
            'grades' => ['rows' => [], 'attempts' => []],
            'completion' => ['summary' => [], 'rows' => []],
        ];
        if ($conn->role === connection::ROLE_TOOL) {
            $d = (new grade_diagnosis())->run($conn, ['limit' => grade_diagnosis::MAXPAGE, 'now' => $now,
                'importtasklog' => false]);
            foreach ($d['findings'] as $f) {
                $pkg['responsibility'][$f['responsible']][] = ['channel' => 'grades', 'code' => $f['code'],
                    'severity' => $f['severity'], 'count' => $f['count'], 'certainty' => $f['certainty'],
                    'source' => $f['source'], 'limit' => $f['limit']];
            }
            foreach ($d['rows'] as $r) {
                $pkg['grades']['rows'][] = [
                    'person' => $r['person'],
                    'link' => $d['links'][$r['linkid']]['resourcelinkid'],
                    'prediction' => $r['prediction'],
                    'evidence' => $r['evidence']['level'],
                    'evidencesource' => $r['evidence']['source'],
                    'evidencetime' => $r['evidence']['time'] ? gmdate('Y-m-d\TH:i:s\Z', $r['evidence']['time']) : '',
                    'attemptid' => $r['evidence']['attemptid'],
                    'risks' => $r['risks'],
                ];
                if (in_array($r['evidence']['level'], ['http_accepted_unconfirmed', 'remote_unobservable'], true)) {
                    // Whether the consumer booked it is unknown from here: mark as an open question for the partner.
                    $pkg['responsibility']['unknown'][] = ['channel' => 'grades', 'code' => 'booking_unknown',
                        'person' => $r['person'], 'attemptid' => $r['evidence']['attemptid']];
                }
            }
            $page = journal::page($conn->key, ['limit' => self::MAXATTEMPTS]);
            foreach ($page['rows'] as $j) {
                $pkg['grades']['attempts'][] = [
                    'attemptid' => (string) $j->attemptid,
                    'correlationid' => (string) $j->correlationid,
                    'attemptno' => (int) $j->attemptno,
                    'origin' => (string) $j->origin,
                    'source' => (string) $j->source,
                    'person' => grade_diagnosis::pseudonym($conn->key, (int) $j->userid),
                    'state' => (string) $j->state,
                    'skipreason' => (string) $j->skipreason,
                    'httpstatus' => (int) $j->httpstatus,
                    'errorclass' => (string) $j->errorclass,
                    'agstimestamp' => (int) $j->agstimestamp ? gmdate('Y-m-d\TH:i:s\Z', (int) $j->agstimestamp) : '',
                    'stateversion' => (int) $j->stateversion,
                    'booking' => (string) $j->bookingstate,
                    'time' => gmdate('Y-m-d\TH:i:s\Z', (int) $j->timecreated),
                ];
            }
        }
        $v = (new completion_view())->build($conn, ['limit' => 500]);
        $pkg['completion']['installed'] = $v['installed'];
        $pkg['completion']['summary'] = $v['summary'];
        foreach ($v['rows'] as $r) {
            $pkg['completion']['rows'][] = array_intersect_key($r, array_flip(['person', 'class', 'version', 'sentversion',
                'ackversion', 'ackstatus', 'remoteversion', 'appliedversion', 'correlationid', 'attempts']));
            if (in_array($r['class'], ['sent_tool_only', 'status_unknown'], true)) {
                $pkg['responsibility']['unknown'][] = ['channel' => 'completion', 'code' => 'ack_missing',
                    'person' => $r['person'], 'correlationid' => $r['correlationid']];
            }
        }
        // Every value is a code, a pseudonym, a hash-free id or a time; redactor::data() would also blank the "state" keys.
        return $pkg;
    }

    /**
     * Serialise.
     *
     * @param array $pkg Package.
     * @param string $format json, md or csv.
     * @return string
     */
    public static function export(array $pkg, string $format): string {
        if ($format === 'json') {
            return json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($format === 'csv') {
            $lines = ['channel,section,person,code,state,detail,correlation,time'];
            foreach ($pkg['responsibility'] as $who => $items) {
                foreach ($items as $i) {
                    $lines[] = self::csv([$i['channel'], 'responsibility_' . $who, $i['person'] ?? '', $i['code'],
                        $i['severity'] ?? '', isset($i['count']) ? 'count=' . $i['count'] : '',
                        $i['attemptid'] ?? ($i['correlationid'] ?? ''), '']);
                }
            }
            foreach ($pkg['grades']['attempts'] as $a) {
                $lines[] = self::csv(['grades', 'attempt', $a['person'], $a['errorclass'] ?: $a['skipreason'], $a['state'],
                    'http=' . $a['httpstatus'] . ' booking=' . $a['booking'] . ' origin=' . $a['origin'], $a['attemptid'],
                    $a['time']]);
            }
            foreach ($pkg['completion']['rows'] as $r) {
                $lines[] = self::csv(['completion', 'row', $r['person'], $r['class'], $r['ackstatus'] ?? '',
                    'version=' . ($r['version'] ?? $r['remoteversion'] ?? ''), $r['correlationid'], '']);
            }
            return implode("\n", $lines) . "\n";
        }
        $t = fn(string $id, $a = null) => get_string($id, 'tool_ltidiagnostics', $a);
        $md = ['# ' . $t('gpkg_title', $pkg['diagnosticid']), '', $t('gpkg_generated', $pkg['generated']), ''];
        foreach (['provider', 'consumer', 'both', 'unknown'] as $who) {
            $md[] = '## ' . $t('gpkg_resp_' . $who);
            $md[] = '';
            if (!$pkg['responsibility'][$who]) {
                $md[] = '- ' . $t('gpkg_none');
            }
            foreach ($pkg['responsibility'][$who] as $i) {
                $line = '- [' . $i['channel'] . '] ' . self::md($i['code']);
                if (isset($i['count'])) {
                    $line .= ' (' . (int) $i['count'] . ')';
                }
                if (isset($i['person'])) {
                    $line .= ' ' . self::md($i['person']);
                }
                foreach (['attemptid', 'correlationid'] as $ref) {
                    if (!empty($i[$ref])) {
                        $line .= ' ' . self::TICK . self::md((string) $i[$ref]) . self::TICK;
                    }
                }
                $md[] = $line;
            }
            $md[] = '';
        }
        $md[] = '## ' . $t('grades_journal');
        $md[] = '';
        $md[] = '| time | attempt | origin | person | state | http | class | booking |';
        $md[] = '|---|---|---|---|---|---|---|---|';
        foreach ($pkg['grades']['attempts'] as $a) {
            $md[] = '| ' . implode(' | ', array_map([self::class, 'md'], [$a['time'], $a['attemptid'] . ' #' . $a['attemptno'],
                $a['origin'], $a['person'], $a['state'], (string) $a['httpstatus'], $a['errorclass'] ?: $a['skipreason'],
                $a['booking']])) . ' |';
        }
        $md[] = '';
        $md[] = '## ' . $t('tab_completion');
        $md[] = '';
        foreach ($pkg['completion']['summary'] as $class => $n) {
            $md[] = '- ' . self::md($class) . ': ' . (int) $n;
        }
        return implode("\n", $md) . "\n";
    }

    /**
     * CSV line with formula injection protection.
     *
     * @param array $cells Cells.
     * @return string
     */
    private static function csv(array $cells): string {
        return implode(',', array_map(fn($c) => \tool_ltidiagnostics\local\report_exporter::cell((string) $c), $cells));
    }

    /**
     * Markdown cell text.
     *
     * @param string $text Text.
     * @return string
     */
    public static function md(string $text): string {
        return str_replace(['|', "\n", self::TICK], ['\|', ' ', "'"], $text);
    }
}
