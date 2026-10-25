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
 * Builds the capability matrix: one row per feature with the seven-step evidence ladder and the
 * status computed by the {@see status_engine}.
 *
 * A pure function of the connection and the collected facts: no database access, no clock.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class matrix_builder {
    /** @var string[] Features of the matrix in display order. */
    public const FEATURES = ['lti_core', 'ags', 'nrps', 'deeplinking', 'dynreg', 'submission_review', 'platform_storage',
        'course_group'];

    /** @var status_engine */
    private $engine;

    /**
     * Constructor.
     *
     * @param status_engine|null $engine Engine (default instance).
     */
    public function __construct(?status_engine $engine = null) {
        $this->engine = $engine ?? new status_engine();
    }

    /**
     * Build the matrix.
     *
     * @param connection $c The connection.
     * @param array $facts Facts from the fact collector.
     * @param array $probes Results of active checks keyed by purpose (may be empty).
     * @param array $incompatible Feature ids that must be flagged incompatible by an external rule.
     * @return array[] Rows keyed by feature id: feature, status, light, reason, next, notes, ladder, decidingstep.
     */
    public function build(connection $c, array $facts, array $probes = [], array $incompatible = []): array {
        $rows = [];
        foreach (self::FEATURES as $feature) {
            $ladder = $this->ladder($c, $feature, $facts, $probes);
            $result = $this->engine->evaluate($ladder, in_array($feature, $incompatible, true));
            $rows[$feature] = [
                'feature' => $feature,
                'status' => $result->status,
                'light' => status::light($result->status),
                'reason' => $result->reason,
                'next' => $result->next,
                'notes' => $result->notes,
                'decidingstep' => $result->decidingstep,
                'ladder' => $ladder,
            ];
        }
        return $rows;
    }

    /**
     * Build the ladder of one feature.
     *
     * @param connection $c Connection.
     * @param string $feature Feature id.
     * @param array $facts Facts.
     * @param array $probes Active check results.
     * @return evidence[] Keyed by step.
     */
    public function ladder(connection $c, string $feature, array $facts, array $probes): array {
        $implemented = $facts['implemented'][$feature] ?? null;
        $ladder = [
            status::STEP_SPECIFIED => new evidence(status::STATE_YES, status::KIND_CODE, 'ev_specified'),
            status::STEP_IMPLEMENTED => $implemented === null ? evidence::unknown('ev_notdetected')
                : new evidence(
                    $implemented ? status::STATE_YES : status::STATE_NO,
                    status::KIND_CODE,
                    $implemented ? 'ev_implemented' : 'ev_notimplemented'
                ),
            status::STEP_ENABLED => evidence::unknown(),
            status::STEP_DECLARED => evidence::unknown(),
            status::STEP_AUTHORISED => evidence::unknown(),
            status::STEP_REACHABLE => $this->reachable($feature, $probes),
            status::STEP_OBSERVED => evidence::unknown('ev_nothingobserved'),
        ];
        if ($implemented === false) {
            return $ladder;
        }
        $fill = $c->role === connection::ROLE_PLATFORM ? $this->platform($c, $feature, $facts) : $this->tool($c, $feature, $facts);
        return $fill + $ladder;
    }

    /**
     * Reachability from the active probes. Only jwks and endpoints of the other side are probed.
     *
     * @param string $feature Feature id.
     * @param array $probes Probes keyed by purpose.
     * @return evidence
     */
    private function reachable(string $feature, array $probes): evidence {
        $probe = $probes['jwks'] ?? null;
        if (!is_array($probe) || empty($probe['code']) || $feature === 'platform_storage' || $feature === 'course_group') {
            return evidence::unknown('ev_notprobed');
        }
        if ($probe['code'] === 'blocked_by_policy') {
            return evidence::unknown('ev_probeblocked');
        }
        if ($probe['code'] === 'ok') {
            return new evidence(status::STATE_YES, status::KIND_PROBE, 'ev_probeok', null, (int) ($probe['checkedat'] ?? 0));
        }
        return new evidence(
            status::STATE_BLOCKED,
            status::KIND_PROBE,
            'ev_probefailed',
            $probe['code'],
            (int) ($probe['checkedat'] ?? 0)
        );
    }

    /**
     * Ladder steps for the platform role.
     *
     * @param connection $c Connection.
     * @param string $feature Feature id.
     * @param array $facts Facts.
     * @return evidence[]
     */
    private function platform(connection $c, string $feature, array $facts): array {
        $obs = $facts['obs'];
        $tokens = $facts['tokens'];
        $access = $facts['access'];
        $active = $c->state === 'active';
        $modern = $c->ltiversion === '1.3.0';
        switch ($feature) {
            case 'lti_core':
                $declared = $modern && !empty($c->endpoints['initiatelogin']) && !empty($c->endpoints['jwks'])
                    && !empty($c->facts['hasredirects']);
                return [
                    status::STEP_ENABLED => $active && $modern
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, 'ev_typeactive')
                        : new evidence(
                            status::STATE_NO,
                            status::KIND_CONFIG,
                            $modern ? 'ev_typenotactive' : 'ev_legacyversion',
                            $c->ltiversion
                        ),
                    status::STEP_DECLARED => $declared
                        ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_toolregistrationstored')
                        : new evidence(status::STATE_NO, status::KIND_DBRECORD, 'ev_toolregistrationincomplete'),
                    status::STEP_AUTHORISED => $tokens['any'] > 0
                        ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_tokensissued', null, $tokens['any'])
                        : evidence::unknown('ev_notokens'),
                    status::STEP_OBSERVED => $obs['view_out'] > 0
                        ? new evidence(status::STATE_YES, status::KIND_LOCALEFFECT, 'ev_launchrendered', null, $obs['view_out'])
                        : evidence::unknown('ev_nothingobserved'),
                ];
            case 'ags':
                $mode = (int) ($c->facts['ags'] ?? 0);
                return [
                    status::STEP_ENABLED => $mode > 0
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, $mode >= 2 ? 'ev_agsfull' : 'ev_agsreadonly')
                        : new evidence(status::STATE_NO, status::KIND_CONFIG, 'ev_agsdisabled'),
                    status::STEP_DECLARED => evidence::not_observable('ev_remoteregistration'),
                    status::STEP_AUTHORISED => $tokens['ags'] > 0
                        ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_agstoken', null, $tokens['ags'])
                        : evidence::unknown('ev_noagstoken'),
                    status::STEP_OBSERVED => $this->platform_ags_observed($obs, $access),
                ];
            case 'nrps':
                return [
                    status::STEP_ENABLED => !empty($c->facts['nrps'])
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, 'ev_nrpsenabled')
                        : new evidence(status::STATE_NO, status::KIND_CONFIG, 'ev_nrpsdisabled'),
                    status::STEP_DECLARED => evidence::not_observable('ev_remoteregistration'),
                    status::STEP_AUTHORISED => $tokens['nrps'] > 0
                        ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_nrpstoken', null, $tokens['nrps'])
                        : evidence::unknown('ev_nonrpstoken'),
                    status::STEP_OBSERVED => $this->access_or_unobservable($access, 'service_nrps', 'ev_nrpsnohook'),
                ];
            case 'deeplinking':
                return [
                    status::STEP_ENABLED => !empty($c->facts['deeplinking'])
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, 'ev_dlenabled')
                        : new evidence(status::STATE_NO, status::KIND_CONFIG, 'ev_dldisabled'),
                    status::STEP_DECLARED => evidence::not_observable('ev_remoteregistration'),
                    status::STEP_OBSERVED => $this->access_or_unobservable($access, 'deeplinking', 'ev_dlnohook'),
                ];
            case 'dynreg':
                return [
                    status::STEP_ENABLED => evidence::na(),
                    status::STEP_OBSERVED => $this->access_or_unobservable($access, 'dynreg', 'ev_dynregnohook'),
                ];
            case 'submission_review':
                return [status::STEP_OBSERVED => evidence::not_observable('ev_remoteregistration')];
        }
        return [];
    }

    /**
     * Observed step of platform AGS: a service call that wrote the gradebook is a real transaction.
     *
     * @param array $obs Observation summary.
     * @param array $access Access log summary.
     * @return evidence
     */
    private function platform_ags_observed(array $obs, array $access): evidence {
        if ($obs['grade_in'] > 0) {
            return new evidence(status::STATE_YES, status::KIND_OBSERVED, 'ev_agsgradeviaservice', null, $obs['grade_in']);
        }
        foreach (['service_ags_scores', 'service_ags_lineitems', 'service_ags_results'] as $cat) {
            if (!empty($access[$cat]['ok'])) {
                return new evidence(status::STATE_YES, status::KIND_ACCESSLOG, 'ev_agsaccesslog', $cat, $access[$cat]['last']);
            }
        }
        if ($obs['grade_local'] > 0) {
            return new evidence(status::STATE_YES, status::KIND_LOCALEFFECT, 'ev_agsgradelocal', null, $obs['grade_local']);
        }
        return evidence::unknown('ev_nothingobserved');
    }

    /**
     * Observed step from imported access log lines, else "not observable".
     *
     * @param array $access Access log summary.
     * @param string $category Category.
     * @param string $nohookkey Explanation when nothing can be seen.
     * @return evidence
     */
    private function access_or_unobservable(array $access, string $category, string $nohookkey): evidence {
        if (!empty($access[$category]['ok'])) {
            return new evidence(
                status::STATE_YES,
                status::KIND_ACCESSLOG,
                'ev_accesslogseen',
                $category,
                $access[$category]['last']
            );
        }
        if (!empty($access[$category]['fail'])) {
            return new evidence(
                status::STATE_UNKNOWN,
                status::KIND_ACCESSLOG,
                'ev_accesslogfailonly',
                $category,
                $access[$category]['last']
            );
        }
        return evidence::not_observable($nohookkey);
    }

    /**
     * Ladder steps for the tool role.
     *
     * @param connection $c Connection.
     * @param string $feature Feature id.
     * @param array $facts Facts.
     * @return evidence[]
     */
    private function tool(connection $c, string $feature, array $facts): array {
        $obs = $facts['obs'];
        $t = $facts['tool'];
        $tasklog = $facts['tasklog'];
        switch ($feature) {
            case 'lti_core':
                $declared = $t['hasauth'] && $t['hasjwks'] && $t['hasoauth'];
                if ($obs['launch'] > 0) {
                    $observed = new evidence(status::STATE_YES, status::KIND_OBSERVED, 'ev_launchobserved', null, $obs['launch']);
                } else if ($t['links'] > 0) {
                    $observed = new evidence(status::STATE_YES, status::KIND_LOCALEFFECT, 'ev_resourcelinkexists', $t['links']);
                } else {
                    $observed = evidence::unknown('ev_nothingobserved');
                }
                return [
                    status::STEP_ENABLED => $t['complete']
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, 'ev_registrationcomplete')
                        : new evidence(status::STATE_NO, status::KIND_CONFIG, 'ev_registrationdraft'),
                    status::STEP_DECLARED => $declared
                        ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_platformendpointsstored')
                        : new evidence(status::STATE_NO, status::KIND_DBRECORD, 'ev_platformendpointsmissing'),
                    status::STEP_OBSERVED => $observed,
                ];
            case 'ags':
                $declared = evidence::unknown('ev_nolaunchyet');
                $authorised = evidence::unknown('ev_nolaunchyet');
                if ($t['links'] > 0) {
                    $declared = $t['agsclaim']
                        ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_agsclaimseen')
                        : new evidence(status::STATE_NO, status::KIND_DBRECORD, 'ev_agsclaimmissing');
                    if ($t['agsclaim']) {
                        $authorised = $t['scorescope']
                            ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_scorescopeoffered')
                            : new evidence(status::STATE_NO, status::KIND_DBRECORD, 'ev_scorescopemissing');
                    }
                }
                if ($t['lastgrade']) {
                    $observed = new evidence(status::STATE_YES, status::KIND_LOCALEFFECT, 'ev_lastgradestored');
                } else if (!empty($tasklog['grades']['ok'])) {
                    $observed = new evidence(
                        status::STATE_UNKNOWN,
                        status::KIND_TASKLOG,
                        'ev_taskranonly',
                        null,
                        $tasklog['grades']['ok']
                    );
                } else {
                    $observed = evidence::unknown('ev_nothingobserved');
                }
                return [
                    status::STEP_ENABLED => $t['gradesync']
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, 'ev_gradesyncon')
                        : new evidence(status::STATE_NO, status::KIND_CONFIG, 'ev_gradesyncoff'),
                    status::STEP_DECLARED => $declared,
                    status::STEP_AUTHORISED => $authorised,
                    status::STEP_OBSERVED => $observed,
                ];
            case 'nrps':
                $declared = evidence::unknown('ev_nolaunchyet');
                if ($t['links'] > 0) {
                    $declared = $t['nrpsclaim']
                        ? new evidence(status::STATE_YES, status::KIND_DBRECORD, 'ev_nrpsclaimseen')
                        : new evidence(status::STATE_NO, status::KIND_DBRECORD, 'ev_nrpsclaimmissing');
                }
                if ($obs['enrolment'] > 0) {
                    $observed = new evidence(
                        status::STATE_YES,
                        status::KIND_LOCALEFFECT,
                        'ev_enrolmentseen',
                        null,
                        $obs['enrolment']
                    );
                } else if (!empty($tasklog['members']['ok'])) {
                    $observed = new evidence(
                        status::STATE_UNKNOWN,
                        status::KIND_TASKLOG,
                        'ev_taskranonly',
                        null,
                        $tasklog['members']['ok']
                    );
                } else {
                    $observed = evidence::unknown('ev_nothingobserved');
                }
                return [
                    status::STEP_ENABLED => $t['membersync']
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, 'ev_membersyncon')
                        : new evidence(status::STATE_NO, status::KIND_CONFIG, 'ev_membersyncoff'),
                    status::STEP_DECLARED => $declared,
                    status::STEP_OBSERVED => $observed,
                ];
            case 'deeplinking':
                return [
                    status::STEP_ENABLED => $t['complete']
                        ? new evidence(status::STATE_YES, status::KIND_CONFIG, 'ev_registrationcomplete')
                        : new evidence(status::STATE_NO, status::KIND_CONFIG, 'ev_registrationdraft'),
                    status::STEP_OBSERVED => evidence::not_observable('ev_dlnohook'),
                ];
            case 'dynreg':
                return [status::STEP_ENABLED => evidence::na()];
        }
        return [];
    }
}
