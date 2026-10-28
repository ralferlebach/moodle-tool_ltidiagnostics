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
 * Redirects are followed manually, at most three, and every hop is checked by the guard again and resolved
 * again. Per hop the host name is resolved exactly once; the connection is pinned to those validated addresses
 * (CURLOPT_RESOLVE), so a changed DNS answer between check and connect cannot redirect the request. After the
 * transfer the address curl really connected to is compared with the validated set.
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
    /** @var string[] Exact allow-list of private targets ("host:port"). */
    private $exact;
    /** @var array Transfer limits and the trust store: connecttimeout, timeout (seconds), cainfo (file or ''). */
    private $limits;

    /**
     * Constructor.
     *
     * @param dns_resolver|null $dns Resolver; the system resolver by default.
     * @param string[]|null $exact Exact allow-list of private targets (null: the plugin setting).
     * @param array $limits connecttimeout, timeout (seconds, bounded to 1..15 and 1..30) and cainfo (a CA bundle file;
     *        set by code only, never from a form; empty: the trust store of the site).
     */
    public function __construct(?dns_resolver $dns = null, ?array $exact = null, array $limits = []) {
        $this->dns = $dns ?? new system_dns_resolver();
        $this->exact = $exact ?? url_guard::exact_list();
        $this->limits = [
            'connecttimeout' => max(1, min(15, (int) ($limits['connecttimeout'] ?? 8))),
            'timeout' => max(1, min(30, (int) ($limits['timeout'] ?? 15))),
            'cainfo' => (string) ($limits['cainfo'] ?? ''),
        ];
    }

    /**
     * Resolve the host once, refuse internal answers and pin the connection to the validated addresses.
     *
     * This closes the gap between checking a name and connecting to it (DNS rebinding, CNAME to a private
     * address, a second answer that differs from the first). One CURLOPT_RESOLVE entry is emitted per validated
     * address so that curl can still fall back between them.
     *
     * @param string $url URL that passed the guard.
     * @param dns_resolver $dns Resolver.
     * @return array|null Curl options (possibly empty) or null when any answer is internal or none was found.
     */
    public static function pin_options(string $url, dns_resolver $dns): ?array {
        $pinned = self::pin($url, $dns);
        return $pinned === null ? null : $pinned['options'];
    }

    /**
     * Pin options for a request that goes through the configured web proxy: the proxy is told to open its tunnel to
     * the validated address (CURLOPT_CONNECT_TO), so it cannot resolve the name again (no DNS rebinding through the
     * proxy). TLS still verifies the certificate against the host name of the URL.
     *
     * @param string $url URL.
     * @param string[] $addresses Validated addresses (the first one is used).
     * @return array Curl options.
     */
    public static function proxy_pin_options(string $url, array $addresses): array {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '' || !$addresses || filter_var($host, FILTER_VALIDATE_IP)) {
            return [];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $address = (string) reset($addresses);
        $target = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        return ['CURLOPT_CONNECT_TO' => [$host . ':' . $port . ':' . $target . ':' . $port]];
    }

    /**
     * Resolve and validate the target of one request.
     *
     * @param string $url URL that passed the guard.
     * @param dns_resolver $dns Resolver.
     * @param bool $exactallowed True when host and port are on the exact allow-list: private answers are accepted (and
     *        pinned) for this one target.
     * @return array|null [options (curl options), addresses (validated list, empty for a literal)] or null to refuse.
     */
    private static function pin(string $url, dns_resolver $dns, bool $exactallowed = false): ?array {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '') {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return url_guard::is_internal_address($host) && !$exactallowed ? null : ['options' => [], 'addresses' => [$host]];
        }
        $addresses = $dns->resolve($host);
        if (!$addresses) {
            return null;
        }
        $addresses = array_values(array_unique($addresses));
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            if (url_guard::is_internal_address($address) && !$exactallowed) {
                return null;
            }
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $entries = [];
        foreach ($addresses as $address) {
            $entries[] = $host . ':' . $port . ':' . (strpos($address, ':') !== false ? '[' . $address . ']' : $address);
        }
        return ['options' => ['CURLOPT_RESOLVE' => $entries], 'addresses' => $addresses];
    }

    /**
     * Whether a request to the URL would go through the Moodle proxy (which then does the name resolution).
     *
     * @param string $url URL.
     * @return bool
     */
    public static function uses_proxy(string $url): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        return !empty($CFG->proxyhost) && !is_proxybypass($url);
    }

    /**
     * The curl options of every request: strict TLS verification, protocol restriction, no redirects, no
     * credentials, bounded time and size.
     *
     * TLS verification is never switched off, also not for private targets: a certificate error is a finding.
     *
     * @param array $pin Pin options.
     * @param bool $allowprivate True when plain http to private targets was explicitly allowed.
     * @param array $limits connecttimeout, timeout, cainfo (see the constructor).
     * @return array
     */
    public static function build_options(array $pin, bool $allowprivate, array $limits = []): array {
        $protocols = $allowprivate ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS;
        $extra = [];
        if (!empty($limits['cainfo'])) {
            $extra['CURLOPT_CAINFO'] = (string) $limits['cainfo'];
        }
        return $pin + $extra + [
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_TIMEOUT' => (int) ($limits['timeout'] ?? 15),
            'CURLOPT_CONNECTTIMEOUT' => (int) ($limits['connecttimeout'] ?? 8),
            'CURLOPT_CERTINFO' => 1,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_PROTOCOLS' => $protocols,
            'CURLOPT_REDIR_PROTOCOLS' => $protocols,
            'CURLOPT_USERAGENT' => 'MoodleLtiDiagnostics/0.3',
            'CURLOPT_MAXFILESIZE' => self::MAX_BODY,
        ];
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
            $reason = url_guard::check($current, $allowprivate, $this->exact);
            if ($reason !== url_guard::OK) {
                $result->blocked = $reason;
                return $result;
            }
            $exactallowed = !$allowprivate && url_guard::exact_allowed($current, $this->exact);
            $pin = ['options' => [], 'addresses' => []];
            $viaproxy = self::uses_proxy($current);
            if (!$allowprivate) {
                $pin = self::pin($current, $this->dns, $exactallowed);
                if ($pin === null) {
                    $result->blocked = 'resolved_private';
                    return $result;
                }
                if ($viaproxy) {
                    // Through a proxy CURLOPT_RESOLVE has no effect (the proxy resolves); pin the tunnel target instead.
                    $pin['options'] = self::proxy_pin_options($current, $pin['addresses']);
                }
            }
            // Moodle's own host and port block list is applied by the guard; a target the administrator named exactly
            // passed the guard on that basis and must not be refused by the same list again.
            $options = self::build_options($pin['options'], $allowprivate, $this->limits);
            if (!$viaproxy) {
                // No Moodle proxy: switch off proxies from the process environment (http_proxy, https_proxy). libcurl
                // would otherwise send the request to such a proxy, which resolves the name itself and bypasses both
                // the pin and the peer address check. With a Moodle proxy, Moodle's curl class sets its own proxy.
                $options['CURLOPT_PROXY'] = '';
            }
            $transfer = $this->transfer($current, $options, $allowprivate || $exactallowed);
            $info = $transfer['info'];
            $result->errno = (int) $transfer['errno'];
            $result->error = (string) $transfer['error'];
            $result->httpstatus = (int) ($info['http_code'] ?? 0);
            $result->headers = [];
            foreach ($transfer['headers'] as $name => $value) {
                $result->headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
            $result->body = is_string($transfer['body']) ? substr($transfer['body'], 0, self::MAX_BODY) : '';
            $primary = (string) ($info['primary_ip'] ?? '');
            $result->info = [
                'connect_time' => (float) ($info['connect_time'] ?? 0),
                'namelookup_time' => (float) ($info['namelookup_time'] ?? 0),
                'ssl_verify_result' => (int) ($info['ssl_verify_result'] ?? 0),
                'certinfo' => $info['certinfo'] ?? [],
                'viaproxy' => $viaproxy,
                // Without a proxy the name is pinned with CURLOPT_RESOLVE; through a proxy the tunnel target is pinned with
                // CURLOPT_CONNECT_TO. A literal address needs no pin.
                'pinned' => !$allowprivate && !empty($pin['options']),
                'pinmode' => $allowprivate || empty($pin['options']) ? '' : ($viaproxy ? 'connect_to' : 'resolve'),
                'exactallowed' => $exactallowed,
                'primary_ip' => $viaproxy ? '' : $primary,
            ];
            if (!self::connected_to_validated($pin['addresses'], $primary, $viaproxy, $allowprivate)) {
                // The transfer reached an address that was never validated: discard everything it returned.
                $result->blocked = 'pin_mismatch';
                $result->body = '';
                $result->headers = [];
                $result->httpstatus = 0;
                return $result;
            }
            $location = $result->headers['location'] ?? '';
            if ($result->httpstatus >= 300 && $result->httpstatus < 400 && $location !== '') {
                if ($hop === self::MAX_HOPS) {
                    $result->blocked = 'redirect_limit';
                    return $result;
                }
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
     * Perform exactly one request without following redirects. Tests replace this method to simulate the network.
     *
     * @param string $url URL of this hop, already approved by the guard.
     * @param array $options Curl options (pin, TLS verification, protocol restriction).
     * @param bool $allowprivate True when the Moodle security helper is to be bypassed (explicit private mode only).
     * @return array body (string|false), info (curl info), errno (int), error (string), headers (array)
     */
    protected function transfer(string $url, array $options, bool $allowprivate): array {
        $curl = new \curl(['ignoresecurity' => $allowprivate, 'cache' => false]);
        $body = $curl->get($url, [], $options);
        return [
            'body' => $body,
            'info' => (array) $curl->get_info(),
            'errno' => (int) $curl->get_errno(),
            'error' => is_string($curl->error ?? null) ? (string) $curl->error : '',
            'headers' => (array) $curl->getResponse(),
        ];
    }

    /**
     * Whether the peer address curl reports belongs to the validated set.
     *
     * @param string[] $validated Validated addresses of this hop (empty when nothing was pinned).
     * @param string $primary Peer address reported by curl (may be empty when the transfer never connected).
     * @param bool $viaproxy True when a proxy was in the path.
     * @param bool $allowprivate True when private targets were allowed.
     * @return bool
     */
    public static function connected_to_validated(array $validated, string $primary, bool $viaproxy, bool $allowprivate): bool {
        if ($allowprivate || $viaproxy || $primary === '' || !$validated) {
            return true;
        }
        $norm = function (string $ip): string {
            $packed = @inet_pton(trim($ip, '[]'));
            return $packed === false ? strtolower($ip) : bin2hex($packed);
        };
        return in_array($norm($primary), array_map($norm, $validated), true);
    }

    /**
     * Resolve a Location header against the current URL (RFC 3986, simplified).
     *
     * An absolute location with any scheme is returned unchanged so that the guard can refuse it.
     *
     * @param string $base Current URL.
     * @param string $location Location header.
     * @return string
     */
    public static function absolute(string $base, string $location): string {
        $location = trim($location);
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $scheme = $p['scheme'] ?? 'https';
        if (strpos($location, '//') === 0) {
            return $scheme . ':' . $location;
        }
        $origin = $scheme . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        if (strpos($location, '?') === 0) {
            return $origin . ($p['path'] ?? '/') . $location;
        }
        $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
        return $origin . $dir . $location;
    }
}
