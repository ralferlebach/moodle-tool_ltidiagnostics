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

/**
 * Tests of the error classifier, the key set analyser and the active checker with fake transports.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\active\error_classifier
 * @covers     \tool_ltidiagnostics\local\active\jwks_analyser
 * @covers     \tool_ltidiagnostics\local\active\active_checker
 */
final class active_checks_test extends \advanced_testcase {
    /**
     * Build a fetch result.
     *
     * @param array $props Properties.
     * @return fetch_result
     */
    private static function make_result(array $props): fetch_result {
        $r = new fetch_result();
        foreach ($props as $k => $v) {
            $r->$k = $v;
        }
        return $r;
    }

    /**
     * Classification of failures.
     *
     * @dataProvider classify_provider
     * @param array $props Result properties.
     * @param string $expected Expected code.
     */
    public function test_classify(array $props, string $expected): void {
        $this->assertSame($expected, error_classifier::classify(self::make_result($props)));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function classify_provider(): array {
        return [
            'ok' => [['httpstatus' => 200], 'ok'],
            'created' => [['httpstatus' => 204], 'ok'],
            'redirect' => [['httpstatus' => 302], 'redirect'],
            'denied' => [['httpstatus' => 403], 'http_denied'],
            'unauthorised' => [['httpstatus' => 401], 'http_denied'],
            'not found' => [['httpstatus' => 404], 'http_notfound'],
            'client error' => [['httpstatus' => 418], 'http_client_error'],
            'server error' => [['httpstatus' => 502], 'http_server_error'],
            'proxy 407' => [['httpstatus' => 407], 'proxy_407'],
            'proxy connect 407' => [['errno' => 56, 'error' => 'CONNECT tunnel failed, response 407'], 'proxy_407'],
            'proxy error' => [['errno' => 5], 'proxy_error'],
            'nxdomain' => [['errno' => 6], 'dns_failure'],
            'refused' => [['errno' => 7], 'connection_refused'],
            'timeout after connect' => [['errno' => 28, 'info' => ['connect_time' => 0.2]], 'timeout'],
            'firewall drop' => [
                ['errno' => 28, 'info' => ['connect_time' => 0, 'namelookup_time' => 0.02]], 'firewall_drop_suspected',
            ],
            'expired certificate' => [['errno' => 60, 'info' => ['ssl_verify_result' => 10]], 'cert_expired'],
            'wrong host' => [['errno' => 60, 'info' => ['ssl_verify_result' => 62]], 'cert_hostname'],
            'missing intermediate' => [['errno' => 60, 'info' => ['ssl_verify_result' => 21]], 'cert_chain_incomplete'],
            'unknown ca' => [['errno' => 60, 'info' => ['ssl_verify_result' => 19]], 'cert_untrusted'],
            'host mismatch errno 51' => [['errno' => 51], 'cert_hostname'],
            'handshake' => [['errno' => 35], 'tls_handshake'],
            'other transfer error' => [['errno' => 18], 'transfer_error'],
            'no status' => [[], 'transfer_error'],
            'blocked by policy' => [['blocked' => 'blocked', 'httpstatus' => 200], 'blocked_by_policy'],
        ];
    }

    /**
     * Clock skew from the Date header.
     */
    public function test_clock_skew(): void {
        $now = gmmktime(12, 0, 0, 10, 24, 2026);
        $this->assertSame(0, error_classifier::clock_skew(['date' => 'Sat, 24 Oct 2026 12:00:00 GMT'], $now));
        $this->assertSame(300, error_classifier::clock_skew(['date' => 'Sat, 24 Oct 2026 12:05:00 GMT'], $now));
        $this->assertSame(-90, error_classifier::clock_skew(['date' => 'Sat, 24 Oct 2026 11:58:30 GMT'], $now));
        $this->assertNull(error_classifier::clock_skew([], $now));
        $this->assertNull(error_classifier::clock_skew(['date' => 'garbage'], $now));
    }

    /**
     * Certificate expiry from curl certinfo.
     */
    public function test_cert_expiry(): void {
        $info = [['Subject' => 'CN=x', 'Expire date' => '2026-12-31 23:59:59 GMT']];
        $this->assertSame(gmmktime(23, 59, 59, 12, 31, 2026), error_classifier::cert_expiry($info));
        $this->assertNull(error_classifier::cert_expiry([]));
        $this->assertNull(error_classifier::cert_expiry([['Subject' => 'CN=x']]));
    }

    /**
     * Framing headers.
     *
     * @dataProvider framing_provider
     * @param array $headers Headers.
     * @param bool|null $blocks Expected.
     */
    public function test_framing(array $headers, ?bool $blocks): void {
        $this->assertSame($blocks, error_classifier::framing($headers)['blocksembedding']);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function framing_provider(): array {
        return [
            'deny' => [['x-frame-options' => 'DENY'], true],
            'sameorigin' => [['x-frame-options' => 'SAMEORIGIN'], true],
            'csp none' => [['content-security-policy' => "default-src 'self'; frame-ancestors 'none'"], true],
            'csp self' => [['content-security-policy' => "frame-ancestors 'self'"], true],
            'csp allows platform' => [['content-security-policy' => 'frame-ancestors https://platform.example.org'], null],
            'nothing' => [[], false],
        ];
    }

    /**
     * Key set analysis.
     */
    public function test_jwks_analyser(): void {
        $good = json_encode(['keys' => [
            ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig', 'n' => 'AA', 'e' => 'AQ'],
        ]]);
        $r = jwks_analyser::analyse($good, 'k1');
        $this->assertTrue($r['valid']);
        $this->assertTrue($r['haskid']);
        $this->assertFalse($r['privateleak']);
        $this->assertFalse(jwks_analyser::analyse($good, 'other')['haskid']);
        $this->assertNull(jwks_analyser::analyse($good)['haskid']);

        $leak = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => 'AA', 'e' => 'AQ', 'd' => 'PRIVATE']]]);
        $this->assertTrue(jwks_analyser::analyse($leak)['privateleak']);
        $this->assertStringNotContainsString(
            'PRIVATE',
            json_encode(jwks_analyser::analyse($leak)),
            'Key material is never copied.'
        );

        $this->assertSame('notjson', jwks_analyser::analyse('<html>')['reason']);
        $this->assertSame('nokeys', jwks_analyser::analyse('{"a":1}')['reason']);
        $this->assertSame('emptykeys', jwks_analyser::analyse('{"keys":[]}')['reason']);
        $this->assertFalse(jwks_analyser::analyse('{"keys":[]}')['valid']);
    }

    /**
     * Build a checker with fakes.
     *
     * @param fetch_result $result What the fake transport returns.
     * @param array|null $dns What the fake resolver returns.
     * @param bool $allowprivate Whether private targets are allowed.
     * @return array The checker and the fake transport.
     */
    private function checker(fetch_result $result, ?array $dns = ['93.184.216.34'], bool $allowprivate = false): array {
        $fetcher = new class ($result) implements http_fetcher {
            /** @var fetch_result Canned result. */
            private $result;
            /** @var int Number of calls. */
            public $calls = 0;

            /**
             * Constructor.
             *
             * @param fetch_result $result Canned result.
             */
            public function __construct(fetch_result $result) {
                $this->result = $result;
            }

            /**
             * Fake GET.
             *
             * @param string $url URL.
             * @param bool $allowprivate Allowed.
             * @return fetch_result
             */
            public function get(string $url, bool $allowprivate): fetch_result {
                $this->calls++;
                return $this->result;
            }
        };
        $resolver = new class ($dns) implements dns_resolver {
            /** @var array|null Canned answer. */
            private $answer;

            /**
             * Constructor.
             *
             * @param array|null $answer Answer.
             */
            public function __construct(?array $answer) {
                $this->answer = $answer;
            }

            /**
             * Fake resolve.
             *
             * @param string $host Host.
             * @return array|null
             */
            public function resolve(string $host): ?array {
                return $this->answer;
            }
        };
        return [new active_checker($fetcher, $resolver, $allowprivate), $fetcher];
    }

    /**
     * A blocked target never reaches the transport.
     */
    public function test_checker_blocks_before_fetching(): void {
        $this->resetAfterTest();
        [$checker, $fetcher] = $this->checker(self::make_result(['httpstatus' => 200]));
        $out = $checker->check('https://127.0.0.1/secret', active_checker::PURPOSE_ENDPOINT, 1000);
        $this->assertSame('blocked_by_policy', $out['code']);
        $this->assertSame('blocked', $out['blockreason']);
        $this->assertSame(0, $fetcher->calls);
    }

    /**
     * An unresolvable host is reported without fetching.
     */
    public function test_checker_dns_failure(): void {
        $this->resetAfterTest();
        [$checker, $fetcher] = $this->checker(self::make_result(['httpstatus' => 200]), null);
        $out = $checker->check('https://nonexistent.example.org/jwks', active_checker::PURPOSE_JWKS, 1000);
        $this->assertSame('dns_failure', $out['code']);
        $this->assertSame(0, $fetcher->calls);
    }

    /**
     * A name that resolves to an internal address is refused (DNS rebinding protection).
     */
    public function test_checker_refuses_internal_dns_answer(): void {
        $this->resetAfterTest();
        [$checker, $fetcher] = $this->checker(self::make_result(['httpstatus' => 200]), ['93.184.216.34', '10.0.0.5']);
        $out = $checker->check('https://rebind.example.org/jwks', active_checker::PURPOSE_JWKS, 1000);
        $this->assertSame('blocked_by_policy', $out['code']);
        $this->assertSame('resolved_private', $out['blockreason']);
        $this->assertSame(0, $fetcher->calls);
    }

    /**
     * The real fetcher pins the connection to the validated address.
     */
    public function test_fetcher_pins_validated_address(): void {
        $resolver = new class implements dns_resolver {
            /**
             * Fake resolve.
             *
             * @param string $host Host.
             * @return array|null
             */
            public function resolve(string $host): ?array {
                $map = ['tool.example.org' => ['93.184.216.34'], 'v6.example.org' => ['2606:2800:220:1::1']];
                return $map[$host] ?? ['10.0.0.9'];
            }
        };
        $this->assertSame(
            ['CURLOPT_RESOLVE' => ['tool.example.org:443:93.184.216.34']],
            curl_fetcher::pin_options('https://tool.example.org/jwks', $resolver)
        );
        $this->assertSame(
            ['CURLOPT_RESOLVE' => ['v6.example.org:8443:[2606:2800:220:1::1]']],
            curl_fetcher::pin_options('https://v6.example.org:8443/jwks', $resolver)
        );
        $this->assertNull(curl_fetcher::pin_options('https://evil.example.org/jwks', $resolver), 'Internal answer.');
        $this->assertNull(curl_fetcher::pin_options('https://127.0.0.1/jwks', $resolver));
        $this->assertSame([], curl_fetcher::pin_options('https://93.184.216.34/jwks', $resolver));
    }

    /**
     * A key set check analyses the body and reports skew.
     */
    public function test_checker_jwks_success(): void {
        $this->resetAfterTest();
        $body = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'abc', 'n' => 'x', 'e' => 'AQ']]]);
        $now = gmmktime(12, 0, 0, 10, 24, 2026);
        [$checker] = $this->checker(self::make_result([
            'httpstatus' => 200, 'body' => $body, 'headers' => ['date' => 'Sat, 24 Oct 2026 12:10:00 GMT'],
        ]));
        $out = $checker->check('https://tool.example.org/jwks', active_checker::PURPOSE_JWKS, $now, 'abc');
        $this->assertSame('ok', $out['code']);
        $this->assertTrue($out['jwks']['haskid']);
        $this->assertSame(600, $out['skew']);
    }

    /**
     * A launch check reports framing headers only.
     */
    public function test_checker_launch_framing(): void {
        $this->resetAfterTest();
        [$checker] = $this->checker(self::make_result(['httpstatus' => 200, 'headers' => ['x-frame-options' => 'DENY']]));
        $out = $checker->check('https://tool.example.org/launch', active_checker::PURPOSE_LAUNCH, 1000);
        $this->assertTrue($out['framing']['blocksembedding']);
        $this->assertNull($out['jwks']);
    }

    /**
     * Private targets can be allowed explicitly.
     */
    public function test_checker_allowprivate(): void {
        $this->resetAfterTest();
        [$checker, $fetcher] = $this->checker(self::make_result(['httpstatus' => 200]), ['10.0.0.5'], true);
        $out = $checker->check('https://10.0.0.5/jwks', active_checker::PURPOSE_ENDPOINT, 1000);
        $this->assertSame('ok', $out['code']);
        $this->assertSame(1, $fetcher->calls);
    }
}
