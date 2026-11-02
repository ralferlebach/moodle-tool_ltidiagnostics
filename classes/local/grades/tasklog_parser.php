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
 * Parser for the output that core's enrol_lti sync_tool_grades task writes into the task log.
 *
 * Core has no event or hook for an outgoing AGS score. The only passive trace of a core send is the task output
 * (mtrace lines), and only while the task log keeps it. The parser turns the lines into per-user, per-resource-link
 * outcomes. It never keeps an exception message or an HTTP header beyond the status code, because those may echo
 * payloads; the classification is all that is stored.
 *
 * Recognised wording: Moodle 4.5 to 5.3 (enrol/lti/classes/local/ltiadvantage/task/sync_tool_grades.php). Unknown lines
 * are counted, never guessed.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tasklog_parser {
    /** @var string Class name of core's per-resource grade sync task. */
    public const TASKCLASS = 'enrol_lti\\local\\ltiadvantage\\task\\sync_tool_grades';

    /** @var string[] Outcome codes. */
    public const OUTCOMES = [
        'no_service', 'invalid_context', 'gated_course', 'gated_activity', 'invalid_grade', 'invalid_grademax',
        'skipped_unchanged', 'sent_ok', 'send_failed',
    ];

    /** @var string Subject of a user line: "for the user 'L', for the resource 'R' and the course 'C'". */
    private const SUBJECT = "for the user '(\\d+)', for the resource '(\\d+)' and the course '(\\d+)'";

    /**
     * Parse one task log output.
     *
     * @param string $output Output of one run.
     * @return array resource (int), course (int), events (array[] with user, resource, course, resourcelink (string or
     *     ''), outcome, grade (normalised 0..1 string or ''), httpstatus (int), errorclass (string), line (int)),
     *     unknown (int, lines that looked like grade sync lines but were not understood).
     */
    public static function parse(string $output): array {
        $result = ['resource' => 0, 'course' => 0, 'events' => [], 'unknown' => 0];
        $currentlink = '';
        $lines = preg_split('/\R/', $output);
        foreach ($lines as $i => $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match("/^Starting - LTI Advantage grade sync for shared resource '(\\d+)' in course '(\\d+)'/", $line, $m)) {
                $result['resource'] = (int) $m[1];
                $result['course'] = (int) $m[2];
                continue;
            }
            if (preg_match('/^Found \d+ resource link\(s\) ' . self::SUBJECT . '/', $line)) {
                $currentlink = '';
                continue;
            }
            if (preg_match("/^Processing resource link '(.{0,255})'\\.$/", $line, $m)) {
                $currentlink = $m[1];
                continue;
            }
            $event = self::outcome($line);
            if ($event === null) {
                if (preg_match('/^Header: HTTP\/[\d.]+ (\d{3})/', $line, $m) && $result['events']) {
                    // Status line of the failed send just before.
                    $last = count($result['events']) - 1;
                    if ($result['events'][$last]['outcome'] === 'send_failed') {
                        $result['events'][$last]['httpstatus'] = (int) $m[1];
                        $result['events'][$last]['errorclass'] = error_classes::from_http((int) $m[1]);
                    }
                } else if (preg_match('/^(Skipping|Not sent|Success|Failed) - /', $line)) {
                    $result['unknown']++;
                } else if (
                    $result['events'] && end($result['events'])['outcome'] === 'send_failed'
                        && end($result['events'])['errorclass'] === 'unclassified'
                ) {
                    // The exception message line after a failed send: classify, never store.
                    $last = count($result['events']) - 1;
                    $result['events'][$last]['errorclass'] = error_classes::from_message($line);
                }
                continue;
            }
            $event['resourcelink'] = $currentlink;
            $event['line'] = $i;
            $result['events'][] = $event;
        }
        return $result;
    }

    /**
     * Recognise an outcome line.
     *
     * @param string $line Trimmed line.
     * @return array|null
     */
    private static function outcome(string $line): ?array {
        $patterns = [
            'no_service' => '/^Skipping - No grade service found ' . self::SUBJECT . '/',
            'gated_course' => '/^Skipping - Course not completed ' . self::SUBJECT . '/',
            'gated_activity' => '/^Skipping - Activity not completed ' . self::SUBJECT . '/',
            'invalid_grade' => '/^Skipping - Invalid grade ' . self::SUBJECT . '/',
            'invalid_grademax' => '/^Skipping - Invalid grademax ' . self::SUBJECT . '/',
            'skipped_unchanged' => '/^Not sent - The grade ' . self::SUBJECT . ' was not sent as the grades are the same/',
            'sent_ok' => "/^Success - The grade '([0-9.Ee+-]{1,30})' " . self::SUBJECT . ' was sent/',
            'send_failed' => "/^Failed - The grade '([0-9.Ee+-]{1,30})' " . self::SUBJECT . ' failed to send/',
        ];
        foreach ($patterns as $outcome => $pattern) {
            if (preg_match($pattern, $line, $m)) {
                $withgrade = in_array($outcome, ['sent_ok', 'send_failed'], true);
                $offset = $withgrade ? 1 : 0;
                return [
                    'user' => (int) $m[1 + $offset],
                    'resource' => (int) $m[2 + $offset],
                    'course' => (int) $m[3 + $offset],
                    'outcome' => $outcome,
                    'grade' => $withgrade ? (string) $m[1] : '',
                    'httpstatus' => 0,
                    'errorclass' => $outcome === 'send_failed' ? 'unclassified' : '',
                ];
            }
        }
        if (preg_match("/^Failed - Invalid contextid '\\d+' for the resource '(\\d+)'/", $line, $m)) {
            return ['user' => 0, 'resource' => (int) $m[1], 'course' => 0, 'outcome' => 'invalid_context', 'grade' => '',
                'httpstatus' => 0, 'errorclass' => ''];
        }
        return null;
    }
}
