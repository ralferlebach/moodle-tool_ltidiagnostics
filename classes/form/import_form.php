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

namespace tool_ltidiagnostics\form;

use tool_ltidiagnostics\local\accesslog\parser;
use tool_ltidiagnostics\local\accesslog\path_allowlist;
use tool_ltidiagnostics\local\config;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form to import a web server log from an allow-listed path or an uploaded file.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $formats = [];
        foreach (['access', 'error'] as $kind) {
            foreach (parser::formats($kind) as $f) {
                $formats[$kind . ':' . $f] = get_string('logformat_' . $f, 'tool_ltidiagnostics');
            }
        }
        $mform->addElement('select', 'format', get_string('logformat', 'tool_ltidiagnostics'), $formats);
        $mform->addElement(
            'select',
            'timezone',
            get_string('logtimezone', 'tool_ltidiagnostics'),
            \core_date::get_list_of_timezones(null, true)
        );
        $mform->setDefault('timezone', 'UTC');
        $mform->addHelpButton('timezone', 'logtimezone', 'tool_ltidiagnostics');
        $mform->addElement('text', 'skew', get_string('logskew', 'tool_ltidiagnostics'));
        $mform->setType('skew', PARAM_INT);
        $mform->setDefault('skew', 0);
        $mform->addHelpButton('skew', 'logskew', 'tool_ltidiagnostics');

        $files = ['' => get_string('choosefile', 'tool_ltidiagnostics')];
        foreach (path_allowlist::list_files(config::get_list('logallowlist')) as $path) {
            $files[$path] = $path;
        }
        $mform->addElement('select', 'path', get_string('logpath', 'tool_ltidiagnostics'), $files);
        $mform->setType('path', PARAM_RAW);
        $mform->addHelpButton('path', 'logpath', 'tool_ltidiagnostics');
        $mform->addElement(
            'filepicker',
            'logupload',
            get_string('logupload', 'tool_ltidiagnostics'),
            null,
            ['maxbytes' => (int) config::get('maxfilesize'), 'accepted_types' => ['.log', '.txt', '.gz', '.1']]
        );
        $this->add_action_buttons(false, get_string('import', 'tool_ltidiagnostics'));
    }

    /**
     * Server side validation.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!preg_match('/^(access|error):[a-z_]+\z/', $data['format'] ?? '')) {
            $errors['format'] = get_string('invalidformat', 'tool_ltidiagnostics');
        }
        $uploaded = $this->get_draft_files('logupload');
        if (empty($data['path']) && !$uploaded) {
            $errors['path'] = get_string('nosource', 'tool_ltidiagnostics');
        }
        return $errors;
    }
}
