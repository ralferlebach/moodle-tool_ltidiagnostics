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

/**
 * E2E helper (stack only, never run by PHPUnit) for the 0.3.0 specs: tool_ltigroupautoenrol mapping of a scenario course
 * (GA-03), group membership, diagnostic observations, and the event recording switch.
 *
 * Usage: LTI_WWWROOT=<site> php diag_fixture.php --root=<moodle tree> --stage=<stage> [options]
 *   --stage=gamap --courseid=N --toolid=N   group "Diag group" in the course, mapped to the published tool (enabled)
 *   --stage=gaunmap --courseid=N            remove the mapping of the course again
 *   --stage=members --groupid=N             number of members of the group
 *   --stage=obs --key=K|any --type=T --from=T   number of observations of the connection (or any) and type since a time
 * Prints one JSON line.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- standalone CLI script loading the config.php of a given tree.

define('CLI_SCRIPT', true);

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
if (empty($opts['root']) || !is_file($opts['root'] . '/config.php')) {
    fwrite(STDERR, "--root=<moodle tree> is required\n");
    exit(2);
}
require($opts['root'] . '/config.php');
require_once($CFG->dirroot . '/group/lib.php');
\core\session\manager::set_user(get_admin());
$out = [];
switch ($opts['stage'] ?? '') {
    case 'gamap':
        $courseid = (int) $opts['courseid'];
        $toolid = (int) $opts['toolid'];
        if (!$DB->get_manager()->table_exists('tool_ltigroupautoenrol')) {
            $out = ['installed' => false];
            break;
        }
        $groupid = (int) $DB->get_field('groups', 'id', ['courseid' => $courseid, 'name' => 'Diag group']);
        if (!$groupid) {
            $groupid = (int) groups_create_group((object) ['courseid' => $courseid, 'name' => 'Diag group']);
        }
        $settings = json_encode([(string) $toolid => [(string) $groupid]]);
        if ($id = $DB->get_field('tool_ltigroupautoenrol', 'id', ['courseid' => $courseid])) {
            $DB->update_record('tool_ltigroupautoenrol', (object) ['id' => $id, 'enable_enrol' => 1, 'settings' => $settings]);
        } else {
            $DB->insert_record('tool_ltigroupautoenrol', (object) ['courseid' => $courseid, 'enable_enrol' => 1,
                'settings' => $settings]);
        }
        $out = ['installed' => true, 'groupid' => $groupid,
            'events' => class_exists('\\tool_ltigroupautoenrol\\event\\group_assignment_completed')];
        break;
    case 'gaunmap':
        $DB->delete_records('tool_ltigroupautoenrol', ['courseid' => (int) $opts['courseid']]);
        $out = ['ok' => true];
        break;
    case 'members':
        $out = ['count' => $DB->count_records('groups_members', ['groupid' => (int) $opts['groupid']])];
        break;
    case 'obs':
        // Key "any": every observation of the type on the site (launch observations of a platform with several
        // registrations carry no connection key, only the issuer hash).
        $any = ($opts['key'] ?? '') === 'any';
        $where = ($any ? '' : 'connkey = :k AND ') . 'eventtype = :t AND timecreated >= :f';
        $params = ['k' => (string) ($opts['key'] ?? ''), 't' => (string) $opts['type'], 'f' => (int) ($opts['from'] ?? 0)];
        if ($any) {
            unset($params['k']);
        }
        $out = ['count' => $DB->count_records_select('tool_ltidiagnostics_obs', $where, $params)];
        break;
    default:
        fwrite(STDERR, "unknown --stage\n");
        exit(2);
}
echo json_encode($out) . "\n";
