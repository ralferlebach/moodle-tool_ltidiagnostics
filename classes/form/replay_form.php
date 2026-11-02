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
 * Confirmation of a grade replay (single, batch or retry). Sent with POST and sesskey by the form API.
 *
 * The fingerprints come from the preview the operator saw; the server compares them again before it queues anything.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class replay_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        foreach (
            ['key' => PARAM_RAW, 'link' => PARAM_INT, 'user' => PARAM_INT, 'attempt' => PARAM_ALPHANUMEXT,
                'mode' => PARAM_ALPHA, 'targets' => PARAM_RAW] as $name => $type
        ) {
            $mform->addElement('hidden', $name, $this->_customdata[$name] ?? '');
            $mform->setType($name, $type);
        }
        $mform->addElement(
            'static',
            'scope',
            get_string('greplay_scope', 'tool_ltidiagnostics'),
            s($this->_customdata['scopetext'] ?? '')
        );
        $mform->addElement('text', 'purpose', get_string('greplay_purpose', 'tool_ltidiagnostics'), ['size' => 60,
            'maxlength' => 200]);
        $mform->setType('purpose', PARAM_TEXT);
        $mform->addRule('purpose', null, 'required', null, 'client');
        $mform->addRule('purpose', null, 'maxlength', 200, 'client');
        $mform->addElement(
            'advcheckbox',
            'confirm',
            get_string('greplay_confirm', 'tool_ltidiagnostics'),
            get_string('greplay_confirm_text', 'tool_ltidiagnostics')
        );
        $mform->addRule('confirm', get_string('required'), 'required', null, 'client');
        $this->add_action_buttons(true, get_string('greplay_send', 'tool_ltidiagnostics'));
    }

    /**
     * Validation.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['confirm'])) {
            $errors['confirm'] = get_string('required');
        }
        if (trim((string) ($data['purpose'] ?? '')) === '') {
            $errors['purpose'] = get_string('required');
        }
        return $errors;
    }
}
