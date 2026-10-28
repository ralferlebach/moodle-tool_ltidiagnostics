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
 * Pure classification of transfer outcomes into distinct, actionable codes.
 *
 * The point is separation: a DNS failure, a refused connection, a timeout that looks like a
 * dropped packet, an expired certificate and a denied scope must never collapse into one
 * generic "connection error".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class error_classifier {
    /**
     * Classify a fetch result.
     *
     * @param fetch_result $r The result.
     * @return string Code: ok, blocked_by_policy, dns_failure, connection_refused, firewall_drop_suspected, timeout,
     *         cert_expired, cert_hostname, cert_chain_incomplete, cert_untrusted, cert_invalid, tls_handshake,
     *         proxy_407, proxy_error, redirect, http_denied, http_notfound, http_client_error, http_server_error,
     *         transfer_error
     */
    public static function classify(fetch_result $r): string {
        if ($r->blocked !== '') {
            return 'blocked_by_policy';
        }
        if ($r->httpstatus === 407 || self::is_proxy_407_message($r->error)) {
            return 'proxy_407';
        }
        switch ($r->errno) {
            case 0:
                break;
            case 5:
                return 'proxy_error';
            case 6:
                return 'dns_failure';
            case 7:
                return 'connection_refused';
            case 28:
                // No TCP connection within the connect timeout although the name was resolved (or pinned: then curl
                // reports a lookup time of about zero, but says that it failed to connect): packets are dropped.
                $connect = (float) ($r->info['connect_time'] ?? 0);
                $resolved = (float) ($r->info['namelookup_time'] ?? 0) > 0.0
                    || stripos($r->error, 'Failed to connect') !== false || stripos($r->error, 'Connection timed out') !== false;
                return $connect <= 0.0 && $resolved ? 'firewall_drop_suspected' : 'timeout';
            case 51:
            case 60:
            case 83:
                return self::certificate((int) ($r->info['ssl_verify_result'] ?? 0), $r->errno, $r->error);
            case 35:
            case 53:
            case 54:
            case 58:
            case 59:
            case 64:
            case 66:
            case 77:
            case 80:
            case 82:
            case 90:
                return 'tls_handshake';
            default:
                return 'transfer_error';
        }
        $s = $r->httpstatus;
        if ($s >= 200 && $s < 300) {
            return 'ok';
        }
        if ($s >= 300 && $s < 400) {
            return 'redirect';
        }
        if ($s === 401 || $s === 403) {
            return 'http_denied';
        }
        if ($s === 404) {
            return 'http_notfound';
        }
        if ($s >= 400 && $s < 500) {
            return 'http_client_error';
        }
        if ($s >= 500) {
            return 'http_server_error';
        }
        return 'transfer_error';
    }

    /**
     * Whether a curl error message reports a 407 answer of a proxy to CONNECT.
     *
     * Only curl's own wordings count: "CONNECT tunnel failed, response 407" (curl 7.71+) and "Received HTTP code 407 from
     * proxy after CONNECT" (older). A loose "contains CONNECT and 407" test matched a plain connect timeout to a port such
     * as 45407 ("Failed to connect to host port 45407 after 2001 ms"), which turned a dropped connection into proxy_407.
     *
     * @param string $error curl error message.
     * @return bool
     */
    public static function is_proxy_407_message(string $error): bool {
        $pattern = '/CONNECT tunnel failed, response 407\b|Received HTTP code 407 from proxy after CONNECT/i';
        return (bool) preg_match($pattern, $error);
    }

    /**
     * Map the OpenSSL verification result to a certificate code.
     *
     * Since curl 7.62 a host name mismatch is reported as error 60 (no longer 51), and the verification result is not
     * the OpenSSL code 62 (curl compares the names itself and reports 0 or 1); the message names the subject name.
     *
     * @param int $verify X509 verify result (curl ssl_verify_result).
     * @param int $errno curl error number.
     * @param string $error curl error text.
     * @return string
     */
    public static function certificate(int $verify, int $errno = 60, string $error = ''): string {
        if (preg_match('/subject name|host ?name|does not match target/i', $error)) {
            return 'cert_hostname';
        }
        switch ($verify) {
            case 10:
                return 'cert_expired';
            case 62:
                return 'cert_hostname';
            case 20:
            case 21:
                return 'cert_chain_incomplete';
            case 18:
            case 19:
                return 'cert_untrusted';
        }
        return $errno === 51 ? 'cert_hostname' : 'cert_invalid';
    }

    /**
     * Expiry time of the leaf certificate from curl's certinfo, if present.
     *
     * @param array $certinfo Value of curl's certinfo.
     * @return int|null Unix time.
     */
    public static function cert_expiry(array $certinfo): ?int {
        foreach ($certinfo as $cert) {
            foreach ((array) $cert as $key => $value) {
                $text = is_string($key) && is_string($value) ? $key . ':' . $value : '';
                if (stripos($text, 'Expire date') === 0 || stripos($text, 'expire') === 0) {
                    $ts = strtotime(trim(substr($text, strpos($text, ':') + 1)));
                    return $ts === false ? null : $ts;
                }
            }
            break;
        }
        return null;
    }

    /**
     * Estimate the clock difference to a remote server from its HTTP Date header.
     *
     * @param array $headers Response headers (lower case names).
     * @param int $now Local time.
     * @return int|null Remote minus local, in seconds. Accuracy is about one second plus latency.
     */
    public static function clock_skew(array $headers, int $now): ?int {
        if (empty($headers['date'])) {
            return null;
        }
        $ts = strtotime($headers['date']);
        return $ts === false ? null : $ts - $now;
    }

    /**
     * Inspect framing protection of a tool page (browser session layer).
     *
     * @param array $headers Response headers (lower case names).
     * @return array [xfo => string, frameancestors => string, blocksembedding => bool|null]. null: cannot tell.
     */
    public static function framing(array $headers): array {
        $xfo = strtolower(trim($headers['x-frame-options'] ?? ''));
        $csp = $headers['content-security-policy'] ?? '';
        $ancestors = '';
        if (preg_match('/frame-ancestors\s+([^;]+)/i', $csp, $m)) {
            $ancestors = trim($m[1]);
        }
        $blocks = null;
        if ($xfo === 'deny' || $xfo === 'sameorigin' || $ancestors === "'none'" || $ancestors === "'self'") {
            $blocks = true;
        } else if ($xfo === '' && $ancestors === '') {
            $blocks = false;
        }
        return ['xfo' => $xfo, 'frameancestors' => $ancestors, 'blocksembedding' => $blocks];
    }
}
