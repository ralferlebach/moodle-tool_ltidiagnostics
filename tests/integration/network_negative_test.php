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

namespace tool_ltidiagnostics\integration;

use tool_ltidiagnostics\local\active\active_checker;
use tool_ltidiagnostics\local\active\curl_fetcher;
use tool_ltidiagnostics\local\active\dns_resolver;
use tool_ltidiagnostics\local\active\system_dns_resolver;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\finding;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\local\inventory;
use tool_ltidiagnostics\tests\lti_data;
use tool_ltidiagnostics\tests\netlab;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/lti_data.php');

/**
 * Reproducible negative integration tests of the network layers (audit item DIA-03): real TLS servers with minted bad
 * certificates, a real proxy that answers 407, a real listener that drops connections, the real system resolver for
 * an NXDOMAIN name, and redirects towards private targets. The plugin's real fetcher (Moodle curl) does every request.
 *
 * Each case asserts the four parts of the diagnosis (cause, uncertainty, responsible system, safe check) and that the
 * health check changed nothing in the LTI configuration (no productive mutation).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\active\curl_fetcher
 * @covers     \tool_ltidiagnostics\local\active\active_checker
 * @covers     \tool_ltidiagnostics\local\active\error_classifier
 * @covers     \tool_ltidiagnostics\local\health_check
 * @covers     \tool_ltidiagnostics\local\finding
 */
final class network_negative_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;
    use lti_data;

    /** @var string Laboratory host name (resolved to 127.0.0.1 by the laboratory resolver). */
    private const HOST = 'lab.diag.test';

    /** @var netlab|null */
    private $lab = null;

    /**
     * Stop the laboratory.
     */
    protected function tearDown(): void {
        if ($this->lab) {
            $this->lab->stop();
            $this->lab = null;
        }
        parent::tearDown();
        $this->check_deprecation_guard();
    }

    /**
     * Open the guard frame (this class has its own tearDown).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->start_deprecation_guard();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->lab = new netlab();
    }

    /**
     * Resolver of the laboratory: *.diag.test points to 127.0.0.1, everything else goes to the system resolver.
     *
     * @return dns_resolver
     */
    private function resolver(): dns_resolver {
        return new class implements dns_resolver {
            /**
             * Resolve.
             *
             * @param string $host Host.
             * @return string[]|null
             */
            public function resolve(string $host): ?array {
                if (substr($host, -strlen('.diag.test')) === '.diag.test') {
                    return ['127.0.0.1'];
                }
                return (new system_dns_resolver())->resolve($host);
            }
        };
    }

    /**
     * A checker that trusts root A of the laboratory and allows exactly the given ports of the laboratory host.
     *
     * @param int[] $ports Allowed ports.
     * @param int $connecttimeout Connect timeout.
     * @return active_checker
     */
    private function checker(array $ports, int $connecttimeout = 3): active_checker {
        $exact = array_map(function ($p) {
            return self::HOST . ':' . $p;
        }, $ports);
        $dns = $this->resolver();
        $fetcher = new curl_fetcher($dns, $exact, ['cainfo' => $this->lab->path('rootA.crt'), 'connecttimeout' => $connecttimeout,
            'timeout' => 5]);
        return new active_checker($fetcher, $dns, false, $exact);
    }

    /**
     * Start a TLS endpoint.
     *
     * @param string $ca Issuer.
     * @param array $opts hosts, expired, withchain, routes, skew.
     * @return int Port.
     */
    private function tls(string $ca, array $opts = []): int {
        $cert = $this->lab->server_cert(
            'srv' . random_string(4),
            $ca,
            $opts['hosts'] ?? [self::HOST],
            !empty($opts['expired']),
            !empty($opts['withchain'])
        );
        return $this->lab->start(['mode' => 'https', 'routes' => $opts['routes'] ?? ['/jwks' => ['status' => 200,
            'body' => json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'e' => 'AQAB', 'n' => 'abc']]])]],
            'skew' => (int) ($opts['skew'] ?? 0)] + $cert);
    }

    /**
     * Snapshot of the LTI configuration tables (to prove that a check changed nothing).
     *
     * @return string
     */
    private function lti_state(): string {
        global $DB;
        $out = [];
        $tables = ['lti_types', 'lti_types_config', 'enrol_lti_app_registration', 'enrol_lti_deployment', 'lti_access_tokens'];
        foreach ($tables as $t) {
            $out[$t] = $DB->get_records($t, null, 'id');
        }
        return sha1(json_encode($out));
    }

    /**
     * Run the health check of a real platform connection with one probe and return the finding with the code.
     *
     * @param array $probe Probe result.
     * @param string $code Expected finding code.
     * @return finding
     */
    private function diagnose(array $probe, string $code): finding {
        $typeid = $this->create_platform_type();
        $conn = (new inventory())->get_connection(connection::make_key(connection::ROLE_PLATFORM, $typeid));
        $before = $this->lti_state();
        $result = (new health_check())->run($conn, time(), ['probes' => ['jwks' => $probe]]);
        $this->assertSame($before, $this->lti_state(), 'The health check must not change the LTI configuration.');
        $found = null;
        foreach ($result['findings'] as $f) {
            if ($f->code === $code) {
                $found = $f;
            }
        }
        $this->assertNotNull($found, "Finding $code expected, got: " . implode(', ', array_map(function ($f) {
            return $f->code;
        }, $result['findings'])));
        $this->assert_diagnosis($found);
        return $found;
    }

    /**
     * The four parts of a diagnosis are present and resolved.
     *
     * @param finding $f Finding.
     */
    private function assert_diagnosis(finding $f): void {
        $d = $f->diagnosis();
        foreach (['cause', 'detail', 'uncertainty', 'check', 'fix'] as $part) {
            $this->assertNotSame('', trim($d[$part]), "$part of {$f->code}");
            $this->assertStringNotContainsString('[[', $d[$part], "$part of {$f->code} is a missing string");
            $this->assertStringNotContainsString('{$a', $d[$part], "$part of {$f->code} has an unresolved placeholder");
        }
        $this->assertContains($d['system'], [finding::SYS_LOCAL, finding::SYS_PARTNER, finding::SYS_NETWORK, finding::SYS_BOTH]);
        $this->assertContains($d['certainty'], [finding::CERTAIN, finding::LIKELY, finding::POSSIBLE]);
    }

    /**
     * Positive control: a valid chain through the exact allow-list and the pin reaches the endpoint and reads the key set.
     */
    public function test_valid_endpoint_is_reached(): void {
        $port = $this->tls('rootA');
        $url = 'https://' . self::HOST . ':' . $port . '/jwks';
        $r = $this->checker([$port])->check($url, active_checker::PURPOSE_JWKS, time(), 'k1');
        $this->assertSame('ok', $r['code'], json_encode($r));
        $this->assertTrue($r['pinned']);
        $this->assertSame('resolve', $r['pinmode']);
        $this->assertTrue($r['jwks']['haskid']);
        $f = $this->diagnose($r, 'tls_ok');
        $this->assertSame(finding::OK, $f->severity);
    }

    /**
     * Expired certificate (minted with a validity in 2020).
     */
    public function test_expired_certificate(): void {
        $port = $this->tls('rootA', ['expired' => true]);
        $r = $this->checker([$port])->check('https://' . self::HOST . ':' . $port . '/jwks', active_checker::PURPOSE_JWKS, time());
        $this->assertSame('cert_expired', $r['code']);
        $f = $this->diagnose($r, 'probe_cert_expired');
        $this->assertSame(finding::L_TLS, $f->layer);
        $this->assertSame(finding::SYS_NETWORK, $f->system);
        $this->assertSame(finding::LIKELY, $f->certainty);
        $this->assertNotSame('', $f->diagnosis()['alternative'], 'The clock of this server is the documented alternative.');
    }

    /**
     * Certificate issued by an intermediate that the server does not send.
     */
    public function test_missing_intermediate(): void {
        $port = $this->tls('interA');
        $r = $this->checker([$port])->check('https://' . self::HOST . ':' . $port . '/jwks', active_checker::PURPOSE_JWKS, time());
        $this->assertSame('cert_chain_incomplete', $r['code']);
        $this->diagnose($r, 'probe_cert_chain_incomplete');
        // Negative control: the same leaf with the intermediate in the chain is accepted.
        $port2 = $this->tls('interA', ['withchain' => true]);
        $r2 = $this->checker([$port2])->check(
            'https://' . self::HOST . ':' . $port2 . '/jwks',
            active_checker::PURPOSE_JWKS,
            time()
        );
        $this->assertSame('ok', $r2['code']);
    }

    /**
     * Certificate for another host name, and one of an untrusted authority.
     */
    public function test_wrong_host_and_untrusted_root(): void {
        $port = $this->tls('rootA', ['hosts' => ['other.diag.test']]);
        // The SAN contains IP:127.0.0.1 for the laboratory, so the name must not be a literal address here.
        $r = $this->checker([$port])->check('https://' . self::HOST . ':' . $port . '/jwks', active_checker::PURPOSE_JWKS, time());
        $this->assertSame('cert_hostname', $r['code']);
        $this->diagnose($r, 'probe_cert_hostname');
        $port2 = $this->tls('rootB', ['withchain' => true]);
        $r2 = $this->checker([$port2])->check(
            'https://' . self::HOST . ':' . $port2 . '/jwks',
            active_checker::PURPOSE_JWKS,
            time()
        );
        $this->assertContains($r2['code'], ['cert_untrusted', 'cert_chain_incomplete']);
        $this->diagnose($r2, 'probe_' . $r2['code']);
    }

    /**
     * Unknown kid: the key set is reachable and valid, but does not contain the key the token names.
     */
    public function test_unknown_kid(): void {
        $port = $this->tls('rootA');
        $r = $this->checker([$port])->check(
            'https://' . self::HOST . ':' . $port . '/jwks',
            active_checker::PURPOSE_JWKS,
            time(),
            'rotated-key'
        );
        $this->assertSame('ok', $r['code']);
        $this->assertFalse($r['jwks']['haskid']);
        $f = $this->diagnose($r, 'jwks_kidmissing');
        $this->assertSame(finding::SYS_PARTNER, $f->system);
        $this->assertSame(finding::L_OIDC, $f->layer);
    }

    /**
     * DNS NXDOMAIN through the real system resolver (".invalid" never resolves, RFC 6761).
     */
    public function test_dns_nxdomain(): void {
        $checker = new active_checker(new curl_fetcher(new system_dns_resolver(), []), new system_dns_resolver(), false, []);
        $r = $checker->check(
            'https://diag-nonexistent-' . random_string(6) . '.invalid/jwks',
            active_checker::PURPOSE_JWKS,
            time()
        );
        $this->assertSame('dns_failure', $r['code']);
        $f = $this->diagnose($r, 'probe_dns_failure');
        $this->assertSame(finding::SYS_NETWORK, $f->system);
        $this->assertSame(finding::L_NETWORK, $f->layer);
    }

    /**
     * Proxy 407: the real Moodle curl class goes through a proxy that demands authentication. The proxy log proves that
     * the tunnel was requested for the pinned address, not for the host name: the proxy cannot resolve the name again
     * (no DNS rebinding through the proxy).
     */
    public function test_proxy_407_and_pinned_tunnel(): void {
        global $CFG;
        $log = $this->lab->path('proxy.log');
        $proxyport = $this->lab->start(['mode' => 'proxy407', 'log' => $log]);
        $CFG->proxyhost = '127.0.0.1';
        $CFG->proxyport = $proxyport;
        $CFG->proxytype = 'HTTP';
        $CFG->proxybypass = '';
        $CFG->proxyuser = '';
        $CFG->proxypassword = '';
        $r = $this->checker([4443])->check('https://' . self::HOST . ':4443/jwks', active_checker::PURPOSE_JWKS, time());
        $this->assertSame('proxy_407', $r['code'], json_encode($r));
        $this->assertTrue($r['viaproxy']);
        $this->assertSame('connect_to', $r['pinmode']);
        $line = trim((string) file_get_contents($log));
        $this->assertStringStartsWith('CONNECT 127.0.0.1:4443 ', $line, 'The tunnel must target the pinned address.');
        $this->assertStringNotContainsString(self::HOST, $line);
        $f = $this->diagnose($r, 'probe_proxy_407');
        $this->assertSame(finding::CERTAIN, $f->certainty);
        $this->assertSame(finding::SYS_NETWORK, $f->system);
    }

    /**
     * Firewall timeout: a listener whose accept queue is full drops new connections, the client times out while
     * connecting. Diagnosed as "possible" only: an overloaded server looks the same.
     */
    public function test_firewall_drop_timeout(): void {
        $port = $this->lab->start(['mode' => 'blackhole']);
        $start = microtime(true);
        $r = $this->checker([$port], 2)->check(
            'https://' . self::HOST . ':' . $port . '/jwks',
            active_checker::PURPOSE_JWKS,
            time()
        );
        $this->assertLessThan(10, microtime(true) - $start, 'The connect timeout bounds the check.');
        $this->assertSame('firewall_drop_suspected', $r['code'], json_encode($r));
        $f = $this->diagnose($r, 'probe_firewall_drop_suspected');
        $this->assertSame(finding::POSSIBLE, $f->certainty);
        $this->assertSame(finding::L_FIREWALL, $f->layer);
    }

    /**
     * No SSRF through redirects: an allowed laboratory endpoint redirects to another port of the same host, to plain
     * http, to a link-local metadata address and to loopback; every hop is refused before it is requested.
     */
    public function test_redirects_to_private_targets_are_refused(): void {
        $routes = [
            '/otherport' => ['status' => 302, 'headers' => ['Location' => 'https://' . self::HOST . ':1/']],
            '/http' => ['status' => 302, 'headers' => ['Location' => 'http://' . self::HOST . '/x']],
            '/metadata' => ['status' => 302, 'headers' => ['Location' => 'https://169.254.169.254/latest/meta-data/']],
            '/loopback' => ['status' => 302, 'headers' => ['Location' => 'https://127.0.0.1/']],
            '/jwks' => ['status' => 200, 'body' => '{"keys":[]}'],
        ];
        $port = $this->tls('rootA', ['routes' => $routes]);
        $checker = $this->checker([$port]);
        $expect = ['/otherport' => 'resolved_private', '/http' => 'scheme', '/metadata' => 'blocked', '/loopback' => 'blocked'];
        foreach ($expect as $path => $reason) {
            $r = $checker->check('https://' . self::HOST . ':' . $port . $path, active_checker::PURPOSE_ENDPOINT, time());
            $this->assertSame('blocked_by_policy', $r['code'], $path . ' ' . json_encode($r));
            $this->assertContains($r['blockreason'], [$reason, 'blocked', 'resolved_private'], $path);
            $this->assertSame(1, $r['redirects'], $path . ': the first hop was followed, the second refused');
        }
        // Without the exact allow-list the laboratory host itself is refused (it resolves to a loopback address).
        $dns = $this->resolver();
        $strict = new active_checker(new curl_fetcher($dns, []), $dns, false, []);
        $r = $strict->check('https://' . self::HOST . ':' . $port . '/jwks', active_checker::PURPOSE_JWKS, time());
        $this->assertSame('blocked_by_policy', $r['code']);
        $this->assertContains($r['blockreason'], ['resolved_private', 'blocked']);
    }

    /**
     * Clock skew measured from the Date header of a real endpoint.
     */
    public function test_clock_skew_of_partner(): void {
        $port = $this->tls('rootA', ['skew' => 900]);
        $r = $this->checker([$port])->check(
            'https://' . self::HOST . ':' . $port . '/jwks',
            active_checker::PURPOSE_JWKS,
            time(),
            'k1'
        );
        $this->assertSame('ok', $r['code']);
        $this->assertGreaterThan(800, $r['skew']);
        $f = $this->diagnose($r, 'skew_blocking');
        $this->assertSame(finding::L_OIDC, $f->layer);
    }

    /**
     * Two-system live check, read only: the key set that the partner declares in its package is fetched from the
     * address this site has configured for it; the declared key must be served there. Positive with the tool key of
     * this site (enrol_lti), negative after a key rotation that the key set does not reflect.
     */
    public function test_partner_live_key_check(): void {
        $pem = (string) get_config('enrol_lti', 'lti_13_privatekey');
        $kid = (string) get_config('enrol_lti', 'lti_13_kid');
        $this->assertNotSame('', $pem, 'enrol_lti has a tool key after installation');
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
        $b64 = function (string $bin): string {
            return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        };
        $jwk = ['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'use' => 'sig', 'e' => $b64($details['rsa']['e']),
            'n' => $b64($details['rsa']['n'])];
        $other = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048]));
        $rotated = ['kty' => 'RSA', 'kid' => 'rotated', 'e' => $b64($other['rsa']['e']), 'n' => $b64($other['rsa']['n'])];
        $port = $this->tls('rootA', ['routes' => [
            '/current' => ['status' => 200, 'body' => json_encode(['keys' => [$jwk]])],
            '/stale' => ['status' => 200, 'body' => json_encode(['keys' => [$rotated]])],
        ]]);
        $reg = $this->create_registration();
        $tool = (new inventory())->get_connection(connection::make_key(connection::ROLE_TOOL, $reg->regid, $reg->depid));
        $typeid = $this->create_platform_type();
        $platform = (new inventory())->get_connection(connection::make_key(connection::ROLE_PLATFORM, $typeid));
        $base = 'https://' . self::HOST . ':' . $port;
        foreach (['/current' => 'partner_live_kid_ok', '/stale' => 'partner_live_kid_missing'] as $path => $code) {
            $package = \tool_ltidiagnostics\local\partner_package::export($tool, [], time());
            // The partner publishes its key set at the laboratory address (on a real pair this is its own wwwroot).
            $package['own']['endpoints']['jwks'] = \tool_ltidiagnostics\local\partner_package::url_entry($base . $path);
            $package = \tool_ltidiagnostics\local\partner_package::parse(json_encode($package));
            $before = $this->lti_state();
            $live = \tool_ltidiagnostics\local\partner_package::live_check(
                $platform,
                $package,
                $base . $path,
                time(),
                $this->checker([$port])
            );
            $this->assertSame($before, $this->lti_state());
            $this->assertSame('ok', $live['probe']['code']);
            $codes = array_map(function ($f) {
                return $f->code;
            }, $live['findings']);
            $this->assertSame([$code], $codes, $path);
            $this->assert_diagnosis($live['findings'][0]);
            $this->assertSame($code === 'partner_live_kid_ok', $live['keys']['match']);
        }
    }
}
