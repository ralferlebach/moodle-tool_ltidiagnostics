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

/**
 * Resolves log file paths against an administrator-configured allow-list.
 *
 * The plugin never searches the file system. A file can only be read if its real path (symbolic
 * links and ".." already resolved) is one of the allow-listed files or lies inside an
 * allow-listed directory.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_allowlist {
    /**
     * Resolve a requested path.
     *
     * @param string $requested Path as typed or selected.
     * @param string[] $allowed Allow-listed files and directories (absolute).
     * @return string|null Real path of a readable regular file, or null when not allowed.
     */
    public static function resolve(string $requested, array $allowed): ?string {
        if ($requested === '' || strpos($requested, "\0") !== false || !$allowed) {
            return null;
        }
        $real = realpath($requested);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }
        foreach ($allowed as $entry) {
            if ($entry === '' || strpos($entry, "\0") !== false || $entry[0] !== '/') {
                continue;
            }
            $allowedreal = realpath($entry);
            if ($allowedreal === false) {
                continue;
            }
            if (is_file($allowedreal) && $allowedreal === $real) {
                return $real;
            }
            if (is_dir($allowedreal) && strpos($real, rtrim($allowedreal, '/') . '/') === 0) {
                return $real;
            }
        }
        return null;
    }

    /**
     * List the files the administrator can pick from.
     *
     * @param string[] $allowed Allow-listed files and directories.
     * @param int $max Maximum number of files.
     * @return string[] Real paths.
     */
    public static function list_files(array $allowed, int $max = 200): array {
        $out = [];
        foreach ($allowed as $entry) {
            $real = $entry !== '' && $entry[0] === '/' ? realpath($entry) : false;
            if ($real === false) {
                continue;
            }
            if (is_file($real)) {
                $out[$real] = $real;
            } else if (is_dir($real)) {
                foreach (scandir($real) ?: [] as $name) {
                    $file = $real . '/' . $name;
                    if ($name[0] !== '.' && is_file($file) && is_readable($file) && preg_match('/log|\.gz$/i', $name)) {
                        $out[$file] = $file;
                    }
                }
            }
            if (count($out) >= $max) {
                break;
            }
        }
        ksort($out);
        return array_slice(array_values($out), 0, $max);
    }
}
