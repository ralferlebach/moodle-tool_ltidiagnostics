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
 * Observability levels: how much of each LTI transaction this system can see, per transaction type,
 * direction (outbound or inbound) and part (request or response).
 *
 * Levels, weakest first:
 *  - not_observable: nothing of this part can be seen from this system with the current instrumentation;
 *  - inferred: only a consequence is visible (a local effect, a task that ran, a page that was viewed);
 *  - transport: a web server log line proves that a request arrived and which status code was returned, but
 *    not what was inside it and not whether the answer was correct;
 *  - recorded: Moodle itself recorded the transaction (core event, database record, task log) without payload;
 *  - payload: the content of the message was seen by this system. This is only possible for requests that
 *    this plugin sends itself (the key set fetch). The content of OIDC, JWT, OAuth 2 and service messages
 *    that other parties exchange is NOT visible to a Moodle plugin: no hook exists, and storing it would
 *    conflict with the data protection rules of this plugin.
 *
 * An HTTP status of 200 is "transport", never more. A cell whose level is below "recorded" cannot make a
 * feature "verified"; that rule lives in {@see status_engine} and is only repeated here for the reader.
 *
 * A further mode (kept out of this version, see docs/DOCUMENTATION.md) could raise a cell: instrumentation on the
 * partner system or a controlled test transaction with a reference tool. Until it exists, those cells stay
 * "not_observable".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observability {
    /** @var string Level: nothing visible. */
    public const NONE = 'not_observable';
    /** @var string Level: only a consequence is visible. */
    public const INFERRED = 'inferred';
    /** @var string Level: request and status code in a web server log. */
    public const TRANSPORT = 'transport';
    /** @var string Level: recorded by Moodle (event, database row, task log), without payload. */
    public const RECORDED = 'recorded';
    /** @var string Level: content seen by this system. */
    public const PAYLOAD = 'payload';

    /** @var string Direction: this system sends. */
    public const OUTBOUND = 'outbound';
    /** @var string Direction: this system receives. */
    public const INBOUND = 'inbound';

    /** @var string Source: own event observer. */
    public const SRC_OBSERVER = 'observer';
    /** @var string Source: imported web server log. */
    public const SRC_ACCESSLOG = 'accesslog';
    /** @var string Source: record created by the flow in a core table. */
    public const SRC_DBRECORD = 'dbrecord';
    /** @var string Source: scheduled or ad-hoc task log. */
    public const SRC_TASKLOG = 'tasklog';
    /** @var string Source: safe active probe of this plugin. */
    public const SRC_PROBE = 'probe';
    /** @var string Source: transfer events of an LTI family plugin, seen by the own observer. */
    public const SRC_FAMILYOBS = 'familyobserver';
    /** @var string Source: transfer events of an LTI family plugin, read from the log stores. */
    public const SRC_FAMILYLOG = 'familylog';

    /** @var string[] Levels in ascending order. */
    private const ORDER = [self::NONE, self::INFERRED, self::TRANSPORT, self::RECORDED, self::PAYLOAD];

    /**
     * All levels in ascending order.
     *
     * @return string[]
     */
    public static function levels(): array {
        return self::ORDER;
    }

    /**
     * Rank of a level.
     *
     * @param string $level Level.
     * @return int
     */
    public static function rank(string $level): int {
        $i = array_search($level, self::ORDER, true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * Source switches that a source needs before it can deliver anything.
     *
     * @return string[] Source => switch name (recordevents, accesslog, activechecks or '' for none)
     */
    public static function switches(): array {
        return [self::SRC_OBSERVER => 'recordevents', self::SRC_ACCESSLOG => 'accesslog', self::SRC_PROBE => 'activechecks',
            self::SRC_DBRECORD => '', self::SRC_TASKLOG => '', self::SRC_FAMILYOBS => 'recordevents',
            self::SRC_FAMILYLOG => 'logstore'];
    }

    /**
     * The specification of all transactions of a role: transaction id, direction and, per part, the candidate sources
     * with the level each can deliver and the fact it reads.
     *
     * A fact is [group, key] into the facts array of the fact collector (or "probe" for the key set probe).
     *
     * @param string $role platform or tool
     * @return array[]
     */
    public static function specification(string $role): array {
        $none = [];
        $log = function (string $category): array {
            return [self::SRC_ACCESSLOG, self::TRANSPORT, ['access', $category]];
        };
        if ($role === connection::ROLE_PLATFORM) {
            return [
                'oidc_login' => ['direction' => self::INBOUND, 'request' => [$log('oidc')], 'response' => [$log('oidc')]],
                'launch_message' => ['direction' => self::OUTBOUND,
                    'request' => [[self::SRC_OBSERVER, self::INFERRED, ['obs', 'view_out']]], 'response' => $none],
                'oauth_token' => ['direction' => self::INBOUND, 'request' => [$log('oauth')],
                    'response' => [[self::SRC_DBRECORD, self::RECORDED, ['tokens', 'any']], $log('oauth')]],
                'ags_scores' => ['direction' => self::INBOUND,
                    'request' => [[self::SRC_OBSERVER, self::RECORDED, ['obs', 'grade_in']], $log('service_ags_scores')],
                    'response' => [$log('service_ags_scores')]],
                'ags_lineitems' => ['direction' => self::INBOUND, 'request' => [$log('service_ags_lineitems')],
                    'response' => [$log('service_ags_lineitems')]],
                'ags_results' => ['direction' => self::INBOUND, 'request' => [$log('service_ags_results')],
                    'response' => [$log('service_ags_results')]],
                'nrps' => ['direction' => self::INBOUND, 'request' => [$log('service_nrps')], 'response' => [$log('service_nrps')]],
                'deeplinking' => ['direction' => self::INBOUND, 'request' => [$log('deeplinking')],
                    'response' => [$log('deeplinking')]],
                'jwks_fetch' => ['direction' => self::OUTBOUND, 'request' => [[self::SRC_PROBE, self::PAYLOAD, ['probe', 'jwks']]],
                    'response' => [[self::SRC_PROBE, self::PAYLOAD, ['probe', 'jwks']]]],
                // Completion sync (optional family plugin): the platform receives the completion message.
                'completion_sync' => ['direction' => self::INBOUND,
                    'request' => [[self::SRC_FAMILYOBS, self::RECORDED, ['family', 'http_received']],
                        [self::SRC_FAMILYLOG, self::RECORDED, ['family', 'http_received']], $log('completion_sync')],
                    'response' => [[self::SRC_FAMILYOBS, self::RECORDED, ['family', 'completion_applied']],
                        [self::SRC_FAMILYLOG, self::RECORDED, ['family', 'completion_applied']]]],
            ];
        }
        return [
            'oidc_login' => ['direction' => self::INBOUND, 'request' => [$log('oidc')], 'response' => [$log('oidc')]],
            'launch_message' => ['direction' => self::INBOUND,
                'request' => [[self::SRC_OBSERVER, self::RECORDED, ['obs', 'launch']], $log('launch')],
                'response' => [$log('launch')]],
            'local_effects' => ['direction' => self::INBOUND,
                'request' => $none,
                'response' => [[self::SRC_OBSERVER, self::RECORDED, ['obs', 'enrolment']]]],
            'oauth_token' => ['direction' => self::OUTBOUND, 'request' => $none, 'response' => $none],
            'ags_scores' => ['direction' => self::OUTBOUND,
                'request' => [[self::SRC_TASKLOG, self::RECORDED, ['tasklog', 'grades']]],
                'response' => $none],
            'nrps' => ['direction' => self::OUTBOUND,
                'request' => [[self::SRC_TASKLOG, self::RECORDED, ['tasklog', 'members']]], 'response' => $none],
            'deeplinking' => ['direction' => self::INBOUND, 'request' => [$log('deeplinking')],
                'response' => [$log('deeplinking')]],
            'jwks_fetch' => ['direction' => self::OUTBOUND, 'request' => [[self::SRC_PROBE, self::PAYLOAD, ['probe', 'jwks']]],
                'response' => [[self::SRC_PROBE, self::PAYLOAD, ['probe', 'jwks']]]],
            // Completion sync (optional family plugin): the tool sends the completion message and gets an acknowledgement.
            'completion_sync' => ['direction' => self::OUTBOUND,
                'request' => [[self::SRC_FAMILYOBS, self::RECORDED, ['family', 'sent']],
                    [self::SRC_FAMILYLOG, self::RECORDED, ['family', 'sent']]],
                'response' => [[self::SRC_FAMILYOBS, self::RECORDED, ['family', 'acknowledged']],
                    [self::SRC_FAMILYLOG, self::RECORDED, ['family', 'acknowledged']]]],
        ];
    }

    /**
     * Build the observability table of a connection.
     *
     * Per cell: level (what the current instrumentation can deliver), potential (what switching every available
     * source on could deliver), seen (time of the newest matching evidence, 0 if none: the level is a capability, only
     * a seen time makes it an observation), seenlevel (the highest level among the sources that really saw something),
     * sources (usable sources), basis (the sources behind the level), needs (switches to turn on to reach the potential).
     *
     * @param connection $c Connection.
     * @param array $facts Facts from the fact collector.
     * @param array $probes Active check results keyed by purpose.
     * @param array $enabled Source => true (usable), false (its switch is off) or a string naming a missing precondition;
     *        sources not listed count as usable. See {@see self::enabled_sources()} and {@see self::family_sources()}.
     * @return array[] Rows keyed by transaction id: transaction, direction, request, response, capable (bool)
     */
    public static function build(connection $c, array $facts, array $probes, array $enabled): array {
        $rows = [];
        foreach (self::specification($c->role) as $id => $spec) {
            $request = self::cell($spec['request'], $facts, $probes, $enabled);
            $response = self::cell($spec['response'], $facts, $probes, $enabled);
            $rows[$id] = [
                'transaction' => $id,
                'direction' => $spec['direction'],
                'request' => $request,
                'response' => $response,
                // Capable means: both parts could be recorded by Moodle itself. Transport-only or inferred never counts,
                // and capable is not observed: see the "seen" time of each cell.
                'capable' => self::rank($request['level']) >= self::rank(self::RECORDED)
                    && self::rank($response['level']) >= self::rank(self::RECORDED),
            ];
        }
        return $rows;
    }

    /**
     * Build one cell.
     *
     * @param array[] $candidates Each [source, level, [group, key]].
     * @param array $facts Facts.
     * @param array $probes Probes.
     * @param array $enabled Source switches.
     * @return array level, potential, seen, sources, needs
     */
    private static function cell(array $candidates, array $facts, array $probes, array $enabled): array {
        $cell = ['level' => self::NONE, 'potential' => self::NONE, 'seen' => 0, 'sources' => [], 'needs' => [],
            'seenlevel' => self::NONE];
        foreach ($candidates as [$source, $level, $fact]) {
            if (self::rank($level) > self::rank($cell['potential'])) {
                $cell['potential'] = $level;
            }
            // A family source is only usable when the caller says so: its emitting plugin is optional.
            $default = in_array($source, [self::SRC_FAMILYOBS, self::SRC_FAMILYLOG], true) ? 'completionsync' : true;
            $usable = $enabled[$source] ?? $default;
            if ($usable !== true) {
                // False: the source's switch is off. A string: the named precondition is missing.
                $switch = is_string($usable) ? $usable : (self::switches()[$source] ?? '');
                if ($switch !== '' && !in_array($switch, $cell['needs'], true)) {
                    $cell['needs'][] = $switch;
                }
                continue;
            }
            if (self::rank($level) > self::rank($cell['level'])) {
                $cell['level'] = $level;
            }
            $cell['sources'][] = $source;
            $seen = self::seen($fact, $facts, $probes);
            $cell['seen'] = max($cell['seen'], $seen);
            // The level of what was really seen: a web server line (transport) never lifts a cell whose recorded
            // source saw nothing.
            if ($seen > 0 && self::rank($level) > self::rank($cell['seenlevel'])) {
                $cell['seenlevel'] = $level;
            }
        }
        $cell['sources'] = array_values(array_unique($cell['sources']));
        // Only sources that deliver at the reached level are listed as the basis of the level.
        $cell['basis'] = [];
        foreach ($candidates as [$source, $level]) {
            if ($level === $cell['level'] && in_array($source, $cell['sources'], true)) {
                $cell['basis'][] = $source;
            }
        }
        $cell['basis'] = array_values(array_unique($cell['basis']));
        return $cell;
    }

    /**
     * The time of the newest evidence behind a fact.
     *
     * @param array $fact [group, key]
     * @param array $facts Facts.
     * @param array $probes Probes.
     * @return int Unix time or 0.
     */
    private static function seen(array $fact, array $facts, array $probes): int {
        [$group, $key] = $fact;
        if ($group === 'probe') {
            $p = $probes[$key] ?? null;
            // A failed probe received no content: nothing was seen.
            return is_array($p) && ($p['code'] ?? '') === 'ok' ? (int) ($p['checkedat'] ?? 0) : 0;
        }
        if ($group === 'access') {
            return (int) ($facts['access'][$key]['last'] ?? 0);
        }
        if ($group === 'tasklog') {
            return (int) max($facts['tasklog'][$key]['ok'] ?? 0, $facts['tasklog'][$key]['failed'] ?? 0);
        }
        return (int) ($facts[$group][$key] ?? 0);
    }

    /**
     * Source switches from the current configuration.
     *
     * @param int $imports Number of finished log imports.
     * @return bool[] Source => usable
     */
    public static function enabled_sources(int $imports): array {
        $recordevents = (bool) config::get('recordevents');
        $logging = false;
        foreach ((new logstore_source())->describe() as $reader) {
            $logging = $logging || $reader['logging'];
        }
        return [
            self::SRC_OBSERVER => $recordevents,
            self::SRC_ACCESSLOG => $imports > 0,
            self::SRC_PROBE => (bool) config::get('activechecks'),
            self::SRC_DBRECORD => true,
            self::SRC_TASKLOG => true,
        ] + self::family_sources($recordevents, $logging, family_events::emitter_installed());
    }

    /**
     * Usability of the family sources. Without the emitting plugin nothing can be recorded at all; the cell then names
     * the missing plugin instead of a switch.
     *
     * @param bool $recordevents Own event recording on.
     * @param bool $logging At least one log store is logging.
     * @param bool $emitter The completion sync plugin is installed.
     * @return array Source => true, false (switch off) or the name of the missing precondition.
     */
    public static function family_sources(bool $recordevents, bool $logging, bool $emitter): array {
        if (!$emitter) {
            return [self::SRC_FAMILYOBS => 'completionsync', self::SRC_FAMILYLOG => 'completionsync'];
        }
        return [self::SRC_FAMILYOBS => $recordevents, self::SRC_FAMILYLOG => $logging];
    }
}
