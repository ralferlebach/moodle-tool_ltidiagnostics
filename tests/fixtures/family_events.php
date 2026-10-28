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

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Fixture events shaped like the transfer events of local_lticompletionsync (spec 08, section 7), in a fictitious family
 * component. They let the tests write real log store rows without depending on the completion sync plugin.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ltidiagfixture\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Base of the fixture transfer events: correlation id, connection id, version and code in "other".
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class sync_event extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return 'Fixture transfer event';
    }

    /**
     * Mapping of other.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['connectionid' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Trigger one event.
     *
     * @param string $correlationid Correlation id.
     * @param int $userid Related user.
     * @param array $extra Further "other" values.
     * @return static
     */
    public static function fire(string $correlationid, int $userid = 0, array $extra = []) {
        $params = ['context' => \context_system::instance(), 'other' => ['correlationid' => $correlationid,
            'connectionid' => 7, 'version' => 1] + $extra];
        if ($userid) {
            $params['relateduserid'] = $userid;
        }
        $event = static::create($params);
        $event->trigger();
        return $event;
    }
}

/**
 * Fixture event source_detected.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_detected extends sync_event {
}

/**
 * Fixture event message_queued.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_queued extends sync_event {
}

/**
 * Fixture event message_sent.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_sent extends sync_event {
}

/**
 * Fixture event http_received.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_received extends sync_event {
}

/**
 * Fixture event identity_resolved.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class identity_resolved extends sync_event {
}

/**
 * Fixture event completion_applied.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_applied extends sync_event {
}

/**
 * Fixture event completion_revoked.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_revoked extends sync_event {
}

/**
 * Fixture event message_acknowledged.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_acknowledged extends sync_event {
}

/**
 * Fixture event retry_scheduled.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retry_scheduled extends sync_event {
}

/**
 * Fixture event dead_letter.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dead_letter extends sync_event {
}

/**
 * Fixture event state_reconciled.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_reconciled extends sync_event {
}
