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
 * Form to choose the safe active checks to run (GET requests only).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'key', $this->_customdata['key']);
        $mform->setType('key', PARAM_RAW);
        $mform->addElement('hidden', 'tab', 'health');
        $mform->setType('tab', PARAM_ALPHA);
        foreach ($this->_customdata['endpoints'] as $purpose => $url) {
            $mform->addElement(
                'advcheckbox',
                'probe_' . $purpose,
                get_string('probe_' . $purpose, 'tool_ltidiagnostics'),
                \tool_ltidiagnostics\local\redactor::url($url, $this->_customdata['reveal'])
            );
            $mform->setDefault('probe_' . $purpose, 1);
        }
        $mform->addElement('text', 'customurl', get_string('customurl', 'tool_ltidiagnostics'), ['size' => 60]);
        $mform->setType('customurl', PARAM_URL);
        $mform->addHelpButton('customurl', 'customurl', 'tool_ltidiagnostics');
        $mform->addElement('text', 'expectedkid', get_string('expectedkid', 'tool_ltidiagnostics'));
        $mform->setType('expectedkid', PARAM_ALPHANUMEXT);
        $this->add_action_buttons(false, get_string('runchecks', 'tool_ltidiagnostics'));
    }
}
