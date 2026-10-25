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

use tool_ltidiagnostics\local\desired_profile;
use tool_ltidiagnostics\local\matrix_builder;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form to edit the desired-state profile of a connection, or to import it as JSON.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'key', $this->_customdata['key']);
        $mform->setType('key', PARAM_RAW);

        $mform->addElement('text', 'name', get_string('profilename', 'tool_ltidiagnostics'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);

        $mform->addElement('header', 'wantedhdr', get_string('wantedfeatures', 'tool_ltidiagnostics'));
        foreach (matrix_builder::FEATURES as $feature) {
            $mform->addElement('advcheckbox', 'wanted_' . $feature, get_string('feature_' . $feature, 'tool_ltidiagnostics'));
        }
        $mform->addElement('header', 'scopeshdr', get_string('wantedscopes', 'tool_ltidiagnostics'));
        foreach (desired_profile::AGS_SCOPES as $scope) {
            $mform->addElement('advcheckbox', 'scope_' . str_replace('.', '_', $scope), $scope);
        }
        $mform->addElement('header', 'expecthdr', get_string('expectations', 'tool_ltidiagnostics'));
        $options = [
            '0' => get_string('expect_unset', 'tool_ltidiagnostics'),
            '1' => get_string('expect_yes', 'tool_ltidiagnostics'),
            '2' => get_string('expect_no', 'tool_ltidiagnostics'),
        ];
        foreach (desired_profile::EXPECTATIONS as $e) {
            $mform->addElement('select', 'expect_' . $e, get_string('expect_' . $e, 'tool_ltidiagnostics'), $options);
            $mform->setType('expect_' . $e, PARAM_INT);
        }
        $mform->addElement('header', 'jsonhdr', get_string('importjson', 'tool_ltidiagnostics'));
        $mform->addElement('textarea', 'json', get_string('profilejson', 'tool_ltidiagnostics'), ['rows' => 8, 'cols' => 70]);
        $mform->setType('json', PARAM_RAW);
        $mform->addHelpButton('json', 'profilejson', 'tool_ltidiagnostics');
        $this->add_action_buttons();
    }

    /**
     * Translate the submitted data into profile JSON. A pasted JSON document wins over the fields.
     *
     * @param \stdClass $data Submitted data.
     * @return string JSON
     */
    public static function data_to_json(\stdClass $data): string {
        if (isset($data->json) && trim($data->json) !== '') {
            return $data->json;
        }
        $wanted = [];
        foreach (matrix_builder::FEATURES as $feature) {
            if (!empty($data->{'wanted_' . $feature})) {
                $wanted[] = $feature;
            }
        }
        $scopes = [];
        foreach (desired_profile::AGS_SCOPES as $scope) {
            if (!empty($data->{'scope_' . str_replace('.', '_', $scope)})) {
                $scopes[] = $scope;
            }
        }
        $expect = [];
        foreach (desired_profile::EXPECTATIONS as $e) {
            $v = (int) ($data->{'expect_' . $e} ?? 0);
            if ($v === 1 || $v === 2) {
                $expect[$e] = $v === 1;
            }
        }
        return json_encode(['schema' => desired_profile::SCHEMA, 'name' => $data->name, 'wanted' => $wanted,
            'ags_scopes' => $scopes, 'expect' => (object) $expect]);
    }

    /**
     * Default form values from a stored profile.
     *
     * @param desired_profile|null $p Profile.
     * @return array
     */
    public static function profile_to_defaults(?desired_profile $p): array {
        if (!$p) {
            return [];
        }
        $d = ['name' => $p->name];
        foreach (matrix_builder::FEATURES as $feature) {
            $d['wanted_' . $feature] = (int) $p->wants($feature);
        }
        foreach (desired_profile::AGS_SCOPES as $scope) {
            $d['scope_' . str_replace('.', '_', $scope)] = (int) in_array($scope, $p->agsscopes, true);
        }
        foreach (desired_profile::EXPECTATIONS as $e) {
            $d['expect_' . $e] = !isset($p->expect[$e]) ? 0 : ($p->expect[$e] ? 1 : 2);
        }
        return $d;
    }

    /**
     * Server side validation.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        [$profile, $problems] = desired_profile::from_json(self::data_to_json((object) $data));
        if ($profile === null) {
            $codes = array_map(function ($p) {
                return get_string('profileerror_' . $p[0], 'tool_ltidiagnostics', $p[1]);
            }, $problems);
            $errors[!empty($data['json']) ? 'json' : 'name'] = implode(' ', $codes);
        }
        return $errors;
    }
}
