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

namespace tool_ltidiagnostics\local\active;

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\probe_runner;

/**
 * The probe runner: capabilities, the privileged private-target mode, audit, and the data that a check reports about
 * its transport (audit item DIA-04).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\probe_runner
 * @covers     \tool_ltidiagnostics\local\active\active_checker
 */
final class probe_runner_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * A tool connection with one probeable endpoint.
     *
     * @return connection
     */
    private function connection(): connection {
        $c = new connection();
        $c->role = connection::ROLE_TOOL;
        $c->key = 'tool:3:4';
        $c->endpoints = ['jwks' => 'https://p.example.org/jwks'];
        return $c;
    }

    /**
     * A checker that records its calls and never touches the network.
     *
     * @param \ArrayObject $log Receives the checked URLs.
     * @return active_checker
     */
    private function checker(\ArrayObject $log): active_checker {
        return new class ($log) extends active_checker {
            /** @var \ArrayObject Log. */
            private $log;

            /**
             * Constructor.
             *
             * @param \ArrayObject $log Log.
             */
            public function __construct(\ArrayObject $log) {
                $this->log = $log;
            }

            /**
             * Fake check.
             *
             * @param string $url URL.
             * @param string $purpose Purpose.
             * @param int $now Time.
             * @param string $expectedkid Key id.
             * @return array
             */
            public function check(string $url, string $purpose, int $now, string $expectedkid = ''): array {
                $this->log[] = $url;
                return ['url' => $url, 'purpose' => $purpose, 'code' => 'ok', 'checkedat' => $now];
            }
        };
    }

    /**
     * A user with the given capabilities in the system context.
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
     * The checks need their capability and the switch in the settings.
     */
    public function test_requires_capability_and_setting(): void {
        $this->resetAfterTest();
        $log = new \ArrayObject();
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        $this->setUser($this->user_with(['view']));
        try {
            (new probe_runner())->run($this->connection(), ['jwks'], '', '', time(), $this->checker($log));
            $this->fail('A viewer must not run active checks.');
        } catch (\required_capability_exception $e) {
            $this->assertCount(0, $log);
        }
        $this->setUser($this->user_with(['runchecks']));
        set_config('activechecks', 0, 'tool_ltidiagnostics');
        $this->expectException(\moodle_exception::class);
        (new probe_runner())->run($this->connection(), ['jwks'], '', '', time(), $this->checker($log));
    }

    /**
     * Normal mode: the runchecks capability is enough, the audit says "runchecks".
     */
    public function test_normal_mode_audit(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        set_config('allowprivatetargets', 0, 'tool_ltidiagnostics');
        $user = $this->user_with(['runchecks']);
        $this->setUser($user);
        $log = new \ArrayObject();
        $results = (new probe_runner())->run(
            $this->connection(),
            ['jwks', 'unknown'],
            'https://x.example.org/y',
            '',
            1000,
            $this->checker($log)
        );
        $this->assertSame(['https://p.example.org/jwks', 'https://x.example.org/y'], $log->getArrayCopy());
        $this->assertSame(['jwks', 'custom'], array_keys($results));
        $audit = $DB->get_record('tool_ltidiagnostics_audit', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame('runchecks', $audit->action);
        $this->assertSame('checks=2', $audit->detail);
        $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit', ['action' => 'runchecks_private']));
    }

    /**
     * Private mode: the extra capability is required, a refusal runs nothing, and a run is audited separately.
     */
    public function test_private_mode_needs_extra_capability_and_is_audited(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('activechecks', 1, 'tool_ltidiagnostics');
        set_config('allowprivatetargets', 1, 'tool_ltidiagnostics');
        $log = new \ArrayObject();
        $this->setUser($this->user_with(['runchecks']));
        try {
            (new probe_runner())->run($this->connection(), ['jwks'], '', '', time(), $this->checker($log));
            $this->fail('Private mode needs tool/ltidiagnostics:runprivatechecks.');
        } catch (\required_capability_exception $e) {
            $this->assertCount(0, $log, 'Nothing may run before the capability check.');
            $this->assertSame(0, $DB->count_records('tool_ltidiagnostics_audit', ['action' => 'runchecks']));
        }
        $user = $this->user_with(['runchecks', 'runprivatechecks']);
        $this->setUser($user);
        (new probe_runner())->run($this->connection(), ['jwks'], '', '', time(), $this->checker($log));
        $this->assertCount(1, $log);
        $audit = $DB->get_record('tool_ltidiagnostics_audit', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame('runchecks_private', $audit->action);
    }

    /**
     * Only the administrator holds the private capability by default.
     */
    public function test_private_capability_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertTrue(has_capability('tool/ltidiagnostics:runprivatechecks', \context_system::instance()));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(has_capability('tool/ltidiagnostics:runprivatechecks', \context_system::instance()));
    }

    /**
     * The checker reports why a request was refused during the transfer (pin mismatch, redirect limit) and whether
     * the proxy path was used.
     */
    public function test_checker_reports_transport_facts(): void {
        $this->resetAfterTest();
        $fetcher = new class implements http_fetcher {
            /** @var fetch_result Result. */
            public $result;

            /**
             * Fake get.
             *
             * @param string $url URL.
             * @param bool $allowprivate Private mode.
             * @return fetch_result
             */
            public function get(string $url, bool $allowprivate): fetch_result {
                return $this->result;
            }
        };
        $resolver = new class implements dns_resolver {
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
        $checker = new active_checker($fetcher, $resolver, false);
        $fetcher->result = new fetch_result();
        $fetcher->result->blocked = 'pin_mismatch';
        $out = $checker->check('https://tool.example.org/jwks', active_checker::PURPOSE_JWKS, 1000);
        $this->assertSame('blocked_by_policy', $out['code']);
        $this->assertSame('pin_mismatch', $out['blockreason']);
        $this->assertNull($out['jwks'], 'Nothing is analysed from a discarded answer.');

        $fetcher->result = new fetch_result();
        $fetcher->result->httpstatus = 200;
        $fetcher->result->body = '{"keys":[]}';
        $fetcher->result->info = ['viaproxy' => true, 'pinned' => false];
        $out = $checker->check('https://tool.example.org/jwks', active_checker::PURPOSE_JWKS, 1000);
        $this->assertTrue($out['viaproxy']);
        $this->assertFalse($out['pinned']);
        $this->assertSame('', $out['blockreason']);

        $fetcher->result = new fetch_result();
        $fetcher->result->httpstatus = 200;
        $fetcher->result->info = ['viaproxy' => false, 'pinned' => true];
        $out = $checker->check('https://tool.example.org/jwks', active_checker::PURPOSE_ENDPOINT, 1000);
        $this->assertFalse($out['viaproxy']);
        $this->assertTrue($out['pinned']);
    }
}
