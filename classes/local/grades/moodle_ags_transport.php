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

use tool_ltidiagnostics\local\active\curl_fetcher;
use tool_ltidiagnostics\local\active\url_guard;
use tool_ltidiagnostics\local\config;

/**
 * AGS transport over Moodle's curl class with the SSRF protection of the active checks.
 *
 * Every request: URL guard (https, no credentials, no internal targets unless an administrator allowed them), one DNS
 * resolution pinned for the connection (no rebinding), strict TLS verification, peer address check after the transfer,
 * no redirects at all (a redirect is a finding, not something to follow with a bearer token), bounded size and time.
 * The access token and the client assertion exist only in memory during the request.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_ags_transport extends curl_fetcher implements ags_transport {
    /** @var int Maximum response size that is parsed. */
    private const MAXJSON = 1048576;

    /**
     * {@inheritDoc}
     *
     * @param \Packback\Lti1p3\Interfaces\ILtiRegistration $registration Registration.
     * @param string[] $scopes Scopes.
     * @return array
     */
    public function token($registration, array $scopes): array {
        $now = time();
        $clientid = (string) $registration->getClientId();
        $assertion = \Firebase\JWT\JWT::encode([
            'iss' => $clientid,
            'sub' => $clientid,
            'aud' => (string) $registration->getAuthServer(),
            'iat' => $now - 5,
            'exp' => $now + 60,
            'jti' => 'ltidiag-' . hash('sha256', random_bytes(32)),
        ], $registration->getToolPrivateKey(), 'RS256', $registration->getKid());
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => implode(' ', $scopes),
        ], '', '&');
        $r = $this->request('POST', (string) $registration->getAuthTokenUrl(), [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);
        $out = ['status' => $r['status'], 'errorclass' => $r['errorclass'], 'token' => '', 'scopes' => []];
        if ($r['errorclass'] !== '') {
            $out['errorclass'] = $r['errorclass'] === 'http_400' || $r['errorclass'] === 'http_401' ? 'token' : $r['errorclass'];
            return $out;
        }
        $json = json_decode($r['body'], true);
        if (!is_array($json) || empty($json['access_token']) || !is_string($json['access_token'])) {
            $out['errorclass'] = 'token';
            return $out;
        }
        $out['token'] = $json['access_token'];
        if (!empty($json['scope']) && is_string($json['scope'])) {
            $out['scopes'] = preg_split('/\s+/', trim($json['scope']));
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $url URL.
     * @param string $token Token.
     * @param array $score Score.
     * @return array
     */
    public function post_score(string $url, string $token, array $score): array {
        $r = $this->request('POST', $url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/vnd.ims.lis.v1.score+json',
            'Accept: application/json',
        ], json_encode($score, JSON_UNESCAPED_SLASHES));
        return ['status' => $r['status'], 'errorclass' => $r['errorclass']];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $url URL.
     * @param string $token Token.
     * @param string $accept Accept.
     * @return array
     */
    public function get_json(string $url, string $token, string $accept): array {
        $r = $this->request('GET', $url, ['Authorization: Bearer ' . $token, 'Accept: ' . $accept], '');
        $json = null;
        if ($r['errorclass'] === '') {
            $decoded = json_decode($r['body'], true);
            $json = is_array($decoded) ? $decoded : null;
            if ($json === null) {
                $r['errorclass'] = 'http_other';
            }
        }
        return ['status' => $r['status'], 'errorclass' => $r['errorclass'], 'json' => $json];
    }

    /**
     * One request without redirects.
     *
     * @param string $method GET or POST.
     * @param string $url URL.
     * @param string[] $headers Headers.
     * @param string $body Body.
     * @return array status, errorclass ('' for 2xx), body (string, only kept for the caller in memory).
     */
    protected function request(string $method, string $url, array $headers, string $body): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $allowprivate = (bool) config::get('allowprivatetargets');
        $reason = url_guard::check($url, $allowprivate, $this->exact);
        if ($reason !== url_guard::OK) {
            return ['status' => 0, 'errorclass' => 'blocked_by_policy', 'body' => ''];
        }
        $exactallowed = !$allowprivate && url_guard::exact_allowed($url, $this->exact);
        $pin = ['options' => [], 'addresses' => []];
        $viaproxy = self::uses_proxy($url);
        if (!$allowprivate) {
            $pin = self::pin($url, $this->dns, $exactallowed);
            if ($pin === null) {
                return ['status' => 0, 'errorclass' => 'blocked_by_policy', 'body' => ''];
            }
            if ($viaproxy) {
                $pin['options'] = self::proxy_pin_options($url, $pin['addresses']);
            }
        }
        $options = self::build_options($pin['options'], $allowprivate, $this->limits);
        unset($options['CURLOPT_CERTINFO']);
        $options['CURLOPT_MAXFILESIZE'] = self::MAXJSON;
        if (!$viaproxy) {
            $options['CURLOPT_PROXY'] = '';
        }
        $t = $this->send_request($method, $url, $options, $headers, $body, $allowprivate || $exactallowed);
        $status = (int) ($t['info']['http_code'] ?? 0);
        if (
            !self::connected_to_validated(
                $pin['addresses'],
                (string) ($t['info']['primary_ip'] ?? ''),
                $viaproxy,
                $allowprivate
            )
        ) {
            return ['status' => 0, 'errorclass' => 'blocked_by_policy', 'body' => ''];
        }
        if ((int) $t['errno'] !== 0) {
            return ['status' => $status, 'errorclass' => error_classes::from_curl((int) $t['errno']), 'body' => ''];
        }
        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'errorclass' => '',
                'body' => is_string($t['body']) ? substr($t['body'], 0, self::MAXJSON) : ''];
        }
        return ['status' => $status, 'errorclass' => $status === 0 ? 'unclassified' : error_classes::from_http($status),
            'body' => ''];
    }

    /**
     * Perform the transfer. Tests replace this method.
     *
     * @param string $method Method.
     * @param string $url URL.
     * @param array $options Curl options.
     * @param string[] $headers Headers.
     * @param string $body Body.
     * @param bool $ignoresecurity Bypass Moodle's host block list (only for explicitly allowed private targets).
     * @return array body, info, errno.
     */
    protected function send_request(
        string $method,
        string $url,
        array $options,
        array $headers,
        string $body,
        bool $ignoresecurity
    ): array {
        $curl = new \curl(['ignoresecurity' => $ignoresecurity, 'cache' => false]);
        $curl->setHeader($headers);
        $response = $method === 'POST' ? $curl->post($url, $body, $options) : $curl->get($url, [], $options);
        return ['body' => $response, 'info' => (array) $curl->get_info(), 'errno' => (int) $curl->get_errno()];
    }
}
