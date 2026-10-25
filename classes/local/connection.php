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

namespace tool_ltidiagnostics\local;

/**
 * One selectable LTI connection: a platform-side tool type or a tool-side registration/deployment.
 *
 * The connection only references core records. Nothing of them is duplicated into own tables.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection {
    /** @var string Role: this site is the platform (mod_lti). */
    public const ROLE_PLATFORM = 'platform';
    /** @var string Role: this site is the tool (enrol_lti / auth_lti). */
    public const ROLE_TOOL = 'tool';

    /** @var string Stable key such as "platform:12" or "tool:3:7". */
    public $key = '';
    /** @var string Role constant. */
    public $role = self::ROLE_PLATFORM;
    /** @var string Display name. */
    public $name = '';
    /** @var string LTI version string as stored by Moodle. */
    public $ltiversion = '';
    /** @var string Issuer of the platform. */
    public $issuer = '';
    /** @var string OAuth client id. */
    public $clientid = '';
    /** @var string Deployment id as it appears in the claims. */
    public $deploymentid = '';
    /** @var int Local primary id (lti_types.id or enrol_lti_app_registration.id). */
    public $localid = 0;
    /** @var int Local deployment row id (tool role only). */
    public $deploymentrowid = 0;
    /** @var int Course the tool is restricted to (platform role, 0 = site wide). */
    public $courseid = 0;
    /** @var string State summary: active, pending, rejected, draft, complete. */
    public $state = '';
    /** @var string[] Endpoint URLs of the other side, keyed by purpose (launch, jwks, auth, oauth, initiatelogin). */
    public $endpoints = [];
    /** @var array Facts that were read from core configuration. Contains no secrets. */
    public $facts = [];
    /** @var array[] Published tools (tool role): each with id, name, flags. */
    public $tools = [];

    /**
     * Build a key.
     *
     * @param string $role Role.
     * @param int $localid Local id.
     * @param int $deploymentrowid Deployment row id.
     * @return string
     */
    public static function make_key(string $role, int $localid, int $deploymentrowid = 0): string {
        return $role === self::ROLE_PLATFORM ? "platform:$localid" : "tool:$localid:$deploymentrowid";
    }

    /**
     * Parse and validate a key.
     *
     * @param string $key Key as received from a request.
     * @return array|null [role, localid, deploymentrowid] or null when malformed
     */
    public static function parse_key(string $key): ?array {
        if (preg_match('/^platform:(\d{1,10})\z/', $key, $m)) {
            return [self::ROLE_PLATFORM, (int) $m[1], 0];
        }
        if (preg_match('/^tool:(\d{1,10}):(\d{1,10})\z/', $key, $m)) {
            return [self::ROLE_TOOL, (int) $m[1], (int) $m[2]];
        }
        return null;
    }
}
