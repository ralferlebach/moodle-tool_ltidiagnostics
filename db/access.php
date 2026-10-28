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

/**
 * Capability definitions for tool_ltidiagnostics.
 *
 * Default roles are recommendations only: apart from the course level aggregate view for
 * teachers, every capability is granted to nobody but the site administrators by default.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Technical diagnosis of all connections (administrators).
    'tool/ltidiagnostics:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL | RISK_CONFIG,
        'archetypes' => [],
    ],
    // Aggregate, anonymous diagnosis of the LTI activities of one course (teachers).
    'tool/ltidiagnostics:viewcourse' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Pseudonymised diagnostic id and error summary (support staff).
    'tool/ltidiagnostics:viewsupport' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    // Audit log, data flow and retention views (privacy officers).
    'tool/ltidiagnostics:viewprivacy' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [],
    ],
    // Desired-state profiles and the diagnostic mode.
    'tool/ltidiagnostics:configure' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_CONFIG,
        'archetypes' => [],
    ],
    // Safe active checks: outgoing GET requests to endpoints of the other side.
    'tool/ltidiagnostics:runchecks' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_CONFIG,
        'archetypes' => [],
    ],
    // Active checks inside private networks (only effective while the administrator allowed private targets).
    'tool/ltidiagnostics:runprivatechecks' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_CONFIG,
        'archetypes' => [],
    ],
    // Import of web server logs.
    'tool/ltidiagnostics:importlogs' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL | RISK_CONFIG,
        'archetypes' => [],
    ],
    // Export of diagnostic reports.
    'tool/ltidiagnostics:export' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [],
    ],
];
