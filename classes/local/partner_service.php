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
use tool_ltidiagnostics\local\active\url_guard;

/**
 * Actions on partner documents, shared by the pages and the command line scripts: export, import and the two-system
 * live check. Every action checks its capability and is audited; none changes an LTI setting.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class partner_service {
    /**
     * Export the partner package of a connection.
     *
     * @param connection $c Connection.
     * @param int $now Time.
     * @return array Package.
     */
    public static function export_package(connection $c, int $now): array {
        $context = \context_system::instance();
        require_capability('tool/ltidiagnostics:export', $context);
        $facts = (new fact_collector())->collect($c, $now);
        $package = partner_package::export($c, $facts, $now);
        audit::log('partner_export', $c->key, 'schema=' . partner_package::SCHEMA);
        return $package;
    }

    /**
     * Import a partner package for a connection.
     *
     * @param connection $c Connection.
     * @param string $json Package text.
     * @param int $now Time.
     * @return array Comparison (see {@see partner_package::compare()}).
     */
    public static function import_package(connection $c, string $json, int $now): array {
        require_capability('tool/ltidiagnostics:configure', \context_system::instance());
        $package = partner_package::parse($json);
        snapshot::save_kind($c->key, partner_package::KIND, ['package' => $package], $now);
        audit::log('partner_import', $c->key, 'partner=' . $package['diagnosticid']);
        return partner_package::compare($c, $package, $now);
    }

    /**
     * The key set URL this site uses for the partner (what the LTI flow really fetches).
     *
     * @param connection $c Connection.
     * @return string
     */
    public static function partner_jwks_url(connection $c): string {
        return (string) ($c->endpoints['jwks'] ?? '');
    }

    /**
     * Two-system live check against the newest imported package (read only, GET through the SSRF guard).
     *
     * @param connection $c Connection.
     * @param int $now Time.
     * @param active_checker|null $checker Checker (tests).
     * @return array|null Result of {@see partner_package::live_check()}, null without an imported package.
     */
    public static function live(connection $c, int $now, ?active_checker $checker = null): ?array {
        $context = \context_system::instance();
        require_capability('tool/ltidiagnostics:runchecks', $context);
        if (!config::get('activechecks')) {
            throw new \moodle_exception('activechecksdisabled', 'tool_ltidiagnostics');
        }
        $stored = snapshot::latest_kind($c->key, partner_package::KIND);
        if ($stored === null || empty($stored['package'])) {
            return null;
        }
        $url = self::partner_jwks_url($c);
        $private = (bool) config::get('allowprivatetargets') || ($url !== '' && url_guard::exact_allowed($url));
        if ($private) {
            require_capability('tool/ltidiagnostics:runprivatechecks', $context);
        }
        $package = partner_package::parse(json_encode($stored['package']));
        $result = partner_package::live_check($c, $package, $url, $now, $checker);
        $probe = $result['probe'];
        unset($probe['dns'], $probe['url']);
        snapshot::save_kind($c->key, partner_package::KIND, ['package' => $stored['package'], 'live' => [
            'keys' => $result['keys'], 'code' => (string) ($probe['code'] ?? ''), 'checkedat' => $now,
            'findings' => array_map(function (finding $f) {
                return $f->code;
            }, $result['findings'])]], $now);
        audit::log($private ? 'partner_live_private' : 'partner_live', $c->key, 'code=' . ($probe['code'] ?? ''));
        return $result;
    }
}
