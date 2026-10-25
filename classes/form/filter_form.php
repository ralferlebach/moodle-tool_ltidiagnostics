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
 * Search and filter form of the connection list (GET).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->disable_form_change_checker();
        $mform->addElement('text', 'search', get_string('search'));
        $mform->setType('search', PARAM_TEXT);
        $mform->addElement('select', 'role', get_string('role', 'tool_ltidiagnostics'), [
            '' => get_string('allroles', 'tool_ltidiagnostics'),
            'platform' => get_string('role_platform', 'tool_ltidiagnostics'),
            'tool' => get_string('role_tool', 'tool_ltidiagnostics'),
        ]);
        $mform->setType('role', PARAM_ALPHA);
        $mform->addElement('text', 'courseid', get_string('courseid', 'tool_ltidiagnostics'));
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('submit', 'submitbutton', get_string('filter', 'tool_ltidiagnostics'));
    }
}
