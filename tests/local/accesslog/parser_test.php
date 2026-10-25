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

namespace tool_ltidiagnostics\local\accesslog;

/**
 * Tests of the web server log parser.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\accesslog\parser
 */
final class parser_test extends \advanced_testcase {
    /** @var string A combined line with a +0200 offset. */
    private const COMBINED = '203.0.113.9 - - [24/Oct/2026:12:00:00 +0200] '
        . '"POST /mod/lti/auth.php?client_id=abc&state=xyz HTTP/1.1" 302 0 "-" "Mozilla/5.0"';

    /**
     * A combined line, the offset of the line wins over the configured zone.
     */
    public function test_combined_line_offset_wins(): void {
        $expected = gmmktime(10, 0, 0, 10, 24, 2026);
        foreach (['UTC', 'Asia/Tokyo', 'America/New_York', 'Not/AZone'] as $tz) {
            $p = parser::parse_access_line(self::COMBINED, 'combined', $tz);
            $this->assertNotNull($p);
            $this->assertSame($expected, $p['time'], $tz);
        }
        $p = parser::parse_access_line(self::COMBINED, 'combined');
        $this->assertSame('POST', $p['method']);
        $this->assertSame(302, $p['status']);
        $this->assertSame('203.0.113.0', $p['ip']);
        $this->assertFalse($p['viaproxy']);
        $this->assertSame('/mod/lti/auth.php?client_id=[redacted]&state=[redacted]', $p['path']);
        $this->assertStringNotContainsString('xyz', json_encode($p));
        $this->assertStringNotContainsString('Mozilla', json_encode($p));
    }

    /**
     * Proxy format: the forwarded address replaces the proxy address and the request id is kept.
     */
    public function test_forwarded_address_and_request_id(): void {
        $line = '10.0.0.2 - - [24/Oct/2026:12:00:00 +0000] "GET /enrol/lti/launch.php?x=1 HTTP/1.1" 200 1234 "-" "UA" '
            . '"198.51.100.7, 10.0.0.2" "req-abc123"';
        $p = parser::parse_access_line($line, 'combined_xff_reqid');
        $this->assertSame('198.51.100.0', $p['ip']);
        $this->assertTrue($p['viaproxy']);
        $this->assertSame('req-abc123', $p['requestid']);
        $this->assertSame(1234, $p['bytes']);

        $reqonly = '10.0.0.2 - - [24/Oct/2026:12:00:00 +0000] "GET /a HTTP/1.1" 200 1 "-" "UA" "r-1"';
        $p = parser::parse_access_line($reqonly, 'combined_reqid');
        $this->assertSame('10.0.0.0', $p['ip']);
        $this->assertFalse($p['viaproxy']);
        $this->assertSame('r-1', $p['requestid']);

        $noxff = '10.0.0.2 - - [24/Oct/2026:12:00:00 +0000] "GET /a HTTP/1.1" 200 1 "-" "UA" "-"';
        $p = parser::parse_access_line($noxff, 'combined_xff');
        $this->assertFalse($p['viaproxy']);
        $this->assertSame('10.0.0.0', $p['ip']);
        $bad = '10.0.0.2 - - [24/Oct/2026:12:00:00 +0000] "GET /a HTTP/1.1" 200 1 "-" "UA" "not-an-ip"';
        $this->assertFalse(parser::parse_access_line($bad, 'combined_xff')['viaproxy']);
    }

    /**
     * A request id is cleaned to safe characters.
     */
    public function test_request_id_is_sanitised(): void {
        $line = '1.1.1.1 - - [24/Oct/2026:12:00:00 +0000] "GET /a HTTP/1.1" 200 1 "-" "UA" "ab<script>cd;--"';
        $p = parser::parse_access_line($line, 'combined_reqid');
        $this->assertSame('abscriptcd--', $p['requestid']);
    }

    /**
     * Common format and format mismatches.
     */
    public function test_common_format(): void {
        $line = '192.0.2.1 - frank [24/Oct/2026:01:00:00 -0500] "GET /mod/lti/certs.php HTTP/1.1" 200 -';
        $p = parser::parse_access_line($line, 'common');
        $this->assertSame(gmmktime(6, 0, 0, 10, 24, 2026), $p['time']);
        $this->assertSame(0, $p['bytes']);
        $this->assertNull(parser::parse_access_line($line, 'combined'), 'A common line lacks referer and agent.');
        $this->assertNull(parser::parse_access_line(self::COMBINED, 'common'), 'A combined line has trailing fields.');
        $this->assertNull(parser::parse_access_line(self::COMBINED, 'nonsense'));
    }

    /**
     * Garbage is rejected, never guessed.
     *
     * @dataProvider garbage_provider
     * @param string $line Line.
     */
    public function test_garbage(string $line): void {
        $this->assertNull(parser::parse_access_line($line, 'combined'));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function garbage_provider(): array {
        return [
            'empty' => [''],
            'text' => ['hello world'],
            'bad time' => ['1.1.1.1 - - [99/Foo/2026:12:00:00 +0000] "GET /a HTTP/1.1" 200 1 "-" "UA"'],
            'bad request' => ['1.1.1.1 - - [24/Oct/2026:12:00:00 +0000] "garbage" 200 1 "-" "UA"'],
            'truncated' => ['1.1.1.1 - - [24/Oct/2026:12:00:00 +0000] "GET /a HTTP/1.1" 200'],
        ];
    }

    /**
     * Error log lines honour the configured zone.
     */
    public function test_nginx_error_line_uses_timezone(): void {
        $line = '2026/10/24 12:00:00 [error] 123#0: *4 connect() failed (111: Connection refused) while connecting to upstream, '
            . 'client: 203.0.113.5, server: a.example, request: "GET /mod/lti/token.php?grant=x HTTP/1.1", host: "a.example"';
        $utc = parser::parse_error_line($line, 'nginx_error', 'UTC');
        $berlin = parser::parse_error_line($line, 'nginx_error', 'Europe/Berlin');
        $this->assertSame(gmmktime(12, 0, 0, 10, 24, 2026), $utc['time']);
        $this->assertSame(gmmktime(10, 0, 0, 10, 24, 2026), $berlin['time']);
        $this->assertSame('error', $utc['level']);
        $this->assertSame('203.0.113.0', $utc['ip']);
        $this->assertSame('/mod/lti/token.php?grant=[redacted]', $utc['path']);
        $this->assertStringNotContainsString('203.0.113.5', $utc['message']);
    }

    /**
     * Apache error lines.
     */
    public function test_apache_error_line(): void {
        $line = '[Sat Oct 24 12:00:00.123456 2026] [proxy_fcgi:error] [pid 12:tid 34] [client 203.0.113.5:4433] AH01071: Got error';
        $p = parser::parse_error_line($line, 'apache_error', 'UTC');
        $this->assertSame(gmmktime(12, 0, 0, 10, 24, 2026), $p['time']);
        $this->assertSame('error', $p['level']);
        $this->assertSame('203.0.113.0', $p['ip']);
        $this->assertNull(parser::parse_error_line('nonsense', 'apache_error'));
        $this->assertNull(parser::parse_error_line($line, 'nginx_error'));
        $this->assertNull(parser::parse_error_line($line, 'unknown'));
    }

    /**
     * Credentials in an error message are removed.
     */
    public function test_error_message_is_redacted(): void {
        $line = '2026/10/24 12:00:00 [warn] 1#0: Authorization: Bearer abcdefghijklmnop mail a@b.example';
        $p = parser::parse_error_line($line, 'nginx_error');
        $this->assertStringNotContainsString('abcdefghijklmnop', $p['message']);
        $this->assertStringNotContainsString('a@b.example', $p['message']);
    }

    /**
     * Format detection.
     */
    public function test_detect_format(): void {
        $xr = '10.0.0.2 - - [24/Oct/2026:12:00:00 +0000] "GET /a HTTP/1.1" 200 1 "-" "UA" "198.51.100.7" "rid"';
        $this->assertSame('combined_xff_reqid', parser::detect_access_format([$xr, $xr]));
        $this->assertSame('combined', parser::detect_access_format([self::COMBINED, '', self::COMBINED]));
        $common = '192.0.2.1 - - [24/Oct/2026:01:00:00 -0500] "GET / HTTP/1.1" 200 5';
        $this->assertSame('common', parser::detect_access_format([$common]));
        $this->assertSame('', parser::detect_access_format(['nonsense', 'more nonsense']));
        $this->assertSame('', parser::detect_access_format([]));
    }

    /**
     * Only LTI paths get a category.
     *
     * @dataProvider category_provider
     * @param string $path Path.
     * @param string $category Expected.
     */
    public function test_categorise(string $path, string $category): void {
        $this->assertSame($category, parser::categorise($path));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function category_provider(): array {
        return [
            ['/mod/lti/token.php', 'oauth'],
            ['/mod/lti/auth.php?x=1', 'oidc'],
            ['/auth/lti/login.php', 'oidc'],
            ['/mod/lti/certs.php', 'jwks'],
            ['/mod/lti/openid-configuration.php', 'dynreg'],
            ['/mod/lti/services.php/2/lineitems/5/lineitem/scores', 'service_ags_scores'],
            ['/mod/lti/services.php/2/lineitems/5/lineitem/results', 'service_ags_results'],
            ['/mod/lti/services.php/2/lineitems', 'service_ags_lineitems'],
            ['/mod/lti/services.php/CourseSection/3/bindings/1/memberships', 'service_nrps'],
            ['/mod/lti/services.php/other', 'service'],
            ['/enrol/lti/launch.php', 'launch'],
            ['/mod/lti/launch.php', 'launch'],
            ['/index.php', ''],
            ['/course/view.php?id=2', ''],
        ];
    }

    /**
     * Time parsing of the supported notations.
     */
    public function test_parse_time(): void {
        $this->assertSame(gmmktime(10, 0, 0, 10, 24, 2026), parser::parse_time('24/Oct/2026:12:00:00 +0200'));
        $this->assertSame(gmmktime(10, 0, 0, 10, 24, 2026), parser::parse_time('2026-10-24T12:00:00+02:00'));
        $this->assertSame(gmmktime(12, 0, 0, 10, 24, 2026), parser::parse_time('2026-10-24 12:00:00', 'UTC'));
        $this->assertSame(gmmktime(10, 0, 0, 10, 24, 2026), parser::parse_time('2026-10-24 12:00:00', 'Europe/Berlin'));
        $this->assertNull(parser::parse_time('yesterday'));
    }

    /**
     * Rotation: the same logical line in a plain and a compressed file parses identically.
     */
    public function test_compressed_and_plain_lines_agree(): void {
        $dir = make_request_directory();
        file_put_contents("$dir/access.log", self::COMBINED . "\n");
        file_put_contents("$dir/access.log.1.gz", gzencode(self::COMBINED . "\n"));
        $plain = parser::parse_access_line(trim(file_get_contents("$dir/access.log")), 'combined');
        $zipped = parser::parse_access_line(trim(implode('', gzfile("$dir/access.log.1.gz"))), 'combined');
        $this->assertSame($plain, $zipped);
    }
}
