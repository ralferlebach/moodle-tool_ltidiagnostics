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
 * Connection inventory built from stable core APIs and core configuration tables.
 *
 * Platform role: mod_lti tool types (lti_types, lti_types_config) and the services reported by
 * lti_get_services(). Tool role: enrol_lti registrations, deployments and published tools.
 * Everything is read only and secrets (enrol_lti_tools.secret, token values, keys) are never read.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inventory {
    /**
     * List connections.
     *
     * @param string $role Restrict to a role ("" = both).
     * @param string $search Case-insensitive search term over name, issuer, client id and deployment id.
     * @param int $courseid Restrict platform connections to tools visible in a course (0 = no restriction).
     * @return connection[] Keyed by connection key.
     */
    public function get_connections(string $role = '', string $search = '', int $courseid = 0): array {
        $list = [];
        if ($role === '' || $role === connection::ROLE_PLATFORM) {
            foreach ($this->platform_connections() as $c) {
                $list[$c->key] = $c;
            }
        }
        if ($role === '' || $role === connection::ROLE_TOOL) {
            foreach ($this->tool_connections() as $c) {
                $list[$c->key] = $c;
            }
        }
        if ($courseid > 0) {
            $list = $this->filter_by_course($list, $courseid);
        }
        $search = \core_text::strtolower(trim($search));
        if ($search !== '') {
            $list = array_filter($list, function (connection $c) use ($search) {
                $hay = \core_text::strtolower(implode(' ', [$c->name, $c->issuer, $c->clientid, $c->deploymentid]));
                return strpos($hay, $search) !== false;
            });
        }
        return $list;
    }

    /**
     * Get one connection by key.
     *
     * @param string $key Connection key.
     * @return connection|null
     */
    public function get_connection(string $key): ?connection {
        $parsed = connection::parse_key($key);
        if ($parsed === null) {
            return null;
        }
        $all = $this->get_connections($parsed[0]);
        return $all[$key] ?? null;
    }

    /**
     * Keep platform tools usable in a course, and all tool-role connections.
     *
     * @param connection[] $list Connections.
     * @param int $courseid Course id.
     * @return connection[]
     */
    private function filter_by_course(array $list, int $courseid): array {
        return array_filter($list, function (connection $c) use ($courseid) {
            if ($c->role === connection::ROLE_TOOL) {
                return true;
            }
            return $c->courseid === 0 || $c->courseid === $courseid;
        });
    }

    /**
     * Platform side: mod_lti tool types.
     *
     * @return connection[]
     */
    private function platform_connections(): array {
        global $DB, $CFG;
        if (!$DB->get_manager()->table_exists('lti_types')) {
            return [];
        }
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $out = [];
        $states = [1 => 'active', 2 => 'pending', 3 => 'rejected'];
        foreach ($DB->get_records('lti_types', null, 'id') as $type) {
            $config = lti_get_type_config($type->id);
            $c = new connection();
            $c->role = connection::ROLE_PLATFORM;
            $c->localid = (int) $type->id;
            $c->key = connection::make_key($c->role, $c->localid);
            $c->name = (string) $type->name;
            $c->ltiversion = (string) $type->ltiversion;
            $c->issuer = $CFG->wwwroot;
            $c->clientid = (string) ($type->clientid ?? '');
            $c->deploymentid = (string) $type->id;
            $c->courseid = (int) $type->course === (int) SITEID ? 0 : (int) $type->course;
            $c->state = $states[(int) $type->state] ?? 'unknown';
            $c->endpoints = array_filter([
                'launch' => (string) $type->baseurl,
                'jwks' => (string) ($config['publickeyset'] ?? ''),
                'initiatelogin' => (string) ($config['initiatelogin'] ?? ''),
            ]);
            $c->facts = [
                'keytype' => (string) ($config['keytype'] ?? ''),
                'ags' => (int) ($config['ltiservice_gradesynchronization'] ?? 0),
                'nrps' => (int) ($config['ltiservice_memberships'] ?? 0),
                'toolsettings' => (int) ($config['ltiservice_toolsettings'] ?? 0),
                'deeplinking' => (int) !empty($config['contentitem']),
                'sendname' => (int) ($config['sendname'] ?? 0),
                'sendemail' => (int) ($config['sendemailaddr'] ?? 0),
                'acceptgrades' => (int) ($config['acceptgrades'] ?? 0),
                'hasredirects' => (int) !empty($config['redirectionuris']),
                // Names (never values) of the custom parameters of the type.
                'customkeys' => self::custom_keys((string) ($config['customparameters'] ?? '')),
                'services' => $this->service_names(),
            ];
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Names of the LTI services this Moodle offers (stable API lti_get_services()).
     *
     * @return string[]
     */
    private function service_names(): array {
        $names = [];
        foreach (lti_get_services() as $service) {
            $names[] = $service->get_component_id();
        }
        sort($names);
        return $names;
    }

    /**
     * Tool side: enrol_lti registrations and deployments.
     *
     * @return connection[]
     */
    private function tool_connections(): array {
        global $DB;
        $manager = $DB->get_manager();
        if (!$manager->table_exists('enrol_lti_app_registration')) {
            return [];
        }
        $out = [];
        $tools = $manager->table_exists('enrol_lti_tools') ? $this->published_tools() : [];
        $deployments = [];
        foreach ($DB->get_records('enrol_lti_deployment', null, 'id') as $d) {
            $deployments[(int) $d->platformid][] = $d;
        }
        $links = $this->links_by_deployment();
        foreach ($DB->get_records('enrol_lti_app_registration', null, 'id') as $reg) {
            $rows = $deployments[(int) $reg->id] ?? [null];
            foreach ($rows as $dep) {
                $c = new connection();
                $c->role = connection::ROLE_TOOL;
                $c->localid = (int) $reg->id;
                $c->deploymentrowid = $dep ? (int) $dep->id : 0;
                $c->key = connection::make_key($c->role, $c->localid, $c->deploymentrowid);
                $c->name = (string) $reg->name . ($dep ? ' / ' . $dep->name : '');
                $c->ltiversion = '1.3.0';
                $c->issuer = (string) $reg->platformid;
                $c->clientid = (string) $reg->clientid;
                $c->deploymentid = $dep ? (string) $dep->deploymentid : '';
                $c->state = ((int) $reg->status === 1) ? 'complete' : 'draft';
                $c->endpoints = array_filter([
                    'auth' => (string) $reg->authenticationrequesturl,
                    'jwks' => (string) $reg->jwksurl,
                    'oauth' => (string) $reg->accesstokenurl,
                ]);
                $linked = $dep ? ($links[(int) $dep->id] ?? []) : [];
                $c->tools = [];
                foreach ($linked as $toolid) {
                    if (isset($tools[$toolid])) {
                        $c->tools[$toolid] = $tools[$toolid];
                    }
                }
                $c->facts = [
                    'toolsinferred' => 0,
                    'issuerhash' => hash('sha256', (string) $reg->platformid),
                    'toolcount' => count($c->tools),
                ];
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Published enrol_lti tools without secrets.
     *
     * @return array[] Keyed by tool id.
     */
    private function published_tools(): array {
        global $DB;
        $sql = "SELECT t.id, t.gradesync, t.gradesynccompletion, t.membersync, t.membersyncmode,
                       t.provisioningmodelearner, t.provisioningmodeinstructor, t.ltiversion, t.enrolid,
                       e.status AS enrolstatus, e.courseid, e.name AS enrolname
                  FROM {enrol_lti_tools} t
                  JOIN {enrol} e ON e.id = t.enrolid
              ORDER BY t.id";
        $out = [];
        foreach ($DB->get_records_sql($sql) as $t) {
            $out[(int) $t->id] = [
                'id' => (int) $t->id,
                'courseid' => (int) $t->courseid,
                'name' => $t->enrolname !== null && $t->enrolname !== '' ? (string) $t->enrolname : ('#' . $t->id),
                'enabled' => (int) ((int) $t->enrolstatus === ENROL_INSTANCE_ENABLED),
                'gradesync' => (int) $t->gradesync,
                'membersync' => (int) $t->membersync,
                'provisioning' => [(int) $t->provisioningmodelearner, (int) $t->provisioningmodeinstructor],
                'ltiversion' => (string) $t->ltiversion,
            ];
        }
        return $out;
    }

    /**
     * Map of deployment row id => published tool ids reachable through resource links.
     *
     * @return array
     */
    private function links_by_deployment(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('enrol_lti_resource_link')) {
            return [];
        }
        $out = [];
        $rs = $DB->get_recordset_sql("SELECT DISTINCT ltideploymentid, resourceid FROM {enrol_lti_resource_link}");
        foreach ($rs as $r) {
            $out[(int) $r->ltideploymentid][(int) $r->resourceid] = (int) $r->resourceid;
        }
        $rs->close();
        return $out;
    }

    /**
     * Names of custom parameters ("name=value" per line). Values are never returned.
     *
     * @param string $text Custom parameter text.
     * @return string[]
     */
    public static function custom_keys(string $text): array {
        $keys = [];
        foreach (preg_split('/[\r\n;]+/', $text) as $line) {
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = clean_param(trim(substr($line, 0, $pos)), PARAM_ALPHANUMEXT);
            if ($key !== '') {
                $keys[] = \core_text::strtolower($key);
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }
}
