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

namespace tool_ltidiagnostics\local\completion;

use tool_ltidiagnostics\local\connection;

/**
 * Completion channel of a connection (issue #7): read-only view of local_lticompletionsync's own state.
 *
 * Nothing here sends, queues or changes anything, and nothing is implemented twice: the outbox, the signed applied
 * acknowledgements, retry, requeue, reconcile and force resync stay in local_lticompletionsync. This class classifies
 * its rows and names the plugin's existing actions with their real reach (a force resync affects the whole connection,
 * there is no per-learner action). Grades, scores and lastgrade are not used for completion. An optional AGS progress of
 * the completion plugin is reported as tool-only and unconfirmed, never as native completion.
 *
 * Works without local_lticompletionsync (the view says so); the completion plugin does not depend on this plugin.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_view {
    /** @var string Component. */
    public const COMPONENT = 'local_lticompletionsync';
    /** @var string[] Classes of a sender row, in the order of the delivery. */
    public const CLASSES = ['pending', 'sent_tool_only', 'retry', 'acked_unapplied', 'acked_applied', 'rejected', 'blocked',
        'dead', 'status_unknown'];
    /** @var string[] Classes of a receiver row. */
    public const RECEIVER_CLASSES = ['received', 'validated', 'applied', 'rejected', 'conflict', 'status_unknown'];
    /** @var int Page size. */
    public const PAGESIZE = 100;

    /**
     * Whether the completion plugin's data is there (its tables exist). Reading needs nothing else.
     *
     * @return bool
     */
    public static function installed(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_lticompletionsync_con') && $dbman->table_exists('local_lticompletionsync_out');
    }

    /**
     * Whether the completion plugin's code is installed (its actions and capability exist).
     *
     * @return bool
     */
    public static function present(): bool {
        return \core_component::get_component_directory(self::COMPONENT) !== null
            && get_capability_info('local/lticompletionsync:manageconnections') !== null;
    }

    /**
     * Class of a sender (outbox) row.
     *
     * @param \stdClass $r Outbox row.
     * @return string
     */
    public static function classify(\stdClass $r): string {
        $status = (string) $r->status;
        $ack = (string) ($r->ackstatus ?? '');
        if ($ack === 'rejected') {
            return 'rejected';
        }
        switch ($status) {
            case 'pending':
                return 'pending';
            case 'sent':
                return 'sent_tool_only';
            case 'retry':
                return 'retry';
            case 'blocked':
                return 'blocked';
            case 'dead':
                return 'dead';
            case 'acked':
                return $ack === 'applied' && (int) $r->ackversion >= (int) $r->version ? 'acked_applied' : 'acked_unapplied';
        }
        return 'status_unknown';
    }

    /**
     * Build the view.
     *
     * @param connection $conn Connection.
     * @param array $options offset, limit.
     * @return array installed, role (sender, receiver or ''), connections (rows of the plugin's register), summary
     *     (class => count), rows (page), total, actions (available actions with reach, only when the user may use them).
     */
    public function build(connection $conn, array $options = []): array {
        global $DB;
        $out = ['installed' => self::installed(), 'role' => '', 'connections' => [], 'summary' => [], 'rows' => [],
            'total' => 0, 'offset' => 0, 'actions' => [], 'agsprogress' => false];
        if (!$out['installed']) {
            return $out;
        }
        if ($conn->role === connection::ROLE_TOOL) {
            $out['role'] = 'sender';
            $cons = $DB->get_records('local_lticompletionsync_con', ['role' => 'sender', 'registrationid' => $conn->localid,
                'deploymentid' => $conn->deploymentrowid], 'id', 'id, enabled, partnerstatus, partnerversion, timechecked,
                agsprogress, endpointurl');
        } else {
            $out['role'] = 'receiver';
            $cons = $DB->get_records(
                'local_lticompletionsync_con',
                ['role' => 'receiver', 'ltitypeid' => $conn->localid],
                'id',
                'id, enabled, conflictpolicy, automanage, lockdown'
            );
        }
        $out['connections'] = array_values($cons);
        if (!$cons) {
            return $out;
        }
        $ids = array_keys($cons);
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'c');
        $limit = max(1, min(500, (int) ($options['limit'] ?? self::PAGESIZE)));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $out['offset'] = $offset;
        if ($out['role'] === 'sender') {
            $out['agsprogress'] = (bool) array_filter(array_column($out['connections'], 'agsprogress'));
            // Counts per raw status and acknowledgement: one grouped query.
            $rs = $DB->get_recordset_sql("SELECT status, ackstatus,
                                                 CASE WHEN ackversion >= version THEN 1 ELSE 0 END AS current,
                                                 CASE WHEN desiredstate = 0 AND version > 1 THEN 1 ELSE 0 END AS revoked,
                                                 COUNT(1) AS n
                                            FROM {local_lticompletionsync_out}
                                           WHERE connectionid $insql
                                        GROUP BY status, ackstatus, CASE WHEN ackversion >= version THEN 1 ELSE 0 END,
                                                 CASE WHEN desiredstate = 0 AND version > 1 THEN 1 ELSE 0 END", $params);
            foreach ($rs as $g) {
                $class = self::classify((object) ['status' => $g->status, 'ackstatus' => $g->ackstatus,
                    'ackversion' => (int) $g->current, 'version' => 1]);
                $out['summary'][$class] = ($out['summary'][$class] ?? 0) + (int) $g->n;
                if ((int) $g->revoked) {
                    $out['summary']['revoked'] = ($out['summary']['revoked'] ?? 0) + (int) $g->n;
                }
                $out['total'] += (int) $g->n;
            }
            $rs->close();
            $rows = $DB->get_records_select(
                'local_lticompletionsync_out',
                "connectionid $insql",
                $params,
                'timemodified DESC, id DESC',
                'id, connectionid, resourcelinkid, userid, sourcekind, sourceid, desiredstate,
                 version, statetime, correlationid, status, sentversion, ackversion, ackstatus, attempts, nextattempt, lasterror,
                 agsstatus, timemodified',
                $offset,
                $limit
            );
            foreach ($rows as $r) {
                $out['rows'][] = [
                    'id' => (int) $r->id,
                    'connectionid' => (int) $r->connectionid,
                    'resourcelinkid' => (int) $r->resourcelinkid,
                    'person' => \tool_ltidiagnostics\local\grades\grade_diagnosis::pseudonym($conn->key, (int) $r->userid),
                    'source' => (string) $r->sourcekind . ' ' . (int) $r->sourceid,
                    'desiredstate' => (int) $r->desiredstate,
                    'revoked' => (int) $r->desiredstate === 0 && (int) $r->version > 1,
                    'version' => (int) $r->version,
                    'statetime' => (int) $r->statetime,
                    'correlationid' => (string) $r->correlationid,
                    'class' => self::classify($r),
                    'sentversion' => (int) $r->sentversion,
                    'ackversion' => (int) $r->ackversion,
                    'ackstatus' => (string) $r->ackstatus,
                    'attempts' => (int) $r->attempts,
                    'nextattempt' => (int) $r->nextattempt,
                    'lasterror' => \tool_ltidiagnostics\local\redactor::text((string) $r->lasterror),
                    'agsstatus' => (string) $r->agsstatus,
                    'timemodified' => (int) $r->timemodified,
                ];
            }
        } else if ($DB->get_manager()->table_exists('local_lticompletionsync_rcv')) {
            $rs = $DB->get_recordset_sql("SELECT status, COUNT(1) AS n FROM {local_lticompletionsync_rcv}
                                           WHERE connectionid $insql GROUP BY status", $params);
            foreach ($rs as $g) {
                $class = in_array((string) $g->status, self::RECEIVER_CLASSES, true) ? (string) $g->status : 'status_unknown';
                $out['summary'][$class] = ($out['summary'][$class] ?? 0) + (int) $g->n;
                $out['total'] += (int) $g->n;
            }
            $rs->close();
            $rows = $DB->get_records_select(
                'local_lticompletionsync_rcv',
                "connectionid $insql",
                $params,
                'timemodified DESC, id DESC',
                'id, connectionid, cmid, userid, remoteversion, remotestate, remotetime,
                 appliedversion, status, reason, correlationid, timemodified',
                $offset,
                $limit
            );
            foreach ($rows as $r) {
                $out['rows'][] = [
                    'id' => (int) $r->id,
                    'connectionid' => (int) $r->connectionid,
                    'cmid' => (int) $r->cmid,
                    'person' => \tool_ltidiagnostics\local\grades\grade_diagnosis::pseudonym($conn->key, (int) $r->userid),
                    'remoteversion' => (int) $r->remoteversion,
                    'remotestate' => (int) $r->remotestate,
                    'appliedversion' => (int) $r->appliedversion,
                    'class' => in_array((string) $r->status, self::RECEIVER_CLASSES, true) ? (string) $r->status
                        : 'status_unknown',
                    'reason' => \tool_ltidiagnostics\local\redactor::text((string) $r->reason),
                    'correlationid' => (string) $r->correlationid,
                    'timemodified' => (int) $r->timemodified,
                ];
            }
        }
        $out['actions'] = self::actions($out['role'], array_keys($cons));
        return $out;
    }

    /**
     * The completion plugin's own actions this user may trigger, with their reach. Only when the plugin is installed and
     * the user holds its capability; the buttons post to the plugin's own page (sesskey checked there).
     *
     * @param string $role sender or receiver.
     * @param int[] $connectionids Connection ids of the plugin.
     * @param bool|null $present Whether the plugin's code is there (null: detect).
     * @param callable|null $can Capability check (null: local/lticompletionsync:manageconnections in the system context).
     * @return array[] action, connectionid, reach (string id), url (moodle_url).
     */
    public static function actions(string $role, array $connectionids, ?bool $present = null, ?callable $can = null): array {
        $present = $present ?? self::present();
        $can = $can ?? fn() => has_capability('local/lticompletionsync:manageconnections', \context_system::instance());
        if (!$present || $role !== 'sender' || !$can()) {
            return [];
        }
        $out = [];
        foreach ($connectionids as $id) {
            foreach (['probe', 'retrynow', 'requeue', 'reconcile', 'resync'] as $action) {
                $out[] = [
                    'action' => $action,
                    'connectionid' => (int) $id,
                    'reach' => 'creach_' . $action,
                    'url' => new \moodle_url('/local/lticompletionsync/index.php', ['action' => $action, 'id' => (int) $id]),
                ];
            }
        }
        return $out;
    }
}
