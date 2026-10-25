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
 * Support view: a pseudonymised diagnostic id and an error summary without any identifiers.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class support {
    /**
     * Pseudonymised diagnostic id of a connection. Stable on this site, meaningless elsewhere.
     *
     * @param string $connkey Connection key.
     * @return string
     */
    public static function diagnostic_id(string $connkey): string {
        return 'LTD-' . strtoupper(substr(hash_hmac('sha256', $connkey, get_site_identifier()), 0, 10));
    }

    /**
     * Reduce a health check result to what support may see: the id, layer lights, and finding codes with severity.
     *
     * @param string $connkey Connection key.
     * @param array $result Result of health_check::evaluate().
     * @return array diagnosticid, role, layers (layer => light), findings (list of code + severity + layer)
     */
    public static function summary(string $connkey, array $result): array {
        $parsed = connection::parse_key($connkey);
        $layers = [];
        foreach ($result['layers'] as $layer => $info) {
            $layers[$layer] = $info['light'];
        }
        $findings = [];
        foreach ($result['findings'] as $f) {
            if ($f->severity === finding::OK) {
                continue;
            }
            $findings[] = ['code' => $f->code, 'layer' => $f->layer, 'severity' => $f->severity];
        }
        return [
            'diagnosticid' => self::diagnostic_id($connkey),
            'role' => $parsed ? $parsed[0] : '',
            'layers' => $layers,
            'findings' => $findings,
            'checkedat' => $result['checkedat'],
        ];
    }
}
