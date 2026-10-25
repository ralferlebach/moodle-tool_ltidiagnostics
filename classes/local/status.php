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
 * Vocabulary of the evidence ladder: statuses, ladder steps, step states and evidence kinds.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status {
    /** @var string Moodle implements the feature, nothing else is known. */
    public const SUPPORTED = 'supported';
    /** @var string Switched on locally. This is NOT proof that the feature works. */
    public const CONFIGURED = 'configured';
    /** @var string Authorisation (token or scope) is evidenced. */
    public const AUTHORISED = 'authorised';
    /** @var string A real transaction was observed or an authorised test passed. */
    public const VERIFIED = 'verified';
    /** @var string Something that is required is demonstrably missing or failing. */
    public const BLOCKED = 'blocked';
    /** @var string Two settings contradict each other. */
    public const INCOMPATIBLE = 'incompatible';
    /** @var string The feature does not exist in this Moodle. */
    public const NOT_SUPPORTED = 'not_supported';
    /** @var string The fact cannot be seen from this system. */
    public const NOT_OBSERVABLE = 'not_observable';
    /** @var string Nothing is known. */
    public const UNKNOWN = 'unknown';

    /** @var string Ladder step: the LTI specification foresees the feature. */
    public const STEP_SPECIFIED = 'specified';
    /** @var string Ladder step: this Moodle has an implementation. */
    public const STEP_IMPLEMENTED = 'implemented';
    /** @var string Ladder step: administratively switched on locally. */
    public const STEP_ENABLED = 'enabled';
    /** @var string Ladder step: the remote side announced the feature. */
    public const STEP_DECLARED = 'declared';
    /** @var string Ladder step: authorisation or scope evidenced. */
    public const STEP_AUTHORISED = 'authorised';
    /** @var string Ladder step: endpoint reachable (TLS, HTTP, safe probe). */
    public const STEP_REACHABLE = 'reachable';
    /** @var string Ladder step: function demonstrated in practice. */
    public const STEP_OBSERVED = 'observed';

    /** @var string Step state: yes. */
    public const STATE_YES = 'yes';
    /** @var string Step state: no. */
    public const STATE_NO = 'no';
    /** @var string Step state: unknown. */
    public const STATE_UNKNOWN = 'unknown';
    /** @var string Step state: cannot be observed from here. */
    public const STATE_NOT_OBSERVABLE = 'not_observable';
    /** @var string Step state: was attempted and failed. */
    public const STATE_BLOCKED = 'blocked';
    /** @var string Step state: not applicable to this feature or role. */
    public const STATE_NA = 'na';

    /** @var string Evidence kind: setting read from configuration. */
    public const KIND_CONFIG = 'config';
    /** @var string Evidence kind: code or plugin presence detected. */
    public const KIND_CODE = 'code_detection';
    /** @var string Evidence kind: a database record created by the flow (token, claim, registration). */
    public const KIND_DBRECORD = 'db_record';
    /** @var string Evidence kind: observed transaction (own observer). */
    public const KIND_OBSERVED = 'observed_transaction';
    /** @var string Evidence kind: a local effect that could have other causes. */
    public const KIND_LOCALEFFECT = 'local_effect';
    /** @var string Evidence kind: authorised, explicitly requested test. */
    public const KIND_TEST = 'authorised_test';
    /** @var string Evidence kind: imported web server log line. */
    public const KIND_ACCESSLOG = 'access_log';
    /** @var string Evidence kind: task log entry. */
    public const KIND_TASKLOG = 'task_log';
    /** @var string Evidence kind: safe active probe from this server. */
    public const KIND_PROBE = 'active_probe';
    /** @var string Evidence kind: nothing. */
    public const KIND_NONE = 'none';

    /**
     * All statuses, ordered from the weakest claim to the strongest.
     *
     * @return string[]
     */
    public static function all(): array {
        return [self::UNKNOWN, self::NOT_OBSERVABLE, self::NOT_SUPPORTED, self::INCOMPATIBLE, self::BLOCKED,
            self::SUPPORTED, self::CONFIGURED, self::AUTHORISED, self::VERIFIED];
    }

    /**
     * The ladder steps in order.
     *
     * @return string[]
     */
    public static function steps(): array {
        return [self::STEP_SPECIFIED, self::STEP_IMPLEMENTED, self::STEP_ENABLED, self::STEP_DECLARED,
            self::STEP_AUTHORISED, self::STEP_REACHABLE, self::STEP_OBSERVED];
    }

    /**
     * All step states.
     *
     * @return string[]
     */
    public static function states(): array {
        return [self::STATE_YES, self::STATE_NO, self::STATE_UNKNOWN, self::STATE_NOT_OBSERVABLE,
            self::STATE_BLOCKED, self::STATE_NA];
    }

    /**
     * Evidence kinds that may justify the status "verified" on the observed step.
     *
     * Configuration, a reachable endpoint, a token or a local side effect alone never do.
     *
     * @return string[]
     */
    public static function verifying_kinds(): array {
        return [self::KIND_OBSERVED, self::KIND_TEST];
    }

    /**
     * Traffic light colour of a status for the user interface.
     *
     * @param string $status A status constant.
     * @return string green, amber, red or grey
     */
    public static function light(string $status): string {
        switch ($status) {
            case self::VERIFIED:
                return 'green';
            case self::AUTHORISED:
            case self::CONFIGURED:
            case self::SUPPORTED:
                return 'amber';
            case self::BLOCKED:
            case self::INCOMPATIBLE:
                return 'red';
            default:
                return 'grey';
        }
    }
}
