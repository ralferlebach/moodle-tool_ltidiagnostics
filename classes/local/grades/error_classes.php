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

namespace tool_ltidiagnostics\local\grades;

/**
 * Error classes of a grade transfer attempt and the retry policy that belongs to each.
 *
 * Only the class is stored, never a response body, a header other than the status or an exception text.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class error_classes {
    /** @var string[] Every class. */
    public const ALL = [
        'http_400', 'http_401', 'http_403', 'http_404', 'http_409', 'http_429', 'http_5xx', 'http_other',
        'tls', 'dns', 'timeout', 'connect', 'redirect_refused', 'blocked_by_policy', 'token', 'scope_missing',
        'lineitem_missing', 'lineitem_ambiguous', 'mapping_missing', 'source_changed', 'source_invalid', 'task_failure',
        'unclassified',
    ];

    /** @var string[] Classes that are retried with backoff (transient by nature). */
    public const RETRYABLE = ['http_429', 'http_5xx', 'timeout', 'connect', 'dns'];

    /**
     * Class of an HTTP status that is not a success.
     *
     * @param int $status HTTP status.
     * @return string
     */
    public static function from_http(int $status): string {
        if (in_array($status, [400, 401, 403, 404, 409, 429], true)) {
            return 'http_' . $status;
        }
        if ($status >= 500 && $status <= 599) {
            return 'http_5xx';
        }
        if ($status >= 300 && $status < 400) {
            return 'redirect_refused';
        }
        return 'http_other';
    }

    /**
     * Class of a transport level failure from its curl error number.
     *
     * @param int $errno Curl error number.
     * @return string
     */
    public static function from_curl(int $errno): string {
        switch ($errno) {
            case 6:
                return 'dns';
            case 7:
                return 'connect';
            case 28:
                return 'timeout';
            case 35:
            case 51:
            case 53:
            case 54:
            case 58:
            case 59:
            case 60:
            case 64:
            case 66:
            case 77:
            case 80:
            case 82:
            case 83:
            case 90:
                return 'tls';
            default:
                return 'unclassified';
        }
    }

    /**
     * Class of a free text failure (core's exception line in the task log). The text is classified, never kept.
     *
     * @param string $text Text.
     * @return string
     */
    public static function from_message(string $text): string {
        if (preg_match('/\b(4\d\d|5\d\d)\b/', $text, $m) && preg_match('/(status|code|HTTP|response)/i', $text)) {
            return self::from_http((int) $m[1]);
        }
        $checks = [
            'tls' => '/(SSL|TLS|certificate)/i',
            'dns' => '/(resolve host|Could not resolve|getaddrinfo)/i',
            'timeout' => '/(timed out|timeout)/i',
            'connect' => '/(Failed to connect|Connection refused)/i',
            'token' => '/(access token|invalid_client|invalid_grant|client_assertion)/i',
            'scope_missing' => '/(scope)/i',
        ];
        foreach ($checks as $class => $pattern) {
            if (preg_match($pattern, $text)) {
                return $class;
            }
        }
        return 'unclassified';
    }

    /**
     * Whether an attempt with this class may be retried automatically.
     *
     * 409 is never retried: the partner holds a score with the same or a newer timestamp, and only a comparison with
     * the partner can tell which one is right (no escalation loop with ever newer timestamps).
     *
     * @param string $class Error class.
     * @return bool
     */
    public static function retryable(string $class): bool {
        return in_array($class, self::RETRYABLE, true);
    }
}
