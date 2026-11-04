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
 * Download of the redacted support package of the grade and completion channels (issue #8).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\grades\support_package;
use tool_ltidiagnostics\local\inventory;

require_login(null, false);
require_sesskey();
$context = context_system::instance();
require_capability('tool/ltidiagnostics:view', $context);
require_capability('tool/ltidiagnostics:export', $context);

$key = required_param('key', PARAM_RAW);
$format = required_param('format', PARAM_ALPHA);
if (
    !in_array($format, support_package::FORMATS, true) || connection::parse_key($key) === null
        || !($conn = (new inventory())->get_connection($key))
) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$now = time();
// The package holds the population of the page's scope (issue #29).
$pkg = support_package::build($conn, $now, \tool_ltidiagnostics\local\scope::from_request());
audit::log('export_support', $key, 'format=' . $format);
\tool_ltidiagnostics\event\report_exported::create(['context' => $context, 'other' => ['connection' => $key,
    'format' => 'support_' . $format]])->trigger();
$mime = ['json' => 'application/json', 'md' => 'text/markdown', 'csv' => 'text/csv'][$format];
send_file(support_package::export($pkg, $format), 'ltidiag-support-' . $pkg['diagnosticid'] . '-' . gmdate('Ymd-His', $now) .
    '.' . $format, 0, 0, true, true, $mime);
