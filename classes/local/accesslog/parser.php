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

namespace tool_ltidiagnostics\local\accesslog;

use tool_ltidiagnostics\local\redactor;

/**
 * Parser for web server access and error log lines (nginx and Apache).
 *
 * Pure functions, no database access. Times are normalised to UTC unix time; the offset written
 * in the line wins over the configured time zone, which is only used for formats without one
 * (error logs). Client addresses are masked and query values are removed at parse time, so
 * nothing sensitive leaves the parser.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parser {
    /** @var string[][] Access formats and the extra fields they append after the combined format. */
    public const ACCESS_FORMATS = [
        'common' => [],
        'combined' => [],
        'combined_xff' => ['xff'],
        'combined_reqid' => ['reqid'],
        'combined_xff_reqid' => ['xff', 'reqid'],
    ];
    /** @var string[] Error log formats. */
    public const ERROR_FORMATS = ['nginx_error', 'apache_error'];

    /**
     * All format identifiers.
     *
     * @param string $kind "access" or "error".
     * @return string[]
     */
    public static function formats(string $kind): array {
        return $kind === 'error' ? self::ERROR_FORMATS : array_keys(self::ACCESS_FORMATS);
    }

    /**
     * Parse one access log line.
     *
     * @param string $line The raw line.
     * @param string $format One of the keys of ACCESS_FORMATS.
     * @param string $timezone IANA time zone used when the time carries no offset.
     * @return array|null Fields time, method, path, status, bytes, ip, viaproxy, requestid, or null if not parseable.
     */
    public static function parse_access_line(string $line, string $format, string $timezone = 'UTC'): ?array {
        if (!isset(self::ACCESS_FORMATS[$format])) {
            return null;
        }
        $line = rtrim($line, "\r\n");
        // Remote address, identd, user, [time], "request", status, bytes.
        $re = '/^(\S+) \S+ \S+ \[([^\]]+)\] "((?:[^"\\\\]|\\\\.)*)" (\d{3}) (\d+|-)(.*)$/';
        if (!preg_match($re, $line, $m)) {
            return null;
        }
        $time = self::parse_time($m[2], $timezone);
        if ($time === null) {
            return null;
        }
        $request = self::parse_request($m[3]);
        if ($request === null) {
            return null;
        }
        $rest = $m[6];
        $fields = self::tail_fields($rest);
        $names = self::ACCESS_FORMATS[$format];
        if ($format !== 'common') {
            // Combined: referer and user agent come first. They are dropped, never stored.
            if (count($fields) < 2 + count($names)) {
                return null;
            }
            $fields = array_slice($fields, 2);
        } else if ($fields) {
            return null;
        }
        $extra = [];
        foreach ($names as $i => $name) {
            $extra[$name] = $fields[$i] ?? '';
        }
        $ip = $m[1];
        $viaproxy = false;
        if (!empty($extra['xff']) && $extra['xff'] !== '-') {
            $first = trim(explode(',', $extra['xff'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
                $viaproxy = true;
            }
        }
        $reqid = isset($extra['reqid']) && $extra['reqid'] !== '-' ? substr(
            preg_replace('/[^A-Za-z0-9_-]/', '', $extra['reqid']),
            0,
            64
        ) : '';
        return [
            'time' => $time,
            'method' => $request['method'],
            'path' => $request['path'],
            'status' => (int) $m[4],
            'bytes' => $m[5] === '-' ? 0 : (int) $m[5],
            'ip' => redactor::mask_ip($ip),
            'viaproxy' => $viaproxy,
            'requestid' => $reqid,
        ];
    }

    /**
     * Parse one error log line.
     *
     * @param string $line The raw line.
     * @param string $format "nginx_error" or "apache_error".
     * @param string $timezone IANA time zone of the server (error logs carry no offset).
     * @return array|null Fields time, level, message (redacted), path (may be empty), ip, or null.
     */
    public static function parse_error_line(string $line, string $format, string $timezone = 'UTC'): ?array {
        $line = rtrim($line, "\r\n");
        if ($format === 'nginx_error') {
            if (!preg_match('#^(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] \d+\#\d+: (.*)$#', $line, $m)) {
                return null;
            }
            $time = self::naive_time($m[1], 'Y/m/d H:i:s', $timezone);
            $level = $m[2];
            $message = $m[3];
        } else if ($format === 'apache_error') {
            if (
                !preg_match(
                    '/^\[([A-Za-z]{3} [A-Za-z]{3} \d{1,2} \d{2}:\d{2}:\d{2}(?:\.\d+)? \d{4})\] \[([^\]]+)\] (.*)$/',
                    $line,
                    $m
                )
            ) {
                return null;
            }
            $stamp = preg_replace('/\.\d+/', '', $m[1]);
            $time = self::naive_time($stamp, 'D M j H:i:s Y', $timezone);
            $level = strtolower(substr(strrchr(':' . $m[2], ':'), 1));
            $message = $m[3];
        } else {
            return null;
        }
        if ($time === null) {
            return null;
        }
        $ip = '';
        if (preg_match('/client:? \[?([0-9a-fA-F:.]+?)(?:\]|:\d+\]|,|\s|$)/', $message, $c)) {
            $ip = redactor::mask_ip($c[1]);
        }
        $path = '';
        if (preg_match('/request: "(?:[A-Z]+) (\S+) HTTP/', $message, $r)) {
            $path = self::clean_path($r[1]);
        }
        return [
            'time' => $time,
            'level' => $level,
            'message' => \core_text::substr(redactor::text($message), 0, 255),
            'path' => $path,
            'ip' => $ip,
        ];
    }

    /**
     * Guess the access format that parses most of the sample lines.
     *
     * @param string[] $lines Sample lines.
     * @return string Format key, or an empty string if none parses at least 80 percent.
     */
    public static function detect_access_format(array $lines): string {
        $lines = array_values(array_filter($lines, function ($l) {
            return trim($l) !== '';
        }));
        if (!$lines) {
            return '';
        }
        $best = '';
        $bestscore = 0;
        // More specific formats first: a longer format parses every line a shorter one does.
        foreach (['combined_xff_reqid', 'combined_xff', 'combined_reqid', 'combined', 'common'] as $format) {
            $ok = 0;
            foreach ($lines as $l) {
                if (self::parse_access_line($l, $format) !== null) {
                    $ok++;
                }
            }
            $score = $ok / count($lines);
            if ($score >= 0.8 && $score > $bestscore) {
                $best = $format;
                $bestscore = $score;
            }
        }
        return $best;
    }

    /**
     * Classify a path into an LTI flow category.
     *
     * @param string $path Request path (query already removed or redacted).
     * @return string Category or an empty string for paths that are not LTI related.
     */
    public static function categorise(string $path): string {
        $p = strtolower(strtok($path, '?'));
        if (strpos($p, '/mod/lti/services.php') !== false || strpos($p, '/mod/lti/service.php') !== false) {
            if (strpos($p, 'membership') !== false) {
                return 'service_nrps';
            }
            if (strpos($p, '/scores') !== false) {
                return 'service_ags_scores';
            }
            if (strpos($p, '/results') !== false) {
                return 'service_ags_results';
            }
            if (strpos($p, 'lineitem') !== false) {
                return 'service_ags_lineitems';
            }
            return 'service';
        }
        $map = [
            '/mod/lti/token.php' => 'oauth',
            '/mod/lti/auth.php' => 'oidc',
            '/auth/lti/login.php' => 'oidc',
            '/enrol/lti/login.php' => 'oidc',
            '/mod/lti/certs.php' => 'jwks',
            '/enrol/lti/jwks.php' => 'jwks',
            '/mod/lti/openid-configuration.php' => 'dynreg',
            '/mod/lti/openid-registration.php' => 'dynreg',
            '/enrol/lti/register.php' => 'dynreg',
            '/mod/lti/contentitem' => 'deeplinking',
            '/enrol/lti/launch_deeplink.php' => 'deeplinking',
            '/enrol/lti/launch.php' => 'launch',
            '/mod/lti/launch.php' => 'launch',
            '/mod/lti/return.php' => 'launch',
        ];
        foreach ($map as $fragment => $category) {
            if (strpos($p, $fragment) !== false) {
                return $category;
            }
        }
        return '';
    }

    /**
     * Parse a CLF or ISO 8601 time.
     *
     * @param string $value The text between the brackets.
     * @param string $timezone Time zone for values without an offset.
     * @return int|null UTC unix time.
     */
    public static function parse_time(string $value, string $timezone = 'UTC'): ?int {
        $value = trim($value);
        if (preg_match('#^\d{1,2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4}$#', $value)) {
            $dt = \DateTime::createFromFormat('d/M/Y:H:i:s O', $value);
            return $dt ? $dt->getTimestamp() : null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            try {
                return (new \DateTime($value, self::zone($timezone)))->getTimestamp();
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Parse a time without offset in a given zone.
     *
     * @param string $value The time text.
     * @param string $format DateTime format.
     * @param string $timezone IANA zone.
     * @return int|null
     */
    private static function naive_time(string $value, string $format, string $timezone): ?int {
        $dt = \DateTime::createFromFormat($format, $value, self::zone($timezone));
        return $dt ? $dt->getTimestamp() : null;
    }

    /**
     * A valid time zone object, UTC for invalid input.
     *
     * @param string $timezone IANA identifier.
     * @return \DateTimeZone
     */
    private static function zone(string $timezone): \DateTimeZone {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Split the remainder of a line into fields. Quoted fields are returned without quotes.
     *
     * @param string $rest Remainder after the byte count.
     * @return string[]
     */
    private static function tail_fields(string $rest): array {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\S+)/', $rest, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $m) {
            $out[] = isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1];
        }
        return $out;
    }

    /**
     * Split a request line into method and a cleaned path.
     *
     * @param string $request For example "GET /a?b=c HTTP/1.1".
     * @return array|null Fields method and path, or null for garbage such as TLS handshakes.
     */
    private static function parse_request(string $request): ?array {
        if (!preg_match('#^([A-Z]{3,10}) (\S+)(?: HTTP/\d(?:\.\d)?)?$#', $request, $m)) {
            return null;
        }
        return ['method' => $m[1], 'path' => self::clean_path($m[2])];
    }

    /**
     * Remove query values and cut the path to the storage length.
     *
     * @param string $target Request target.
     * @return string
     */
    private static function clean_path(string $target): string {
        $parts = explode('?', $target, 2);
        $path = $parts[0];
        if (isset($parts[1])) {
            $path .= '?' . redactor::query($parts[1]);
        }
        return \core_text::substr($path, 0, 255);
    }
}
