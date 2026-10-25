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
 * Runs the safe active checks selected for a connection and stores their outcome.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class probe_runner {
    /** @var string[] Endpoint key => purpose of the check. */
    private const PURPOSES = [
        'jwks' => active_checker::PURPOSE_JWKS,
        'launch' => active_checker::PURPOSE_LAUNCH,
        'auth' => active_checker::PURPOSE_ENDPOINT,
        'oauth' => active_checker::PURPOSE_ENDPOINT,
        'initiatelogin' => active_checker::PURPOSE_ENDPOINT,
    ];

    /**
     * The endpoints of a connection that can be probed.
     *
     * @param connection $c Connection.
     * @return string[] Key => URL.
     */
    public static function probeable_endpoints(connection $c): array {
        return array_intersect_key($c->endpoints, self::PURPOSES);
    }

    /**
     * Run the checks.
     *
     * @param connection $c Connection.
     * @param string[] $selected Endpoint keys to check.
     * @param string $customurl Optional additional URL (checked as plain endpoint).
     * @param string $expectedkid Optional kid to look for in a key set.
     * @param int $now Time.
     * @param active_checker|null $checker Checker (default: real one).
     * @return array Results keyed by endpoint key ("custom" for the additional URL).
     */
    public function run(
        connection $c,
        array $selected,
        string $customurl,
        string $expectedkid,
        int $now,
        ?active_checker $checker = null
    ): array {
        require_capability('tool/ltidiagnostics:runchecks', \context_system::instance());
        if (!config::get('activechecks')) {
            throw new \moodle_exception('activechecksdisabled', 'tool_ltidiagnostics');
        }
        $checker = $checker ?? new active_checker();
        $results = [];
        $endpoints = self::probeable_endpoints($c);
        foreach ($selected as $key) {
            if (!isset($endpoints[$key])) {
                continue;
            }
            $results[$key] = $checker->check($endpoints[$key], self::PURPOSES[$key], $now, $key === 'jwks' ? $expectedkid : '');
        }
        if ($customurl !== '') {
            $results['custom'] = $checker->check($customurl, active_checker::PURPOSE_ENDPOINT, $now);
        }
        audit::log('runchecks', $c->key, 'checks=' . count($results));
        return $results;
    }
}
