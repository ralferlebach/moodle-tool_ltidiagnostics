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
 * Events of the other plugins of the LTI family (gate CS-G6).
 *
 * Family plugins are recognised by their component prefix (local_lti*, ltisource_*), not by a list of names, so a new
 * family member is read without a change here. None of them is a dependency: nothing in this class loads a class of
 * another plugin; a plugin table is only read after checking that it exists.
 *
 * Contract with a family plugin: an event that belongs to a transfer carries other['correlationid'] (the same value on
 * both sites); optional other['connectionid'] (the plugin's own connection id), other['version'] and other['code'] (a
 * short status code). Only these keys are taken over; any other value in "other" is ignored and never stored.
 *
 * The transfer events of local_lticompletionsync (spec 08, section 7) are classified into the steps of a transaction.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_events {
    /** @var string[] Component prefixes of the LTI family. */
    public const PREFIXES = ['local_lti', 'ltisource_'];

    /** @var string Component of the completion sync plugin (optional). */
    public const COMPLETIONSYNC = 'local_lticompletionsync';

    /** @var int Maximum length of a correlation id (the observation column has 64 characters). */
    public const CORRELATION_MAXLEN = 64;

    /**
     * Steps of a completion sync transaction, keyed by the short event class name: [spec name, stage, direction].
     *
     * Direction is seen from the site that triggers the event: out on the sending tool, in on the receiving platform.
     *
     * @var array[]
     */
    public const COMPLETION_STEPS = [
        'source_detected' => ['source_detected', 'task', 'out'],
        'message_queued' => ['queued', 'task', 'out'],
        'message_sent' => ['sent', 'service', 'out'],
        'retry_scheduled' => ['retry_scheduled', 'task', 'out'],
        'dead_letter' => ['dead_letter', 'followup', 'out'],
        'message_acknowledged' => ['acknowledged', 'followup', 'out'],
        'http_received' => ['http_received', 'service', 'in'],
        'identity_resolved' => ['identity_resolved', 'followup', 'in'],
        'completion_applied' => ['completion_applied', 'followup', 'in'],
        'completion_revoked' => ['completion_revoked', 'followup', 'in'],
        'state_reconciled' => ['reconciled', 'followup', 'in'],
    ];

    /** @var string[] Causal order of the steps; breaks ties between events logged in the same second. */
    public const STEP_ORDER = ['source_detected', 'queued', 'sent', 'retry_scheduled', 'http_received', 'identity_resolved',
        'completion_applied', 'completion_revoked', 'reconciled', 'acknowledged', 'dead_letter'];

    /** @var string[] Spec names that end a transaction, with the outcome. */
    public const OUTCOMES = [
        'acknowledged' => 'acknowledged',
        'completion_applied' => 'applied',
        'completion_revoked' => 'applied',
        'reconciled' => 'applied',
        'dead_letter' => 'failed',
    ];

    /** @var array|null Cache of connection rows of the completion sync plugin, keyed by id. */
    private static $connections = null;

    /**
     * Whether a component belongs to the LTI family (and is not this plugin).
     *
     * @param string $component Frankenstyle component.
     * @return bool
     */
    public static function is_family_component(string $component): bool {
        foreach (self::PREFIXES as $prefix) {
            if (strpos($component, $prefix) === 0 && strlen($component) > strlen($prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a family plugin that emits transfer events is installed. Read from the plugin list, no class is loaded.
     *
     * @param string $component Component.
     * @return bool
     */
    public static function emitter_installed(string $component = self::COMPLETIONSYNC): bool {
        return \core_component::get_component_directory($component) !== null
            && get_config($component, 'version') !== false;
    }

    /**
     * The values taken over from "other": correlation id, connection id, version, code (or detail). Anything else is
     * dropped.
     *
     * @param mixed $other The event's other data.
     * @return array correlationid (string, '' if absent or invalid), connectionid (int), version (int), code (string)
     */
    public static function sanitise_other($other): array {
        $other = is_array($other) ? $other : [];
        $cid = is_scalar($other['correlationid'] ?? null) ? (string) $other['correlationid'] : '';
        $cid = clean_param($cid, PARAM_ALPHANUMEXT);
        if (\core_text::strlen($cid) > self::CORRELATION_MAXLEN) {
            $cid = '';
        }
        // The plugin local_lticompletionsync calls its short result code "detail" (e.g. "applied", "attempt:2",
        // "tool_only_no_confirmation", "rejected:identity_unresolved"); other members may call it "code". Only a short
        // machine code survives: letters, digits and _ : . - (anything else is cut).
        $raw = $other['code'] ?? ($other['detail'] ?? null);
        $code = is_scalar($raw) ? (string) preg_replace('/[^A-Za-z0-9_:.\-]/', '', (string) $raw) : '';
        return [
            'correlationid' => $cid,
            'connectionid' => is_numeric($other['connectionid'] ?? null) ? max(0, (int) $other['connectionid']) : 0,
            'version' => is_numeric($other['version'] ?? null) ? max(0, (int) $other['version']) : 0,
            'code' => \core_text::substr($code, 0, 32),
        ];
    }

    /**
     * Short class name of an event name.
     *
     * @param string $eventname Event name such as \local_x\event\message_sent.
     * @return string
     */
    public static function short_name(string $eventname): string {
        $parts = explode('\\', $eventname);
        return (string) end($parts);
    }

    /**
     * Classify a family event.
     *
     * @param string $eventname Event name.
     * @return array step (spec name or the short name), stage (timeline stage), direction (in, out or local), known (bool)
     */
    public static function classify(string $eventname): array {
        $short = self::short_name($eventname);
        if (isset(self::COMPLETION_STEPS[$short])) {
            [$step, $stage, $direction] = self::COMPLETION_STEPS[$short];
            return ['step' => $step, 'stage' => $stage, 'direction' => $direction, 'known' => true];
        }
        return ['step' => $short, 'stage' => 'followup', 'direction' => 'local', 'known' => false];
    }

    /**
     * Connection key of a family event, when it can be resolved unambiguously.
     *
     * For local_lticompletionsync the connection id is looked up in its connection table, if that table exists:
     * a receiver row (ltitypeid) belongs to the platform connection, a sender row (registration and deployment) to the
     * tool connection. Anything else gives an empty key ("connection unknown").
     *
     * @param string $component Component of the event.
     * @param int $connectionid other['connectionid'].
     * @param callable|null $lookup For tests: function (int $id): ?\stdClass returning a row with role, ltitypeid,
     *        registrationid, deploymentid.
     * @return string
     */
    public static function connection_key(string $component, int $connectionid, ?callable $lookup = null): string {
        if ($connectionid <= 0 || $component !== self::COMPLETIONSYNC) {
            return '';
        }
        $row = $lookup ? $lookup($connectionid) : self::completionsync_connection($connectionid);
        if (!$row) {
            return '';
        }
        if (($row->role ?? '') === 'receiver' && (int) ($row->ltitypeid ?? 0) > 0) {
            return 'platform:' . (int) $row->ltitypeid;
        }
        if (($row->role ?? '') === 'sender' && (int) ($row->registrationid ?? 0) > 0 && (int) ($row->deploymentid ?? 0) > 0) {
            return 'tool:' . (int) $row->registrationid . ':' . (int) $row->deploymentid;
        }
        return '';
    }

    /**
     * Read one connection row of the completion sync plugin, if its table exists.
     *
     * @param int $id Connection id.
     * @return \stdClass|null
     */
    private static function completionsync_connection(int $id): ?\stdClass {
        global $DB;
        if (self::$connections === null) {
            self::$connections = [];
            try {
                $table = new \xmldb_table('local_lticompletionsync_con');
                if ($DB->get_manager()->table_exists($table)) {
                    self::$connections = $DB->get_records(
                        'local_lticompletionsync_con',
                        null,
                        'id',
                        'id, role, ltitypeid, registrationid, deploymentid'
                    );
                }
            } catch (\Throwable $e) {
                self::$connections = [];
            }
        }
        return self::$connections[$id] ?? null;
    }

    /**
     * Forget cached connection rows (tests, long running scripts).
     */
    public static function reset_cache(): void {
        self::$connections = null;
    }

    /**
     * Causal rank of a step (unknown steps last).
     *
     * @param string $step Step.
     * @return int
     */
    public static function step_rank(string $step): int {
        $i = array_search($step, self::STEP_ORDER, true);
        return $i === false ? count(self::STEP_ORDER) : (int) $i;
    }

    /**
     * Group family items by correlation id into transactions.
     *
     * @param array[] $items Each with time, step, correlationid, component, connkey (optional), source (optional).
     * @return array[] Newest transaction first; each with correlationid, component, first, last, steps (in time order),
     *         outcome (acknowledged, applied, failed or open), retries (int), sources (list).
     */
    public static function transactions(array $items): array {
        $groups = [];
        foreach ($items as $item) {
            $cid = (string) ($item['correlationid'] ?? '');
            if ($cid === '') {
                continue;
            }
            $groups[$cid][] = $item;
        }
        $out = [];
        foreach ($groups as $cid => $group) {
            usort($group, function (array $a, array $b) {
                return [$a['time'], self::step_rank((string) $a['step'])] <=> [$b['time'], self::step_rank((string) $b['step'])];
            });
            $steps = [];
            $sources = [];
            $outcome = 'open';
            $retries = 0;
            foreach ($group as $item) {
                $step = (string) $item['step'];
                // The same step from two sources (observer and log store) is one step.
                if (!in_array($step, $steps, true) || $step === 'retry_scheduled') {
                    $steps[] = $step;
                }
                $sources[] = (string) ($item['source'] ?? '');
                if ($step === 'retry_scheduled') {
                    $retries++;
                }
                if (isset(self::OUTCOMES[$step])) {
                    // A failure is never hidden by a later step; otherwise the strongest outcome counts.
                    if ($outcome !== 'failed') {
                        $outcome = self::OUTCOMES[$step] === 'acknowledged' || $outcome === 'open'
                            ? self::OUTCOMES[$step] : $outcome;
                    }
                }
            }
            $out[] = [
                'correlationid' => (string) $cid,
                'component' => (string) ($group[0]['component'] ?? ''),
                'connkey' => (string) ($group[0]['connkey'] ?? ''),
                'first' => (int) $group[0]['time'],
                'last' => (int) end($group)['time'],
                'steps' => $steps,
                'outcome' => $outcome,
                'retries' => $retries,
                'sources' => array_values(array_unique(array_filter($sources))),
            ];
        }
        usort($out, function (array $a, array $b) {
            return $b['last'] <=> $a['last'];
        });
        return $out;
    }
}
