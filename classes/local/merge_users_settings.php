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
 * Settings for the optional plugin tool_mergeusers (Gate 2 finding F5, audit round 2026-10-27).
 *
 * Every table of this plugin with a user column is declared, so a merge preview (local_ltiaccountmerge) never sees an
 * undeclared table. All of them are excluded from the merge because every user column records history, not something the
 * person owns:
 *  - audit.userid: who looked at, exported or changed diagnostics (an accountable act);
 *  - obs.userid: the account an observed LTI event happened to. Moving the rows would forge the surviving account's
 *    journey (for example a second account_created or a launch with another platform subject);
 *  - imp.userid: who imported a web server log (provenance of the imported lines, an accountable act);
 *  - snap.userid: who saved a snapshot (0 for the scheduled task);
 *  - profile.usermodified: who last changed a desired-state profile.
 * tool_ltidiagnostics_acc has no user column. The rows keep the merged-away account's id; tool_mergeusers suspends that
 * account, the retention task deletes the rows on schedule and the privacy provider still finds them under that id.
 *
 * tool_mergeusers does not need to be installed: the callback is registered in db/hooks.php, Moodle's hook manager only
 * calls it when that plugin dispatches its hook, and the method accepts any object with add_raw_settings().
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_users_settings {
    /** @var string The audit table (kept for callers and tests). */
    public const AUDIT_TABLE = 'tool_ltidiagnostics_audit';

    /** @var string[] Table => user columns of every table of this plugin that has one (schema order; all stay unchanged). */
    public const USER_TABLES = [
        'tool_ltidiagnostics_obs' => ['userid'],
        'tool_ltidiagnostics_audit' => ['userid'],
        'tool_ltidiagnostics_profile' => ['usermodified'],
        'tool_ltidiagnostics_snap' => ['userid'],
        'tool_ltidiagnostics_imp' => ['userid'],
    ];

    /**
     * The settings this plugin adds.
     *
     * The tables are listed under string keys on purpose: tool_mergeusers merges its configuration layers with
     * array_replace_recursive, so a plain list entry at index 0 would overwrite (or be overwritten by) the first entry
     * of another layer, for example its default exception user_preferences.
     *
     * @return array
     */
    public static function settings(): array {
        $tables = array_keys(self::USER_TABLES);
        return ['exceptions' => array_combine($tables, $tables)];
    }

    /**
     * Callback for \tool_mergeusers\hook\add_settings_before_merging.
     *
     * @param object $hook The hook (or any object with add_raw_settings(array)).
     */
    public static function add_settings_before_merging(object $hook): void {
        $hook->add_raw_settings(self::settings());
    }
}
