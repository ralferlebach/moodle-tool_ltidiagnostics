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

/**
 * Admin tree entries and settings of tool_ltidiagnostics.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (
    $hassiteconfig || has_capability('tool/ltidiagnostics:view', context_system::instance())
        || has_capability('tool/ltidiagnostics:viewsupport', context_system::instance())
) {
    // Moodle 4.5 creates the 'tools' category only for users with moodle/site:config (5.x always), so a delegated
    // viewer would otherwise get no admin tree entry and "Access denied" from admin_externalpage_setup().
    $toolsparent = $ADMIN->locate('tools') ? 'tools' : 'root';
    $ADMIN->add($toolsparent, new admin_category('tool_ltidiagnostics_category', get_string('pluginname', 'tool_ltidiagnostics')));

    $ADMIN->add('tool_ltidiagnostics_category', new admin_externalpage(
        'tool_ltidiagnostics_index',
        get_string('connections', 'tool_ltidiagnostics'),
        new moodle_url('/admin/tool/ltidiagnostics/index.php'),
        ['tool/ltidiagnostics:view']
    ));
    $ADMIN->add('tool_ltidiagnostics_category', new admin_externalpage(
        'tool_ltidiagnostics_support',
        get_string('support', 'tool_ltidiagnostics'),
        new moodle_url('/admin/tool/ltidiagnostics/support.php'),
        ['tool/ltidiagnostics:viewsupport']
    ));
    $ADMIN->add('tool_ltidiagnostics_category', new admin_externalpage(
        'tool_ltidiagnostics_logimport',
        get_string('logimport', 'tool_ltidiagnostics'),
        new moodle_url('/admin/tool/ltidiagnostics/logimport.php'),
        ['tool/ltidiagnostics:importlogs']
    ));
    $ADMIN->add('tool_ltidiagnostics_category', new admin_externalpage(
        'tool_ltidiagnostics_audit',
        get_string('auditlog', 'tool_ltidiagnostics'),
        new moodle_url('/admin/tool/ltidiagnostics/audit.php'),
        ['tool/ltidiagnostics:viewprivacy']
    ));

    $settings = new admin_settingpage(
        'tool_ltidiagnostics_settings',
        get_string('settings', 'tool_ltidiagnostics'),
        'moodle/site:config'
    );
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'tool_ltidiagnostics/recordevents',
            get_string('setting_recordevents', 'tool_ltidiagnostics'),
            get_string('setting_recordevents_desc', 'tool_ltidiagnostics'),
            1
        ));
        $settings->add(new admin_setting_configtext(
            'tool_ltidiagnostics/retentiondays',
            get_string('setting_retentiondays', 'tool_ltidiagnostics'),
            get_string('setting_retentiondays_desc', 'tool_ltidiagnostics'),
            30,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtextarea(
            'tool_ltidiagnostics/logallowlist',
            get_string('setting_logallowlist', 'tool_ltidiagnostics'),
            get_string('setting_logallowlist_desc', 'tool_ltidiagnostics'),
            '',
            PARAM_RAW,
            60,
            4
        ));
        $settings->add(new admin_setting_configtext(
            'tool_ltidiagnostics/maxlinesperimport',
            get_string('setting_maxlines', 'tool_ltidiagnostics'),
            get_string('setting_maxlines_desc', 'tool_ltidiagnostics'),
            50000,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configcheckbox(
            'tool_ltidiagnostics/activechecks',
            get_string('setting_activechecks', 'tool_ltidiagnostics'),
            get_string('setting_activechecks_desc', 'tool_ltidiagnostics'),
            0
        ));
        $settings->add(new admin_setting_configcheckbox(
            'tool_ltidiagnostics/allowprivatetargets',
            get_string('setting_allowprivate', 'tool_ltidiagnostics'),
            get_string('setting_allowprivate_desc', 'tool_ltidiagnostics'),
            0
        ));
        $settings->add(new admin_setting_configtextarea(
            'tool_ltidiagnostics/privatetargetallowlist',
            get_string('setting_privatetargetallowlist', 'tool_ltidiagnostics'),
            get_string('setting_privatetargetallowlist_desc', 'tool_ltidiagnostics'),
            '',
            PARAM_RAW,
            60,
            3
        ));
        $settings->add(new admin_setting_configtext(
            'tool_ltidiagnostics/importratelimit',
            get_string('setting_importratelimit', 'tool_ltidiagnostics'),
            get_string('setting_importratelimit_desc', 'tool_ltidiagnostics'),
            10,
            PARAM_INT
        ));
        foreach (
            ['retentionaccdays' => 30, 'retentionauditdays' => 365, 'retentionsnapdays' => 365,
                'retentionpartnerdays' => 30] as $name => $default
        ) {
            $settings->add(new admin_setting_configtext(
                'tool_ltidiagnostics/' . $name,
                get_string('setting_' . $name, 'tool_ltidiagnostics'),
                get_string('setting_' . $name . '_desc', 'tool_ltidiagnostics'),
                $default,
                PARAM_INT
            ));
        }
        $settings->add(new admin_setting_configtext(
            'tool_ltidiagnostics/diagmodemaxminutes',
            get_string('setting_diagmodemax', 'tool_ltidiagnostics'),
            get_string('setting_diagmodemax_desc', 'tool_ltidiagnostics'),
            240,
            PARAM_INT
        ));
    }
    $ADMIN->add('tool_ltidiagnostics_category', $settings);
}
