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

use tool_ltidiagnostics\local\accesslog\importer;

/**
 * Collects the observable facts of one connection from core tables and the plugin's evidence tables.
 *
 * Read only. Returns plain arrays so the matrix builder stays a pure function of its input.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fact_collector {
    /**
     * Collect facts.
     *
     * @param connection $c The connection.
     * @param int $now Current time.
     * @param array $filters Optional: from, to, courseid, cmid.
     * @return array
     */
    public function collect(connection $c, int $now, array $filters = []): array {
        $obs = observation_store::get_for_connection($c, $filters + ['limit' => 1000]);
        $facts = [
            'now' => $now,
            'implemented' => implementation_detector::detect()[$c->role],
            'obs' => $this->summarise_observations($obs),
            'access' => $this->summarise_access($now, $filters),
            'tasklog' => $this->summarise_tasklog($filters),
            'tokens' => [],
            'tool' => [],
        ];
        if ($c->role === connection::ROLE_PLATFORM) {
            $facts['tokens'] = $this->platform_tokens($c);
        } else {
            $facts['tool'] = $this->tool_facts($c);
        }
        return $facts;
    }

    /**
     * Summarise observations: last time per valid evidence class.
     *
     * @param \stdClass[] $obs Observations, newest first.
     * @return array
     */
    private function summarise_observations(array $obs): array {
        $s = ['launch' => 0, 'enrolment' => 0, 'group' => 0, 'grade_in' => 0, 'grade_local' => 0, 'view_out' => 0,
            'account_created' => 0, 'service_unknown' => 0, 'count' => count($obs)];
        foreach ($obs as $o) {
            $t = (int) $o->timecreated;
            if ($o->eventtype === 'launch' || $o->eventtype === 'launch_sync') {
                // A login outside the launch script is no evidence of a launch.
                if ($o->entrypoint === entrypoint::LTI_LAUNCH) {
                    $s['launch'] = max($s['launch'], $t);
                }
            } else if ($o->eventtype === 'grade') {
                $key = $o->direction === 'in' && $o->entrypoint === entrypoint::LTI_SERVICES ? 'grade_in' : 'grade_local';
                $s[$key] = max($s[$key], $t);
            } else if ($o->eventtype === 'view') {
                $s['view_out'] = max($s['view_out'], $t);
            } else if (isset($s[$o->eventtype])) {
                $s[$o->eventtype] = max($s[$o->eventtype], $t);
            }
        }
        return $s;
    }

    /**
     * Imported access log evidence per category: successful and failed requests.
     *
     * @param int $now Current time.
     * @param array $filters Filters (from, to).
     * @return array
     */
    private function summarise_access(int $now, array $filters): array {
        $from = !empty($filters['from']) ? (int) $filters['from'] : $now - 30 * DAYSECS;
        $to = !empty($filters['to']) ? (int) $filters['to'] : 0;
        $out = [];
        foreach (importer::get_rows($from, $to, [], 1000) as $r) {
            if ($r->kind !== 'access') {
                continue;
            }
            $key = ($r->httpstatus >= 200 && $r->httpstatus < 400) ? 'ok' : 'fail';
            $out[$r->category][$key] = ($out[$r->category][$key] ?? 0) + 1;
            $out[$r->category]['last'] = max($out[$r->category]['last'] ?? 0, (int) $r->logtime);
        }
        return $out;
    }

    /**
     * Last successful and failed run of the grade and member sync tasks.
     *
     * @param array $filters Filters (from, to).
     * @return array
     */
    private function summarise_tasklog(array $filters): array {
        $source = new task_source();
        $out = [];
        foreach ($source->task_log($filters + ['limit' => 500]) as $row) {
            foreach (['sync_grades' => 'grades', 'sync_members' => 'members'] as $needle => $key) {
                if (strpos($row['classname'], $needle) !== false) {
                    $slot = $row['failed'] ? 'failed' : 'ok';
                    $out[$key][$slot] = max($out[$key][$slot] ?? 0, (int) $row['start']);
                }
            }
        }
        return $out;
    }

    /**
     * Access tokens issued by this platform for a tool type (token values are never read).
     *
     * @param connection $c Platform connection.
     * @return array ags, nrps, any: last issue time or 0
     */
    private function platform_tokens(connection $c): array {
        global $DB;
        $out = ['ags' => 0, 'nrps' => 0, 'any' => 0];
        if (!$DB->get_manager()->table_exists('lti_access_tokens')) {
            return $out;
        }
        $rs = $DB->get_recordset(
            'lti_access_tokens',
            ['typeid' => $c->localid],
            'timecreated DESC',
            'id, scope, timecreated',
            0,
            200
        );
        foreach ($rs as $t) {
            $time = (int) $t->timecreated;
            $out['any'] = max($out['any'], $time);
            if (strpos((string) $t->scope, 'lti-ags') !== false) {
                $out['ags'] = max($out['ags'], $time);
            }
            if (strpos((string) $t->scope, 'lti-nrps') !== false) {
                $out['nrps'] = max($out['nrps'], $time);
            }
        }
        $rs->close();
        return $out;
    }

    /**
     * Tool-side facts: registration, published tools, resource link claims, grade sync results.
     *
     * @param connection $c Tool connection.
     * @return array
     */
    private function tool_facts(connection $c): array {
        global $DB;
        $f = [
            'complete' => $c->state === 'complete',
            'hasauth' => !empty($c->endpoints['auth']),
            'hasjwks' => !empty($c->endpoints['jwks']),
            'hasoauth' => !empty($c->endpoints['oauth']),
            'tools' => count($c->tools),
            'gradesync' => false,
            'membersync' => false,
            'links' => 0,
            'agsclaim' => false,
            'scorescope' => false,
            'nrpsclaim' => false,
            'lastgrade' => false,
        ];
        foreach ($c->tools as $tool) {
            if (!empty($tool['enabled'])) {
                $f['gradesync'] = $f['gradesync'] || !empty($tool['gradesync']);
                $f['membersync'] = $f['membersync'] || !empty($tool['membersync']);
            }
        }
        if ($c->deploymentrowid > 0 && $DB->get_manager()->table_exists('enrol_lti_resource_link')) {
            $rs = $DB->get_recordset(
                'enrol_lti_resource_link',
                ['ltideploymentid' => $c->deploymentrowid],
                '',
                'id, lineitemsservice, lineitemservice, scorescope, contextmembershipsurl'
            );
            foreach ($rs as $l) {
                $f['links']++;
                $f['agsclaim'] = $f['agsclaim'] || !empty($l->lineitemsservice) || !empty($l->lineitemservice);
                $f['scorescope'] = $f['scorescope'] || !empty($l->scorescope);
                $f['nrpsclaim'] = $f['nrpsclaim'] || !empty($l->contextmembershipsurl);
            }
            $rs->close();
        }
        if ($c->tools) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($c->tools), SQL_PARAMS_NAMED);
            $f['lastgrade'] = $DB->record_exists_select('enrol_lti_users', "toolid $insql AND lastgrade IS NOT NULL", $params);
        }
        return $f;
    }
}
