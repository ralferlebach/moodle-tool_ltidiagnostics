<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\local\accesslog\path_allowlist;
use tool_ltidiagnostics\local\active\active_checker;
use tool_ltidiagnostics\local\active\dns_resolver;
use tool_ltidiagnostics\local\active\fetch_result;
use tool_ltidiagnostics\local\active\http_fetcher;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\implementation_detector;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\local\matrix_builder;
use tool_ltidiagnostics\local\probe_runner;
use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/lti_data.php');

/**
 * Evidence tests for the feasibility gates G1 to G7 of the specification (section D).
 *
 * Each test states what the running Moodle offers. Where a gate is negative, the test proves the absence of the
 * extension point so that the documented fallback is justified. They run on every supported Moodle version.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\implementation_detector
 * @covers     \tool_ltidiagnostics\local\probe_runner
 */
final class gates_test extends \advanced_testcase {
    use lti_data;

    /**
     * Class names in a namespace of a component.
     *
     * @param string $component Component.
     * @param string $namespace Sub namespace.
     * @return string[]
     */
    private function classes_in(string $component, string $namespace): array {
        return array_keys(\core_component::get_component_classes_in_namespace($component, $namespace));
    }

    /**
     * G1 (positive part): the passive launch evidence the plugin relies on exists as stable core events.
     */
    public function test_g1_events_used_for_launch_evidence_exist(): void {
        foreach (
            [\core\event\user_loggedin::class, \core\event\user_created::class, \core\event\user_updated::class,
            \core\event\user_enrolment_created::class, \core\event\group_member_added::class, \core\event\user_graded::class,
            \mod_lti\event\course_module_viewed::class, \mod_lti\event\unknown_service_api_called::class] as $class
        ) {
            $this->assertTrue(class_exists($class), $class);
        }
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->assertTrue(class_exists(\auth_plugin_lti::class));
        $this->assertTrue(method_exists(\auth_plugin_lti::class, 'create_user_binding'));
    }

    /**
     * G1 (negative part): there is no core hook or event for the OIDC login, the JWT verification or the launch
     * completion. The plugin therefore cannot show a complete JWT/OIDC trace without a fork (gate stays partly open).
     */
    public function test_g1_no_core_extension_point_for_oidc_or_jwt_steps(): void {
        $hooks = array_merge(
            $this->classes_in('core', 'hook'),
            $this->classes_in('mod_lti', 'hook'),
            $this->classes_in('auth_lti', 'hook'),
            $this->classes_in('enrol_lti', 'hook')
        );
        $this->assertNotEmpty($hooks, 'The hook API itself exists.');
        foreach ($hooks as $class) {
            $this->assertDoesNotMatchRegularExpression('/lti|oidc|jwt|launch/i', $class, "Unexpected LTI hook: $class");
        }
        $events = array_merge(
            $this->classes_in('mod_lti', 'event'),
            $this->classes_in('auth_lti', 'event'),
            $this->classes_in('enrol_lti', 'event')
        );
        foreach ($events as $class) {
            $this->assertDoesNotMatchRegularExpression('/jwt|oidc|login_request|launch_complete/i', $class, $class);
        }
    }

    /**
     * G2: AGS, NRPS and deep linking calls leave no dedicated event or hook; only the unknown-service event exists.
     */
    public function test_g2_no_event_for_service_calls(): void {
        $events = [];
        foreach (
            ['mod_lti', 'ltiservice_gradebookservices', 'ltiservice_memberships', 'ltiservice_toolsettings',
            'ltiservice_profile'] as $component
        ) {
            if (\core_component::get_component_directory($component) === null) {
                continue;
            }
            $events = array_merge($events, $this->classes_in($component, 'event'));
        }
        $short = array_map(function ($c) {
            return substr($c, strrpos($c, '\\') + 1);
        }, $events);
        sort($short);
        $this->assertContains('unknown_service_api_called', $short);
        foreach ($short as $name) {
            $this->assertDoesNotMatchRegularExpression('/score|lineitem|result|membership|roster|deep/i', $name, $name);
        }
        foreach (['ltiservice_gradebookservices', 'ltiservice_memberships'] as $component) {
            $this->assertSame([], $this->classes_in($component, 'hook'));
        }
    }

    /**
     * G2 (what is possible): a grade written while the service script runs is visible as a gradebook event.
     * See observer_test::test_grade_events; here the entry point classification that makes it usable is checked.
     */
    public function test_g2_service_entry_points_are_distinguishable(): void {
        $this->assertSame('lti_services', local\entrypoint::classify('/mod/lti/services.php'));
        $this->assertSame('lti_services', local\entrypoint::classify('/mod/lti/token.php'));
        $this->assertSame('lti_launch', local\entrypoint::classify('/enrol/lti/launch.php'));
        $this->assertFileExists(\core_component::get_component_directory('mod_lti') . '/services.php');
        $this->assertFileExists(\core_component::get_component_directory('mod_lti') . '/token.php');
    }

    /**
     * G3: the log manager hands out readers with the generic reader interface.
     */
    public function test_g3_logstore_reader_interface(): void {
        $this->resetAfterTest();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        $readers = get_log_manager(true)->get_readers('\core\log\sql_reader');
        $this->assertArrayHasKey('logstore_standard', $readers);
        $reader = $readers['logstore_standard'];
        $this->assertInstanceOf(\core\log\sql_reader::class, $reader);
        foreach (
            ['get_events_select', 'get_events_select_count', 'get_events_select_iterator', 'is_logging',
            'get_internal_log_table_name'] as $method
        ) {
            $this->assertTrue(method_exists($reader, $method), $method);
        }
        // A second store plugin is a different reader class: the adapter must not assume the standard table.
        $this->assertTrue(class_exists(\logstore_database\log\store::class));
        $this->assertNotSame(\logstore_database\log\store::class, get_class($reader));
    }

    /**
     * G4: there is no reader API for the task log; the plugin reads the documented table directly.
     */
    public function test_g4_no_task_log_reader_api(): void {
        global $DB;
        $reflection = new \ReflectionClass(\core\task\logmanager::class);
        $public = array_map(function ($m) {
            return $m->getName();
        }, $reflection->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($public as $name) {
            $pattern = '/^(fetch|read|search|list|query|get_logs|get_records|get_log_)/';
            $this->assertDoesNotMatchRegularExpression($pattern, $name, $name);
        }
        $columns = $DB->get_columns('task_log');
        foreach (['type', 'component', 'classname', 'timestart', 'timeend', 'result', 'output'] as $column) {
            $this->assertArrayHasKey($column, $columns, "task_log.$column");
        }
        // The queue and scheduler APIs that the plugin does use are stable.
        foreach (['get_all_scheduled_tasks', 'get_adhoc_tasks_summary'] as $method) {
            $this->assertTrue(method_exists(\core\task\manager::class, $method), $method);
        }
    }

    /**
     * G5: web server logs are reachable only through an explicit allow-list or an upload.
     */
    public function test_g5_access_logs_need_an_allow_list(): void {
        $this->resetAfterTest();
        $this->assertSame('', (string) get_config('tool_ltidiagnostics', 'logallowlist'));
        $this->assertSame([], local\config::get_list('logallowlist'));
        $this->assertNull(path_allowlist::resolve('/etc/passwd', local\config::get_list('logallowlist')));
        $dir = make_request_directory();
        file_put_contents("$dir/access.log", 'x');
        $this->assertNull(path_allowlist::resolve("$dir/access.log", local\config::get_list('logallowlist')));
        set_config('logallowlist', "$dir\n", 'tool_ltidiagnostics');
        $this->assertNotNull(path_allowlist::resolve("$dir/access.log", local\config::get_list('logallowlist')));
    }

    /**
     * G6: local settings are queryable through the LTI APIs; remote ones are reported as not observable.
     */
    public function test_g6_local_versus_remote_settings(): void {
        $this->resetAfterTest();
        $typeid = $this->create_platform_type();
        $c = (new inventory())->get_connection("platform:$typeid");
        $this->assertSame(2, $c->facts['ags'], 'The local switch is readable.');
        $this->assertContains('ltiservice_gradebookservices', $c->facts['services']);
        $facts = [
            'implemented' => implementation_detector::detect()['platform'],
            'obs' => ['launch' => 0, 'enrolment' => 0, 'group' => 0, 'grade_in' => 0, 'grade_local' => 0, 'view_out' => 0],
            'access' => [], 'tokens' => ['ags' => 0, 'nrps' => 0, 'any' => 0],
        ];
        $matrix = (new matrix_builder())->build($c, $facts);
        foreach (['ags', 'nrps', 'deeplinking'] as $feature) {
            $this->assertSame('not_observable', $matrix[$feature]['ladder']['declared']->state, $feature);
        }
        $this->assertSame('yes', $matrix['ags']['ladder']['enabled']->state);
    }

    /**
     * G6: the implementation detection agrees with the code of the running Moodle.
     */
    public function test_g6_implementation_detection(): void {
        $detected = implementation_detector::detect();
        $this->assertSame(['platform', 'tool'], array_keys($detected));
        foreach ([connection::ROLE_PLATFORM, connection::ROLE_TOOL] as $role) {
            $this->assertSame(matrix_builder::FEATURES, array_keys($detected[$role]), $role);
            $this->assertTrue($detected[$role]['lti_core'], $role);
            $this->assertTrue($detected[$role]['ags'], $role);
            $this->assertTrue($detected[$role]['nrps'], $role);
            $this->assertTrue($detected[$role]['deeplinking'], $role);
        }
        // Features that these Moodle versions do not implement are reported as such and so never "supported".
        $this->assertFalse($detected['platform']['platform_storage']);
        $this->assertFalse($detected['tool']['platform_storage']);
        $this->assertFalse($detected['platform']['course_group']);
        $this->assertFalse($detected['tool']['course_group']);
        $this->assertTrue($detected['platform']['submission_review']);
        $this->assertFalse($detected['tool']['submission_review']);
    }

    /**
     * G7: the active checks cannot mutate anything: the transport has one method and issues only GET requests.
     */
    public function test_g7_active_checks_are_read_only(): void {
        global $CFG;
        $methods = array_map(function ($m) {
            return $m->getName();
        }, (new \ReflectionClass(http_fetcher::class))->getMethods());
        $this->assertSame(['get'], $methods);
        foreach (glob($CFG->dirroot . '/admin/tool/ltidiagnostics/classes/local/active/*.php') as $file) {
            $source = file_get_contents($file);
            $pattern = '/CURLOPT_(POST|POSTFIELDS|CUSTOMREQUEST|PUT|UPLOAD)/';
            $this->assertDoesNotMatchRegularExpression($pattern, $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/->(post|put|delete|patch|head)\s*\(/i', $source, basename($file));
        }
        $this->assertStringContainsString('->get(', file_get_contents(
            $CFG->dirroot . '/admin/tool/ltidiagnostics/classes/local/active/curl_fetcher.php'
        ));
    }

    /**
     * G7: the probe runner needs its capability, honours the global switch, checks only selected endpoints and audits.
     */
    public function test_g7_probe_runner_rules(): void {
        global $DB;
        $this->resetAfterTest();
        $typeid = $this->create_platform_type();
        $c = (new inventory())->get_connection("platform:$typeid");

        $fetcher = new class implements http_fetcher {
            /** @var string[] Requested URLs. */
            public $urls = [];

            /**
             * Fake GET.
             *
             * @param string $url URL.
             * @param bool $allowprivate Whether private targets are allowed.
             * @return fetch_result
             */
            public function get(string $url, bool $allowprivate): fetch_result {
                $this->urls[] = $url;
                $r = new fetch_result();
                $r->httpstatus = 200;
                $r->body = '{"keys":[{"kty":"RSA","kid":"k1"}]}';
                return $r;
            }
        };
        $dns = new class implements dns_resolver {
            /**
             * Fake resolve.
             *
             * @param string $host Host.
             * @return array|null
             */
            public function resolve(string $host): ?array {
                return ['93.184.216.34'];
            }
        };
        $checker = new active_checker($fetcher, $dns, false);
        $runner = new probe_runner();

        // Without the capability.
        $this->setUser($this->getDataGenerator()->create_user());
        try {
            $runner->run($c, ['jwks'], '', '', time(), $checker);
            $this->fail('The capability is required');
        } catch (\required_capability_exception $e) {
            $this->assertSame([], $fetcher->urls);
        }

        $this->setAdminUser();
        // Switched off.
        set_config('activechecks', 0, 'tool_ltidiagnostics');
        try {
            $runner->run($c, ['jwks'], '', '', time(), $checker);
            $this->fail('The global switch must stop the checks');
        } catch (\moodle_exception $e) {
            $this->assertSame('activechecksdisabled', $e->errorcode);
            $this->assertSame([], $fetcher->urls);
        }

        // Switched on: only the selected, known endpoint and a safe custom address are requested.
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        $results = $runner->run($c, ['jwks', 'bogus', 'oauth'], 'https://custom.example.org/probe', 'k1', time(), $checker);
        $this->assertSame(['https://tool.example.org/jwks.json', 'https://custom.example.org/probe'], $fetcher->urls);
        $this->assertSame('ok', $results['jwks']['code']);
        $this->assertTrue($results['jwks']['jwks']['haskid']);
        $this->assertArrayNotHasKey('bogus', $results);
        $this->assertArrayHasKey('custom', $results);
        $this->assertTrue($DB->record_exists(
            'tool_ltidiagnostics_audit',
            ['action' => 'runchecks', 'connkey' => "platform:$typeid"]
        ));

        // A custom address into the internal network is refused before any request.
        $fetcher->urls = [];
        $results = $runner->run($c, [], 'https://127.0.0.1:8080/admin', '', time(), $checker);
        $this->assertSame('blocked_by_policy', $results['custom']['code']);
        $this->assertSame([], $fetcher->urls);
        $results = $runner->run($c, [], 'https://user:pw@custom.example.org/', '', time(), $checker);
        $this->assertSame('credentials', $results['custom']['blockreason']);
        $results = $runner->run($c, [], 'http://custom.example.org/', '', time(), $checker);
        $this->assertSame('scheme', $results['custom']['blockreason']);
        $this->assertSame([], $fetcher->urls);
    }

    /**
     * G7 (not implemented, stated honestly): there is no state-changing self-test in this release.
     */
    public function test_g7_no_mutating_test_context_exists(): void {
        $classes = $this->classes_in('tool_ltidiagnostics', 'local\\active');
        foreach ($classes as $class) {
            foreach (get_class_methods($class) as $method) {
                $pattern = '/send_score|write_|create_lineitem|mutat/i';
                $this->assertDoesNotMatchRegularExpression($pattern, $method, "$class::$method");
            }
        }
        $this->assertFalse(get_string_manager()->string_exists('mutating', 'tool_ltidiagnostics'));
    }
}
