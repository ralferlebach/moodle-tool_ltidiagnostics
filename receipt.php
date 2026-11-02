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
 * Consumer side of a grade receipt (issue #6): answer a receipt request from the provider with a signed receipt.
 *
 * The answer reads the own gradebook (read only), contains no name and no user id, and is signed with this platform's
 * LTI key. Sending it to the provider is a decision of this site's administrator.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\grades\receipt;
use tool_ltidiagnostics\local\inventory;

$key = required_param('key', PARAM_RAW);
$pageurl = new moodle_url('/admin/tool/ltidiagnostics/receipt.php', ['key' => $key]);
admin_externalpage_setup('tool_ltidiagnostics_index', '', null, $pageurl);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:view', $context);
require_capability('tool/ltidiagnostics:export', $context);
if (
    connection::parse_key($key) === null || !($conn = (new inventory())->get_connection($key))
        || $conn->role !== connection::ROLE_PLATFORM
) {
    throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
}
$form = new \tool_ltidiagnostics\form\receipt_form($pageurl, ['key' => $key, 'kind' => 'grequest']);
if ($data = $form->get_data()) {
    $r = receipt::answer((string) $data->document, time());
    if (!$r['ok']) {
        redirect(
            $pageurl,
            get_string('greceipt_answerfail', 'tool_ltidiagnostics', $r['reason']),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    send_file(json_encode($r['document'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'ltidiag-grade-receipt-' .
        substr($r['payload']['attemptid'], 0, 8) . '.json', 0, 0, true, true, 'application/json');
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('greceipt_answer_title', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('greceipt_answer_desc', 'tool_ltidiagnostics'));
echo html_writer::link(
    new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => 'grades']),
    get_string('backtoconnection', 'tool_ltidiagnostics'),
    ['class' => 'd-block mb-3']
);
$form->display();
echo $OUTPUT->footer();
