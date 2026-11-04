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

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\redactor;

/**
 * Connection related grade diagnosis of the tool side (issue #3). Read only.
 *
 * One row per consumer resource link and learner of one connection (issuer, client, deployment). The same local learner
 * reached through another connection is never merged into this view: its rows belong to that connection, here they
 * only show up as a risk ("also linked through another consumer"), because core keeps one lastgrade and one LTI subject
 * per learner and published resource, shared by all consumers.
 *
 * Two statements per row are kept apart:
 *  - prediction: what core's sync_tool_grades task does with this row on its next run (send or skip, with the reason),
 *    derived from the enrol_lti configuration, the gradebook, the completion state and lastgrade;
 *  - evidence: the last thing that is known to have happened (journal: task log or own replay; booking evidence).
 * Neither a launch, a successful task run, lastgrade nor an existing line item is ever presented as a booked grade.
 *
 * Every observation names its source, time, certainty and the limit of what can be seen. Queries are per data kind and
 * page, never per row.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_diagnosis {
    /** @var int Default page size (link and learner pairs). */
    public const PAGESIZE = 100;
    /** @var int Maximum page size. */
    public const MAXPAGE = 500;
    /** @var string Scheduled task of core that queues the per-resource sync. */
    public const SCHEDULEDCLASS = '\\enrol_lti\\local\\ltiadvantage\\task\\sync_grades';

    /** @var string[] Predictions (core's next run), in the order of core's checks. */
    public const PREDICTIONS = [
        'prereq_missing', 'publication_disabled', 'gradesync_off', 'invalid_context', 'no_service', 'no_scope',
        'gated', 'no_grade', 'no_grademax', 'lastgrade_unchanged', 'would_send',
    ];

    /** @var string[] Evidence ladder shown per row, weakest first. */
    public const LADDER = [
        'not_attempted', 'skipped', 'http_attempt', 'error', 'http_accepted_unconfirmed', 'remote_unobservable',
        'remote_mismatch', 'results_match', 'remote_booked',
    ];

    /** @var source_reader Source reader. */
    private $reader;

    /**
     * Constructor.
     *
     * @param source_reader|null $reader Reader.
     */
    public function __construct(?source_reader $reader = null) {
        $this->reader = $reader ?? new source_reader();
    }

    /**
     * Pseudonym of a learner, stable per connection, not reversible without the site identifier.
     *
     * @param string $connkey Connection key.
     * @param int $userid Local user id.
     * @return string
     */
    public static function pseudonym(string $connkey, int $userid): string {
        return 'P-' . strtoupper(substr(hash_hmac('sha256', $connkey . ':' . $userid, get_site_identifier()), 0, 8));
    }

    /**
     * Run the diagnosis.
     *
     * @param connection $conn Connection (tool role with a deployment).
     * @param array $options offset, limit, resourcelinkrowid, userid, reveal (bool: identifiers in clear),
     *     showscores (bool: score values; else only none/null/zero/value), now (int), importtasklog (bool, default true),
     *     courseid, cmid (scope), invalidscope (bool: the scope is invalid, nothing matches; issue #29).
     * @return array See the class comment; keys connkey, generated, applicable, prerequisites, resources, links, rows,
     *     total, offset, limit, summary, findings.
     */
    public function run(connection $conn, array $options = []): array {
        $now = (int) ($options['now'] ?? time());
        $out = [
            'connkey' => $conn->key,
            'generated' => $now,
            'applicable' => $conn->role === connection::ROLE_TOOL && $conn->deploymentrowid > 0,
            'prerequisites' => [],
            'resources' => [],
            'links' => [],
            'rows' => [],
            'total' => 0,
            'offset' => 0,
            'limit' => 0,
            'summary' => ['prediction' => [], 'evidence' => []],
            'findings' => [],
            'tasklog' => null,
            'counters' => pair_query::empty_counters(),
            'scopelinks' => [],
            'scopetools' => [],
            'scoped' => false,
            'filters' => pair_query::clean([]),
        ];
        if (!$out['applicable']) {
            return $out;
        }
        if ($options['importtasklog'] ?? true) {
            $out['tasklog'] = (new tasklog_importer())->import();
        }
        $reveal = !empty($options['reveal']);
        $showscores = !empty($options['showscores']);
        $limit = max(1, min(self::MAXPAGE, (int) ($options['limit'] ?? self::PAGESIZE)));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $out['limit'] = $limit;
        $out['offset'] = $offset;

        $out['prerequisites'] = $this->prerequisites($now);
        $links = $this->links($conn, $reveal);
        $resources = [];
        foreach (array_unique(array_column($links, 'toolid')) as $toolid) {
            $res = $this->reader->resource((int) $toolid);
            if ($res) {
                $resources[(int) $toolid] = $res;
            }
        }
        // Scope (issue #13) and publication filters work on the published resources, in SQL through their link ids.
        $courseid = (int) ($options['courseid'] ?? 0);
        $cmid = (int) ($options['cmid'] ?? 0);
        $filters = pair_query::clean(($options['filters'] ?? []) + [
            'linkid' => (int) ($options['resourcelinkrowid'] ?? 0),
            'userid' => (int) ($options['userid'] ?? 0),
        ]);
        // Published resources inside the scope (issue #29): the publication list, the findings and the population use only
        // these. An invalid scope (unknown or contradictory course or activity, invalid period) matches nothing.
        $invalid = !empty($options['invalidscope']);
        $scopetools = $invalid ? [] : self::tools_in_scope($resources, $courseid, $cmid, $filters);
        $inscope = [];
        foreach ($links as $id => $link) {
            if (in_array($link['toolid'], $scopetools, true)) {
                $inscope[$id] = $link;
            }
        }
        $out['scopelinks'] = array_keys($inscope);
        $out['scopetools'] = $scopetools;
        $out['scoped'] = $invalid || $courseid > 0 || $cmid > 0;
        $out['filters'] = $filters;
        $items = [];
        foreach ($resources as $toolid => $res) {
            $items[$toolid] = (int) $res['itemid'];
        }
        $page = pair_query::page(
            $conn->key,
            (int) $conn->deploymentrowid,
            $scopetools,
            $items,
            $filters,
            $offset,
            $limit
        );
        $pairs = $page['pairs'];
        // Links of the page that the bounded link list did not load (deployments with very many links).
        $missing = array_diff(array_map(fn($p) => (int) $p->resourcelinkrowid, $pairs), array_keys($links));
        if ($missing) {
            $links += $this->links($conn, $reveal, array_values(array_unique($missing)));
        }
        $out['links'] = $links;
        $out['total'] = $page['total'];
        $out['counters'] = $page['counters'];
        $out['resources'] = $resources;

        // Bulk data for the page.
        $userids = array_values(array_unique(array_map(fn($p) => (int) $p->userid, $pairs)));
        $grades = [];
        foreach ($resources as $toolid => $res) {
            $users = array_values(array_unique(array_map(
                fn($p) => (int) $p->userid,
                array_filter($pairs, fn($p) => (int) $p->toolid === $toolid)
            )));
            $grades[$toolid] = $users ? $this->reader->grades($res, $users) : [];
        }
        $subjects = $this->linked_subjects($conn->issuer, $userids);
        $deployments = $this->deployments_per_ltiuser(array_map(fn($p) => (int) $p->ltiuserid, $pairs));
        $linksonthisdep = $this->links_per_ltiuser_here($pairs);
        // The last transfer outcome per link and learner; a later skip does not erase it, it is used only when no transfer
        // is known at all.
        $latest = journal::latest($conn->key, array_keys($links), $userids, '', true)
            + journal::latest($conn->key, array_keys($links), $userids);
        $latestpassive = $this->latest_unattributed($userids, array_keys($resources));

        foreach ($pairs as $p) {
            $toolid = (int) $p->toolid;
            $userid = (int) $p->userid;
            $link = $links[(int) $p->resourcelinkrowid];
            $res = $resources[$toolid] ?? null;
            $src = $grades[$toolid][$userid] ?? null;
            $row = $this->row($conn, $link, $res, $src, $p, [
                'prereq' => $out['prerequisites'],
                'subjects' => $subjects[$userid] ?? [],
                'deployments' => $deployments[(int) $p->ltiuserid] ?? [],
                'linkshere' => $linksonthisdep[(int) $p->ltiuserid] ?? 1,
                'journal' => $latest[(int) $p->resourcelinkrowid . ':' . $userid] ?? null,
                'passive' => $latestpassive[$toolid . ':' . $userid] ?? null,
                'reveal' => $reveal,
                'showscores' => $showscores,
                'now' => $now,
            ]);
            $out['rows'][] = $row;
            $out['summary']['prediction'][$row['prediction']] = ($out['summary']['prediction'][$row['prediction']] ?? 0) + 1;
            $out['summary']['evidence'][$row['evidence']['level']] =
                ($out['summary']['evidence'][$row['evidence']['level']] ?? 0) + 1;
        }
        $out['findings'] = $this->findings($out);
        return $out;
    }

    /**
     * Published resources (enrol_lti tool ids) of the connection inside a course/activity scope.
     *
     * @param connection $conn Connection.
     * @param \tool_ltidiagnostics\local\scope $scope Scope.
     * @return int[]|null Null when the scope does not restrict courses or activities.
     */
    public function scope_toolids(connection $conn, \tool_ltidiagnostics\local\scope $scope): ?array {
        global $DB;
        if ($scope->invalid) {
            // Fail closed (issue #29): an invalid scope matches no publication.
            return [];
        }
        if (!$scope->courseid && !$scope->cmid) {
            return null;
        }
        $ids = $DB->get_fieldset_select(
            'enrol_lti_resource_link',
            'DISTINCT resourceid',
            'ltideploymentid = :dep',
            ['dep' => (int) $conn->deploymentrowid]
        );
        $out = [];
        foreach ($ids as $toolid) {
            $res = $this->reader->resource((int) $toolid);
            if (
                $res && (!$scope->courseid || $res['courseid'] === $scope->courseid)
                && (!$scope->cmid || $res['cmid'] === $scope->cmid)
            ) {
                $out[] = (int) $toolid;
            }
        }
        return $out;
    }

    /**
     * Every (link, learner) pair of the filtered population, bounded (server side "select every match", issue #22).
     *
     * @param connection $conn Connection.
     * @param \tool_ltidiagnostics\local\scope $scope Scope.
     * @param array $filters Clean pair filters.
     * @param int $max Maximum; more matches return no pairs and the total.
     * @return array [pairs (linkid, userid), total]
     */
    public function matching_pairs(connection $conn, \tool_ltidiagnostics\local\scope $scope, array $filters, int $max): array {
        if ($conn->role !== connection::ROLE_TOOL || !$conn->deploymentrowid) {
            return [[], 0];
        }
        $links = $this->links($conn, false);
        $resources = [];
        foreach (array_unique(array_column($links, 'toolid')) as $toolid) {
            if ($res = $this->reader->resource((int) $toolid)) {
                $resources[(int) $toolid] = $res;
            }
        }
        $filters = pair_query::clean($filters);
        $items = array_map(fn($r) => (int) $r['itemid'], $resources);
        // An invalid scope (also an invalid period alone) matches nothing (issue #29, fail closed).
        $tools = $scope->invalid ? [] : self::tools_in_scope($resources, $scope->courseid, $scope->cmid, $filters);
        return pair_query::all($conn->key, (int) $conn->deploymentrowid, $tools, $items, $filters, $max);
    }

    /**
     * Published resources (tool ids) inside the course/activity scope and the grade sync filter.
     *
     * @param array $resources Tool id => resource (courseid, cmid, gradesync).
     * @param int $courseid Course or 0.
     * @param int $cmid Course module or 0.
     * @param array $filters Clean pair filters (gradesync).
     * @return int[]
     */
    private static function tools_in_scope(array $resources, int $courseid, int $cmid, array $filters): array {
        $out = [];
        foreach ($resources as $toolid => $res) {
            if (
                ($courseid && $res['courseid'] !== $courseid) || ($cmid && $res['cmid'] !== $cmid)
                || ($filters['gradesync'] !== '' && (string) (int) $res['gradesync'] !== $filters['gradesync'])
            ) {
                continue;
            }
            $out[] = (int) $toolid;
        }
        return $out;
    }

    /**
     * Prerequisites of core's grade sync: auth_lti and enrol_lti enabled, scheduled task state, ad-hoc queue per resource.
     *
     * @param int $now Now.
     * @return array authlti, enrollti (bool), scheduled (array|null: lastrun, nextrun, disabled, faildelay), adhoc
     *     (resource id => queued, failed, nextrun), lastruns (resource id => time, failed), observedat (int).
     */
    public function prerequisites(int $now): array {
        global $DB;
        $out = [
            'authlti' => is_enabled_auth('lti'),
            'enrollti' => enrol_is_enabled('lti'),
            'scheduled' => null,
            'adhoc' => [],
            'lastruns' => [],
            'observedat' => $now,
            'tasklogmode' => (int) (get_config('core', 'task_logmode') === false ? 1 : get_config('core', 'task_logmode')),
        ];
        $s = $DB->get_record(
            'task_scheduled',
            ['classname' => self::SCHEDULEDCLASS],
            'id, lastruntime, nextruntime, disabled, faildelay'
        );
        if ($s) {
            $out['scheduled'] = [
                'lastrun' => (int) $s->lastruntime,
                'nextrun' => (int) $s->nextruntime,
                'disabled' => (int) $s->disabled,
                'faildelay' => (int) $s->faildelay,
            ];
        }
        $rs = $DB->get_recordset_select(
            'task_adhoc',
            'classname = :c',
            ['c' => '\\' . tasklog_parser::TASKCLASS],
            'id ASC',
            'id, customdata, faildelay, nextruntime',
            0,
            2000
        );
        foreach ($rs as $t) {
            $data = json_decode((string) $t->customdata);
            $rid = is_object($data) && isset($data->id) ? (int) $data->id : 0;
            $entry = $out['adhoc'][$rid] ?? ['queued' => 0, 'failed' => 0, 'nextrun' => 0];
            $entry['queued']++;
            $entry['failed'] += (int) ((int) $t->faildelay > 0);
            $entry['nextrun'] = $entry['nextrun'] ? min($entry['nextrun'], (int) $t->nextruntime) : (int) $t->nextruntime;
            $out['adhoc'][$rid] = $entry;
        }
        $rs->close();
        // Newest runs of the per-resource task, bounded; the resource id is read from the first output line.
        $runs = $DB->get_records_select(
            'task_log',
            'classname = :c',
            ['c' => tasklog_parser::TASKCLASS],
            'id DESC',
            'id, timestart, result, output',
            0,
            100
        );
        foreach ($runs as $run) {
            if (preg_match("/shared resource '(\\d+)'/", substr((string) $run->output, 0, 500), $m)) {
                $rid = (int) $m[1];
                if (!isset($out['lastruns'][$rid])) {
                    $out['lastruns'][$rid] = ['time' => (int) $run->timestart, 'failed' => (int) $run->result === 1];
                }
            }
        }
        return $out;
    }

    /**
     * Resource links of the deployment with their AGS claims (URLs masked unless revealed, scopes as flags).
     *
     * @param connection $conn Connection.
     * @param bool $reveal Show identifiers in clear.
     * @param int[]|null $ids Only these rows (null: the first 5 000 of the deployment).
     * @return array Keyed by resource link row id.
     */
    public function links(connection $conn, bool $reveal, ?array $ids = null): array {
        global $DB;
        $out = [];
        $fields = 'id, resourcelinkid, resourceid, lineitemsservice, lineitemservice, lineitemscope, resultscope, scorescope,
             timecreated, timemodified';
        if ($ids === null) {
            $rs = $DB->get_recordset(
                'enrol_lti_resource_link',
                ['ltideploymentid' => $conn->deploymentrowid],
                'id ASC',
                $fields,
                0,
                5000
            );
        } else {
            [$insql, $params] = $DB->get_in_or_equal($ids ?: [0], SQL_PARAMS_NAMED, 'lk');
            $params['dep'] = $conn->deploymentrowid;
            $rs = $DB->get_recordset_select(
                'enrol_lti_resource_link',
                "ltideploymentid = :dep AND id $insql",
                $params,
                'id ASC',
                $fields
            );
        }
        foreach ($rs as $r) {
            $scopes = self::scopes($r);
            $out[(int) $r->id] = [
                'id' => (int) $r->id,
                'resourcelinkid' => redactor::identifier((string) $r->resourcelinkid, $reveal),
                'toolid' => (int) $r->resourceid,
                'lineitem' => $r->lineitemservice ? redactor::url((string) $r->lineitemservice, $reveal) : '',
                'lineitems' => $r->lineitemsservice ? redactor::url((string) $r->lineitemsservice, $reveal) : '',
                'haslineitem' => !empty($r->lineitemservice),
                'haslineitems' => !empty($r->lineitemsservice),
                'scopes' => $scopes,
                'service' => !empty($r->lineitemservice) || !empty($r->lineitemsservice),
                'claimtime' => (int) $r->timemodified,
            ];
        }
        $rs->close();
        return $out;
    }

    /**
     * Scope flags of a resource link row as core stored them from the AGS claim of the last launch.
     *
     * @param \stdClass $r enrol_lti_resource_link row.
     * @return array score, lineitem, lineitemreadonly, result (bool each).
     */
    public static function scopes(\stdClass $r): array {
        $line = (string) ($r->lineitemscope ?? '');
        return [
            'score' => strpos((string) ($r->scorescope ?? ''), 'scope/score') !== false,
            'lineitem' => preg_match('#scope/lineitem(\s|"|$|,)#', $line) === 1 || substr($line, -14) === 'scope/lineitem',
            'lineitemreadonly' => strpos($line, 'lineitem.readonly') !== false,
            'result' => strpos((string) ($r->resultscope ?? ''), 'result.readonly') !== false,
        ];
    }

    /**
     * LTI subjects that auth_lti linked to the users for the connection's issuer (hashes only).
     *
     * @param string $issuer Issuer.
     * @param int[] $userids Users.
     * @return array userid => list of sub hashes (sha256 of the sub, as auth_lti stores them).
     */
    private function linked_subjects(string $issuer, array $userids): array {
        global $DB;
        if (!$userids || !$DB->get_manager()->table_exists('auth_lti_linked_login')) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params['iss'] = hash('sha256', $issuer);
        $out = [];
        $rs = $DB->get_recordset_select(
            'auth_lti_linked_login',
            "issuer256 = :iss AND userid $insql",
            $params,
            '',
            'id, userid, sub256'
        );
        foreach ($rs as $r) {
            $out[(int) $r->userid][] = (string) $r->sub256;
        }
        $rs->close();
        return $out;
    }

    /**
     * Deployments in which each LTI user record has resource links (multi consumer detection).
     *
     * @param int[] $ltiuserids enrol_lti_users ids.
     * @return array ltiuserid => deployment row ids.
     */
    private function deployments_per_ltiuser(array $ltiuserids): array {
        global $DB;
        $ltiuserids = array_values(array_unique($ltiuserids));
        if (!$ltiuserids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ltiuserids, SQL_PARAMS_NAMED, 'lu');
        $out = [];
        $rs = $DB->get_recordset_sql("SELECT DISTINCT url.ltiuserid, rl.ltideploymentid
                                        FROM {enrol_lti_user_resource_link} url
                                        JOIN {enrol_lti_resource_link} rl ON rl.id = url.resourcelinkid
                                       WHERE url.ltiuserid $insql", $params);
        foreach ($rs as $r) {
            $out[(int) $r->ltiuserid][] = (int) $r->ltideploymentid;
        }
        $rs->close();
        return $out;
    }

    /**
     * Number of resource links per LTI user record within the page (several links of the same consumer).
     *
     * @param \stdClass[] $pairs Pairs.
     * @return array ltiuserid => count.
     */
    private function links_per_ltiuser_here(array $pairs): array {
        $out = [];
        foreach ($pairs as $p) {
            $out[(int) $p->ltiuserid] = ($out[(int) $p->ltiuserid] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Latest passive journal rows that the importer could not attribute to one resource link.
     *
     * @param int[] $userids Users.
     * @param int[] $toolids Published resources.
     * @return array "toolid:userid" => row.
     */
    private function latest_unattributed(array $userids, array $toolids): array {
        global $DB;
        if (!$userids || !$toolids) {
            return [];
        }
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        [$tsql, $tparams] = $DB->get_in_or_equal($toolids, SQL_PARAMS_NAMED, 't');
        // Two bounded steps, see journal::latest().
        $maxids = $DB->get_fieldset_sql("SELECT MAX(id)
                                           FROM {" . journal::TABLE . "}
                                          WHERE source = :src AND resourcelinkrowid = 0 AND userid $usql AND toolid $tsql
                                       GROUP BY toolid, userid", $uparams + $tparams + ['src' => 'task_log']);
        $out = [];
        foreach (journal::rows_by_ids($maxids) as $r) {
            $out[(int) $r->toolid . ':' . (int) $r->userid] = $r;
        }
        return $out;
    }

    /**
     * Build one row.
     *
     * @param connection $conn Connection.
     * @param array $link Link.
     * @param array|null $res Resource.
     * @param array|null $src Source grade of the user.
     * @param \stdClass $p Pair.
     * @param array $ctx Context: prereq, subjects, deployments, linkshere, journal, passive, reveal, showscores, now.
     * @return array
     */
    private function row(connection $conn, array $link, ?array $res, ?array $src, \stdClass $p, array $ctx): array {
        $obs = [];
        $risks = [];
        $prereq = $ctx['prereq'];
        $now = $ctx['now'];
        $toolid = (int) $p->toolid;

        // Prediction: replicate the order of core's checks.
        $prediction = 'would_send';
        if (!$prereq['authlti'] || !$prereq['enrollti'] || ($prereq['scheduled']['disabled'] ?? 0)) {
            $prediction = 'prereq_missing';
        } else if (!$res || !$res['enabled']) {
            $prediction = 'publication_disabled';
        } else if (!$res['gradesync']) {
            $prediction = 'gradesync_off';
        } else if (!$res['contextvalid'] || $res['kind'] === '') {
            $prediction = 'invalid_context';
        } else if (!$link['service']) {
            $prediction = 'no_service';
        } else if (!$link['scopes']['score']) {
            $prediction = 'no_scope';
        } else if ($res['gradesynccompletion'] && $src && $src['completed'] === false) {
            $prediction = 'gated';
        } else if (!$src || $src['grade'] === null) {
            $prediction = 'no_grade';
        } else if (empty($res['grademax'])) {
            $prediction = 'no_grademax';
        } else if ($p->lastgrade !== null && abs((float) $p->lastgrade - (float) $src['grade']) < 0.00001) {
            $prediction = 'lastgrade_unchanged';
        }
        if (!$link['scopes']['score'] && $link['service'] && $prediction !== 'no_scope') {
            // Core itself does not check the scope before it asks for a token; the platform refuses the token.
            $risks[] = 'score_scope_missing';
        }
        $obs[] = $this->obs('prediction_' . $prediction, 'enrol_lti', $now, 'certain', 'limit_prediction');

        // Source grade.
        $sourceinfo = [
            'state' => $src ? $src['state'] : 'none',
            'grade' => null,
            'max' => null,
            'dategraded' => $src ? (int) $src['dategraded'] : 0,
            'locked' => $src ? (int) ($src['locked'] || ($res['itemlocked'] ?? 0)) : 0,
            'overridden' => $src ? (int) $src['overridden'] : 0,
            'hidden' => $src ? (int) ($src['hidden'] || ($res['itemhidden'] ?? 0)) : 0,
            'completed' => $src ? $src['completed'] : null,
            'kind' => $res['kind'] ?? '',
            'stale' => (int) ($res['needsupdate'] ?? 0),
            'version' => $src ? $src['version'] : '',
        ];
        if ($ctx['showscores'] && $src) {
            $sourceinfo['grade'] = $src['grade'];
            $sourceinfo['max'] = $res['grademax'] ?? null;
        }
        $obs[] = $this->obs(
            'source_' . $sourceinfo['state'],
            'gradebook',
            $sourceinfo['dategraded'] ?: $now,
            'certain',
            'limit_source'
        );
        if ($sourceinfo['stale']) {
            $risks[] = 'course_total_stale';
        }
        if ($sourceinfo['locked'] || $sourceinfo['overridden']) {
            $obs[] = $this->obs(
                $sourceinfo['locked'] ? 'source_locked' : 'source_overridden',
                'gradebook',
                $sourceinfo['dategraded'] ?: $now,
                'certain',
                'limit_source'
            );
        }

        // Mapping of the learner to the AGS userId of this consumer.
        $subjects = $ctx['subjects'];
        $deployments = array_values(array_unique($ctx['deployments']));
        $otherdeps = array_values(array_diff($deployments, [$conn->deploymentrowid]));
        $mapping = 'linked_login';
        if (!$subjects) {
            $own = (int) $p->ltideploymentid === $conn->deploymentrowid && $p->sourceidhash !== '';
            $mapping = $own ? 'sourceid_only' : 'unknown';
        } else if (count($subjects) > 1) {
            $mapping = 'ambiguous';
        } else if ($p->sourceidhash !== '' && !in_array($p->sourceidhash, $subjects, true)) {
            // Core sends enrol_lti_users.sourceid, which belongs to the consumer of the learner's latest launch.
            $mapping = 'core_sends_other_subject';
        }
        if ($mapping !== 'linked_login') {
            $risks[] = 'mapping_' . $mapping;
        }
        if ($otherdeps) {
            $risks[] = 'multi_consumer';
            if (in_array($prediction, ['lastgrade_unchanged'], true)) {
                $risks[] = 'lastgrade_shared';
            }
        }
        if ($ctx['linkshere'] > 1) {
            $risks[] = 'multiple_links';
        }

        // Evidence.
        $j = $ctx['journal'];
        $passive = $ctx['passive'];
        $evidence = $this->evidence($j, $passive, $link);
        if (
            $prediction === 'lastgrade_unchanged' && !in_array(
                $evidence['level'],
                ['http_accepted_unconfirmed', 'results_match', 'remote_booked', 'remote_mismatch', 'remote_unobservable'],
                true
            )
        ) {
            // Core skips because the value equals the last value sent to *some* consumer; nothing shows that this link
            // ever received it. Risk, never proof of delivery.
            $risks[] = 'lastgrade_blocks';
            if ($src && $src['state'] === 'zero' && abs((float) $p->lastgrade) < 0.00001) {
                // Core (enrol_lti) starts every learner with lastgrade 0.0: a grade of 0 is never sent by core.
                $risks[] = 'zero_never_sent';
            }
        }
        $obs[] = $this->obs(
            'evidence_' . $evidence['level'],
            $evidence['source'] ?: 'journal',
            $evidence['time'] ?: $now,
            $evidence['certainty'],
            $evidence['limit']
        );

        return [
            'linkid' => (int) $p->resourcelinkrowid,
            'toolid' => $toolid,
            'userid' => (int) $p->userid,
            'person' => $ctx['reveal'] ? (string) $p->userid : self::pseudonym($conn->key, (int) $p->userid),
            'prediction' => $prediction,
            'source' => $sourceinfo,
            'lastgradeequal' => $prediction === 'lastgrade_unchanged',
            'mapping' => $mapping,
            'otherconnections' => count($otherdeps),
            'evidence' => $evidence,
            'risks' => array_values(array_unique($risks)),
            'observations' => $obs,
            'replayable' => in_array($prediction, ['would_send', 'lastgrade_unchanged'], true)
                && in_array($mapping, ['linked_login', 'sourceid_only', 'core_sends_other_subject'], true),
        ];
    }

    /**
     * Evidence level of a row.
     *
     * @param \stdClass|null $j Latest journal row of this link and learner.
     * @param \stdClass|null $passive Latest unattributed task log row of the learner and resource.
     * @param array $link Link.
     * @return array level, source, time, certainty, limit, state, httpstatus, errorclass, booking, attemptid.
     */
    private function evidence(?\stdClass $j, ?\stdClass $passive, array $link): array {
        $out = ['level' => 'not_attempted', 'source' => '', 'time' => 0, 'certainty' => 'possible',
            'limit' => 'limit_no_trace', 'state' => '', 'httpstatus' => 0, 'errorclass' => '', 'booking' => '',
            'attemptid' => '', 'skipreason' => ''];
        $row = $j;
        $attributed = true;
        if (!$row && $passive) {
            $row = $passive;
            $attributed = false;
        }
        if (!$row) {
            return $out;
        }
        $out['source'] = (string) $row->source === 'task_log' ? 'task_log' : 'journal';
        $out['time'] = (int) $row->timemodified;
        $out['state'] = (string) $row->state;
        $out['httpstatus'] = (int) $row->httpstatus;
        $out['errorclass'] = (string) $row->errorclass;
        $out['booking'] = (string) $row->bookingstate;
        $out['attemptid'] = (string) $row->attemptid;
        $out['skipreason'] = (string) $row->skipreason;
        $out['certainty'] = $row->source === 'instrumented' ? 'certain' : ($attributed ? 'likely' : 'possible');
        $out['limit'] = $row->source === 'instrumented' ? 'limit_instrumented'
            : ($attributed ? 'limit_tasklog' : 'limit_tasklog_unattributed');
        switch ((string) $row->state) {
            case 'skipped':
                $out['level'] = 'skipped';
                break;
            case 'queued':
            case 'sending':
            case 'retry_wait':
                $out['level'] = 'http_attempt';
                break;
            case 'http_rejected':
            case 'dead':
            case 'aborted':
                $out['level'] = 'error';
                break;
            case 'http_accepted':
                $booking = (string) $row->bookingstate;
                if ($booking === 'receipt_confirmed') {
                    $out['level'] = 'remote_booked';
                    $out['limit'] = 'limit_receipt';
                } else if ($booking === 'results_match') {
                    // The consumer's results service reports the value; its gradebook was not inspected.
                    $out['level'] = 'results_match';
                    $out['limit'] = 'limit_results';
                } else if (in_array($booking, ['results_mismatch', 'receipt_mismatch'], true)) {
                    $out['level'] = 'remote_mismatch';
                } else if ($booking === 'unobservable') {
                    $out['level'] = 'remote_unobservable';
                } else {
                    $out['level'] = 'http_accepted_unconfirmed';
                }
                break;
        }
        return $out;
    }

    /**
     * Observation.
     *
     * @param string $code Code (string obs_<code>).
     * @param string $source Source.
     * @param int $time Time.
     * @param string $certainty certain, likely, possible.
     * @param string $limit Limit string key (obslimit_<limit>).
     * @return array
     */
    private function obs(string $code, string $source, int $time, string $certainty, string $limit): array {
        return ['code' => $code, 'source' => $source, 'time' => $time, 'certainty' => $certainty, 'limit' => $limit];
    }

    /**
     * Aggregated findings of the page with counts.
     *
     * @param array $out Diagnosis so far.
     * @return array[] code, severity, count, source, certainty, limit, responsible.
     */
    private function findings(array $out): array {
        $f = [];
        $add = function (
            string $code,
            string $severity,
            int $count,
            string $source,
            string $certainty,
            string $limit,
            string $responsible
        ) use (&$f) {
            if ($count > 0) {
                $f[] = compact('code', 'severity', 'count', 'source', 'certainty', 'limit', 'responsible');
            }
        };
        $p = $out['prerequisites'];
        $add('gr_prereq_auth', 'blocking', (int) !$p['authlti'], 'config', 'certain', 'limit_prediction', 'provider');
        $add('gr_prereq_enrol', 'blocking', (int) !$p['enrollti'], 'config', 'certain', 'limit_prediction', 'provider');
        $add(
            'gr_task_missing',
            'blocking',
            (int) ($p['scheduled'] === null),
            'task_scheduled',
            'certain',
            'limit_prediction',
            'provider'
        );
        $add(
            'gr_task_disabled',
            'blocking',
            (int) (($p['scheduled']['disabled'] ?? 0) === 1),
            'task_scheduled',
            'certain',
            'limit_prediction',
            'provider'
        );
        $add(
            'gr_task_failing',
            'warning',
            (int) (($p['scheduled']['faildelay'] ?? 0) > 0),
            'task_scheduled',
            'certain',
            'limit_prediction',
            'provider'
        );
        // Ad hoc tasks per published resource: only those of the scope count (issue #29).
        $adhoc = !empty($out['scoped']) ? array_intersect_key($p['adhoc'], array_flip($out['scopetools'])) : $p['adhoc'];
        $failedadhoc = array_sum(array_column($adhoc, 'failed'));
        $add('gr_adhoc_failing', 'warning', $failedadhoc, 'task_adhoc', 'certain', 'limit_prediction', 'provider');
        $add('gr_tasklog_off', 'warning', (int) ($p['tasklogmode'] === 0), 'config', 'certain', 'limit_tasklog', 'provider');
        $risks = [];
        foreach ($out['rows'] as $r) {
            foreach ($r['risks'] as $risk) {
                $risks[$risk] = ($risks[$risk] ?? 0) + 1;
            }
        }
        $map = [
            'lastgrade_blocks' => ['blocking', 'enrol_lti', 'likely', 'limit_tasklog', 'provider'],
            'lastgrade_shared' => ['warning', 'enrol_lti', 'certain', 'limit_prediction', 'provider'],
            'mapping_core_sends_other_subject' => ['blocking', 'auth_lti', 'likely', 'limit_prediction', 'provider'],
            'mapping_unknown' => ['warning', 'auth_lti', 'possible', 'limit_prediction', 'provider'],
            'mapping_ambiguous' => ['warning', 'auth_lti', 'certain', 'limit_prediction', 'provider'],
            'mapping_sourceid_only' => ['info', 'enrol_lti', 'likely', 'limit_prediction', 'provider'],
            'multi_consumer' => ['info', 'enrol_lti', 'certain', 'limit_prediction', 'provider'],
            'multiple_links' => ['info', 'enrol_lti', 'certain', 'limit_prediction', 'provider'],
            'score_scope_missing' => ['blocking', 'enrol_lti', 'certain', 'limit_claim', 'consumer'],
            'course_total_stale' => ['warning', 'gradebook', 'certain', 'limit_source', 'provider'],
            'zero_never_sent' => ['blocking', 'enrol_lti', 'certain', 'limit_tasklog', 'provider'],
        ];
        foreach ($map as $risk => [$sev, $src, $cert, $limit, $resp]) {
            $add('gr_' . $risk, $sev, $risks[$risk] ?? 0, $src, $cert, $limit, $resp);
        }
        $pred = $out['summary']['prediction'];
        $add('gr_no_service', 'blocking', $pred['no_service'] ?? 0, 'enrol_lti', 'certain', 'limit_claim', 'consumer');
        $add('gr_no_scope', 'blocking', $pred['no_scope'] ?? 0, 'enrol_lti', 'certain', 'limit_claim', 'consumer');
        $add('gr_gated', 'info', $pred['gated'] ?? 0, 'completion', 'certain', 'limit_source', 'provider');
        $add('gr_no_grade', 'info', $pred['no_grade'] ?? 0, 'gradebook', 'certain', 'limit_source', 'provider');
        $add(
            'gr_gradesync_off',
            'blocking',
            $pred['gradesync_off'] ?? 0,
            'enrol_lti',
            'certain',
            'limit_prediction',
            'provider'
        );
        $ev = $out['summary']['evidence'];
        $add('gr_errors', 'warning', $ev['error'] ?? 0, 'journal', 'certain', 'limit_tasklog', 'both');
        $add(
            'gr_unconfirmed',
            'info',
            $ev['http_accepted_unconfirmed'] ?? 0,
            'journal',
            'certain',
            'limit_unconfirmed',
            'consumer'
        );
        $add(
            'gr_remote_mismatch',
            'blocking',
            $ev['remote_mismatch'] ?? 0,
            'journal',
            'certain',
            'limit_results',
            'consumer'
        );
        return $f;
    }
}
