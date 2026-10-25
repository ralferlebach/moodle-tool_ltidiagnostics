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
 * DNS resolver using the PHP resolver of this server.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class system_dns_resolver implements dns_resolver {
    /**
     * Resolve a host name.
     *
     * @param string $host Host name.
     * @return string[]|null
     */
    public function resolve(string $host): ?array {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false) {
            return null;
        }
        $out = [];
        foreach ($records as $r) {
            if (!empty($r['ip'])) {
                $out[] = $r['ip'];
            } else if (!empty($r['ipv6'])) {
                $out[] = $r['ipv6'];
            }
        }
        return $out;
    }
}
