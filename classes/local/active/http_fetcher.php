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

namespace tool_ltidiagnostics\local\active;

/**
 * Contract of the component that performs a safe GET request. Tests replace it with a fake.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface http_fetcher {
    /**
     * Perform a GET request without side effects: no body, no credentials, bounded time and size.
     *
     * @param string $url Absolute URL already approved by {@see url_guard}.
     * @param bool $allowprivate True when private targets were explicitly allowed.
     * @return fetch_result
     */
    public function get(string $url, bool $allowprivate): fetch_result;
}
