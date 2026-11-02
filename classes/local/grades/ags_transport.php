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

namespace tool_ltidiagnostics\local\grades;

/**
 * Network access of the grade replay and of the results check: OAuth2 client credentials token and AGS requests.
 *
 * The only implementation for production is {@see moodle_ags_transport}; tests supply a scripted one. Results carry a
 * status and a class, never the token outside the transport's caller, and only the parsed JSON of read requests.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface ags_transport {
    /**
     * Ask the platform's token endpoint for an access token (client credentials with a signed client assertion).
     *
     * @param \Packback\Lti1p3\Interfaces\ILtiRegistration $registration Registration of the platform.
     * @param string[] $scopes Requested scopes.
     * @return array status (int HTTP status, 0 when none), errorclass (string, '' on success), token (string, '' when
     *     none), scopes (string[] granted scopes as reported, [] when the platform did not say).
     */
    public function token($registration, array $scopes): array;

    /**
     * POST a score to the scores endpoint of a line item.
     *
     * @param string $url Scores URL.
     * @param string $token Access token.
     * @param array $score Score object.
     * @return array status (int), errorclass (string, '' for 2xx).
     */
    public function post_score(string $url, string $token, array $score): array;

    /**
     * GET a JSON document (line items container or results container).
     *
     * @param string $url URL.
     * @param string $token Access token.
     * @param string $accept Media type.
     * @return array status (int), errorclass (string), json (array|null).
     */
    public function get_json(string $url, string $token, string $accept): array;
}
