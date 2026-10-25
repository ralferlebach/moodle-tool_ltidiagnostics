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
 * Time-limited diagnostic mode.
 *
 * In the default mode identifiers (client id, issuer host, deployment id) are masked in every view and
 * export. The diagnostic mode, enabled by an administrator for a limited time, lets authorised users see
 * them. Secrets, tokens, JWTs, cookies and e-mail addresses are never shown in either mode. Every view that
 * reveals identifiers is audited.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostic_mode {
    /**
     * Whether the mode is currently active.
     *
     * @param int|null $now Time (default: now).
     * @return bool
     */
    public static function is_active(?int $now = null): bool {
        return (int) get_config('tool_ltidiagnostics', 'diagmode_until') > ($now ?? time());
    }

    /**
     * When the mode ends (0 = not active).
     *
     * @return int
     */
    public static function until(): int {
        $until = (int) get_config('tool_ltidiagnostics', 'diagmode_until');
        return $until > time() ? $until : 0;
    }

    /**
     * Enable the mode for a limited time.
     *
     * @param int $minutes Duration, limited to the configured maximum.
     * @param int|null $now Time (default: now).
     * @return int The end time.
     */
    public static function enable(int $minutes, ?int $now = null): int {
        global $USER;
        require_capability('tool/ltidiagnostics:configure', \context_system::instance());
        $now = $now ?? time();
        $minutes = max(1, min($minutes, (int) config::get('diagmodemaxminutes')));
        $until = $now + $minutes * MINSECS;
        set_config('diagmode_until', $until, 'tool_ltidiagnostics');
        set_config('diagmode_by', (int) $USER->id, 'tool_ltidiagnostics');
        audit::log('modeenable', '', 'minutes=' . $minutes);
        \tool_ltidiagnostics\event\diagnostic_mode_changed::create([
            'context' => \context_system::instance(),
            'other' => ['enabled' => 1, 'until' => $until],
        ])->trigger();
        return $until;
    }

    /**
     * Disable the mode.
     */
    public static function disable(): void {
        require_capability('tool/ltidiagnostics:configure', \context_system::instance());
        self::expire(true);
    }

    /**
     * Switch the mode off when it has ended (or unconditionally).
     *
     * @param bool $force Switch off even if the time has not passed.
     * @return bool True when the mode was switched off.
     */
    public static function expire(bool $force = false): bool {
        $until = (int) get_config('tool_ltidiagnostics', 'diagmode_until');
        if ($until === 0 || (!$force && $until > time())) {
            return false;
        }
        set_config('diagmode_until', 0, 'tool_ltidiagnostics');
        audit::log('modedisable', '', $force ? 'manual' : 'expired');
        \tool_ltidiagnostics\event\diagnostic_mode_changed::create([
            'context' => \context_system::instance(),
            'other' => ['enabled' => 0, 'until' => 0],
        ])->trigger();
        return true;
    }

    /**
     * Whether the current user may see identifiers right now.
     *
     * @return bool
     */
    public static function can_reveal(): bool {
        return self::is_active() && has_capability('tool/ltidiagnostics:view', \context_system::instance());
    }
}
