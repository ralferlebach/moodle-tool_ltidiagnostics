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

namespace tool_ltidiagnostics\tests;

/**
 * Network laboratory for the negative integration tests (audit item DIA-03): a private certificate authority minted
 * with the openssl command line (expired, wrong host name, missing intermediate, untrusted root) and local servers
 * on 127.0.0.1 (TLS endpoint, proxy that answers 407, listener that drops connections). Everything is real: the
 * plugin's own fetcher with Moodle's curl class connects to these servers; nothing is mocked except the name lookup
 * of the laboratory host names, which point to 127.0.0.1.
 *
 * Requirements: the openssl command line tool and the PHP binary that runs the tests (PHP_BINARY). A missing tool
 * makes the test fail with a clear message; it is never skipped.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class netlab {
    /** @var string Working directory. */
    private $dir;
    /** @var array[] Started processes: [process, pipes]. */
    private $processes = [];
    /** @var resource[] Client sockets that fill a blackhole backlog. */
    private $fillers = [];

    /**
     * Constructor: creates the working directory and the certificate authorities.
     */
    public function __construct() {
        $this->dir = make_request_directory();
        $this->openssl(['version']);
        $this->root('rootA');
        $this->root('rootB');
        $this->intermediate('interA', 'rootA');
    }

    /**
     * Path of a file in the laboratory.
     *
     * @param string $name File name.
     * @return string
     */
    public function path(string $name): string {
        return $this->dir . '/' . $name;
    }

    /**
     * Run openssl.
     *
     * @param string[] $args Arguments.
     * @return string Output.
     */
    private function openssl(array $args): string {
        $cmd = 'openssl ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            throw new \coding_exception('netlab: openssl failed (' . $rc . '): ' . implode("\n", $out));
        }
        return implode("\n", $out);
    }

    /**
     * Configuration file for "openssl ca".
     *
     * @param string $ca CA name.
     * @param string[] $san Subject alternative names (DNS:x, IP:y) or empty for a CA extension.
     * @return string Path.
     */
    private function cnf(string $ca, array $san): string {
        $d = $this->path($ca . '.db');
        if (!is_dir($d)) {
            mkdir($d);
            file_put_contents($d . '/index.txt', '');
            file_put_contents($d . '/serial', sprintf("%04X\n", random_int(4096, 65535)));
        }
        $alt = '';
        foreach ($san as $i => $entry) {
            [$type, $value] = explode(':', $entry, 2);
            $alt .= $type . '.' . ($i + 1) . ' = ' . $value . "\n";
        }
        $text = "[ ca ]\ndefault_ca = d\n[ d ]\ndir = $d\ndatabase = $d/index.txt\nnew_certs_dir = $d\nserial = $d/serial\n"
            . "default_md = sha256\npolicy = p\nunique_subject = no\ncopy_extensions = none\n[ p ]\ncommonName = supplied\n"
            . "[ leaf ]\nbasicConstraints = CA:FALSE\nkeyUsage = digitalSignature, keyEncipherment\n"
            . "extendedKeyUsage = serverAuth\n" . ($alt !== '' ? "subjectAltName = @alt\n[ alt ]\n" . $alt : '')
            . "[ sub ]\nbasicConstraints = critical, CA:TRUE, pathlen:0\nkeyUsage = critical, keyCertSign, cRLSign\n";
        $file = $this->path($ca . '-' . md5($text) . '.cnf');
        file_put_contents($file, $text);
        return $file;
    }

    /**
     * Self-signed root.
     *
     * @param string $name Name.
     */
    private function root(string $name): void {
        $this->openssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '30', '-subj', '/CN=netlab ' . $name,
            '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
            '-keyout', $this->path($name . '.key'), '-out', $this->path($name . '.crt')]);
    }

    /**
     * Sign a request with a CA.
     *
     * @param string $name Name of the new certificate.
     * @param string $ca CA name.
     * @param string $ext Extension section (leaf or sub).
     * @param string[] $san Names.
     * @param string $start Start date (YYYYMMDDHHMMSSZ) or '' for now.
     * @param string $end End date or '' for 30 days.
     */
    private function sign(string $name, string $ca, string $ext, array $san, string $start = '', string $end = ''): void {
        $this->openssl(['req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=' . $name, '-keyout',
            $this->path($name . '.key'), '-out', $this->path($name . '.csr')]);
        $args = ['ca', '-batch', '-notext', '-config', $this->cnf($ca, $san), '-cert', $this->path($ca . '.crt'), '-keyfile',
            $this->path($ca . '.key'), '-in', $this->path($name . '.csr'), '-out', $this->path($name . '.crt'), '-extensions',
            $ext];
        $args = array_merge($args, $start !== '' ? ['-startdate', $start] : [], $end !== '' ? ['-enddate', $end] : ['-days', '30']);
        $this->openssl($args);
    }

    /**
     * Intermediate CA.
     *
     * @param string $name Name.
     * @param string $root Issuing root.
     */
    private function intermediate(string $name, string $root): void {
        $this->sign($name, $root, 'sub', []);
    }

    /**
     * Mint a server certificate.
     *
     * @param string $name Name.
     * @param string $ca Issuer (rootA, rootB or interA).
     * @param string[] $hosts DNS names.
     * @param bool $expired True for a certificate that expired in 2020.
     * @param bool $withchain True to append the intermediate to the served chain.
     * @return array cert (chain file), key
     */
    public function server_cert(string $name, string $ca, array $hosts, bool $expired = false, bool $withchain = false): array {
        $san = array_merge(array_map(function ($h) {
            return 'DNS:' . $h;
        }, $hosts), ['IP:127.0.0.1']);
        $this->sign($name, $ca, 'leaf', $san, $expired ? '20200101000000Z' : '', $expired ? '20200201000000Z' : '');
        $chain = file_get_contents($this->path($name . '.crt'));
        if ($withchain) {
            $chain .= file_get_contents($this->path($ca . '.crt'));
        }
        file_put_contents($this->path($name . '.chain.pem'), $chain);
        return ['cert' => $this->path($name . '.chain.pem'), 'key' => $this->path($name . '.key')];
    }

    /**
     * Start a laboratory server.
     *
     * @param array $config mode, cert, key, routes, log, skew.
     * @return int Port.
     */
    public function start(array $config): int {
        global $CFG;
        $file = $this->path('server-' . count($this->processes) . '.json');
        file_put_contents($file, json_encode($config + ['lifetime' => 300]));
        $script = $CFG->dirroot . '/admin/tool/ltidiagnostics/tests/fixtures/netlab_server.php';
        $process = proc_open([PHP_BINARY, $script, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \coding_exception('netlab: cannot start ' . PHP_BINARY);
        }
        $this->processes[] = [$process, $pipes];
        stream_set_blocking($pipes[1], true);
        $read = [$pipes[1]];
        $write = null;
        $except = null;
        if (!stream_select($read, $write, $except, 10)) {
            throw new \coding_exception('netlab: server did not start: ' . stream_get_contents($pipes[2]));
        }
        $port = (int) trim((string) fgets($pipes[1]));
        if ($port <= 0) {
            throw new \coding_exception('netlab: server did not report a port: ' . stream_get_contents($pipes[2]));
        }
        if (($config['mode'] ?? '') === 'blackhole') {
            // Fill the accept queue: the kernel drops further connection attempts while it is full.
            for ($i = 0; $i < 4; $i++) {
                $s = @stream_socket_client(
                    'tcp://127.0.0.1:' . $port,
                    $errno,
                    $errstr,
                    1,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
                );
                if ($s) {
                    $this->fillers[] = $s;
                }
            }
            usleep(300000);
        }
        return $port;
    }

    /**
     * Stop every server and remove the working directory.
     */
    public function stop(): void {
        foreach ($this->fillers as $s) {
            @fclose($s);
        }
        $this->fillers = [];
        foreach ($this->processes as [$process, $pipes]) {
            @proc_terminate($process, 9);
            foreach ($pipes as $p) {
                @fclose($p);
            }
            @proc_close($process);
        }
        $this->processes = [];
        remove_dir($this->dir);
    }
}
