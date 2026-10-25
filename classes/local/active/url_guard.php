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
 * SSRF guard for every URL that the active checks may request.
 *
 * Builds on Moodle's \core\files\curl_security_helper (blocked hosts, blocked ports, DNS
 * resolution) and adds the rules of this plugin: https only, no credentials in the URL, a bounded
 * length, and no private or loopback targets unless an administrator explicitly allowed them.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_guard {
    /** @var string Reason: allowed. */
    public const OK = 'ok';

    /**
     * Check a URL.
     *
     * @param string $url The URL to request.
     * @param bool $allowprivate True when private and loopback targets (and plain http) were explicitly allowed.
     * @return string "ok" or a reason code: empty, toolong, malformed, scheme, credentials, host, blocked
     */
    public static function check(string $url, bool $allowprivate = false): string {
        $url = trim($url);
        if ($url === '') {
            return 'empty';
        }
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return strlen($url) > 2000 ? 'toolong' : 'malformed';
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'malformed';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($allowprivate && $scheme === 'http')) {
            return 'scheme';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials';
        }
        if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+\z/', $parts['host'])) {
            return 'host';
        }
        if ($allowprivate) {
            return self::OK;
        }
        // The built-in rules do not depend on the site's configuration, which an administrator may have emptied.
        if (self::is_internal_host($parts['host'])) {
            return 'blocked';
        }
        $helper = new \core\files\curl_security_helper();
        if ($helper->url_is_blocked($url)) {
            return 'blocked';
        }
        return self::OK;
    }

    /**
     * Whether a host name or literal address points into a private, local or reserved network.
     *
     * @param string $host Host as found in the URL (IPv6 literals with brackets).
     * @return bool
     */
    public static function is_internal_host(string $host): bool {
        $host = strtolower(trim($host, "[] \t."));
        if ($host === '') {
            return true;
        }
        if ($host === 'localhost' || preg_match('/\.(localhost|local|localdomain|internal|lan|home|corp)$/', $host)) {
            return true;
        }
        if (strpos($host, ':') !== false) {
            return self::is_internal_address($host);
        }
        if (preg_match('/^[0-9.]+$/', $host) || preg_match('/^0x[0-9a-f.x]*$/', $host)) {
            // Anything that looks like a number: only a clean dotted quad is judged by range; the rest
            // (decimal, octal and hexadecimal spellings) is refused.
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || self::is_internal_address($host);
        }
        return false;
    }

    /**
     * Whether an IP address is private, loopback, link-local, reserved or an IPv4-mapped form of one.
     *
     * @param string $ip Address without brackets.
     * @return bool True for unusable or internal addresses, also for strings that are no address.
     */
    public static function is_internal_address(string $ip): bool {
        $ip = strtolower(trim($ip, '[] '));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        if (strpos($ip, ':') !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return true;
            }
            // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible forms are judged by the embedded IPv4 address.
            $prefix = substr($packed, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === str_repeat("\0", 12)) {
                $v4 = inet_ntop(substr($packed, 12));
                return $v4 === false || self::is_internal_address($v4);
            }
            $first = ord($packed[0]);
            if (($first & 0xfe) === 0xfc || ($first === 0xfe && (ord($packed[1]) & 0xc0) === 0x80) || $first === 0xff) {
                return true; // Unique local, link-local and multicast.
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        if (strpos($ip, ':') === false) {
            // Ranges that PHP does not flag: shared address space (carrier grade NAT) and benchmarking.
            $long = ip2long($ip);
            foreach (['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24'] as $range) {
                [$net, $bits] = explode('/', $range);
                $mask = -1 << (32 - (int) $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return true;
                }
            }
        }
        return false;
    }
}
