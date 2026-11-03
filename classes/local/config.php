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
 * Typed access to the plugin settings with their defaults.
 *
 * get_config() returns false for a setting that was never saved, so every read goes through
 * here to get the documented default.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var array Defaults of all settings. */
    public const DEFAULTS = [
        'recordevents' => 1,
        'retentiondays' => 30,
        'logallowlist' => '',
        'maxlinesperimport' => 50000,
        'maxfilesize' => 52428800,
        'activechecks' => 0,
        'allowprivatetargets' => 0,
        'clockwindow' => 120,
        'diagmodemaxminutes' => 240,
        // Since 0.3.0: retention per data class (days), import rate limit, exact private targets.
        'retentionaccdays' => 30,
        'retentionauditdays' => 365,
        'retentionsnapdays' => 365,
        'retentionpartnerdays' => 30,
        'importratelimit' => 10,
        'privatetargetallowlist' => '',
        // Since 0.4.0: grade channel. The active grade replay is off by default and needs an opt-in per connection.
        'graderepair' => 0,
        'graderepairconnections' => '',
        'repairbatchmax' => 20,
        'selectionttl' => 900,
        'repairratelimit' => 30,
        'repairmaxattempts' => 4,
        'repairbackoff' => 300,
        'repairresultcheck' => 1,
        'retentiongradedays' => 365,
    ];

    /** @var array Bounds of numeric grade channel settings: name => [min, max]. */
    public const BOUNDS = [
        'repairbatchmax' => [1, 100],
        'selectionttl' => [60, 3600],
        'repairratelimit' => [1, 500],
        'repairmaxattempts' => [1, 10],
        'repairbackoff' => [30, 86400],
        'retentiongradedays' => [1, 3650],
    ];

    /**
     * Read a setting.
     *
     * @param string $name Setting name.
     * @return mixed
     */
    public static function get(string $name) {
        $value = get_config('tool_ltidiagnostics', $name);
        if ($value === false || $value === null) {
            return self::DEFAULTS[$name] ?? null;
        }
        return is_numeric(self::DEFAULTS[$name] ?? null) ? (int) $value : $value;
    }

    /**
     * Numeric setting clamped to its bounds (a mistyped value can never mean "unlimited").
     *
     * @param string $name Setting name (key of BOUNDS).
     * @return int
     */
    public static function bounded(string $name): int {
        [$min, $max] = self::BOUNDS[$name];
        return max($min, min($max, (int) self::get($name)));
    }

    /**
     * Newline separated setting as a clean list.
     *
     * @param string $name Setting name.
     * @return string[]
     */
    public static function get_list(string $name): array {
        $raw = (string) self::get($name);
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
