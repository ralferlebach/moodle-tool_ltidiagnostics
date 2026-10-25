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
 * Tests of the SSRF guard that protects every active check.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\active\url_guard
 */
final class url_guard_test extends \advanced_testcase {
    /**
     * Addresses that must be refused.
     *
     * @dataProvider blocked_provider
     * @param string $url URL.
     * @param string $reason Expected reason.
     */
    public function test_refused(string $url, string $reason): void {
        $this->resetAfterTest();
        $this->assertSame($reason, url_guard::check($url));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function blocked_provider(): array {
        return [
            'localhost' => ['https://localhost/jwks', 'blocked'],
            'loopback v4' => ['https://127.0.0.1/jwks', 'blocked'],
            'loopback other' => ['https://127.1.2.3/jwks', 'blocked'],
            'private 10' => ['https://10.0.0.5/jwks', 'blocked'],
            'private 172' => ['https://172.16.4.4/jwks', 'blocked'],
            'private 192' => ['https://192.168.1.1/jwks', 'blocked'],
            'link local metadata' => ['https://169.254.169.254/latest/meta-data', 'blocked'],
            'loopback v6' => ['https://[::1]/jwks', 'blocked'],
            'http' => ['http://example.org/jwks', 'scheme'],
            'ftp' => ['ftp://example.org/jwks', 'scheme'],
            'file' => ['file:///etc/passwd', 'malformed'],
            'credentials' => ['https://user:pw@example.org/jwks', 'credentials'],
            'user only' => ['https://user@example.org/jwks', 'credentials'],
            'empty' => ['', 'empty'],
            'whitespace' => ["https://example.org/a b", 'malformed'],
            'no host' => ['https:///path', 'malformed'],
            'too long' => ['https://example.org/' . str_repeat('a', 2100), 'toolong'],
            'bad host' => ['https://exa_mple.org/', 'host'],
            'ipv4 mapped loopback' => ['https://[::ffff:127.0.0.1]/jwks', 'blocked'],
            'ipv6 unique local' => ['https://[fd00::1]/jwks', 'blocked'],
            'ipv6 link local' => ['https://[fe80::1]/jwks', 'blocked'],
            'decimal loopback' => ['https://2130706433/jwks', 'blocked'],
            'hex loopback' => ['https://0x7f000001/jwks', 'blocked'],
            'octal loopback' => ['https://0177.0.0.1/jwks', 'blocked'],
            'unspecified' => ['https://0.0.0.0/jwks', 'blocked'],
            'carrier grade' => ['https://100.64.0.1/jwks', 'blocked'],
            'dot local' => ['https://printer.local/jwks', 'blocked'],
            'dot internal' => ['https://metadata.internal/jwks', 'blocked'],
            'trailing dot localhost' => ['https://localhost./jwks', 'blocked'],
            'upper case' => ['https://LOCALHOST/jwks', 'blocked'],
        ];
    }

    /**
     * A public address is accepted.
     */
    public function test_public_address_ok(): void {
        $this->resetAfterTest();
        $this->assertSame(url_guard::OK, url_guard::check('https://93.184.216.34/.well-known/jwks.json'));
    }

    /**
     * Private targets are accepted only on explicit permission, but credentials and bad schemes stay refused.
     */
    public function test_allowprivate_is_explicit(): void {
        $this->resetAfterTest();
        $this->assertSame(url_guard::OK, url_guard::check('https://127.0.0.1/jwks', true));
        $this->assertSame(url_guard::OK, url_guard::check('http://10.0.0.5/jwks', true));
        $this->assertSame('credentials', url_guard::check('https://u:p@127.0.0.1/', true));
        $this->assertSame('scheme', url_guard::check('ftp://127.0.0.1/', true));
    }

    /**
     * The built-in rules hold even when the site configuration allows everything.
     */
    public function test_builtin_rules_do_not_depend_on_site_config(): void {
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $this->assertSame('blocked', url_guard::check('https://127.0.0.1/jwks'));
        $this->assertSame('blocked', url_guard::check('https://169.254.169.254/'));
    }

    /**
     * Address classification.
     *
     * @dataProvider address_provider
     * @param string $ip Address.
     * @param bool $internal Expected.
     */
    public function test_is_internal_address(string $ip, bool $internal): void {
        $this->assertSame($internal, url_guard::is_internal_address($ip));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function address_provider(): array {
        return [
            ['93.184.216.34', false],
            ['8.8.8.8', false],
            ['2606:2800:220:1:248:1893:25c8:1946', false],
            ['::ffff:8.8.8.8', false],
            ['127.0.0.1', true],
            ['10.1.2.3', true],
            ['172.31.255.255', true],
            ['172.32.0.1', false],
            ['192.168.0.1', true],
            ['169.254.1.1', true],
            ['0.0.0.0', true],
            ['255.255.255.255', true],
            ['::1', true],
            ['::', true],
            ['::ffff:10.0.0.1', true],
            ['fc00::1', true],
            ['fe80::abcd', true],
            ['ff02::1', true],
            ['not an address', true],
        ];
    }

    /**
     * The core blocklist configuration is honoured.
     */
    public function test_core_blocklist_is_used(): void {
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', "blocked.example.org\n");
        $this->assertSame('blocked', url_guard::check('https://blocked.example.org/jwks'));
    }
}
