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
 * E2E helper (stack only, never run by PHPUnit): trigger real transfer events of local_lticompletionsync with one
 * correlation id on a site, so that the browser test can look for them in tool_ltidiagnostics.
 *
 * Usage: LTI_WWWROOT=<site> php family_events.php --root=<moodle tree> --corr=<id> --role=sender|receiver
 *        [--registration=<id> --deployment=<row id> | --typeid=<lti type id>]
 * Prints one JSON line: installed (bool), connectionid (int, 0 when no matching connection row), events (list).
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

$corr = clean_param($opts['corr'] ?? '', PARAM_ALPHANUMEXT);
$role = ($opts['role'] ?? 'sender') === 'receiver' ? 'receiver' : 'sender';
$installed = class_exists('\local_lticompletionsync\event\message_sent');
$out = ['installed' => $installed, 'connectionid' => 0, 'events' => []];
if ($installed && $corr !== '') {
    $conditions = $role === 'receiver' ? ['role' => 'receiver', 'ltitypeid' => (int) ($opts['typeid'] ?? 0)]
        : ['role' => 'sender', 'registrationid' => (int) ($opts['registration'] ?? 0),
            'deploymentid' => (int) ($opts['deployment'] ?? 0)];
    $out['connectionid'] = (int) $DB->get_field('local_lticompletionsync_con', 'id', $conditions, IGNORE_MULTIPLE);
    $classes = $role === 'receiver' ? ['http_received', 'identity_resolved', 'completion_applied']
        : ['message_queued', 'message_sent', 'message_acknowledged'];
    foreach ($classes as $class) {
        $name = '\local_lticompletionsync\event\\' . $class;
        $name::create(['context' => context_system::instance(), 'other' => ['correlationid' => $corr,
            'connectionid' => $out['connectionid'], 'version' => 1, 'code' => 'e2e']])->trigger();
        $out['events'][] = $class;
    }
}
echo json_encode($out) . "\n";
