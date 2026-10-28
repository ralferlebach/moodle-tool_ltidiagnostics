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
 * Completion sync transaction reports (audit item DIA-05): the whole path of one completion message,
 *
 *   source event -> outbox -> task -> HTTP/JWT -> receiver -> native completion -> ACK / retry / revocation,
 *
 * keyed by the correlation id that local_lticompletionsync carries on both sites.
 *
 * One site sees only its own half. The other half comes from a partner trace: a redacted export of the same report
 * on the other site (correlation ids, stages, times and short result codes; no user, no payload), imported here. With
 * it a transaction is "complete" when both halves agree; without it the report is labelled "limited" and names the
 * stages it cannot see. The no-partner mode of local_lticompletionsync (completion delivered as an AGS progress
 * value only, acknowledgement code "tool_only_no_confirmation") is always "limited": native completion on the
 * platform is not confirmed by anybody.
 *
 * The person is shown only as a masked id that is stable inside one connection of this site and meaningless elsewhere;
 * user ids never leave the site. A task run is linked to a transfer only by time containment within the same component
 * ("strong"), or not at all ("unmatched"): no correlation is invented.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_report {
    /** @var string Trace schema. */
    public const SCHEMA = 'ltidiag-trace-1';
    /** @var string Snapshot kind of an imported partner trace. */
    public const KIND = 'partnertrace';
    /** @var int Largest accepted trace in bytes. */
    public const MAXBYTES = 524288;
    /** @var int Largest number of transactions in a trace. */
    public const MAXTRANSACTIONS = 500;

    /** @var string[] Stages of the path in order. */
    public const STAGES = ['source_event', 'outbox', 'task', 'http_jwt', 'receiver', 'native_completion', 'ack', 'retry',
        'revocation'];

    /** @var array Family step => [stage, side]; side is sender (tool) or receiver (platform). */
    public const STEP_STAGE = [
        'source_detected' => ['source_event', 'sender'],
        'queued' => ['outbox', 'sender'],
        'sent' => ['http_jwt', 'sender'],
        'retry_scheduled' => ['retry', 'sender'],
        'acknowledged' => ['ack', 'sender'],
        'dead_letter' => ['ack', 'sender'],
        'http_received' => ['http_jwt', 'receiver'],
        'identity_resolved' => ['receiver', 'receiver'],
        'reconciled' => ['receiver', 'receiver'],
        'completion_applied' => ['native_completion', 'receiver'],
        'completion_revoked' => ['revocation', 'receiver'],
    ];

    /** @var string[] Stages that must be seen for a complete transfer, per side. */
    private const REQUIRED = [
        'sender' => ['outbox', 'http_jwt', 'ack'],
        'receiver' => ['http_jwt', 'receiver', 'native_completion'],
    ];

    /**
     * Collect the local family items of a connection: log store rows (with code, context and user) and own observations.
     *
     * @param connection $c Connection.
     * @param \context $context Context for the capability check of the log store.
     * @param array $filters from, to.
     * @param int $now Time.
     * @return array items (time, step, correlationid, code, contextid, userid, source), taskruns (start, end, classname,
     *         failed), logstore (available, truncated)
     */
    public static function collect(connection $c, \context $context, array $filters, int $now): array {
        $from = !empty($filters['from']) ? (int) $filters['from'] : $now - logstore_source::DEFAULT_WINDOW;
        $to = !empty($filters['to']) ? (int) $filters['to'] : 0;
        $wanted = $c->role === connection::ROLE_PLATFORM ? 'in' : 'out';
        $items = [];
        $seen = [];
        $log = (new logstore_source())->fetch($context, ['from' => $from, 'to' => $to, 'limit' => 500]);
        foreach ($log['rows'] as $r) {
            // Any family member that uses the transfer events of the completion sync contract (spec 08, section 7).
            if (empty($r['family']) || $r['correlationid'] === '' || !family_events::classify((string) $r['eventname'])['known']) {
                continue;
            }
            if (($r['connkey'] !== '' && $r['connkey'] !== $c->key) || ($r['connkey'] === '' && $r['direction'] !== $wanted)) {
                continue;
            }
            $items[] = ['time' => (int) $r['time'], 'step' => (string) $r['step'], 'correlationid' => (string) $r['correlationid'],
                'code' => (string) $r['code'], 'contextid' => (int) $r['contextid'], 'userid' => (int) $r['userid'],
                'source' => 'logstore'];
            $seen[$r['correlationid'] . '|' . $r['step'] . '|' . $r['time']] = true;
        }
        foreach (
            observation_store::get_for_connection($c, ['from' => $from, 'to' => $to, 'limit' => 1000,
                'eventtype' => observation_store::FAMILY_EVENT]) as $o
        ) {
            $step = family_events::classify((string) $o->eventname)['step'];
            $cid = (string) ($o->correlationid ?? '');
            if ($cid === '' || isset($seen[$cid . '|' . $step . '|' . $o->timecreated])) {
                continue;
            }
            $items[] = ['time' => (int) $o->timecreated, 'step' => $step, 'correlationid' => $cid, 'code' => '',
                'contextid' => (int) $o->contextid, 'userid' => (int) $o->userid, 'source' => 'observer'];
        }
        $runs = [];
        foreach ((new task_source())->task_log(['from' => $from, 'to' => $to, 'limit' => 500]) as $t) {
            if ($t['component'] === family_events::COMPLETIONSYNC || strpos($t['classname'], 'local_lticompletionsync') !== false) {
                $runs[] = ['start' => (int) floor($t['start']), 'end' => (int) ceil($t['start'] + $t['duration']),
                    'classname' => $t['classname'], 'failed' => $t['failed']];
            }
        }
        return ['items' => $items, 'taskruns' => $runs,
            'logstore' => ['available' => $log['available'], 'truncated' => !empty($log['familytruncated'])]];
    }

    /**
     * Build the report.
     *
     * @param connection $c Connection.
     * @param array $collected Result of {@see self::collect()}.
     * @param array|null $partner Parsed partner trace ({@see self::parse_trace()}) or null.
     * @param callable|null $native function (int $contextid, int $userid, int $time): ?array returning state (int) and
     *        time of the native completion record, null when unknown. Default: course_modules_completion.
     * @return array side, mode, transactions (newest first), summary (verdict => count), limits
     */
    public static function build(connection $c, array $collected, ?array $partner = null, ?callable $native = null): array {
        $side = $c->role === connection::ROLE_PLATFORM ? 'receiver' : 'sender';
        $native = $native ?? [self::class, 'native_completion'];
        $groups = [];
        foreach ($collected['items'] as $item) {
            $groups[$item['correlationid']][] = $item;
        }
        $partnertx = $partner['transactions'] ?? [];
        $out = [];
        foreach ($groups as $cid => $items) {
            usort($items, function (array $a, array $b) {
                return [$a['time'], family_events::step_rank($a['step'])] <=> [$b['time'], family_events::step_rank($b['step'])];
            });
            $tx = self::transaction((string) $cid, $side, $items, $collected['taskruns'], $partnertx[$cid] ?? null, $native, $c);
            $out[] = $tx;
        }
        usort($out, function (array $a, array $b) {
            return $b['last'] <=> $a['last'];
        });
        $summary = [];
        foreach ($out as $tx) {
            $summary[$tx['verdict']] = ($summary[$tx['verdict']] ?? 0) + 1;
        }
        return [
            'side' => $side,
            'mode' => $partner ? 'two_sided' : 'one_sided',
            'partner' => $partner ? ['diagnosticid' => $partner['diagnosticid'], 'generated' => $partner['generated'],
                'role' => $partner['role']] : null,
            'transactions' => $out,
            'summary' => $summary,
            'limits' => array_values(array_filter([
                $partner ? '' : 'partner_side_not_observable',
                !empty($collected['logstore']['truncated']) ? 'logstore_truncated' : '',
                empty($collected['logstore']['available']) ? 'logstore_unavailable' : '',
            ])),
        ];
    }

    /**
     * One transaction.
     *
     * @param string $cid Correlation id.
     * @param string $side Local side.
     * @param array[] $items Local items in order.
     * @param array[] $runs Task runs of the plugin.
     * @param array|null $partner Partner transaction or null.
     * @param callable $native Native completion lookup.
     * @param connection $c Connection.
     * @return array
     */
    private static function transaction(
        string $cid,
        string $side,
        array $items,
        array $runs,
        ?array $partner,
        callable $native,
        connection $c
    ): array {
        $stages = array_fill_keys(self::STAGES, ['local' => null, 'partner' => null, 'state' => 'missing']);
        $person = '';
        $retries = 0;
        $codes = [];
        $sent = 0;
        $applied = null;
        foreach ($items as $item) {
            [$stage] = self::STEP_STAGE[$item['step']] ?? ['', ''];
            if ($stage === '') {
                continue;
            }
            if ($item['step'] === 'retry_scheduled') {
                $retries++;
            }
            if ($item['step'] === 'sent' && $sent === 0) {
                $sent = $item['time'];
            }
            if (in_array($item['step'], ['completion_applied', 'completion_revoked'], true)) {
                $applied = $item;
            }
            // The newest entry of a stage wins (a retry repeats "sent"); the code of every entry is kept.
            $stages[$stage]['local'] = ['time' => $item['time'], 'step' => $item['step'], 'code' => $item['code'],
                'source' => $item['source']];
            if ($item['code'] !== '') {
                $codes[] = $item['step'] . ':' . $item['code'];
            }
            if ($person === '' && $item['userid'] > 0) {
                $person = self::mask_person($item['userid'], $c->key);
            }
        }
        // Task: the delivery task run that contains the first send, same component, by time only.
        if ($side === 'sender' && $sent > 0) {
            foreach ($runs as $run) {
                if ($run['start'] <= $sent && $sent <= $run['end'] + 1) {
                    $stages['task']['local'] = ['time' => $run['start'], 'step' => 'task', 'code' => $run['failed'] ? 'failed' : '',
                        'source' => 'tasklog', 'confidence' => correlation::STRONG];
                    break;
                }
            }
        }
        // Native completion: the completion record of the activity and user, read on the receiving site.
        $nativestate = null;
        if ($side === 'receiver' && $applied !== null) {
            $nativestate = $native((int) $applied['contextid'], (int) $applied['userid'], (int) $applied['time']);
            $expected = $applied['step'] === 'completion_applied' ? 1 : 0;
            $stage = $applied['step'] === 'completion_applied' ? 'native_completion' : 'revocation';
            if ($nativestate !== null) {
                $nativeok = ((int) $nativestate['state'] >= 1 ? 1 : 0) === $expected;
                $stages[$stage]['local']['native'] = $nativeok ? 'match' : 'mismatch';
            }
        }
        foreach ((array) ($partner['stages'] ?? []) as $stage => $p) {
            if (isset($stages[$stage]) && is_array($p)) {
                $stages[$stage]['partner'] = $p;
            }
        }
        // Stage states.
        $other = $side === 'sender' ? 'receiver' : 'sender';
        foreach ($stages as $stage => &$s) {
            $mine = in_array($stage, self::REQUIRED[$side], true) || in_array($stage, ['source_event', 'task', 'retry'], true)
                && $side === 'sender' || $stage === 'revocation' && $side === 'receiver';
            if ($s['local'] !== null || $s['partner'] !== null) {
                $s['state'] = 'seen';
                $code = (string) ($s['local']['code'] ?? $s['partner']['code'] ?? '');
                if (
                    ($s['local']['native'] ?? '') === 'mismatch' || preg_match('/^(rejected|conflict)/', $code)
                        || ($s['local']['step'] ?? $s['partner']['step'] ?? '') === 'dead_letter'
                ) {
                    $s['state'] = 'failed';
                }
            } else if (!$mine && $partner === null) {
                $s['state'] = 'not_observable';
            }
        }
        unset($s);
        $verdict = self::verdict($side, $other, $stages, $codes, $partner !== null);
        $first = $items[0]['time'];
        $last = end($items)['time'];
        return [
            'correlationid' => $cid,
            'person' => $person,
            'first' => $first,
            'last' => $last,
            'retries' => $retries,
            'stages' => $stages,
            'codes' => array_values(array_unique($codes)),
            'verdict' => $verdict,
            'native' => $nativestate === null ? '' : ((int) $nativestate['state'] >= 1 ? 'complete' : 'incomplete'),
            'partner' => $partner !== null,
            'skew' => self::skew($stages, $side),
        ];
    }

    /**
     * Verdict of a transaction.
     *
     * @param string $side Local side.
     * @param string $other Partner side.
     * @param array $stages Stages.
     * @param string[] $codes step:code list.
     * @param bool $haspartner A partner trace is present for this transaction.
     * @return string complete, failed, limited_no_partner_mode, limited_one_side, open
     */
    public static function verdict(string $side, string $other, array $stages, array $codes, bool $haspartner): string {
        foreach ($stages as $s) {
            if ($s['state'] === 'failed') {
                return 'failed';
            }
        }
        foreach ($codes as $code) {
            if (substr($code, -strlen('tool_only_no_confirmation')) === 'tool_only_no_confirmation') {
                return 'limited_no_partner_mode';
            }
        }
        foreach (self::REQUIRED[$side] as $stage) {
            if ($stages[$stage]['local'] === null) {
                return 'open';
            }
        }
        if (!$haspartner) {
            return 'limited_one_side';
        }
        foreach (self::REQUIRED[$other] as $stage) {
            if ($stages[$stage]['partner'] === null) {
                return 'open';
            }
        }
        return 'complete';
    }

    /**
     * Clock difference estimate between the two sites from the HTTP stage: receiver time minus sender time (positive
     * when the receiver clock is ahead, plus the transfer time).
     *
     * @param array $stages Stages.
     * @param string $side Local side.
     * @return int|null Seconds.
     */
    private static function skew(array $stages, string $side): ?int {
        $local = $stages['http_jwt']['local']['time'] ?? null;
        $partner = $stages['http_jwt']['partner']['time'] ?? null;
        if ($local === null || $partner === null) {
            return null;
        }
        return $side === 'receiver' ? (int) $local - (int) $partner : (int) $partner - (int) $local;
    }

    /**
     * Masked person id: stable inside one connection of this site, meaningless elsewhere; no user id leaves the site.
     *
     * @param int $userid User id.
     * @param string $connkey Connection key.
     * @return string
     */
    public static function mask_person(int $userid, string $connkey): string {
        return 'P-' . strtoupper(substr(hash_hmac('sha256', 'u' . $userid, get_site_identifier() . '|' . $connkey), 0, 8));
    }

    /**
     * Native completion state of the activity of a module context for a user (course_modules_completion).
     *
     * @param int $contextid Module context id.
     * @param int $userid User id.
     * @param int $time Time of the applied event.
     * @return array|null state, time; null when the context is no module context.
     */
    public static function native_completion(int $contextid, int $userid, int $time): ?array {
        global $DB;
        if ($contextid <= 0 || $userid <= 0) {
            return null;
        }
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel !== CONTEXT_MODULE) {
            return null;
        }
        $row = $DB->get_record(
            'course_modules_completion',
            ['coursemoduleid' => $context->instanceid, 'userid' => $userid],
            'id, completionstate, timemodified'
        );
        return ['state' => $row ? (int) $row->completionstate : 0, 'time' => $row ? (int) $row->timemodified : 0];
    }

    /**
     * Redacted trace of this site for the partner: correlation ids, stages, times and codes only.
     *
     * @param connection $c Connection.
     * @param array $report Result of {@see self::build()}.
     * @param int $now Time.
     * @return array
     */
    public static function export_trace(connection $c, array $report, int $now): array {
        $tx = [];
        foreach (array_slice($report['transactions'], 0, self::MAXTRANSACTIONS) as $t) {
            $stages = [];
            foreach ($t['stages'] as $stage => $s) {
                if ($s['local'] !== null) {
                    $stages[$stage] = ['time' => (int) $s['local']['time'], 'step' => (string) $s['local']['step'],
                        'code' => (string) $s['local']['code']] + (isset($s['local']['native'])
                        ? ['native' => $s['local']['native']] : []);
                }
            }
            $tx[$t['correlationid']] = ['stages' => $stages, 'verdict' => $t['verdict']];
        }
        return ['schema' => self::SCHEMA, 'generated' => gmdate('c', $now), 'role' => $c->role,
            'diagnosticid' => support::diagnostic_id($c->key), 'side' => $report['side'], 'transactions' => $tx];
    }

    /**
     * Parse and validate an imported partner trace.
     *
     * @param string $json Text.
     * @return array
     * @throws \moodle_exception invalidpartnertrace
     */
    public static function parse_trace(string $json): array {
        if (strlen($json) > self::MAXBYTES) {
            throw new \moodle_exception('invalidpartnertrace', 'tool_ltidiagnostics', '', 'size');
        }
        $data = json_decode($json, true);
        if (
            !is_array($data) || ($data['schema'] ?? '') !== self::SCHEMA
                || !in_array($data['role'] ?? '', [connection::ROLE_PLATFORM, connection::ROLE_TOOL], true)
        ) {
            throw new \moodle_exception('invalidpartnertrace', 'tool_ltidiagnostics', '', 'schema');
        }
        $tx = [];
        foreach ((array) ($data['transactions'] ?? []) as $cid => $t) {
            $cid = (string) $cid;
            if (count($tx) >= self::MAXTRANSACTIONS || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $cid) || !is_array($t)) {
                continue;
            }
            $stages = [];
            foreach ((array) ($t['stages'] ?? []) as $stage => $s) {
                if (!in_array($stage, self::STAGES, true) || !is_array($s)) {
                    continue;
                }
                $step = (string) ($s['step'] ?? '');
                $stages[$stage] = [
                    'time' => max(0, (int) ($s['time'] ?? 0)),
                    'step' => isset(self::STEP_STAGE[$step]) || $step === 'task' ? $step : '',
                    'code' => \core_text::substr(
                        (string) preg_replace('/[^A-Za-z0-9_:.\-]/', '', (string) ($s['code'] ?? '')),
                        0,
                        64
                    ),
                ] + (in_array($s['native'] ?? '', ['match', 'mismatch'], true) ? ['native' => $s['native']] : []);
            }
            $tx[$cid] = ['stages' => $stages];
        }
        return ['schema' => self::SCHEMA, 'generated' => clean_param((string) ($data['generated'] ?? ''), PARAM_TEXT),
            'role' => $data['role'], 'diagnosticid' => clean_param((string) ($data['diagnosticid'] ?? ''), PARAM_ALPHANUMEXT),
            'transactions' => $tx];
    }

    /**
     * Markdown rendering of a report (for the export).
     *
     * @param array $report Report.
     * @param connection $c Connection.
     * @param int $now Time.
     * @return string
     */
    public static function to_markdown(array $report, connection $c, int $now): string {
        $t = function (string $key, $a = null) {
            return get_string($key, 'tool_ltidiagnostics', $a);
        };
        $md = '# ' . $t('transfers_title') . "\n\n";
        $md .= '- ' . $t('report_generated') . ': ' . gmdate('c', $now) . " (UTC)\n";
        $md .= '- ' . $t('report_diagid') . ': ' . support::diagnostic_id($c->key) . "\n";
        $md .= '- ' . $t('transfers_side') . ': ' . $t('transfers_side_' . $report['side']) . "\n";
        $md .= '- ' . $t('transfers_mode') . ': ' . $t('transfers_mode_' . $report['mode']) . "\n";
        foreach ($report['limits'] as $limit) {
            $md .= '- ' . $t('transfers_limit_' . $limit) . "\n";
        }
        $md .= "\n| " . $t('transfers_correlation') . ' | ' . $t('transfers_person') . ' | ';
        foreach (self::STAGES as $stage) {
            $md .= $t('transfers_stage_' . $stage) . ' | ';
        }
        $md .= $t('transfers_verdict') . " |\n|" . str_repeat('---|', count(self::STAGES) + 3) . "\n";
        foreach ($report['transactions'] as $tx) {
            $md .= '| ' . $tx['correlationid'] . ' | ' . $tx['person'] . ' | ';
            foreach (self::STAGES as $stage) {
                $s = $tx['stages'][$stage];
                $cell = $t('transfers_state_' . $s['state']);
                if ($s['local'] !== null && $s['local']['code'] !== '') {
                    $cell .= ' (' . $s['local']['code'] . ')';
                }
                if ($s['partner'] !== null) {
                    $cell .= ' / ' . $t('transfers_partnerseen');
                }
                $md .= str_replace('|', '\\|', $cell) . ' | ';
            }
            $md .= $t('transfers_verdict_' . $tx['verdict']) . " |\n";
        }
        return $md;
    }
}
