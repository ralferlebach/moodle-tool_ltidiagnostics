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
 * Import of web server access and error logs from an allow-listed path or an uploaded file.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_ltidiagnostics\local\accesslog\importer;
use tool_ltidiagnostics\local\accesslog\path_allowlist;
use tool_ltidiagnostics\local\audit;
use tool_ltidiagnostics\local\config;

$url = new moodle_url('/admin/tool/ltidiagnostics/logimport.php');
admin_externalpage_setup('tool_ltidiagnostics_logimport', '', null, $url);
$context = context_system::instance();
require_capability('tool/ltidiagnostics:importlogs', $context);

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete) {
    // Deleting needs the confirmation page and a valid session key.
    require_sesskey();
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('deleteimport_confirm', 'tool_ltidiagnostics', $delete),
            new moodle_url($url, ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }
    $lines = importer::delete_import($delete);
    redirect($url, get_string('deleteimport_done', 'tool_ltidiagnostics', $lines), null, \core\output\notification::NOTIFY_SUCCESS);
}

$form = new \tool_ltidiagnostics\form\import_form($url);
$job = null;
if ($data = $form->get_data()) {
    [$kind, $format] = explode(':', $data->format, 2);
    $options = [
        'kind' => $kind,
        'format' => $format,
        'timezone' => $data->timezone,
        'skew' => (int) $data->skew,
        'maxlines' => (int) config::get('maxlinesperimport'),
        'maxbytes' => (int) config::get('maxfilesize'),
        'userid' => (int) $USER->id,
    ];
    $importer = new importer();
    if (!empty($data->path)) {
        $real = path_allowlist::resolve($data->path, config::get_list('logallowlist'));
        if ($real === null) {
            throw new \moodle_exception('pathnotallowed', 'tool_ltidiagnostics');
        }
        $job = $importer->import_file($real, $options);
    } else {
        $draftid = file_get_submitted_draft_itemid('logupload');
        $files = get_file_storage()->get_area_files(
            context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftid,
            'id',
            false
        );
        $file = reset($files);
        if (!$file) {
            throw new \moodle_exception('nosource', 'tool_ltidiagnostics');
        }
        $temp = $file->copy_content_to_temp();
        try {
            $job = $importer->import_file($temp, ['sourcename' => $file->get_filename()] + $options);
            $DB->set_field('tool_ltidiagnostics_imp', 'sourcetype', 'upload', ['id' => $job->id]);
            $DB->set_field(
                'tool_ltidiagnostics_imp',
                'sourcename',
                clean_param($file->get_filename(), PARAM_FILE),
                ['id' => $job->id]
            );
        } finally {
            @unlink($temp);
        }
    }
    audit::log('logimport', '', 'job=' . $job->id . ',status=' . $job->status);
}

$output = $PAGE->get_renderer('tool_ltidiagnostics');
echo $output->header();
echo $output->heading(get_string('logimport', 'tool_ltidiagnostics'));
echo html_writer::tag('p', get_string('logimport_desc', 'tool_ltidiagnostics'));
if (!config::get_list('logallowlist')) {
    echo $output->notification(get_string('noallowlist', 'tool_ltidiagnostics'), \core\output\notification::NOTIFY_INFO);
}
if ($job) {
    echo $output->notification(
        get_string('importresult', 'tool_ltidiagnostics', $job),
        $job->status === 'failed' ? \core\output\notification::NOTIFY_ERROR : \core\output\notification::NOTIFY_SUCCESS
    );
}
$form->display();
echo $output->imports_table($DB->get_records('tool_ltidiagnostics_imp', null, 'timecreated DESC, id DESC', '*', 0, 20), true);
echo $output->footer();
