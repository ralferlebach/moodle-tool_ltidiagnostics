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

namespace tool_ltidiagnostics\tests;

/**
 * Makes every PHP deprecation that a test of this plugin triggers visible, with its source and a stack trace.
 *
 * Why (audit item DIA-01): Moodle 4.5 runs PHPUnit 9, which does not convert deprecations into failures
 * (convertDeprecationsToExceptions defaults to false; with display_errors off the message only reaches stderr), so a
 * deprecation there passes silently. Moodle 5.x runs PHPUnit 11 with failOnDeprecation, which fails the run but names
 * only file and line. The guard closes both gaps the same way on every tree:
 *
 *  - it sits on top of the error handler of the test runner for the duration of one test;
 *  - every E_DEPRECATED / E_USER_DEPRECATED is recorded with message, file, line, the classified source
 *    (plugin code, plugin tests, Moodle core, vendor library, other) and the call stack;
 *  - the message is handed on to the runner's own handler, so PHPUnit 11 still counts it (nothing is swallowed);
 *  - at the end of the test a recorded deprecation fails the test with the full report.
 *
 * A guard frame can also be started without handing messages on ("capture" mode); the self test uses that to prove
 * the guard without making the run red.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deprecation_guard {
    /** @var string Source class: production code of this plugin. */
    public const SRC_PLUGIN = 'plugin_code';
    /** @var string Source class: tests of this plugin. */
    public const SRC_TESTS = 'plugin_tests';
    /** @var string Source class: Moodle core (or another plugin of the tree). */
    public const SRC_MOODLE = 'moodle_core';
    /** @var string Source class: a library below vendor/. */
    public const SRC_VENDOR = 'vendor';
    /** @var string Source class: anything else (eval, internal). */
    public const SRC_OTHER = 'other';

    /** @var array[] Open frames: each records, chain flag. */
    private static $frames = [];

    /**
     * Open a guard frame.
     *
     * @param bool $chain True: hand every message on to the previous handler (normal test mode).
     */
    public static function start(bool $chain = true): void {
        $index = count(self::$frames);
        self::$frames[$index] = ['records' => [], 'chain' => $chain, 'previous' => null];
        $previous = set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use ($index) {
            return self::handle($index, $errno, $errstr, $errfile, $errline);
        });
        self::$frames[$index]['previous'] = $previous;
    }

    /**
     * Close the innermost frame and return what it recorded.
     *
     * @return array[] Records: type, message, file, line, source, trace (list of "file:line function")
     */
    public static function stop(): array {
        if (!self::$frames) {
            return [];
        }
        $frame = array_pop(self::$frames);
        restore_error_handler();
        return $frame['records'];
    }

    /**
     * Number of open frames (the self test checks that the stack is balanced).
     *
     * @return int
     */
    public static function depth(): int {
        return count(self::$frames);
    }

    /**
     * The handler of one frame.
     *
     * @param int $index Frame index.
     * @param int $errno Error level.
     * @param string $errstr Message.
     * @param string $errfile File.
     * @param int $errline Line.
     * @return bool
     */
    private static function handle(int $index, int $errno, string $errstr, string $errfile, int $errline): bool {
        $frame = self::$frames[$index] ?? null;
        $previous = $frame['previous'] ?? null;
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            self::$frames[$index]['records'][] = [
                'type' => $errno === E_DEPRECATED ? 'E_DEPRECATED' : 'E_USER_DEPRECATED',
                'message' => $errstr,
                'file' => $errfile,
                'line' => $errline,
                'source' => self::classify($errfile, self::trace()),
                'trace' => self::trace(),
            ];
            if (!($frame['chain'] ?? true)) {
                return true;
            }
        }
        if (is_callable($previous)) {
            return (bool) $previous($errno, $errstr, $errfile, $errline);
        }
        // No handler below: let PHP's standard handler run.
        return false;
    }

    /**
     * The call stack of the current deprecation, without the guard's own frames.
     *
     * @return string[] "file:line class::function" per frame, innermost first
     */
    private static function trace(): array {
        $out = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
            if (($f['class'] ?? '') === self::class || ($f['file'] ?? '') === __FILE__) {
                continue;
            }
            $out[] = self::relative($f['file'] ?? '[internal]') . ':' . ($f['line'] ?? 0) . ' '
                . (isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') : '') . ($f['function'] ?? '');
            if (count($out) >= 25) {
                break;
            }
        }
        return $out;
    }

    /**
     * Classify the source of a deprecation.
     *
     * The file that triggered the message decides; a message raised inside a vendor library on behalf of this
     * plugin's code (the first non-vendor frame belongs to the plugin) is attributed to the plugin.
     *
     * @param string $file File that triggered the message.
     * @param string[] $trace Formatted stack (see {@see self::trace()}).
     * @return string One of the SRC_* constants.
     */
    public static function classify(string $file, array $trace = []): string {
        $own = self::own_class($file);
        if ($own !== null) {
            return $own;
        }
        if (strpos($file, '/vendor/') !== false) {
            foreach ($trace as $line) {
                $tracefile = (string) strtok($line, ':');
                if (strpos($tracefile, '/vendor/') !== false || $tracefile === '' || $tracefile === '[internal]') {
                    continue;
                }
                return self::own_class($tracefile) ?? self::SRC_MOODLE;
            }
            return self::SRC_VENDOR;
        }
        if ($file === '' || strpos($file, '(') !== false) {
            return self::SRC_OTHER;
        }
        return self::SRC_MOODLE;
    }

    /**
     * Source class of a file of this plugin, null for a foreign file.
     *
     * @param string $file Absolute or tree relative path.
     * @return string|null
     */
    private static function own_class(string $file): ?string {
        $file = str_replace('\\', '/', $file);
        if (!preg_match('#(^|/)admin/tool/ltidiagnostics/(.*)$#', $file, $m)) {
            return null;
        }
        return strpos($m[2], 'tests/') === 0 ? self::SRC_TESTS : self::SRC_PLUGIN;
    }

    /**
     * Path relative to the Moodle tree.
     *
     * @param string $file Path.
     * @return string
     */
    private static function relative(string $file): string {
        global $CFG;
        $root = isset($CFG->dirroot) ? rtrim($CFG->dirroot, '/') . '/' : '';
        if ($root !== '' && strpos($file, $root) === 0) {
            return substr($file, strlen($root));
        }
        return $file;
    }

    /**
     * Human readable report of recorded deprecations (the failure message of a test).
     *
     * @param array[] $records Records.
     * @return string
     */
    public static function format(array $records): string {
        $lines = [count($records) . ' PHP deprecation(s) during this test (tool_ltidiagnostics deprecation guard, DIA-01):'];
        foreach ($records as $i => $r) {
            $lines[] = sprintf('#%d [%s] %s: %s', $i + 1, $r['source'], $r['type'], $r['message']);
            $lines[] = '    at ' . self::relative($r['file']) . ':' . $r['line'];
            foreach ($r['trace'] as $frame) {
                $lines[] = '    ' . $frame;
            }
        }
        return implode("\n", $lines);
    }
}
