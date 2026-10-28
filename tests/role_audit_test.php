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

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\partner_service;
use tool_ltidiagnostics\local\transfer_service;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/lti_data.php');
require_once(__DIR__ . '/fixtures/family_events.php');

/**
 * Role and privacy audit of the 0.3.0 actions (audit items DIA-04, FAM-04): every partner and transfer action needs
 * its capability, a capability for one action never opens another, every action is audited with the acting user, and
 * the exported documents contain no names, e-mail addresses or user ids.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\partner_service
 * @covers     \tool_ltidiagnostics\local\transfer_service
 * @covers     \tool_ltidiagnostics\local\transfer_report
 */
final class role_audit_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use lti_data;

    /**
     * A user holding exactly the given capabilities in the system context.
     *
     * @param string[] $caps Short names.
     * @return \stdClass
     */
    private function user_with(array $caps): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($caps as $cap) {
            assign_capability('tool/ltidiagnostics:' . $cap, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    /**
     * Whether an action is allowed for the current user.
     *
     * @param callable $fn Action.
     * @return bool
     */
    private function allowed(callable $fn): bool {
        try {
            $fn();
            return true;
        } catch (\required_capability_exception $e) {
            return false;
        }
    }

    /**
     * The capability matrix of the actions.
     */
    public function test_capability_matrix(): void {
        $this->resetAfterTest();
        $reg = $this->create_registration();
        $tool = (new inventory())->get_connection(connection::make_key(connection::ROLE_TOOL, $reg->regid, $reg->depid));
        $typeid = $this->create_platform_type();
        $platform = (new inventory())->get_connection(connection::make_key(connection::ROLE_PLATFORM, $typeid));
        $this->setAdminUser();
        $package = json_encode(partner_service::export_package($tool, time()));
        $trace = json_encode(transfer_service::export_trace($tool, [], time()));
        $actions = [
            'export_package' => function () use ($tool) {
                partner_service::export_package($tool, time());
            },
            'import_package' => function () use ($platform, $package) {
                partner_service::import_package($platform, $package, time());
            },
            'live_check' => function () use ($platform) {
                partner_service::live($platform, time());
            },
            'transfer_report' => function () use ($tool) {
                transfer_service::report($tool, [], time());
            },
            'export_trace' => function () use ($tool) {
                transfer_service::export_trace($tool, [], time());
            },
            'import_trace' => function () use ($platform, $trace) {
                transfer_service::import_trace($platform, $trace, time());
            },
        ];
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        $matrix = [
            'view' => ['transfer_report'],
            'export' => ['export_package'],
            'configure' => ['import_package', 'import_trace'],
            'runchecks' => ['live_check'],
            'viewsupport' => [],
            'viewprivacy' => [],
            'viewcourse' => [],
        ];
        foreach ($matrix as $cap => $expected) {
            $this->setUser($this->user_with([$cap]));
            $got = [];
            foreach ($actions as $name => $fn) {
                if ($this->allowed($fn)) {
                    $got[] = $name;
                }
            }
            $this->assertSame($expected, $got, "Capability $cap");
        }
        // The trace export reads the log stores, which needs "view" as well (like the report export).
        $this->setUser($this->user_with(['view', 'export']));
        $this->assertTrue($this->allowed($actions['export_trace']));
        // Students and teachers in a course hold none of them.
        $course = $this->getDataGenerator()->create_course();
        foreach (['student', 'editingteacher'] as $role) {
            $this->setUser($this->getDataGenerator()->create_and_enrol($course, $role));
            foreach ($actions as $name => $fn) {
                $this->assertFalse($this->allowed($fn), "$role: $name");
            }
        }
    }

    /**
     * Every action is audited with the acting user; the exported documents contain no names, e-mail addresses or user ids.
     */
    public function test_audit_and_data_minimisation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $reg = $this->create_registration();
        $tool = (new inventory())->get_connection(connection::make_key(connection::ROLE_TOOL, $reg->regid, $reg->depid));
        $learner = $this->getDataGenerator()->create_user(['firstname' => 'Zelda', 'lastname' => 'Quuxington',
            'email' => 'zelda.quux@example.org']);
        \local_ltidiagfixture\event\message_queued::fire('cid-min', (int) $learner->id);
        \local_ltidiagfixture\event\message_sent::fire('cid-min', (int) $learner->id);
        $actor = $this->user_with(['view', 'export', 'configure']);
        $this->setUser($actor);
        $docs = [
            json_encode(partner_service::export_package($tool, time())),
            json_encode(transfer_service::export_trace($tool, [], time())),
            json_encode(transfer_service::report($tool, [], time())),
        ];
        foreach ($docs as $doc) {
            $this->assertStringNotContainsString('Zelda', $doc);
            $this->assertStringNotContainsString('Quuxington', $doc);
            $this->assertStringNotContainsString('zelda.quux', $doc);
            $this->assertStringNotContainsString('"userid"', $doc);
            $this->assertStringNotContainsString('"relateduserid"', $doc);
        }
        $this->assertStringContainsString('cid-min', $docs[1]);
        foreach (['partner_export', 'trace_export'] as $action) {
            $this->assertTrue(
                $DB->record_exists('tool_ltidiagnostics_audit', ['action' => $action, 'userid' => $actor->id]),
                $action
            );
        }
    }
}
