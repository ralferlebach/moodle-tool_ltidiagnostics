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
 * Redaction and masking of diagnostic data before it is stored, shown or exported.
 *
 * Secrets (JWTs, bearer tokens, cookies, keys, passwords) are always removed, in every mode.
 * Identifiers (client id, deployment id, issuer) and addresses are masked by default and are only
 * shown in the time-limited diagnostic mode.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class redactor {
    /** @var string Replacement for secrets. */
    public const SECRET = '[redacted]';
    /** @var string Pattern of array keys whose value is never shown. */
    private const SECRET_KEY = '/(secret|token|password|passwd|authorization|cookie|private|assertion|jwt|nonce|state|'
        . 'login_hint|lti_message_hint|signature|credential|apikey|api_key|sesskey)/i';

    /**
     * Remove secrets and personal strings from free text such as log lines and URLs.
     *
     * @param string $text Any text.
     * @return string
     */
    public static function text(string $text): string {
        // Private key blocks.
        $text = preg_replace(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s',
            self::SECRET,
            $text
        );
        // JSON web tokens (three base64url parts, the first one starting with a JSON header).
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', self::SECRET, $text);
        // Authorization and cookie headers.
        $text = preg_replace('/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie)\s*[:=]\s*[^\r\n]*/i', '$1: '
            . self::SECRET, $text);
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 ' . self::SECRET, $text);
        // Query strings: keep the keys, drop the values.
        $text = preg_replace_callback('/\?([^\s"\'#]*)/', function ($m) {
            return '?' . self::query($m[1]);
        }, $text);
        // E-mail addresses.
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
        // Long opaque strings that look like keys or hashes.
        $text = preg_replace('/\b[A-Fa-f0-9]{40,}\b/', self::SECRET, $text);
        // IP addresses.
        $text = preg_replace_callback('/\b(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}\b/', function ($m) {
            return $m[1] . '.0';
        }, $text);
        return $text;
    }

    /**
     * Reduce a query string to its keys.
     *
     * @param string $query The part after the question mark.
     * @return string
     */
    public static function query(string $query): string {
        if ($query === '') {
            return '';
        }
        $parts = [];
        foreach (explode('&', $query) as $pair) {
            $key = explode('=', $pair, 2)[0];
            if ($key !== '') {
                $parts[] = $key . '=' . self::SECRET;
            }
        }
        return implode('&', $parts);
    }

    /**
     * Truncate a client address (IPv4 to /24, IPv6 to /48). Used for storage and display alike.
     *
     * @param string $ip Address or empty string.
     * @return string
     */
    public static function mask_ip(string $ip): string {
        $ip = trim($ip);
        if ($ip === '' || $ip === '-') {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
        }
        return '';
    }

    /**
     * Mask an identifier such as a client id, unless the diagnostic mode allows to show it.
     *
     * @param string $value The identifier.
     * @param bool $reveal True when identifiers may be shown.
     * @return string
     */
    public static function identifier(string $value, bool $reveal = false): string {
        if ($reveal || $value === '') {
            return $value;
        }
        $len = \core_text::strlen($value);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        return \core_text::substr($value, 0, 3) . str_repeat('*', min(8, $len - 5)) . \core_text::substr($value, -2);
    }

    /**
     * Mask the host and path of a URL, keep scheme and registrable-looking host when revealing.
     *
     * @param string $url A URL.
     * @param bool $reveal True when identifiers may be shown.
     * @return string URL without credentials and with the query values removed.
     */
    public static function url(string $url, bool $reveal = false): string {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return self::text($url);
        }
        $host = $reveal ? $parts['host'] : self::identifier($parts['host'], false);
        $out = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '') . $host;
        if (!empty($parts['port'])) {
            $out .= ':' . (int) $parts['port'];
        }
        $out .= $parts['path'] ?? '';
        if (!empty($parts['query'])) {
            $out .= '?' . self::query($parts['query']);
        }
        return $out;
    }

    /**
     * Redact a nested array by key names and by content. Keys are kept, values of secret keys are removed.
     *
     * @param array $data Data.
     * @return array
     */
    public static function data(array $data): array {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key)) {
                $out[$key] = self::SECRET;
            } else if (is_array($value)) {
                $out[$key] = self::data($value);
            } else if (is_string($value)) {
                $out[$key] = self::text($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
