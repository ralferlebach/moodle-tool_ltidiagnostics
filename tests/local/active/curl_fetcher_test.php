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
 * Negative tests of the fetcher against SSRF, DNS rebinding, redirect abuse and address mismatch (audit item DIA-04).
 *
 * The real hop logic of {@see curl_fetcher} runs; only the single network transfer is replaced, so that the tests can
 * decide what DNS answers, which address "curl connected to" and where a server redirects to.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\active\curl_fetcher
 */
final class curl_fetcher_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * Build a fetcher whose transfer is scripted.
     *
     * @param array $dns Host => list of answers per call (the last answer repeats), or a plain list for every call.
     * @param array $script Responses per transfer call: each [status, location, primary_ip, errno].
     * @return array [fetcher, log] where log is an ArrayObject with calls (url, options) and resolutions (host)
     */
    private function fetcher(array $dns, array $script): array {
        $log = new \ArrayObject(['calls' => [], 'resolutions' => []]);
        $resolver = new class ($dns, $log) implements dns_resolver {
            /** @var array Scripted answers. */
            private $answers;
            /** @var \ArrayObject Log. */
            private $log;
            /** @var int[] Calls per host. */
            private $count = [];

            /**
             * Constructor.
             *
             * @param array $answers Answers.
             * @param \ArrayObject $log Log.
             */
            public function __construct(array $answers, \ArrayObject $log) {
                $this->answers = $answers;
                $this->log = $log;
            }

            /**
             * Fake resolve.
             *
             * @param string $host Host.
             * @return string[]|null
             */
            public function resolve(string $host): ?array {
                $this->count[$host] = ($this->count[$host] ?? -1) + 1;
                $res = $this->log['resolutions'];
                $res[] = $host;
                $this->log['resolutions'] = $res;
                $answer = $this->answers[$host] ?? null;
                if ($answer === null) {
                    return null;
                }
                if (isset($answer[0]) && is_array($answer[0])) {
                    return $answer[min($this->count[$host], count($answer) - 1)];
                }
                return $answer;
            }
        };
        $fetcher = new class ($resolver, $script, $log) extends curl_fetcher {
            /** @var array Script. */
            private $script;
            /** @var \ArrayObject Log. */
            private $log;

            /**
             * Constructor.
             *
             * @param dns_resolver $dns Resolver.
             * @param array $script Script.
             * @param \ArrayObject $log Log.
             */
            public function __construct(dns_resolver $dns, array $script, \ArrayObject $log) {
                parent::__construct($dns);
                $this->script = $script;
                $this->log = $log;
            }

            /**
             * Scripted transfer.
             *
             * @param string $url URL.
             * @param array $options Options.
             * @param bool $allowprivate Private mode.
             * @return array
             */
            protected function transfer(string $url, array $options, bool $allowprivate): array {
                $calls = $this->log['calls'];
                $calls[] = ['url' => $url, 'options' => $options, 'allowprivate' => $allowprivate];
                $this->log['calls'] = $calls;
                [$status, $location, $primary, $errno] = array_shift($this->script) + [200, '', '', 0];
                return [
                    'body' => 'BODY',
                    'info' => ['http_code' => $status, 'primary_ip' => $primary, 'connect_time' => 0.01],
                    'errno' => $errno,
                    'error' => '',
                    'headers' => $location !== '' ? ['Location' => $location] : [],
                ];
            }
        };
        return [$fetcher, $log];
    }

    /**
     * A plain request is pinned to the one validated answer and resolved exactly once.
     */
    public function test_request_is_pinned_and_resolved_once(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(['tool.example.org' => ['93.184.216.34']], [[200, '', '93.184.216.34']]);
        $result = $fetcher->get('https://tool.example.org/jwks', false);
        $this->assertSame('', $result->blocked);
        $this->assertSame(200, $result->httpstatus);
        $this->assertSame(['tool.example.org'], $log['resolutions']);
        $this->assertCount(1, $log['calls']);
        $this->assertSame(['tool.example.org:443:93.184.216.34'], $log['calls'][0]['options']['CURLOPT_RESOLVE']);
        $this->assertTrue($result->info['pinned']);
    }

    /**
     * DNS rebinding: the name answers with a public address first and a private one afterwards. The connection must go
     * to the validated public address, and the later private answer must never be consulted.
     */
    public function test_dns_rebinding_cannot_change_the_target(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(
            ['rebind.example.org' => [['93.184.216.34'], ['127.0.0.1']]],
            [[200, '', '93.184.216.34']]
        );
        $result = $fetcher->get('https://rebind.example.org/jwks', false);
        $this->assertSame('', $result->blocked);
        $this->assertCount(1, $log['resolutions'], 'Resolved once per hop.');
        $this->assertStringContainsString('93.184.216.34', $log['calls'][0]['options']['CURLOPT_RESOLVE'][0]);
        $this->assertStringNotContainsString('127.0.0.1', json_encode($log['calls'][0]['options']));
    }

    /**
     * A name that resolves to an internal address on the first answer is refused before any transfer.
     */
    public function test_internal_answer_is_refused_before_transfer(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(['evil.example.org' => ['127.0.0.1']], []);
        $result = $fetcher->get('https://evil.example.org/jwks', false);
        $this->assertSame('resolved_private', $result->blocked);
        $this->assertSame([], $log['calls']);
    }

    /**
     * One internal address among public ones is enough to refuse (a client could pick any of them).
     *
     * @dataProvider mixed_answer_provider
     * @param string[] $answer DNS answer.
     */
    public function test_mixed_answers_are_refused(array $answer): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(['mixed.example.org' => $answer], []);
        $this->assertSame('resolved_private', $fetcher->get('https://mixed.example.org/', false)->blocked);
        $this->assertSame([], $log['calls']);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function mixed_answer_provider(): array {
        return [
            'public and private v4' => [['93.184.216.34', '10.0.0.5']],
            'private first' => [['192.168.1.1', '93.184.216.34']],
            'public and mapped loopback' => [['93.184.216.34', '::ffff:127.0.0.1']],
            'public and nat64 metadata' => [['93.184.216.34', '64:ff9b::a9fe:a9fe']],
            'public and 6to4 loopback' => [['2606:4700::1111', '2002:7f00:1::']],
            'public and link local' => [['2606:4700::1111', 'fe80::1']],
            'garbage answer' => [['93.184.216.34', 'not-an-ip']],
        ];
    }

    /**
     * No answer at all (NXDOMAIN or a failed lookup) is refused, never retried by curl on its own.
     */
    public function test_unresolvable_name_is_refused(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher([], []);
        $this->assertSame('resolved_private', $fetcher->get('https://gone.example.org/', false)->blocked);
        $this->assertSame([], $log['calls']);
        [$fetcher, $log] = $this->fetcher(['empty.example.org' => []], []);
        $this->assertSame('resolved_private', $fetcher->get('https://empty.example.org/', false)->blocked);
        $this->assertSame([], $log['calls']);
    }

    /**
     * Every validated address is pinned, so curl may still fall back between them.
     */
    public function test_all_validated_addresses_are_pinned(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(
            ['dual.example.org' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']],
            [[200, '', '2606:2800:220:1:248:1893:25c8:1946']]
        );
        $result = $fetcher->get('https://dual.example.org:8443/jwks', false);
        $this->assertSame('', $result->blocked);
        $this->assertSame([
            'dual.example.org:8443:93.184.216.34',
            'dual.example.org:8443:[2606:2800:220:1:248:1893:25c8:1946]',
        ], $log['calls'][0]['options']['CURLOPT_RESOLVE']);
    }

    /**
     * A redirect to a private IP literal is refused on the second hop and the private address is never requested.
     */
    public function test_redirect_to_private_literal_is_refused(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(
            ['tool.example.org' => ['93.184.216.34']],
            [[302, 'https://169.254.169.254/latest/meta-data/', '93.184.216.34']]
        );
        $result = $fetcher->get('https://tool.example.org/jwks', false);
        $this->assertSame('blocked', $result->blocked);
        $this->assertCount(1, $log['calls']);
        $this->assertSame(['https://169.254.169.254/latest/meta-data/'], $result->redirects);
    }

    /**
     * A redirect to a name that resolves to a private address (CNAME to an internal host) is refused.
     */
    public function test_redirect_to_name_with_private_answer_is_refused(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(
            ['tool.example.org' => ['93.184.216.34'], 'cname.example.org' => ['10.1.2.3']],
            [[301, 'https://cname.example.org/x', '93.184.216.34']]
        );
        $result = $fetcher->get('https://tool.example.org/jwks', false);
        $this->assertSame('resolved_private', $result->blocked);
        $this->assertCount(1, $log['calls']);
    }

    /**
     * Each hop is resolved and pinned on its own.
     */
    public function test_each_hop_is_validated_and_pinned(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(
            ['a.example.org' => ['93.184.216.34'], 'b.example.net' => ['203.0.114.9']],
            [[302, 'https://b.example.net/next', '93.184.216.34'], [200, '', '203.0.114.9']]
        );
        $result = $fetcher->get('https://a.example.org/start', false);
        $this->assertSame('', $result->blocked);
        $this->assertSame(['a.example.org', 'b.example.net'], $log['resolutions']);
        $this->assertSame(['b.example.net:443:203.0.114.9'], $log['calls'][1]['options']['CURLOPT_RESOLVE']);
        $this->assertSame(200, $result->httpstatus);
    }

    /**
     * Redirects to other schemes, downgrades to http, credentials and protocol-relative hosts are judged by the guard.
     *
     * @dataProvider redirect_target_provider
     * @param string $location Location header.
     * @param string $reason Expected block reason.
     */
    public function test_hostile_redirect_targets(string $location, string $reason): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher(
            ['tool.example.org' => ['93.184.216.34'], 'other.example.org' => ['93.184.216.35']],
            [[302, $location, '93.184.216.34']]
        );
        $result = $fetcher->get('https://tool.example.org/jwks', false);
        $this->assertSame($reason, $result->blocked, $location);
        $this->assertCount(1, $log['calls']);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function redirect_target_provider(): array {
        return [
            'downgrade to http' => ['http://other.example.org/x', 'scheme'],
            'ftp' => ['ftp://other.example.org/x', 'scheme'],
            'file' => ['file:///etc/passwd', 'malformed'],
            'gopher' => ['gopher://other.example.org/_x', 'scheme'],
            'credentials' => ['https://user:pw@other.example.org/x', 'credentials'],
            'protocol relative loopback' => ['//127.0.0.1/x', 'blocked'],
            'protocol relative v6 mapped' => ['//[::ffff:7f00:1]/x', 'blocked'],
            'decimal loopback' => ['https://2130706433/x', 'blocked'],
            'localhost' => ['https://localhost/x', 'blocked'],
            'internal suffix' => ['https://metadata.internal/x', 'blocked'],
            'header injection' => ["https://other.example.org/x\r\nX: y", 'malformed'],
        ];
    }

    /**
     * An endless redirect chain stops after three hops and says so; it is no reachable result.
     */
    public function test_redirect_limit(): void {
        $this->resetAfterTest();
        $script = array_fill(0, 6, [302, 'https://tool.example.org/again', '93.184.216.34']);
        [$fetcher, $log] = $this->fetcher(['tool.example.org' => ['93.184.216.34']], $script);
        $result = $fetcher->get('https://tool.example.org/loop', false);
        $this->assertSame('redirect_limit', $result->blocked);
        $this->assertCount(4, $log['calls'], 'The first request plus three redirects.');
        $this->assertSame('blocked_by_policy', error_classifier::classify($result));
    }

    /**
     * The address curl really connected to must be one of the validated ones: otherwise the answer is discarded.
     */
    public function test_connection_to_unvalidated_address_is_discarded(): void {
        $this->resetAfterTest();
        [$fetcher] = $this->fetcher(['tool.example.org' => ['93.184.216.34']], [[200, '', '127.0.0.1']]);
        $result = $fetcher->get('https://tool.example.org/jwks', false);
        $this->assertSame('pin_mismatch', $result->blocked);
        $this->assertSame('', $result->body, 'Nothing returned by the wrong peer may be analysed.');
        $this->assertSame([], $result->headers);
        $this->assertSame(0, $result->httpstatus);
    }

    /**
     * Address comparison ignores the spelling of an IPv6 address.
     */
    public function test_connected_to_validated(): void {
        $this->assertTrue(curl_fetcher::connected_to_validated(['93.184.216.34'], '93.184.216.34', false, false));
        $this->assertTrue(curl_fetcher::connected_to_validated(
            ['2606:2800:220:1:248:1893:25c8:1946'],
            '2606:2800:0220:0001:0248:1893:25C8:1946',
            false,
            false
        ));
        $this->assertFalse(curl_fetcher::connected_to_validated(['93.184.216.34'], '93.184.216.35', false, false));
        $this->assertFalse(curl_fetcher::connected_to_validated(['93.184.216.34'], '::ffff:93.184.216.35', false, false));
        // Nothing connected (a failed transfer) or a proxy in the path: nothing to compare.
        $this->assertTrue(curl_fetcher::connected_to_validated(['93.184.216.34'], '', false, false));
        $this->assertTrue(curl_fetcher::connected_to_validated(['93.184.216.34'], '10.0.0.1', true, false));
    }

    /**
     * With a proxy the proxy would resolve the name again: the tunnel target is pinned to the validated address.
     */
    public function test_proxy_path_is_pinned_with_connect_to(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.org';
        $CFG->proxyport = 3128;
        $CFG->proxybypass = '';
        [$fetcher] = $this->fetcher(['tool.example.org' => ['93.184.216.34']], [[200, '', '10.9.9.9']]);
        $result = $fetcher->get('https://tool.example.org/jwks', false);
        $this->assertSame('', $result->blocked, 'The peer address of a proxy transfer is the proxy and is not compared.');
        $this->assertTrue($result->info['viaproxy']);
        // Since 0.3.0 the tunnel target is pinned to the validated address (CURLOPT_CONNECT_TO), so the proxy cannot
        // resolve the name again.
        $this->assertTrue($result->info['pinned']);
        $this->assertSame('connect_to', $result->info['pinmode']);
        $this->assertSame(
            ['CURLOPT_CONNECT_TO' => ['tool.example.org:443:93.184.216.34:443']],
            curl_fetcher::proxy_pin_options('https://tool.example.org/jwks', ['93.184.216.34'])
        );
        // The DNS answer is still validated locally before the request.
        [$fetcher, $log] = $this->fetcher(['evil.example.org' => ['10.0.0.1']], []);
        $this->assertSame('resolved_private', $fetcher->get('https://evil.example.org/', false)->blocked);
        $this->assertSame([], $log['calls']);
        // A host on the bypass list does not use the proxy.
        $CFG->proxybypass = 'tool.example.org';
        [$fetcher] = $this->fetcher(['tool.example.org' => ['93.184.216.34']], [[200, '', '93.184.216.34']]);
        $result = $fetcher->get('https://tool.example.org/jwks', false);
        $this->assertFalse($result->info['viaproxy']);
        $this->assertTrue($result->info['pinned']);
    }

    /**
     * TLS verification is never switched off and only https is spoken, also in the explicit private mode; no
     * credentials, cookies or user tokens are part of the options.
     */
    public function test_request_options_are_strict(): void {
        $options = curl_fetcher::build_options(['CURLOPT_RESOLVE' => ['h:443:93.184.216.34']], false);
        $this->assertSame(1, $options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(CURLPROTO_HTTPS, $options['CURLOPT_PROTOCOLS']);
        $this->assertSame(CURLPROTO_HTTPS, $options['CURLOPT_REDIR_PROTOCOLS']);
        $this->assertSame(0, $options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $options['CURLOPT_MAXREDIRS']);
        $private = curl_fetcher::build_options([], true);
        $this->assertSame(1, $private['CURLOPT_SSL_VERIFYPEER'], 'Private mode must not weaken TLS verification.');
        $this->assertSame(2, $private['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $private['CURLOPT_PROTOCOLS']);
        foreach ([$options, $private] as $set) {
            $names = strtoupper(implode(' ', array_keys($set)));
            $forbiddenlist = ['USERPWD', 'COOKIE', 'HTTPHEADER', 'POST', 'CUSTOMREQUEST', 'XOAUTH2', 'BEARER', 'PROXYUSERPWD'];
            foreach ($forbiddenlist as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $names);
            }
        }
    }

    /**
     * The private mode skips the pin and the peer comparison but still asks the guard, and keeps the scheme rules.
     */
    public function test_private_mode_still_applies_the_guard(): void {
        $this->resetAfterTest();
        [$fetcher, $log] = $this->fetcher([], [[200, '', '10.0.0.5']]);
        $result = $fetcher->get('https://10.0.0.5/jwks', true);
        $this->assertSame('', $result->blocked);
        $this->assertTrue($log['calls'][0]['allowprivate']);
        [$fetcher, $log] = $this->fetcher([], [[302, 'ftp://10.0.0.5/x', '10.0.0.5']]);
        $this->assertSame('scheme', $fetcher->get('https://10.0.0.5/jwks', true)->blocked);
        [$fetcher] = $this->fetcher([], [[302, 'https://user:pw@10.0.0.5/x', '10.0.0.5']]);
        $this->assertSame('credentials', $fetcher->get('https://10.0.0.5/jwks', true)->blocked);
    }

    /**
     * Location resolution, including forms that must not stay on the original host.
     *
     * @dataProvider location_provider
     * @param string $base Current URL.
     * @param string $location Location header.
     * @param string $expected Absolute URL.
     */
    public function test_location_resolution(string $base, string $location, string $expected): void {
        $this->assertSame($expected, curl_fetcher::absolute($base, $location));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function location_provider(): array {
        return [
            'absolute' => ['https://a.example.org/x/y', 'https://b.example.org/z', 'https://b.example.org/z'],
            'other scheme kept for the guard' => ['https://a.example.org/x', 'ftp://b.example.org/z', 'ftp://b.example.org/z'],
            'protocol relative' => ['https://a.example.org/x', '//b.example.org/z', 'https://b.example.org/z'],
            'root relative' => ['https://a.example.org:8443/x/y', '/z', 'https://a.example.org:8443/z'],
            'relative' => ['https://a.example.org/x/y', 'z', 'https://a.example.org/x/z'],
            'query only' => ['https://a.example.org/x/y?a=1', '?b=2', 'https://a.example.org/x/y?b=2'],
            'upper case scheme' => ['https://a.example.org/', 'HTTP://b.example.org/', 'HTTP://b.example.org/'],
        ];
    }
}
