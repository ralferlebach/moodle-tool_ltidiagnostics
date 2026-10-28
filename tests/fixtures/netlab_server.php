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

/**
 * Network laboratory server for the negative integration tests (DIA-03). Started by {@see \tool_ltidiagnostics\tests\netlab}
 * as a separate PHP process on 127.0.0.1; prints its port on the first line and serves until it is stopped.
 *
 * Modes (from the JSON configuration file given as the only argument):
 *  - https: TLS server with the given certificate chain and key; answers configured routes (status, headers, body);
 *  - proxy407: plain TCP; records the first request line (e.g. "CONNECT 127.0.0.1:443 HTTP/1.1") in a log file and
 *    answers 407 Proxy Authentication Required;
 *  - blackhole: listens with a backlog of 1 and never accepts, so that further connection attempts are dropped
 *    (the client sees a connect timeout, like behind a firewall that drops packets).
 *
 * This file is a test fixture: it is not part of the plugin's runtime code and is never reachable through the web server
 * (it refuses to run unless called from the command line).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// phpcs:disable moodle.Files.RequireLogin.Missing

if (PHP_SAPI !== 'cli') {
    die();
}
$config = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (!is_array($config)) {
    fwrite(STDERR, "netlab: no configuration\n");
    exit(2);
}
$mode = (string) ($config['mode'] ?? 'https');
$context = stream_context_create(['socket' => ['backlog' => $mode === 'blackhole' ? 1 : 16]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDERR, "netlab: $errstr\n");
    exit(2);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1) . "\n";
flush();
$deadline = time() + (int) ($config['lifetime'] ?? 300);
if ($mode === 'blackhole') {
    while (time() < $deadline) {
        sleep(1);
    }
    exit(0);
}
while (time() < $deadline) {
    $conn = @stream_socket_accept($server, 1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    if ($mode === 'proxy407') {
        $line = (string) fgets($conn, 4096);
        file_put_contents((string) $config['log'], trim($line) . "\n", FILE_APPEND);
        while (($header = fgets($conn, 4096)) !== false && trim($header) !== '') {
            // Read the request headers.
            continue;
        }
        fwrite($conn, "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm=\"netlab\"\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($conn);
        continue;
    }
    stream_context_set_option($conn, 'ssl', 'local_cert', (string) $config['cert']);
    stream_context_set_option($conn, 'ssl', 'local_pk', (string) $config['key']);
    stream_context_set_option($conn, 'ssl', 'verify_peer', false);
    $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    if ($ok !== true) {
        // The client refused the certificate: that is the point of most scenarios.
        @fclose($conn);
        continue;
    }
    $request = (string) fgets($conn, 8192);
    while (($header = fgets($conn, 8192)) !== false && trim($header) !== '') {
        continue;
    }
    $path = (string) (explode(' ', $request)[1] ?? '/');
    $route = $config['routes'][$path] ?? ['status' => 404, 'body' => 'not found'];
    $body = (string) ($route['body'] ?? '');
    $head = 'HTTP/1.1 ' . (int) ($route['status'] ?? 200) . " X\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n"
        . 'Date: ' . gmdate('D, d M Y H:i:s', time() + (int) ($config['skew'] ?? 0)) . " GMT\r\n";
    foreach ((array) ($route['headers'] ?? []) as $hname => $hvalue) {
        $head .= $hname . ': ' . $hvalue . "\r\n";
    }
    fwrite($conn, $head . "\r\n" . $body);
    @fclose($conn);
}
