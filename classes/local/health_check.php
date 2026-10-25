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

use tool_ltidiagnostics\local\active\active_checker;

/**
 * The guided health check: evaluates a connection layer by layer and returns prioritised,
 * plain-language findings with the next safe step.
 *
 * {@see self::evaluate()} is a pure function of its inputs. {@see self::run()} collects the
 * inputs from Moodle. No finding is green on configuration alone: an enabled setting yields at
 * best "configured, not yet observed".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health_check {
    /** @var string[] Probe codes that belong to the DNS and network layer. */
    private const NETWORK_CODES = ['dns_failure', 'connection_refused', 'timeout', 'proxy_407', 'proxy_error', 'transfer_error',
        'blocked_by_policy'];
    /** @var string[] Probe codes that belong to the TLS layer. */
    private const TLS_CODES = ['cert_expired', 'cert_hostname', 'cert_chain_incomplete', 'cert_untrusted', 'cert_invalid',
        'tls_handshake'];
    /** @var string[] Probe codes that are HTTP level problems. */
    private const HTTP_CODES = ['http_denied', 'http_notfound', 'http_client_error', 'http_server_error', 'redirect'];

    /**
     * Collect the inputs and evaluate.
     *
     * @param connection $c The connection.
     * @param int $now Current time.
     * @param array $options profile (desired_profile|null), probes (array), filters (array).
     * @return array The report, see {@see self::evaluate()}.
     */
    public function run(connection $c, int $now, array $options = []): array {
        $filters = $options['filters'] ?? [];
        $facts = (new fact_collector())->collect($c, $now, $filters);
        $logstore = new logstore_source();
        $tasks = new task_source();
        global $DB;
        $env = [
            'readers' => $logstore->describe(),
            'cron' => $tasks->cron_status($now),
            'scheduled' => $tasks->scheduled($now),
            'adhoc' => $tasks->adhoc(),
            'tasklogfailed' => $tasks->task_log(['onlyfailed' => true, 'from' => $now - DAYSECS, 'limit' => 50]),
            'recordevents' => (bool) config::get('recordevents'),
            'imports' => (int) $DB->count_records('tool_ltidiagnostics_imp', ['status' => 'done']),
        ];
        return $this->evaluate($c, [
            'now' => $now,
            'facts' => $facts,
            'probes' => $options['probes'] ?? [],
            'profile' => $options['profile'] ?? null,
            'env' => $env,
        ]);
    }

    /**
     * Evaluate the inputs.
     *
     * @param connection $c The connection.
     * @param array $in now, facts, probes, profile (desired_profile|null), env.
     * @return array progress (steps), matrix, delta, layers (layer => [light, findings]), findings (sorted), summary
     */
    public function evaluate(connection $c, array $in): array {
        $now = (int) $in['now'];
        $probes = $in['probes'] ?? [];
        $profile = $in['profile'] ?? null;
        $incompatible = [];
        if ($profile instanceof desired_profile && $profile->expects('pseudonymised_sub')) {
            $incompatible = array_values(array_intersect(['ags', 'nrps'], $profile->wanted));
        }
        $matrix = (new matrix_builder())->build($c, $in['facts'], $probes, $incompatible);

        $findings = array_merge(
            $this->configuration($c, $in['facts'], $now),
            $this->probe_findings($c, $probes, $now),
            $this->advantage($c, $matrix, $profile, $in['facts'], $now),
            $this->core($c, $matrix, $profile, $in['facts'], $now),
            $this->background($in['env'], $now),
            $this->observability($c, $in['facts'], $in['env'], $now),
            $this->privacy($c, $profile, $now)
        );
        $findings = $this->ensure_layer_presence($findings, $probes, $now);
        usort($findings, function (finding $a, finding $b) {
            return [finding::weight($a->severity), array_search($a->layer, finding::layers()), $a->code]
                <=> [finding::weight($b->severity), array_search($b->layer, finding::layers()), $b->code];
        });

        $layers = [];
        foreach (finding::layers() as $layer) {
            $layers[$layer] = ['light' => 'grey', 'findings' => []];
        }
        foreach ($findings as $f) {
            $layers[$f->layer]['findings'][] = $f;
        }
        foreach ($layers as $layer => $info) {
            $layers[$layer]['light'] = self::light($info['findings']);
        }
        $counts = array_fill_keys([finding::BLOCKING, finding::WARNING, finding::INFO, finding::OK, finding::NOTCHECKED], 0);
        foreach ($findings as $f) {
            $counts[$f->severity]++;
        }
        return [
            'progress' => $this->progress($in),
            'matrix' => $matrix,
            'delta' => $profile instanceof desired_profile ? $this->delta($c, $matrix, $profile) : [],
            'layers' => $layers,
            'findings' => $findings,
            'summary' => $counts,
            'checkedat' => $now,
        ];
    }

    /**
     * Traffic light of a set of findings: red if any blocking, amber for warnings and unchecked parts, green only
     * if everything that was looked at is fine, grey if nothing was looked at.
     *
     * @param finding[] $findings Findings of one layer.
     * @return string green, amber, red or grey
     */
    public static function light(array $findings): string {
        if (!$findings) {
            return 'grey';
        }
        $sev = array_map(function (finding $f) {
            return $f->severity;
        }, $findings);
        if (in_array(finding::BLOCKING, $sev, true)) {
            return 'red';
        }
        if (in_array(finding::WARNING, $sev, true)) {
            return 'amber';
        }
        if (in_array(finding::OK, $sev, true)) {
            return in_array(finding::NOTCHECKED, $sev, true) ? 'amber' : 'green';
        }
        return 'grey';
    }

    /**
     * Transparent progress: which steps ran and which were skipped, and why.
     *
     * @param array $in Inputs.
     * @return array[] Each with step, done (bool).
     */
    private function progress(array $in): array {
        $probed = !empty($in['probes']);
        return [
            ['step' => 'progress_inventory', 'done' => true],
            ['step' => 'progress_matrix', 'done' => true],
            ['step' => 'progress_probes', 'done' => $probed],
            ['step' => 'progress_evidence', 'done' => true],
            ['step' => 'progress_profile', 'done' => ($in['profile'] ?? null) instanceof desired_profile],
            ['step' => 'progress_background', 'done' => true],
        ];
    }

    /**
     * Configuration layer.
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param int $now Time.
     * @return finding[]
     */
    private function configuration(connection $c, array $facts, int $now): array {
        $out = [];
        $add = function (string $code, string $sev, string $who, array $opt = []) use (&$out, $now) {
            $out[] = new finding($code, finding::L_CONFIG, $sev, $who, $opt + ['evidencekind' => status::KIND_CONFIG,
                'checkedat' => $now]);
        };
        $before = count($out);
        if ($c->role === connection::ROLE_PLATFORM) {
            if ($c->ltiversion !== '1.3.0') {
                $add('cfg_legacy', finding::WARNING, finding::ADMIN, ['a' => s($c->ltiversion), 'risk' => 'medium']);
            }
            if ($c->state !== 'active') {
                $add('cfg_state', finding::BLOCKING, finding::ADMIN, ['a' => s($c->state)]);
            }
            if ($c->ltiversion === '1.3.0') {
                if ($c->clientid === '') {
                    $add('cfg_clientid', finding::BLOCKING, finding::ADMIN);
                }
                if (empty($c->endpoints['initiatelogin'])) {
                    $add('cfg_initiate', finding::BLOCKING, finding::ADMIN);
                }
                $keytype = (string) ($c->facts['keytype'] ?? '');
                if ($keytype === 'JWK_KEYSET' && empty($c->endpoints['jwks'])) {
                    $add('cfg_jwks', finding::BLOCKING, finding::ADMIN);
                }
                if (empty($c->facts['hasredirects'])) {
                    $add('cfg_redirect', finding::WARNING, finding::ADMIN);
                }
            }
        } else {
            if ($c->state !== 'complete') {
                $add('cfg_registration_draft', finding::BLOCKING, finding::ADMIN);
            }
            $missing = [];
            foreach (['auth' => 'authenticationrequesturl', 'jwks' => 'jwksurl', 'oauth' => 'accesstokenurl'] as $key => $label) {
                if (empty($c->endpoints[$key])) {
                    $missing[] = $label;
                }
            }
            if ($c->clientid === '') {
                $missing[] = 'clientid';
            }
            if ($missing) {
                $add('cfg_endpoints', finding::BLOCKING, finding::ADMIN, ['a' => implode(', ', $missing)]);
            }
            if ($c->deploymentid === '') {
                $add('cfg_nodeployment', finding::WARNING, finding::PARTNER);
            }
        }
        if (count($out) === $before) {
            $add('cfg_ok', finding::OK, finding::ADMIN);
        }
        return $out;
    }

    /**
     * Network, TLS, OIDC and browser findings derived from active probes.
     *
     * @param connection $c Connection.
     * @param array $probes Probes keyed by purpose.
     * @param int $now Time.
     * @return finding[]
     */
    private function probe_findings(connection $c, array $probes, int $now): array {
        $out = [];
        foreach ($probes as $probekey => $p) {
            if (!is_array($p) || empty($p['code'])) {
                continue;
            }
            $purpose = (string) ($p['purpose'] ?? $probekey);
            $code = $p['code'];
            $at = (int) ($p['checkedat'] ?? $now);
            $a = ['evidencekind' => status::KIND_PROBE, 'evidence' => 'probe:' . $purpose, 'checkedat' => $at];
            if ($code === 'ok') {
                $out[] = new finding('net_ok', finding::L_NETWORK, finding::OK, finding::NETWORK, $a);
                $out[] = new finding('fw_inbound', finding::L_FIREWALL, finding::INFO, finding::PARTNER, $a);
                $out[] = new finding('tls_ok', finding::L_TLS, finding::OK, finding::NETWORK, $a);
                if (!empty($p['certexpiry']) && $p['certexpiry'] - $at < 14 * DAYSECS) {
                    $days = (int) floor(($p['certexpiry'] - $at) / DAYSECS);
                    $out[] = new finding('tls_expiring', finding::L_TLS, finding::WARNING, finding::NETWORK, $a + ['a' => $days,
                        'risk' => 'low']);
                }
            } else {
                $out[] = $this->probe_failure($code, $a, $purpose);
            }
            if ($p['skew'] !== null && abs((int) $p['skew']) > 60) {
                $out[] = new finding(
                    'skew_' . (abs((int) $p['skew']) > 300 ? 'blocking' : 'warn'),
                    finding::L_OIDC,
                    abs((int) $p['skew']) > 300 ? finding::BLOCKING : finding::WARNING,
                    finding::NETWORK,
                    $a + ['a' => (int) $p['skew'],
                    'risk' => 'low']
                );
            }
            if ($purpose === active_checker::PURPOSE_JWKS && !empty($p['jwks'])) {
                $out = array_merge($out, $this->jwks_findings($p['jwks'], $a));
            }
            if ($purpose === active_checker::PURPOSE_LAUNCH) {
                $out[] = $this->framing_finding($p['framing'] ?? null, $a);
            }
        }
        return $out;
    }

    /**
     * Finding for a failed probe.
     *
     * @param string $code Classifier code.
     * @param array $a Common options.
     * @param string $purpose Purpose.
     * @return finding
     */
    private function probe_failure(string $code, array $a, string $purpose): finding {
        if ($code === 'firewall_drop_suspected') {
            $opts = $a + ['risk' => 'medium'];
            return new finding('probe_' . $code, finding::L_FIREWALL, finding::BLOCKING, finding::NETWORK, $opts);
        }
        if (in_array($code, self::TLS_CODES, true)) {
            return new finding('probe_' . $code, finding::L_TLS, finding::BLOCKING, finding::NETWORK, $a + ['risk' => 'medium']);
        }
        if (in_array($code, self::HTTP_CODES, true)) {
            $layer = $purpose === active_checker::PURPOSE_JWKS ? finding::L_OIDC : finding::L_NETWORK;
            return new finding(
                'probe_' . $code,
                $layer,
                $code === 'redirect' ? finding::WARNING : finding::BLOCKING,
                finding::PARTNER,
                $a
            );
        }
        if ($code === 'blocked_by_policy') {
            return new finding('probe_' . $code, finding::L_NETWORK, finding::NOTCHECKED, finding::ADMIN, $a);
        }
        return new finding('probe_' . $code, finding::L_NETWORK, finding::BLOCKING, finding::NETWORK, $a + ['risk' => 'medium']);
    }

    /**
     * Findings for the analysed key set.
     *
     * @param array $jwks Result of jwks_analyser.
     * @param array $a Common options.
     * @return finding[]
     */
    private function jwks_findings(array $jwks, array $a): array {
        $out = [];
        if (!$jwks['valid']) {
            $opts = $a + ['a' => s($jwks['reason'])];
            $out[] = new finding('jwks_invalid', finding::L_OIDC, finding::BLOCKING, finding::PARTNER, $opts);
            return $out;
        }
        if (!empty($jwks['privateleak'])) {
            $out[] = new finding('jwks_privateleak', finding::L_OIDC, finding::BLOCKING, finding::PARTNER, $a + ['risk' => 'high']);
        }
        if ($jwks['haskid'] === false) {
            $out[] = new finding('jwks_kidmissing', finding::L_OIDC, finding::BLOCKING, finding::PARTNER, $a);
        } else if (empty($jwks['privateleak'])) {
            $out[] = new finding('jwks_ok', finding::L_OIDC, finding::OK, finding::PARTNER, $a + ['a' => count($jwks['keys'])]);
        }
        return $out;
    }

    /**
     * Framing finding for the browser session layer.
     *
     * @param array|null $framing Result of error_classifier::framing().
     * @param array $a Common options.
     * @return finding
     */
    private function framing_finding(?array $framing, array $a): finding {
        if ($framing && $framing['blocksembedding'] === true) {
            return new finding('bs_framing_blocked', finding::L_BROWSER, finding::WARNING, finding::PARTNER, $a + ['a' => s(
                $framing['xfo'] !== '' ? 'X-Frame-Options: ' . $framing['xfo'] : 'frame-ancestors ' . $framing['frameancestors']
            )]);
        }
        return new finding('bs_server_only', finding::L_BROWSER, finding::INFO, finding::ADMIN, $a);
    }

    /**
     * Make sure the layers that depend on probes show "not checked" when no probe ran.
     *
     * @param finding[] $findings Findings so far.
     * @param array $probes Probes.
     * @param int $now Time.
     * @return finding[]
     */
    private function ensure_layer_presence(array $findings, array $probes, int $now): array {
        $present = [];
        foreach ($findings as $f) {
            $present[$f->layer] = true;
        }
        $missing = [
            finding::L_NETWORK => 'net_notchecked',
            finding::L_FIREWALL => 'fw_notchecked',
            finding::L_TLS => 'tls_notchecked',
            finding::L_OIDC => 'oidc_notchecked',
            finding::L_OAUTH => 'oauth_notchecked',
            finding::L_BROWSER => 'bs_notchecked',
        ];
        foreach ($missing as $layer => $code) {
            if (empty($present[$layer])) {
                $findings[] = new finding($code, $layer, finding::NOTCHECKED, $layer === finding::L_FIREWALL ? finding::PARTNER
                    : finding::ADMIN, ['checkedat' => $now]);
            }
        }
        return $findings;
    }

    /**
     * Advantage layer: one finding per wanted (or configured) feature, derived from the status reason.
     *
     * @param connection $c Connection.
     * @param array $matrix Matrix rows.
     * @param desired_profile|null $profile Profile.
     * @param array $facts Facts.
     * @param int $now Time.
     * @return finding[]
     */
    private function advantage(connection $c, array $matrix, ?desired_profile $profile, array $facts, int $now): array {
        $out = [];
        foreach ($matrix as $feature => $row) {
            $wanted = $profile ? $profile->wants($feature) : in_array($row['status'], [status::CONFIGURED, status::AUTHORISED,
                status::VERIFIED, status::BLOCKED, status::INCOMPATIBLE], true);
            if (!$wanted) {
                continue;
            }
            $map = [
                'incompatible' => ['adv_incompatible', finding::BLOCKING, finding::ADMIN, 'high'],
                'not_specified' => ['adv_notsupported', finding::BLOCKING, finding::ADMIN, 'low'],
                'not_implemented' => ['adv_notsupported', finding::BLOCKING, finding::ADMIN, 'low'],
                'supported_not_enabled' => ['adv_localoff', finding::BLOCKING, finding::ADMIN, 'medium'],
                'remote_not_offered' => ['adv_remotenotoffered', finding::BLOCKING, finding::PARTNER, 'low'],
                'scope_missing' => ['adv_scopemissing', finding::BLOCKING, finding::PARTNER, 'medium'],
                'unreachable' => ['adv_unreachable', finding::BLOCKING, finding::NETWORK, 'medium'],
                'observed_failure' => ['adv_observedfail', finding::BLOCKING, finding::ADMIN, 'low'],
                'configured_not_observed' => ['adv_notverified', finding::WARNING, finding::ADMIN, 'low'],
                'authorised_not_observed' => ['adv_notverified', finding::WARNING, finding::ADMIN, 'low'],
                'observed' => ['adv_verified', finding::OK, finding::ADMIN, 'low'],
            ];
            $reason = $row['reason'];
            $spec = $map[$reason] ?? ['adv_unknown', finding::WARNING, finding::ADMIN, 'low'];
            if (strpos($reason, 'notobservable_') === 0) {
                $spec = ['adv_unknown', finding::WARNING, finding::PARTNER, 'low'];
            }
            $a = ['a' => (object) ['feature' => get_string('feature_' . $feature, 'tool_ltidiagnostics'),
                'status' => get_string('status_' . $row['status'], 'tool_ltidiagnostics')],
                'feature' => $feature, 'checkedat' => $now, 'risk' => $spec[3], 'evidencekind' => status::KIND_CONFIG];
            $out[] = new finding($spec[0], finding::L_ADVANTAGE, $spec[1], $spec[2], $a);
            foreach ($row['notes'] as $note) {
                if ($note === 'evidence_insufficient') {
                    $out[] = new finding('adv_evidenceweak', finding::L_ADVANTAGE, finding::INFO, finding::ADMIN, $a);
                }
            }
        }
        if ($profile && $profile->wants('ags')) {
            $out = array_merge($out, $this->scope_findings($c, $profile, $facts, $now));
        }
        return $out;
    }

    /**
     * Scope level gap between the wanted AGS scopes and what is available.
     *
     * @param connection $c Connection.
     * @param desired_profile $profile Profile.
     * @param array $facts Facts.
     * @param int $now Time.
     * @return finding[]
     */
    private function scope_findings(connection $c, desired_profile $profile, array $facts, int $now): array {
        $wantsscore = in_array('score', $profile->agsscopes, true) || in_array('lineitem', $profile->agsscopes, true)
            || $profile->expects('gradebook_update');
        if (!$wantsscore) {
            return [];
        }
        $a = ['feature' => 'ags', 'checkedat' => $now, 'evidencekind' => status::KIND_CONFIG];
        if ($c->role === connection::ROLE_PLATFORM && (int) ($c->facts['ags'] ?? 0) === 1) {
            $opts = $a + ['risk' => 'medium'];
            return [new finding('adv_readonlyscope', finding::L_OAUTH, finding::BLOCKING, finding::ADMIN, $opts)];
        }
        if (
            $c->role === connection::ROLE_TOOL && $facts['tool']['links'] > 0 && $facts['tool']['agsclaim']
                && !$facts['tool']['scorescope']
        ) {
            return [new finding('adv_noscorescope', finding::L_OAUTH, finding::BLOCKING, finding::PARTNER, $a)];
        }
        return [];
    }

    /**
     * Moodle core layer: local effects, kept apart from the service evidence.
     *
     * @param connection $c Connection.
     * @param array $matrix Matrix.
     * @param desired_profile|null $profile Profile.
     * @param array $facts Facts.
     * @param int $now Time.
     * @return finding[]
     */
    private function core(connection $c, array $matrix, ?desired_profile $profile, array $facts, int $now): array {
        $out = [];
        $obs = $facts['obs'];
        $access = $facts['access'];
        $a = ['checkedat' => $now];
        if ($profile && $profile->expects('gradebook_update') && $c->role === connection::ROLE_PLATFORM) {
            $served = !empty($access['service_ags_scores']['ok']);
            if ($obs['grade_in'] > 0) {
                $opts = $a + ['evidencekind' => status::KIND_OBSERVED, 'feature' => 'ags'];
                $out[] = new finding('core_grade_ok', finding::L_CORE, finding::OK, finding::ADMIN, $opts);
            } else if ($served) {
                $out[] = new finding('core_grade_unconfirmed', finding::L_CORE, finding::WARNING, finding::ADMIN, $a + [
                    'evidencekind' => status::KIND_ACCESSLOG, 'feature' => 'ags', 'confidence' => 'tentative']);
            } else {
                $out[] = new finding('core_no_score', finding::L_CORE, finding::WARNING, finding::PARTNER, $a + [
                    'evidencekind' => status::KIND_NONE, 'feature' => 'ags']);
            }
        }
        if ($profile && $profile->expects('gradebook_update') && $c->role === connection::ROLE_TOOL) {
            if ($facts['tool']['lastgrade']) {
                $out[] = new finding('core_toolgrade_local', finding::L_CORE, finding::INFO, finding::ADMIN, $a + [
                    'evidencekind' => status::KIND_LOCALEFFECT, 'feature' => 'ags']);
            } else {
                $out[] = new finding('core_no_score', finding::L_CORE, finding::WARNING, finding::PARTNER, $a + [
                    'feature' => 'ags']);
            }
        }
        if ($profile && $profile->expects('enrolment') && $c->role === connection::ROLE_TOOL) {
            $opts = $a + ['evidencekind' => status::KIND_LOCALEFFECT];
            $out[] = $obs['enrolment'] > 0
                ? new finding('core_enrol_ok', finding::L_CORE, finding::OK, finding::ADMIN, $opts)
                : new finding('core_enrol_missing', finding::L_CORE, finding::WARNING, finding::ADMIN, $a);
        }
        if ($profile && $profile->expects('groups') && $c->role === connection::ROLE_TOOL) {
            $opts = $a + ['evidencekind' => status::KIND_LOCALEFFECT];
            $out[] = $obs['group'] > 0
                ? new finding('core_group_ok', finding::L_CORE, finding::OK, finding::ADMIN, $opts)
                : new finding('core_group_missing', finding::L_CORE, finding::WARNING, finding::ADMIN, $a);
        }
        if ($c->role === connection::ROLE_TOOL && $obs['launch'] > 0) {
            $out[] = new finding('core_launch_seen', finding::L_CORE, finding::OK, finding::ADMIN, $a + [
                'evidencekind' => status::KIND_OBSERVED, 'a' => userdate($obs['launch'])]);
            if ($obs['enrolment'] > 0) {
                $out[] = new finding('core_enrol_seen', finding::L_CORE, finding::INFO, finding::ADMIN, $a + [
                    'evidencekind' => status::KIND_LOCALEFFECT, 'a' => userdate($obs['enrolment'])]);
            }
        }
        if (!$out) {
            $out[] = new finding('core_nothing', finding::L_CORE, finding::INFO, finding::ADMIN, $a);
        }
        return $out;
    }

    /**
     * Background processing layer.
     *
     * @param array $env Environment.
     * @param int $now Time.
     * @return finding[]
     */
    private function background(array $env, int $now): array {
        $out = [];
        $a = ['checkedat' => $now, 'evidencekind' => status::KIND_TASKLOG];
        $cron = $env['cron'];
        if ($cron['status'] === 'never') {
            $out[] = new finding('bg_cron_never', finding::L_BACKGROUND, finding::BLOCKING, finding::ADMIN, $a + ['risk' => 'low']);
        } else if ($cron['status'] === 'stale') {
            $out[] = new finding('bg_cron_stale', finding::L_BACKGROUND, finding::BLOCKING, finding::ADMIN, $a + [
                'a' => (int) floor($cron['age'] / 60)]);
        }
        foreach ($env['scheduled'] as $t) {
            if ($t['overdue']) {
                $out[] = new finding('bg_task_overdue', finding::L_BACKGROUND, finding::WARNING, finding::ADMIN, $a + [
                    'a' => s($t['classname']), 'evidence' => $t['classname']]);
            }
            if ($t['faildelay'] > 0 && !$t['disabled']) {
                $out[] = new finding('bg_task_failing', finding::L_BACKGROUND, finding::WARNING, finding::ADMIN, $a + [
                    'a' => s($t['classname']), 'evidence' => $t['classname']]);
            }
        }
        foreach ($env['adhoc'] as $t) {
            if ($t['failed'] > 0) {
                $out[] = new finding('bg_adhoc_failed', finding::L_BACKGROUND, finding::WARNING, finding::ADMIN, $a + [
                    'a' => (object) ['class' => s($t['classname']), 'count' => $t['failed']], 'evidence' => $t['classname']]);
            } else if ($t['due'] > 0) {
                $out[] = new finding('bg_adhoc_pending', finding::L_BACKGROUND, finding::INFO, finding::ADMIN, $a + [
                    'a' => (object) ['class' => s($t['classname']), 'count' => $t['due']], 'evidence' => $t['classname']]);
            }
        }
        if (!empty($env['tasklogfailed'])) {
            $out[] = new finding('bg_tasklog_failed', finding::L_BACKGROUND, finding::WARNING, finding::ADMIN, $a + [
                'a' => count($env['tasklogfailed'])]);
        }
        if (!$out) {
            $out[] = new finding('bg_ok', finding::L_BACKGROUND, finding::OK, finding::ADMIN, $a);
        }
        return $out;
    }

    /**
     * Observability layer: how complete is the evidence?
     *
     * @param connection $c Connection.
     * @param array $facts Facts.
     * @param array $env Environment.
     * @param int $now Time.
     * @return finding[]
     */
    private function observability(connection $c, array $facts, array $env, int $now): array {
        $out = [];
        $a = ['checkedat' => $now];
        $logging = array_filter($env['readers'], function ($r) {
            return $r['logging'];
        });
        if (!$env['readers']) {
            $out[] = new finding('obs_noreader', finding::L_OBSERVABILITY, finding::WARNING, finding::ADMIN, $a);
        } else if (!$logging) {
            $out[] = new finding('obs_notlogging', finding::L_OBSERVABILITY, finding::WARNING, finding::ADMIN, $a);
        }
        if (!$env['recordevents']) {
            $out[] = new finding('obs_recording_off', finding::L_OBSERVABILITY, finding::WARNING, finding::ADMIN, $a);
        }
        if ($env['imports'] === 0) {
            $out[] = new finding('obs_noaccesslog', finding::L_OBSERVABILITY, finding::INFO, finding::ADMIN, $a);
        }
        $out[] = new finding('obs_incomplete', finding::L_OBSERVABILITY, finding::INFO, finding::ADMIN, $a);
        return $out;
    }

    /**
     * Data protection indications derived from the desired state. Never a compliance verdict.
     *
     * @param connection $c Connection.
     * @param desired_profile|null $profile Profile.
     * @param int $now Time.
     * @return finding[]
     */
    private function privacy(connection $c, ?desired_profile $profile, int $now): array {
        $out = [];
        if (!$profile) {
            return $out;
        }
        $a = ['checkedat' => $now, 'evidencekind' => status::KIND_CONFIG];
        if ($profile->expects('pseudonymised_sub')) {
            if (array_intersect(['ags', 'nrps'], $profile->wanted)) {
                $out[] = new finding('priv_pseudonym_services', finding::L_PRIVACY, finding::WARNING, finding::ADMIN, $a + [
                    'risk' => 'high']);
            }
            if ($c->role === connection::ROLE_PLATFORM) {
                $opts = $a + ['risk' => 'medium'];
                $out[] = new finding('priv_loginhint', finding::L_PRIVACY, finding::WARNING, finding::ADMIN, $opts);
            }
        }
        if ($c->role === connection::ROLE_PLATFORM) {
            foreach (['send_name' => 'sendname', 'send_email' => 'sendemail'] as $expect => $fact) {
                if (isset($profile->expect[$expect]) && $profile->expect[$expect] === false && !empty($c->facts[$fact])) {
                    $opts = $a + ['risk' => 'medium'];
                    $out[] = new finding('priv_' . $expect, finding::L_PRIVACY, finding::WARNING, finding::ADMIN, $opts);
                }
            }
        }
        return $out;
    }

    /**
     * Layer-wise desired/actual comparison for the report.
     *
     * @param connection $c Connection.
     * @param array $matrix Matrix.
     * @param desired_profile $profile Profile.
     * @return array[] Each with feature, wanted, local, remote, authorised, observed (step states).
     */
    private function delta(connection $c, array $matrix, desired_profile $profile): array {
        $rows = [];
        foreach ($profile->wanted as $feature) {
            $ladder = $matrix[$feature]['ladder'];
            $rows[] = [
                'feature' => $feature,
                'status' => $matrix[$feature]['status'],
                'local' => $ladder[status::STEP_ENABLED]->state,
                'remote' => $ladder[status::STEP_DECLARED]->state,
                'authorised' => $ladder[status::STEP_AUTHORISED]->state,
                'observed' => $ladder[status::STEP_OBSERVED]->state,
                'observedkind' => $ladder[status::STEP_OBSERVED]->kind,
            ];
        }
        return $rows;
    }
}
