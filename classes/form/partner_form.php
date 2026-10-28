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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Import of a partner document: a partner diagnostic package or a partner completion trace (JSON text).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class partner_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $kind = $this->_customdata['kind'];
        $mform->addElement('hidden', 'key', $this->_customdata['key']);
        $mform->setType('key', PARAM_RAW);
        $mform->addElement('hidden', 'action', 'import');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement(
            'textarea',
            'document',
            get_string($kind . '_document', 'tool_ltidiagnostics'),
            ['rows' => 10, 'cols' => 80, 'spellcheck' => 'false']
        );
        $mform->setType('document', PARAM_RAW);
        $mform->addRule('document', null, 'required', null, 'client');
        $mform->addHelpButton('document', $kind . '_document', 'tool_ltidiagnostics');
        $this->add_action_buttons(false, get_string($kind . '_import', 'tool_ltidiagnostics'));
    }

    /**
     * Validate the document with the parser of its kind.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        try {
            if ($this->_customdata['kind'] === 'partner') {
                \tool_ltidiagnostics\local\partner_package::parse((string) $data['document']);
            } else {
                \tool_ltidiagnostics\local\transfer_report::parse_trace((string) $data['document']);
            }
        } catch (\moodle_exception $e) {
            $errors['document'] = $e->getMessage();
        }
        return $errors;
    }
}
