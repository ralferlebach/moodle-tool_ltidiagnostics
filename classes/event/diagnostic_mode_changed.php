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

namespace tool_ltidiagnostics\event;

/**
 * Event diagnostic_mode_changed.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostic_mode_changed extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_diagnostic_mode_changed', 'tool_ltidiagnostics');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' changed the diagnostic mode of tool_ltidiagnostics.";
    }

    /**
     * Link to the diagnostics page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/ltidiagnostics/index.php');
    }
}
