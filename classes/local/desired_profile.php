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
 * The desired-state profile: what an administrator wants the connection to do.
 *
 * Non-binding. It is compared with the local, remote, authorised and observed state and never
 * changes any setting, here or elsewhere.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class desired_profile {
    /** @var int Schema version of the JSON document. */
    public const SCHEMA = 1;
    /** @var string[] AGS scopes (suffix after .../lti-ags/scope/). */
    public const AGS_SCOPES = ['lineitem', 'lineitem.readonly', 'result.readonly', 'score'];
    /** @var string[] Boolean expectations. */
    public const EXPECTATIONS = ['send_name', 'send_email', 'pseudonymised_sub', 'gradebook_update', 'enrolment', 'groups'];

    /** @var string Display name. */
    public $name = '';
    /** @var string[] Wanted features (ids of matrix_builder::FEATURES). */
    public $wanted = [];
    /** @var string[] Wanted AGS scopes. */
    public $agsscopes = [];
    /** @var array Expectations: name => bool. Missing means no expectation. */
    public $expect = [];

    /**
     * Parse and validate a JSON document.
     *
     * @param string $json JSON text.
     * @return array [profile|null, errors (array of [code, param])]
     */
    public static function from_json(string $json): array {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [null, [['notjson', '']]];
        }
        return self::from_array($data);
    }

    /**
     * Validate a decoded document.
     *
     * @param array $data Decoded document.
     * @return array [profile|null, errors]
     */
    public static function from_array(array $data): array {
        $errors = [];
        $p = new self();
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = ['schema', (string) ($data['schema'] ?? '')];
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, ['schema', 'name', 'wanted', 'ags_scopes', 'expect'], true)) {
                $errors[] = ['badkey', \core_text::substr(clean_param((string) $key, PARAM_ALPHANUMEXT), 0, 40)];
            }
        }
        $p->name = isset($data['name']) && is_string($data['name']) ? \core_text::substr(
            clean_param($data['name'], PARAM_TEXT),
            0,
            255
        ) : '';
        if ($p->name === '') {
            $errors[] = ['noname', ''];
        }
        foreach ((array) ($data['wanted'] ?? []) as $feature) {
            if (is_string($feature) && in_array($feature, matrix_builder::FEATURES, true)) {
                $p->wanted[$feature] = $feature;
            } else {
                $errors[] = ['badfeature', is_scalar($feature) ? (string) $feature : gettype($feature)];
            }
        }
        $p->wanted = array_values($p->wanted);
        foreach ((array) ($data['ags_scopes'] ?? []) as $scope) {
            if (is_string($scope) && in_array($scope, self::AGS_SCOPES, true)) {
                $p->agsscopes[$scope] = $scope;
            } else {
                $errors[] = ['badscope', is_scalar($scope) ? (string) $scope : gettype($scope)];
            }
        }
        $p->agsscopes = array_values($p->agsscopes);
        foreach ((array) ($data['expect'] ?? []) as $key => $value) {
            if (in_array($key, self::EXPECTATIONS, true) && is_bool($value)) {
                $p->expect[$key] = $value;
            } else {
                $errors[] = ['badexpect', (string) $key];
            }
        }
        if ($p->agsscopes && !in_array('ags', $p->wanted, true)) {
            $errors[] = ['scopesnoags', ''];
        }
        return [$errors ? null : $p, $errors];
    }

    /**
     * Export as JSON.
     *
     * @return string
     */
    public function to_json(): string {
        return json_encode([
            'schema' => self::SCHEMA,
            'name' => $this->name,
            'wanted' => $this->wanted,
            'ags_scopes' => $this->agsscopes,
            'expect' => (object) $this->expect,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether a feature is wanted.
     *
     * @param string $feature Feature id.
     * @return bool
     */
    public function wants(string $feature): bool {
        return in_array($feature, $this->wanted, true);
    }

    /**
     * Whether an expectation is explicitly true.
     *
     * @param string $name Expectation name.
     * @return bool
     */
    public function expects(string $name): bool {
        return !empty($this->expect[$name]);
    }
}
