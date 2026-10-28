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
 * Partner package and two-system check from the command line (DIA-02, DIA-03). Read only towards LTI: nothing in an
 * LTI setting is changed. Runs as the site administrator; every action is audited like in the browser.
 *
 * Examples:
 *   php admin/tool/ltidiagnostics/cli/partner.php --key=tool:1:1 --export > b.json
 *   php admin/tool/ltidiagnostics/cli/partner.php --key=platform:3 --import=b.json
 *   php admin/tool/ltidiagnostics/cli/partner.php --key=platform:3 --live
 *   php admin/tool/ltidiagnostics/cli/partner.php --key=platform:3 --findings
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\partner_service;

[$options, $unrecognised] = cli_get_params(
    ['key' => '', 'export' => false, 'import' => '', 'live' => false, 'findings' => false, 'list' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised || $options['help'] || ($options['key'] === '' && !$options['list'])) {
    cli_writeln("Options: --list | --key=<connection key> with --export | --import=<file> | --live | --findings");
    exit($options['help'] ? 0 : 2);
}
\core\session\manager::set_user(get_admin());
$now = time();
if ($options['list']) {
    foreach ((new inventory())->get_connections() as $c) {
        cli_writeln($c->key . "\t" . $c->role . "\t" . $c->name);
    }
    exit(0);
}
$conn = (new inventory())->get_connection((string) $options['key']);
if ($conn === null) {
    cli_error('Unknown connection key');
}
if ($options['export']) {
    echo json_encode(partner_service::export_package($conn, $now), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if ($options['import'] !== '') {
    $json = @file_get_contents((string) $options['import']);
    if ($json === false) {
        cli_error('Cannot read the file');
    }
    $result = partner_service::import_package($conn, $json, $now);
    echo json_encode(['mismatches' => $result['mismatches'], 'keys' => $result['keys']], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if ($options['live']) {
    $result = partner_service::live($conn, $now);
    if ($result === null) {
        cli_error('No partner package imported for this connection');
    }
    $probe = $result['probe'];
    echo json_encode(['code' => $probe['code'], 'pinned' => $probe['pinned'], 'pinmode' => $probe['pinmode'] ?? '',
        'findings' => array_map(function ($f) {
            return $f->code;
        }, $result['findings']), 'keys' => $result['keys']], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if ($options['findings']) {
    $inputs = health_check::partner_inputs($conn, $now);
    $out = [];
    foreach ($inputs['partnerfindings'] ?? [] as $f) {
        $out[] = $f->diagnosis();
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}
cli_error('Nothing to do');
