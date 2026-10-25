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
 * Fetcher based on Moodle's curl wrapper (proxy settings and the security helper apply).
 *
 * Redirects are followed manually, at most three, and every hop is checked by the guard again.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curl_fetcher implements http_fetcher {
    /** @var int Maximum number of redirects that are followed. */
    private const MAX_HOPS = 3;
    /** @var int Maximum body size in bytes. */
    private const MAX_BODY = 262144;

    /** @var dns_resolver Resolver used to validate and pin the target address. */
    private $dns;

    /**
     * Constructor.
     *
     * @param dns_resolver|null $dns Resolver; the system resolver by default.
     */
    public function __construct(?dns_resolver $dns = null) {
        $this->dns = $dns ?? new system_dns_resolver();
    }

    /**
     * Resolve the host once, refuse internal answers and pin the connection to the validated address.
     *
     * This closes the gap between checking a name and connecting to it (DNS rebinding).
     *
     * @param string $url URL that passed the guard.
     * @param dns_resolver $dns Resolver.
     * @return array|null Curl options (possibly empty) or null when any answer is internal or none was found.
     */
    public static function pin_options(string $url, dns_resolver $dns): ?array {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '') {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return url_guard::is_internal_address($host) ? null : [];
        }
        $addresses = $dns->resolve($host);
        if (!$addresses) {
            return null;
        }
        foreach ($addresses as $address) {
            if (url_guard::is_internal_address($address)) {
                return null;
            }
        }
        $port = (int) ($parts['port'] ?? 443);
        $address = $addresses[0];
        if (strpos($address, ':') !== false) {
            $address = '[' . $address . ']';
        }
        return ['CURLOPT_RESOLVE' => [$host . ':' . $port . ':' . $address]];
    }

    /**
     * Perform the request.
     *
     * @param string $url Absolute URL already approved by the guard.
     * @param bool $allowprivate True when private targets were explicitly allowed.
     * @return fetch_result
     */
    public function get(string $url, bool $allowprivate): fetch_result {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $result = new fetch_result();
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $reason = url_guard::check($current, $allowprivate);
            if ($reason !== url_guard::OK) {
                $result->blocked = $reason;
                return $result;
            }
            $pin = [];
            if (!$allowprivate) {
                $pin = self::pin_options($current, $this->dns);
                if ($pin === null) {
                    $result->blocked = 'resolved_private';
                    return $result;
                }
            }
            $curl = new \curl(['ignoresecurity' => $allowprivate, 'cache' => false]);
            $body = $curl->get($current, [], $pin + [
                'CURLOPT_FOLLOWLOCATION' => 0,
                'CURLOPT_MAXREDIRS' => 0,
                'CURLOPT_TIMEOUT' => 15,
                'CURLOPT_CONNECTTIMEOUT' => 8,
                'CURLOPT_CERTINFO' => 1,
                'CURLOPT_USERAGENT' => 'MoodleLtiDiagnostics/0.1',
                'CURLOPT_MAXFILESIZE' => self::MAX_BODY,
            ]);
            $info = $curl->get_info();
            $result->errno = (int) $curl->get_errno();
            $result->error = is_string($curl->error ?? null) ? (string) $curl->error : '';
            $result->httpstatus = (int) ($info['http_code'] ?? 0);
            $result->headers = [];
            foreach ((array) $curl->getResponse() as $name => $value) {
                $result->headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
            $result->body = is_string($body) ? substr($body, 0, self::MAX_BODY) : '';
            $result->info = [
                'connect_time' => (float) ($info['connect_time'] ?? 0),
                'namelookup_time' => (float) ($info['namelookup_time'] ?? 0),
                'ssl_verify_result' => (int) ($info['ssl_verify_result'] ?? 0),
                'certinfo' => $info['certinfo'] ?? [],
            ];
            $location = $result->headers['location'] ?? '';
            if ($result->httpstatus >= 300 && $result->httpstatus < 400 && $location !== '') {
                $next = self::absolute($current, $location);
                $result->redirects[] = $next;
                $current = $next;
                continue;
            }
            break;
        }
        return $result;
    }

    /**
     * Resolve a Location header against the current URL.
     *
     * @param string $base Current URL.
     * @param string $location Location header.
     * @return string
     */
    private static function absolute(string $base, string $location): string {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
        return $origin . $dir . $location;
    }
}
