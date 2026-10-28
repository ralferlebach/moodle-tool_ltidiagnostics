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
 * Completion sync transfer reports from the command line (DIA-05). Runs as the site administrator; audited.
 *
 * Examples:
 *   php admin/tool/ltidiagnostics/cli/transfers.php --key=tool:1:1 --trace > b-trace.json
 *   php admin/tool/ltidiagnostics/cli/transfers.php --key=platform:3 --import=b-trace.json
 *   php admin/tool/ltidiagnostics/cli/transfers.php --key=platform:3 --report=json|md
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\transfer_report;
use tool_ltidiagnostics\local\transfer_service;

[$options, $unrecognised] = cli_get_params(['key' => '', 'trace' => false, 'import' => '', 'report' => '', 'from' => 0,
    'help' => false], ['h' => 'help']);
if ($unrecognised || $options['help'] || $options['key'] === '') {
    cli_writeln("Options: --key=<connection key> with --trace | --import=<file> | --report=json|md [--from=<unix time>]");
    exit($options['help'] ? 0 : 2);
}
\core\session\manager::set_user(get_admin());
$now = time();
$conn = (new inventory())->get_connection((string) $options['key']);
if ($conn === null) {
    cli_error('Unknown connection key');
}
$filters = ['from' => (int) $options['from'], 'to' => 0];
if ($options['trace']) {
    echo json_encode(transfer_service::export_trace($conn, $filters, $now), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if ($options['import'] !== '') {
    $json = @file_get_contents((string) $options['import']);
    if ($json === false) {
        cli_error('Cannot read the file');
    }
    cli_writeln((string) transfer_service::import_trace($conn, $json, $now));
    exit(0);
}
if ($options['report'] === 'json') {
    echo json_encode(transfer_service::report($conn, $filters, $now), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if ($options['report'] === 'md') {
    echo transfer_report::to_markdown(transfer_service::report($conn, $filters, $now), $conn, $now);
    exit(0);
}
cli_error('Nothing to do');
