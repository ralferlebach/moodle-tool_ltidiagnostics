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
 * Outcome of one HTTP request made by a fetcher.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_result {
    /** @var int curl error number, 0 when the transfer worked. */
    public $errno = 0;
    /** @var string curl error text. */
    public $error = '';
    /** @var int HTTP status, 0 when none was received. */
    public $httpstatus = 0;
    /** @var string[] Response headers, names in lower case. */
    public $headers = [];
    /** @var string Body, truncated. */
    public $body = '';
    /** @var array Selected transfer info: connect_time, namelookup_time, ssl_verify_result, certinfo. */
    public $info = [];
    /** @var string[] URLs that were followed. */
    public $redirects = [];
    /** @var string Policy reason when the request was refused before it was made, else empty. */
    public $blocked = '';
}
