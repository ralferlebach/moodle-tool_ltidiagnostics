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

namespace tool_ltidiagnostics\local;

/**
 * Tests of the redaction helpers.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\redactor
 */
final class redactor_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * Free text redaction.
     *
     * @dataProvider text_provider
     * @param string $input Input.
     * @param string $mustnotcontain Fragment that must be gone.
     * @param string $mustcontain Fragment that must remain.
     */
    public function test_text(string $input, string $mustnotcontain, string $mustcontain = ''): void {
        $out = redactor::text($input);
        $this->assertStringNotContainsString($mustnotcontain, $out);
        if ($mustcontain !== '') {
            $this->assertStringContainsString($mustcontain, $out);
        }
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function text_provider(): array {
        $jwt = 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjEifQ.eyJpc3MiOiJodHRwczovL2V4YW1wbGUub3JnIn0.c2lnbmF0dXJlYnl0ZXM';
        return [
            'jwt' => ["id_token=$jwt rest", 'eyJpc3MiOi', 'rest'],
            'bearer' => ['Authorization: Bearer abcdef0123456789', 'abcdef0123456789', 'Authorization'],
            'bearer inline' => ['sent Bearer abcdefghijklmnop today', 'abcdefghijklmnop', 'today'],
            'cookie' => ["Cookie: MoodleSession=abc123secret; other=1\nnext", 'abc123secret', 'next'],
            'set-cookie' => ['Set-Cookie: MoodleSession=zzz999; Path=/', 'zzz999'],
            'private key' => [
                "x -----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkq\n-----END PRIVATE KEY----- y",
                'MIIEvQIBADANBgkq', ' y',
            ],
            'private key without end' => ["-----BEGIN RSA PRIVATE KEY-----\nAAAABBBBCCCC", 'AAAABBBBCCCC'],
            'email' => ['contact jane.doe@example.org now', 'jane.doe', 'now'],
            'query values' => ['GET /a?state=SECRETVALUE&nonce=NN HTTP/1.1', 'SECRETVALUE', 'state='],
            'long hex' => ['key ' . str_repeat('ab12', 12), 'ab12ab12ab12ab12'],
            'ipv4' => ['from 203.0.113.77 ok', '203.0.113.77', '203.0.113.0'],
        ];
    }

    /**
     * Query redaction keeps keys and drops values.
     */
    public function test_query(): void {
        $this->assertSame('a=[redacted]&b=[redacted]', redactor::query('a=1&b=2'));
        $this->assertSame('', redactor::query(''));
        $this->assertSame('flag=[redacted]', redactor::query('flag'));
    }

    /**
     * Address masking.
     */
    public function test_mask_ip(): void {
        $this->assertSame('198.51.100.0', redactor::mask_ip('198.51.100.23'));
        $this->assertSame('', redactor::mask_ip('-'));
        $this->assertSame('', redactor::mask_ip('not an ip'));
        $v6 = redactor::mask_ip('2001:db8:abcd:1234:5678:9abc:def0:1234');
        $this->assertStringEndsWith('/48', $v6);
        $this->assertStringNotContainsString('5678', $v6);
        $this->assertStringNotContainsString('1234:5678', $v6);
    }

    /**
     * Identifiers are masked unless revealed.
     */
    public function test_identifier(): void {
        $this->assertSame('abcdefghijkl', redactor::identifier('abcdefghijkl', true));
        $masked = redactor::identifier('abcdefghijkl');
        $this->assertStringStartsWith('abc', $masked);
        $this->assertStringEndsWith('kl', $masked);
        $this->assertStringContainsString('*', $masked);
        $this->assertStringNotContainsString('defghij', $masked);
        $this->assertSame('****', redactor::identifier('abcd'));
        $this->assertSame('', redactor::identifier(''));
    }

    /**
     * URL redaction.
     */
    public function test_url(): void {
        $url = 'https://tool.example.org:8443/lti/launch?login_hint=42&lti_message_hint=XYZ';
        $masked = redactor::url($url);
        $this->assertStringNotContainsString('tool.example.org', $masked);
        $this->assertStringNotContainsString('XYZ', $masked);
        $this->assertStringContainsString(':8443/lti/launch', $masked);
        $this->assertStringContainsString('login_hint=[redacted]', $masked);
        $revealed = redactor::url($url, true);
        $this->assertStringContainsString('tool.example.org', $revealed);
        $this->assertStringNotContainsString('XYZ', $revealed, 'Query values are never revealed.');
    }

    /**
     * Array redaction by key name and by value.
     */
    public function test_data(): void {
        $out = redactor::data([
            'client_secret' => 'topsecret',
            'access_token' => 'tok',
            'note' => 'mail me at a@b.example',
            'nested' => ['password' => 'pw', 'plain' => 'fine', 'sesskey' => 'abc'],
            'count' => 3,
        ]);
        $this->assertSame(redactor::SECRET, $out['client_secret']);
        $this->assertSame(redactor::SECRET, $out['access_token']);
        $this->assertStringNotContainsString('a@b.example', $out['note']);
        $this->assertSame(redactor::SECRET, $out['nested']['password']);
        $this->assertSame(redactor::SECRET, $out['nested']['sesskey']);
        $this->assertSame('fine', $out['nested']['plain']);
        $this->assertSame(3, $out['count']);
    }
}
