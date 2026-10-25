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
 * Pure status engine: turns the seven-step evidence ladder of one feature into one status.
 *
 * The engine has no side effects and reads no database, so it can be tested exhaustively.
 *
 * Rules, applied in this order:
 *  1. The "incompatible" flag wins: two settings contradict each other.
 *  2. A feature that is not specified or not implemented is "not_supported".
 *  3. A feature whose implementation cannot be determined is "not_observable" or "unknown".
 *  4. While the feature is switched on locally, a step answering "no" or "blocked" on declared,
 *     authorised, reachable or observed makes the status "blocked".
 *  5. "verified" requires the observed step to be "yes" with a verifying evidence kind
 *     (observed transaction or authorised test). Any other evidence is rejected and noted.
 *  6. Otherwise the highest step proven with a real evidence wins: authorised, configured, supported.
 *  7. A step that is "not_observable" never lifts the status. It only adds a note.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_engine {
    /**
     * Evaluate one feature.
     *
     * @param evidence[] $ladder Evidence per step, keyed by status::STEP_*. Missing steps count as unknown.
     * @param bool $incompatible True when a documented incompatibility applies.
     * @return status_result
     */
    public function evaluate(array $ladder, bool $incompatible = false): status_result {
        $steps = [];
        foreach (status::steps() as $step) {
            $steps[$step] = $ladder[$step] ?? evidence::unknown();
            if (!in_array($steps[$step]->state, status::states(), true)) {
                $steps[$step] = evidence::unknown();
            }
        }
        $notes = [];

        if ($incompatible) {
            return new status_result(status::INCOMPATIBLE, 'incompatible', '', 'review_settings');
        }

        foreach ([status::STEP_SPECIFIED, status::STEP_IMPLEMENTED] as $step) {
            if ($steps[$step]->state === status::STATE_NO) {
                return new status_result(status::NOT_SUPPORTED, 'not_' . $step, $step, 'none');
            }
        }
        foreach ([status::STEP_SPECIFIED, status::STEP_IMPLEMENTED] as $step) {
            if ($steps[$step]->state === status::STATE_NOT_OBSERVABLE) {
                return new status_result(status::NOT_OBSERVABLE, 'notobservable_' . $step, $step, 'ask_partner');
            }
        }
        if ($steps[status::STEP_IMPLEMENTED]->state !== status::STATE_YES) {
            return new status_result(status::UNKNOWN, 'unknown_implemented', status::STEP_IMPLEMENTED, 'collect_config');
        }

        $enabled = $steps[status::STEP_ENABLED]->state === status::STATE_YES;
        foreach ($steps as $step => $ev) {
            if ($ev->state === status::STATE_NOT_OBSERVABLE) {
                $notes[] = 'notobservable_' . $step;
            }
        }

        if ($enabled) {
            $reasons = [
                status::STEP_DECLARED => ['remote_not_offered', 'ask_partner'],
                status::STEP_AUTHORISED => ['scope_missing', 'check_scopes'],
                status::STEP_REACHABLE => ['unreachable', 'run_safe_probe'],
                status::STEP_OBSERVED => ['observed_failure', 'inspect_logs'],
            ];
            foreach ($reasons as $step => $info) {
                $state = $steps[$step]->state;
                if ($state === status::STATE_NO || $state === status::STATE_BLOCKED) {
                    return new status_result(status::BLOCKED, $info[0], $step, $info[1], $notes);
                }
            }
        }

        $observed = $steps[status::STEP_OBSERVED];
        if ($observed->state === status::STATE_YES) {
            if ($enabled && in_array($observed->kind, status::verifying_kinds(), true)) {
                return new status_result(status::VERIFIED, 'observed', status::STEP_OBSERVED, 'none', $notes);
            }
            $notes[] = 'evidence_insufficient';
        }

        if ($enabled && $steps[status::STEP_AUTHORISED]->state === status::STATE_YES) {
            return new status_result(
                status::AUTHORISED,
                'authorised_not_observed',
                status::STEP_AUTHORISED,
                'observe_transaction',
                $notes
            );
        }
        if ($enabled) {
            return new status_result(
                status::CONFIGURED,
                'configured_not_observed',
                status::STEP_ENABLED,
                'observe_transaction',
                $notes
            );
        }
        return new status_result(
            status::SUPPORTED,
            'supported_not_enabled',
            status::STEP_IMPLEMENTED,
            'enable_locally',
            $notes
        );
    }
}
