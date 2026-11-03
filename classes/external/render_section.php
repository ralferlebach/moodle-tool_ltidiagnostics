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

namespace tool_ltidiagnostics\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\scope;
use tool_ltidiagnostics\output\sections;

/**
 * Render one filterable section of a connection report for the AMD module (issues #21, #23, #25).
 *
 * Read only: the same code path as the page load (sections::render), the same capability checks, and every parameter is
 * typed by the section itself. The AJAX service layer checks the session key; the function checks the context and
 * tool/ltidiagnostics:view. Nothing is written except the audit entry of the view.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class render_section extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'key' => new external_value(PARAM_RAW, 'Connection key'),
            'section' => new external_value(PARAM_ALPHAEXT, 'Section name'),
            'query' => new external_value(PARAM_RAW, 'URL query string of the link or form (filters, paging)'),
        ]);
    }

    /**
     * Render the section.
     *
     * @param string $key Connection key.
     * @param string $section Section.
     * @param string $query Query string.
     * @return array html, url, total
     */
    public static function execute(string $key, string $section, string $query): array {
        global $PAGE;
        $params = self::validate_parameters(self::execute_parameters(), ['key' => $key, 'section' => $section,
            'query' => $query]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ltidiagnostics:view', $context);
        if (!array_key_exists($params['section'], sections::SECTIONS)) {
            throw new \invalid_parameter_exception('section');
        }
        if (connection::parse_key($params['key']) === null || !($conn = (new inventory())->get_connection($params['key']))) {
            throw new \moodle_exception('invalidconnection', 'tool_ltidiagnostics');
        }
        $q = [];
        parse_str(\core_text::substr($params['query'], 0, 4000), $q);
        $q = array_filter($q, fn($v, $k) => is_string($v) && preg_match('/^[a-z]{1,20}$/', (string) $k), ARRAY_FILTER_USE_BOTH);
        $scope = scope::make(
            (int) clean_param($q['courseid'] ?? 0, PARAM_INT),
            (int) clean_param($q['cmid'] ?? 0, PARAM_INT),
            trim((string) clean_param($q['from'] ?? '', PARAM_TEXT)),
            trim((string) clean_param($q['to'] ?? '', PARAM_TEXT))
        );
        $PAGE->set_url(new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $conn->key]));
        $r = sections::render($conn, $params['section'], $q, $scope);
        \tool_ltidiagnostics\local\audit::log('view_' . $params['section'], $conn->key, 'ajax');
        return ['html' => $r['html'], 'url' => $r['url']->out(false), 'total' => (int) $r['total']];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered section'),
            'url' => new external_value(PARAM_URL, 'URL of the same state for the browser history'),
            'total' => new external_value(PARAM_INT, 'Number of matching entries'),
        ]);
    }
}
