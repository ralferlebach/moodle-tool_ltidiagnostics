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

namespace tool_ltidiagnostics\local\active;

/**
 * Structural analysis of a JSON Web Key Set document. Never stores or shows key material.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jwks_analyser {
    /** @var string[] Members that must never appear in a public key set. */
    private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'k'];

    /**
     * Analyse a JWKS body.
     *
     * @param string $body Response body.
     * @param string $expectedkid Key id that a token header requested (optional).
     * @return array valid (bool), reason (string), keys (array of kid/kty/alg/use), haskid (bool|null), privateleak (bool)
     */
    public static function analyse(string $body, string $expectedkid = ''): array {
        $out = ['valid' => false, 'reason' => 'notjson', 'keys' => [], 'haskid' => null, 'privateleak' => false];
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $out;
        }
        if (!isset($json['keys']) || !is_array($json['keys'])) {
            $out['reason'] = 'nokeys';
            return $out;
        }
        if (!$json['keys']) {
            $out['reason'] = 'emptykeys';
            return $out;
        }
        foreach ($json['keys'] as $key) {
            if (!is_array($key)) {
                continue;
            }
            foreach (self::PRIVATE_MEMBERS as $m) {
                if (array_key_exists($m, $key)) {
                    $out['privateleak'] = true;
                }
            }
            $out['keys'][] = [
                'kid' => isset($key['kid']) ? (string) $key['kid'] : '',
                'kty' => isset($key['kty']) ? (string) $key['kty'] : '',
                'alg' => isset($key['alg']) ? (string) $key['alg'] : '',
                'use' => isset($key['use']) ? (string) $key['use'] : '',
            ];
        }
        $out['valid'] = (bool) $out['keys'];
        $out['reason'] = $out['valid'] ? 'ok' : 'nokeys';
        if ($expectedkid !== '') {
            $out['haskid'] = in_array($expectedkid, array_column($out['keys'], 'kid'), true);
        }
        return $out;
    }
}
