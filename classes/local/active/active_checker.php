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

namespace tool_ltidiagnostics\local\active;

/**
 * Safe active checks: DNS, TLS, reachability, JWKS and framing headers, using GET only.
 *
 * The checker never sends a body, a credential or a token and never mutates anything. Every URL
 * passes the {@see url_guard}. The result of a check is evidence of kind "active_probe": it
 * proves reachability from this server, not that an LTI transaction works.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class active_checker {
    /** @var string Purpose: JWKS endpoint. */
    public const PURPOSE_JWKS = 'jwks';
    /** @var string Purpose: tool launch page (framing headers are inspected). */
    public const PURPOSE_LAUNCH = 'launch';
    /** @var string Purpose: any other endpoint, reachability only. */
    public const PURPOSE_ENDPOINT = 'endpoint';

    /** @var http_fetcher */
    private $fetcher;
    /** @var dns_resolver */
    private $dns;
    /** @var bool */
    private $allowprivate;
    /** @var string[] Exact allow-list of private targets. */
    private $exact;

    /**
     * Constructor.
     *
     * @param http_fetcher|null $fetcher HTTP component (default: Moodle curl).
     * @param dns_resolver|null $dns DNS component (default: system resolver).
     * @param bool|null $allowprivate Whether private targets are allowed (default: plugin setting).
     * @param string[]|null $exact Exact allow-list of private targets (default: plugin setting).
     */
    public function __construct(
        ?http_fetcher $fetcher = null,
        ?dns_resolver $dns = null,
        ?bool $allowprivate = null,
        ?array $exact = null
    ) {
        $this->dns = $dns ?? new system_dns_resolver();
        $this->exact = $exact ?? url_guard::exact_list();
        $this->fetcher = $fetcher ?? new curl_fetcher($this->dns, $this->exact);
        $this->allowprivate = $allowprivate ?? (bool) \tool_ltidiagnostics\local\config::get('allowprivatetargets');
    }

    /**
     * Run the checks for one URL.
     *
     * @param string $url The URL.
     * @param string $purpose One of the PURPOSE_* constants.
     * @param int $now Local time (injectable for tests).
     * @param string $expectedkid Optional kid to look for in a JWKS.
     * @return array url, purpose, code (classifier code), dns (null|array), httpstatus, certexpiry, skew, framing, jwks,
     *         redirects (count), viaproxy, pinned, blockreason, checkedat
     */
    public function check(string $url, string $purpose, int $now, string $expectedkid = ''): array {
        $out = [
            'url' => $url, 'purpose' => $purpose, 'code' => '', 'dns' => null, 'httpstatus' => 0, 'certexpiry' => null,
            'skew' => null, 'framing' => null, 'jwks' => null, 'redirects' => 0, 'checkedat' => $now,
            'viaproxy' => false, 'pinned' => false, 'pinmode' => '', 'blockreason' => '',
        ];
        $reason = url_guard::check($url, $this->allowprivate, $this->exact);
        if ($reason !== url_guard::OK) {
            $out['code'] = 'blocked_by_policy';
            $out['blockreason'] = $reason;
            return $out;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $addresses = $this->dns->resolve(trim($host, '[]'));
        $out['dns'] = $addresses;
        if ($addresses === null || $addresses === []) {
            $out['code'] = 'dns_failure';
            return $out;
        }
        if (!$this->allowprivate && !url_guard::exact_allowed($url, $this->exact)) {
            foreach ($addresses as $address) {
                if (url_guard::is_internal_address($address)) {
                    $out['code'] = 'blocked_by_policy';
                    $out['blockreason'] = 'resolved_private';
                    return $out;
                }
            }
        }
        $result = $this->fetcher->get($url, $this->allowprivate);
        $out['code'] = error_classifier::classify($result);
        $out['blockreason'] = $result->blocked;
        $out['viaproxy'] = !empty($result->info['viaproxy']);
        $out['pinned'] = !empty($result->info['pinned']);
        $out['pinmode'] = (string) ($result->info['pinmode'] ?? '');
        $out['httpstatus'] = $result->httpstatus;
        $out['redirects'] = count($result->redirects);
        if (!empty($result->info['certinfo'])) {
            $out['certexpiry'] = error_classifier::cert_expiry((array) $result->info['certinfo']);
        }
        $out['skew'] = error_classifier::clock_skew($result->headers, $now);
        if ($purpose === self::PURPOSE_LAUNCH && $result->httpstatus > 0) {
            $out['framing'] = error_classifier::framing($result->headers);
        }
        if ($purpose === self::PURPOSE_JWKS && $out['code'] === 'ok') {
            $out['jwks'] = jwks_analyser::analyse($result->body, $expectedkid);
        }
        return $out;
    }
}
