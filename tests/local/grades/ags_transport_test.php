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

namespace tool_ltidiagnostics\local\grades;

use tool_ltidiagnostics\local\active\dns_resolver;

/**
 * The guarded AGS transport (issue #9): SSRF guard and address pin, no redirects, strict TLS options, request shape,
 * token handling, classification. The transfer layer is scripted; the guard, the pin and the parsing are real.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\grades\moodle_ags_transport
 */
final class ags_transport_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * Transport with a scripted transfer layer and a fixed resolver.
     *
     * @param array $answers Queue of transfer answers (body, info, errno).
     * @param array $dns Host => addresses.
     * @return moodle_ags_transport
     */
    private function transport(array $answers, array $dns = ['platform.example.org' => ['93.184.216.34']]): moodle_ags_transport {
        $resolver = new class ($dns) implements dns_resolver {
            /** @var array Answers. */
            private $dns;
            /**
             * Constructor.
             *
             * @param array $dns Answers.
             */
            public function __construct(array $dns) {
                $this->dns = $dns;
            }
            /**
             * Resolve.
             *
             * @param string $host Host.
             * @return array|null
             */
            public function resolve(string $host): ?array {
                return $this->dns[$host] ?? [];
            }
        };
        return new class ($answers, $resolver) extends moodle_ags_transport {
            /** @var array Answers. */
            public $answers;
            /** @var array Requests. */
            public $requests = [];
            /**
             * Constructor.
             *
             * @param array $answers Answers.
             * @param dns_resolver $resolver Resolver.
             */
            public function __construct(array $answers, dns_resolver $resolver) {
                parent::__construct($resolver, []);
                $this->answers = $answers;
            }
            /**
             * Scripted transfer.
             *
             * @param string $method Method.
             * @param string $url URL.
             * @param array $options Options.
             * @param array $headers Headers.
             * @param string $body Body.
             * @param bool $ignoresecurity Bypass.
             * @return array
             */
            protected function send_request(
                string $method,
                string $url,
                array $options,
                array $headers,
                string $body,
                bool $ignoresecurity
            ): array {
                $this->requests[] = compact('method', 'url', 'options', 'headers', 'body', 'ignoresecurity');
                return array_shift($this->answers);
            }
        };
    }

    /**
     * Answer.
     *
     * @param int $status Status.
     * @param string $body Body.
     * @param string $ip Peer address.
     * @param int $errno Curl error.
     * @return array
     */
    private static function answer(int $status, string $body = '', string $ip = '93.184.216.34', int $errno = 0): array {
        return ['body' => $body, 'info' => ['http_code' => $status, 'primary_ip' => $ip], 'errno' => $errno];
    }

    /**
     * Score POST: pinned, no redirects, TLS verified, bearer token, score media type; statuses classified.
     */
    public function test_post_score_shape_and_classes(): void {
        $this->resetAfterTest();
        $url = 'https://platform.example.org/mod/lti/services.php/2/lineitems/4/lineitem/scores?type_id=1';
        $t = $this->transport([self::answer(204), self::answer(409), self::answer(302), self::answer(503),
            self::answer(0, '', '', 28), self::answer(200, '', '10.0.0.5')]);
        $this->assertSame(['status' => 204, 'errorclass' => ''], $t->post_score($url, 'tok', ['scoreGiven' => 1]));
        $req = $t->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertContains('Authorization: Bearer tok', $req['headers']);
        $this->assertContains('Content-Type: application/vnd.ims.lis.v1.score+json', $req['headers']);
        $this->assertSame('{"scoreGiven":1}', $req['body']);
        $this->assertSame(0, $req['options']['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $req['options']['CURLOPT_MAXREDIRS']);
        $this->assertSame(1, $req['options']['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $req['options']['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(CURLPROTO_HTTPS, $req['options']['CURLOPT_PROTOCOLS']);
        $this->assertSame(['platform.example.org:443:93.184.216.34'], $req['options']['CURLOPT_RESOLVE']);
        $this->assertFalse($req['ignoresecurity']);
        $this->assertSame('http_409', $t->post_score($url, 'tok', [])['errorclass']);
        $this->assertSame('redirect_refused', $t->post_score($url, 'tok', [])['errorclass'], 'A redirect is never followed.');
        $this->assertSame('http_5xx', $t->post_score($url, 'tok', [])['errorclass']);
        $this->assertSame('timeout', $t->post_score($url, 'tok', [])['errorclass']);
        $this->assertSame(
            'blocked_by_policy',
            $t->post_score($url, 'tok', [])['errorclass'],
            'A connection to an address that was not validated is discarded.'
        );
        $this->assertCount(6, $t->requests);
    }

    /**
     * Refused before any transfer: private targets, internal names, plain http, credentials in the URL.
     */
    public function test_ssrf_guard(): void {
        $this->resetAfterTest();
        $t = $this->transport([], ['rebind.example.org' => ['10.1.2.3'], 'platform.example.org' => ['93.184.216.34']]);
        foreach (
            ['https://127.0.0.1/x', 'https://localhost/x', 'https://rebind.example.org/x', 'http://platform.example.org/x',
                'https://user:pw@platform.example.org/x', 'https://169.254.169.254/latest/meta-data'] as $url
        ) {
            $this->assertSame('blocked_by_policy', $t->post_score($url, 'tok', [])['errorclass'], $url);
        }
        $this->assertSame([], $t->requests);
    }

    /**
     * Token: client credentials with a signed assertion of the tool key; the token never leaves the call's result.
     */
    public function test_token_request(): void {
        $this->resetAfterTest();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $registration = \Packback\Lti1p3\LtiRegistration::new()
            ->setIssuer('https://platform.example.org')
            ->setClientId('client-1')
            ->setAuthTokenUrl('https://platform.example.org/mod/lti/token.php')
            ->setAuthServer('https://platform.example.org/mod/lti/token.php')
            ->setKid('kid-1')
            ->setToolPrivateKey($pem);
        $t = $this->transport([
            self::answer(200, json_encode(['access_token' => 'secret-access', 'scope' => 'a b'])),
            self::answer(200, json_encode(['token_type' => 'bearer'])),
            self::answer(400, '{"error":"invalid_scope"}'),
        ]);
        $ok = $t->token($registration, ['a', 'b']);
        $this->assertSame('secret-access', $ok['token']);
        $this->assertSame(['a', 'b'], $ok['scopes']);
        parse_str($t->requests[0]['body'], $form);
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('a b', $form['scope']);
        $claims = (array) \Firebase\JWT\JWT::decode($form['client_assertion'], new \Firebase\JWT\Key(
            openssl_pkey_get_details($key)['key'],
            'RS256'
        ));
        $this->assertSame('client-1', $claims['iss']);
        $this->assertSame('https://platform.example.org/mod/lti/token.php', $claims['aud']);
        $this->assertSame('token', $t->token($registration, ['a'])['errorclass'], 'No access token in the answer.');
        $this->assertSame('token', $t->token($registration, ['a'])['errorclass']);
    }

    /**
     * GET JSON: parsed only on success, a broken body is an error, not data.
     */
    public function test_get_json(): void {
        $this->resetAfterTest();
        $t = $this->transport([self::answer(200, '[{"id":"x"}]'), self::answer(200, 'not json'), self::answer(403)]);
        $this->assertSame([['id' => 'x']], $t->get_json('https://platform.example.org/li', 'tok', 'a/b')['json']);
        $this->assertContains('Accept: a/b', $t->requests[0]['headers']);
        $this->assertSame('http_other', $t->get_json('https://platform.example.org/li', 'tok', 'a/b')['errorclass']);
        $r = $t->get_json('https://platform.example.org/li', 'tok', 'a/b');
        $this->assertSame('http_403', $r['errorclass']);
        $this->assertNull($r['json']);
    }
}
