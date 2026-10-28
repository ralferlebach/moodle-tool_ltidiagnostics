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
 * One finding of the health check: a cause with its location, the required intervention, the risk
 * and the way to verify the fix.
 *
 * Language strings are derived from the code: f_<code>_title, f_<code>_detail, f_<code>_fix and, where a
 * different cause is plausible, f_<code>_alt. Shared per layer: verify_<layer> (low risk way to confirm the fix),
 * where_<layer> (where in Moodle or at the partner the setting lives) and uncertain_<layer> (what cannot be seen
 * from here).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finding {
    /** @var string Severity: a required part is demonstrably broken. */
    public const BLOCKING = 'blocking';
    /** @var string Severity: works, but a risk or doubt remains. */
    public const WARNING = 'warning';
    /** @var string Severity: information. */
    public const INFO = 'info';
    /** @var string Severity: this check passed. */
    public const OK = 'ok';
    /** @var string Severity: this could not be checked from here. */
    public const NOTCHECKED = 'not_checked';

    /** @var string Responsible: Moodle administrator. */
    public const ADMIN = 'admin';
    /** @var string Responsible: network or infrastructure operator. */
    public const NETWORK = 'network';
    /** @var string Responsible: the LTI partner (other side). */
    public const PARTNER = 'partner';
    /** @var string Responsible: teacher. */
    public const TEACHER = 'teacher';

    /** @var string Layer: configuration. */
    public const L_CONFIG = 'configuration';
    /** @var string Layer: DNS and network. */
    public const L_NETWORK = 'dns_network';
    /** @var string Layer: firewall. */
    public const L_FIREWALL = 'firewall';
    /** @var string Layer: TLS and certificates. */
    public const L_TLS = 'tls';
    /** @var string Layer: OpenID Connect and JWT. */
    public const L_OIDC = 'oidc_jwt';
    /** @var string Layer: OAuth 2. */
    public const L_OAUTH = 'oauth2';
    /** @var string Layer: LTI Advantage. */
    public const L_ADVANTAGE = 'advantage';
    /** @var string Layer: Moodle core effects. */
    public const L_CORE = 'moodle_core';
    /** @var string Layer: background processing. */
    public const L_BACKGROUND = 'background';
    /** @var string Layer: observability. */
    public const L_OBSERVABILITY = 'observability';
    /** @var string Layer: browser session. */
    public const L_BROWSER = 'browser_session';
    /** @var string Layer: data protection indications. */
    public const L_PRIVACY = 'privacy';

    /** @var string Machine readable code. */
    public $code;
    /** @var string Layer. */
    public $layer;
    /** @var string Severity. */
    public $severity;
    /** @var string Responsible role. */
    public $responsible;
    /** @var string Risk of the intervention: low, medium, high. */
    public $risk;
    /** @var mixed Parameter(s) for the strings. */
    public $a;
    /** @var string Evidence class used, one of status::KIND_*. */
    public $evidencekind;
    /** @var string Evidence reference (what was looked at). */
    public $evidence;
    /** @var string Feature id or empty. */
    public $feature;
    /** @var int Time of the check. */
    public $checkedat;
    /** @var string Test id, stable across runs. */
    public $testid;
    /** @var string Confidence: direct, strong, tentative or empty. */
    public $confidence;
    /** @var bool True when the finding is probably only a consequence of an earlier, more fundamental one. */
    public $consequence = false;
    /** @var string System that has to act: this_site, partner_site, network or both_sites. */
    public $system;
    /** @var string How sure the cause is: certain (read from data of this system), likely (observed symptom, the
     *      named cause is the most probable one), possible (only an indication). */
    public $certainty;

    /** @var string System: this Moodle site. */
    public const SYS_LOCAL = 'this_site';
    /** @var string System: the LTI partner. */
    public const SYS_PARTNER = 'partner_site';
    /** @var string System: network, DNS, proxy, firewall or certificates between the two. */
    public const SYS_NETWORK = 'network';
    /** @var string System: either side may hold the wrong value (a mismatch). */
    public const SYS_BOTH = 'both_sites';
    /** @var string Certainty: read directly from data of this system. */
    public const CERTAIN = 'certain';
    /** @var string Certainty: the symptom was observed, the cause is the most probable one. */
    public const LIKELY = 'likely';
    /** @var string Certainty: only an indication. */
    public const POSSIBLE = 'possible';

    /**
     * Constructor.
     *
     * @param string $code Code.
     * @param string $layer Layer.
     * @param string $severity Severity.
     * @param string $responsible Responsible role.
     * @param array $options a, risk, evidencekind, evidence, feature, checkedat, confidence.
     */
    public function __construct(string $code, string $layer, string $severity, string $responsible, array $options = []) {
        $this->code = $code;
        $this->layer = $layer;
        $this->severity = $severity;
        $this->responsible = $responsible;
        $this->risk = $options['risk'] ?? 'low';
        $this->a = $options['a'] ?? null;
        $this->evidencekind = $options['evidencekind'] ?? status::KIND_NONE;
        $this->evidence = $options['evidence'] ?? '';
        $this->feature = $options['feature'] ?? '';
        $this->checkedat = (int) ($options['checkedat'] ?? 0);
        $this->confidence = $options['confidence'] ?? '';
        $this->system = $options['system'] ?? self::default_system($responsible);
        $this->certainty = $options['certainty'] ?? self::default_certainty($severity, $this->evidencekind);
        $this->testid = $layer . '.' . $code . ($this->feature !== '' ? '.' . $this->feature : '');
    }

    /**
     * The system that has to act, derived from the responsible team when the producer did not say.
     *
     * @param string $responsible Responsible team.
     * @return string
     */
    public static function default_system(string $responsible): string {
        if ($responsible === self::PARTNER) {
            return self::SYS_PARTNER;
        }
        return $responsible === self::NETWORK ? self::SYS_NETWORK : self::SYS_LOCAL;
    }

    /**
     * Default certainty: configuration and database facts of this system are certain; a failed probe or an observed
     * transaction proves the symptom, its cause stays "likely"; everything else is "possible".
     *
     * @param string $severity Severity.
     * @param string $kind Evidence kind.
     * @return string
     */
    public static function default_certainty(string $severity, string $kind): string {
        if (in_array($kind, [status::KIND_CONFIG, status::KIND_DBRECORD, status::KIND_TASKLOG], true)) {
            return self::CERTAIN;
        }
        if (
            in_array($kind, [status::KIND_PROBE, status::KIND_OBSERVED, status::KIND_LOCALEFFECT, status::KIND_ACCESSLOG,
                status::KIND_TEST], true)
        ) {
            return self::LIKELY;
        }
        return $severity === self::OK ? self::LIKELY : self::POSSIBLE;
    }

    /**
     * The four parts every diagnosis must name (audit item DIA-03): cause, uncertainty, responsible system and a
     * concrete safe check, plus the fix and where the setting lives.
     *
     * @return array code, layer, severity, cause, detail, certainty, uncertainty, alternative, system, team, check, fix,
     *         where, evidence
     */
    public function diagnosis(): array {
        $str = $this->strings();
        $checkid = 'f_' . $this->code . '_check';
        $check = get_string_manager()->string_exists($checkid, 'tool_ltidiagnostics')
            ? get_string($checkid, 'tool_ltidiagnostics', $this->a) : $str['verify'];
        return [
            'code' => $this->code,
            'layer' => $this->layer,
            'severity' => $this->severity,
            'cause' => $str['title'],
            'detail' => $str['detail'],
            'certainty' => $this->certainty,
            'uncertainty' => $str['uncertain'],
            'alternative' => $str['alt'],
            'system' => $this->system,
            'team' => $this->responsible,
            'check' => $check,
            'fix' => $str['fix'],
            'where' => $str['where'],
            'evidence' => $this->evidencekind . ($this->evidence !== '' ? ':' . $this->evidence : ''),
        ];
    }

    /**
     * All layers in display order.
     *
     * @return string[]
     */
    public static function layers(): array {
        return [self::L_CONFIG, self::L_NETWORK, self::L_FIREWALL, self::L_TLS, self::L_OIDC, self::L_OAUTH, self::L_ADVANTAGE,
            self::L_CORE, self::L_BACKGROUND, self::L_OBSERVABILITY, self::L_BROWSER, self::L_PRIVACY];
    }

    /**
     * Sort weight of a severity (lower is more urgent).
     *
     * @param string $severity Severity.
     * @return int
     */
    public static function weight(string $severity): int {
        $order = [self::BLOCKING => 0, self::WARNING => 1, self::NOTCHECKED => 2, self::INFO => 3, self::OK => 4];
        return $order[$severity] ?? 5;
    }

    /**
     * Layers in the order in which a transaction depends on them: a failure in an earlier layer makes the results of
     * the later layers unreliable. Background processing is independent of this chain.
     *
     * @return string[]
     */
    public static function dependency_chain(): array {
        return [self::L_CONFIG, self::L_NETWORK, self::L_FIREWALL, self::L_TLS, self::L_OIDC, self::L_OAUTH, self::L_ADVANTAGE,
            self::L_CORE];
    }

    /**
     * Language string identifiers of this finding.
     *
     * @return string[] title, detail, fix, verify, where, uncertain (alt is optional and resolved in {@see self::strings()})
     */
    public function string_ids(): array {
        return ['title' => 'f_' . $this->code . '_title', 'detail' => 'f_' . $this->code . '_detail',
            'fix' => 'f_' . $this->code . '_fix', 'verify' => 'verify_' . $this->layer, 'where' => 'where_' . $this->layer,
            'uncertain' => 'uncertain_' . $this->layer];
    }

    /**
     * Resolve the strings in the current language.
     *
     * @return array title, detail, fix, verify, where, uncertain, alt (empty when no alternative cause is documented)
     */
    public function strings(): array {
        $ids = $this->string_ids();
        $out = [];
        foreach ($ids as $k => $id) {
            $out[$k] = get_string($id, 'tool_ltidiagnostics', $this->a);
        }
        $altid = 'f_' . $this->code . '_alt';
        $out['alt'] = get_string_manager()->string_exists($altid, 'tool_ltidiagnostics')
            ? get_string($altid, 'tool_ltidiagnostics', $this->a) : '';
        return $out;
    }
}
