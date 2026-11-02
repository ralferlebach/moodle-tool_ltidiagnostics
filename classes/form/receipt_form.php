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
 * Import of a grade receipt request (consumer) or of a grade receipt (provider).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class receipt_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $kind = $this->_customdata['kind'];
        $mform->addElement('hidden', 'key', $this->_customdata['key']);
        $mform->setType('key', PARAM_RAW);
        $mform->addElement('hidden', 'attempt', $this->_customdata['attempt'] ?? '');
        $mform->setType('attempt', PARAM_ALPHANUMEXT);
        $mform->addElement(
            'textarea',
            'document',
            get_string($kind . '_document', 'tool_ltidiagnostics'),
            ['rows' => 8, 'cols' => 80, 'spellcheck' => 'false']
        );
        $mform->setType('document', PARAM_RAW);
        $mform->addRule('document', null, 'required', null, 'client');
        $mform->addHelpButton('document', $kind . '_document', 'tool_ltidiagnostics');
        $this->add_action_buttons(false, get_string($kind . '_import', 'tool_ltidiagnostics'));
    }

    /**
     * Validation: size and schema only; the content is checked by the receipt class.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $doc = (string) ($data['document'] ?? '');
        $json = strlen($doc) <= \tool_ltidiagnostics\local\grades\receipt::MAXBYTES ? json_decode($doc, true) : null;
        $schema = $this->_customdata['kind'] === 'grequest' ? \tool_ltidiagnostics\local\grades\receipt::SCHEMA_REQUEST
            : \tool_ltidiagnostics\local\grades\receipt::SCHEMA_RECEIPT;
        if (!is_array($json) || ($json['schema'] ?? '') !== $schema) {
            $errors['document'] = get_string('greceipt_invalid', 'tool_ltidiagnostics');
        }
        return $errors;
    }
}
