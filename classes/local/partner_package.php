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

namespace tool_ltidiagnostics\local;

use tool_ltidiagnostics\local\active\active_checker;
use tool_ltidiagnostics\local\active\jwks_analyser;

/**
 * Partner diagnostic package (spec V2 DIA-UX-08; audit items DIA-02, DIA-03): the non-sensitive view one site has of a
 * connection, exported as JSON, handed to the administrator of the other site and compared there with its own view.
 *
 * This is the only way to check the values that each side holds about the other without any network access between
 * the diagnostics of the two sites: issuer (iss), client id (aud), deployment id, the endpoints each side expects of
 * the other, and the signing key (kid and RFC 7638 thumbprint, both public). Identifiers travel as SHA-256 hashes with a
 * masked display form; nothing secret is in the package (no private key, token, secret, user data or log line).
 * The comparison never changes a setting: it only produces findings with the responsible side.
 *
 * A live check ({@see self::live_check()}) additionally fetches the partner's key set through the SSRF guard and
 * checks that the key the partner declares is really served (two-system check, read only).
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class partner_package {
    /** @var string Schema identifier. */
    public const SCHEMA = 'ltidiag-partner-1';
    /** @var int Largest accepted package in bytes. */
    public const MAXBYTES = 65536;
    /** @var string Snapshot kind of an imported package. */
    public const KIND = 'partner';

    /**
     * Export the view of this site.
     *
     * @param connection $c Connection.
     * @param array $facts Facts (for the field column), may be empty.
     * @param int $now Time.
     * @return array
     */
    public static function export(connection $c, array $facts, int $now): array {
        global $CFG;
        $plugin = \core_plugin_manager::instance()->get_plugin_info('tool_ltidiagnostics');
        $own = $c->role === connection::ROLE_PLATFORM ? self::platform_own() : self::tool_own($c);
        $expects = [];
        foreach ($c->endpoints as $name => $url) {
            $expects[$name] = self::url_entry((string) $url);
        }
        $statickey = '';
        if ($c->role === connection::ROLE_PLATFORM && ($c->facts['keytype'] ?? '') === 'RSA_KEY') {
            $statickey = self::platform_static_thumbprint($c);
        }
        $fields = [];
        $column = $c->role === connection::ROLE_PLATFORM ? field_matrix::platform_column($c)
            : field_matrix::tool_column($c, $facts);
        foreach ($column as $field => [$state]) {
            $fields[$field] = $state;
        }
        return [
            'schema' => self::SCHEMA,
            'generated' => gmdate('c', $now),
            'role' => $c->role,
            'diagnosticid' => support::diagnostic_id($c->key),
            'software' => ['moodle' => (string) $CFG->branch, 'plugin' => $plugin ? (string) $plugin->release : ''],
            'lti' => [
                'version' => $c->ltiversion,
                'state' => $c->state,
                'issuer' => self::id_entry($c->issuer, true),
                'clientid' => self::id_entry($c->clientid),
                'deploymentid' => self::id_entry($c->deploymentid),
            ],
            'own' => $own,
            'expects' => $expects,
            'statickey' => $statickey,
            'fields' => $fields,
        ];
    }

    /**
     * Endpoints and key of this site as an LTI platform (mod_lti).
     *
     * @return array issuer, endpoints (auth, token, jwks), keys (kid, thumbprint)
     */
    private static function platform_own(): array {
        global $CFG;
        $keys = [];
        try {
            $jwks = \mod_lti\local\ltiopenid\jwks_helper::get_jwks();
            foreach ((array) ($jwks['keys'] ?? []) as $key) {
                $keys[] = ['kid' => (string) ($key['kid'] ?? ''), 'thumbprint' => jwks_analyser::thumbprint((array) $key)];
            }
        } catch (\Throwable $e) {
            $keys = [];
        }
        return [
            'issuer' => self::id_entry($CFG->wwwroot, true),
            'endpoints' => [
                'auth' => self::url_entry($CFG->wwwroot . '/mod/lti/auth.php'),
                'oauth' => self::url_entry($CFG->wwwroot . '/mod/lti/token.php'),
                'jwks' => self::url_entry($CFG->wwwroot . '/mod/lti/certs.php'),
            ],
            'keys' => $keys,
        ];
    }

    /**
     * Endpoints and key of this site as an LTI tool (enrol_lti) for one registration.
     *
     * @param connection $c Tool connection.
     * @return array issuer, endpoints (initiatelogin, launch, deeplinking, jwks), keys
     */
    private static function tool_own(connection $c): array {
        global $CFG, $DB;
        $uniqueid = (string) $DB->get_field('enrol_lti_app_registration', 'uniqueid', ['id' => $c->localid]);
        $kid = (string) get_config('enrol_lti', 'lti_13_kid');
        $thumb = jwks_analyser::thumbprint_from_pem((string) get_config('enrol_lti', 'lti_13_privatekey'));
        return [
            'issuer' => self::id_entry($CFG->wwwroot, true),
            'endpoints' => [
                'initiatelogin' => self::url_entry($CFG->wwwroot . '/enrol/lti/login.php?id=' . $uniqueid),
                'launch' => self::url_entry($CFG->wwwroot . '/enrol/lti/launch.php'),
                'deeplinking' => self::url_entry($CFG->wwwroot . '/enrol/lti/launch_deeplink.php'),
                'jwks' => self::url_entry($CFG->wwwroot . '/enrol/lti/jwks.php'),
            ],
            'keys' => $kid !== '' || $thumb !== '' ? [['kid' => $kid, 'thumbprint' => $thumb]] : [],
        ];
    }

    /**
     * Thumbprint of a static public key configured on a platform type (no key set URL).
     *
     * @param connection $c Platform connection.
     * @return string
     */
    private static function platform_static_thumbprint(connection $c): string {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $config = lti_get_type_config($c->localid);
        return jwks_analyser::thumbprint_from_pem((string) ($config['publickey'] ?? ''));
    }

    /**
     * Identifier entry: hash for comparison plus a masked form for people.
     *
     * @param string $value Value.
     * @param bool $url True for a URL (normalised before hashing).
     * @return array hash, display
     */
    public static function id_entry(string $value, bool $url = false): array {
        $norm = $url ? self::normalise_url($value) : trim($value);
        return ['hash' => $norm === '' ? '' : hash('sha256', $norm),
            'display' => $url ? redactor::url($value, false) : redactor::identifier($value, false)];
    }

    /**
     * URL entry: hash of the normalised URL and a redacted display form.
     *
     * @param string $url URL.
     * @return array hash, display
     */
    public static function url_entry(string $url): array {
        $norm = self::normalise_url($url);
        return ['hash' => $norm === '' ? '' : hash('sha256', $norm), 'display' => redactor::url($url, true)];
    }

    /**
     * Normalise a URL for comparison: lower case scheme and host, default port removed, no trailing slash on an empty
     * path, query kept as it is.
     *
     * @param string $url URL.
     * @return string
     */
    public static function normalise_url(string $url): string {
        $url = trim($url);
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            return $url;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $port = isset($p['port']) && !(($scheme === 'https' && (int) $p['port'] === 443)
            || ($scheme === 'http' && (int) $p['port'] === 80)) ? ':' . (int) $p['port'] : '';
        $path = (string) ($p['path'] ?? '');
        $path = $path === '/' ? '' : rtrim($path, '/');
        return $scheme . '://' . strtolower((string) $p['host']) . $port . $path . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /**
     * Parse and validate an imported package. Unknown members are dropped; strings are cleaned.
     *
     * @param string $json Text.
     * @return array The clean package.
     * @throws \moodle_exception invalidpartnerpackage
     */
    public static function parse(string $json): array {
        if (strlen($json) > self::MAXBYTES) {
            throw new \moodle_exception('invalidpartnerpackage', 'tool_ltidiagnostics', '', 'size');
        }
        $data = json_decode($json, true);
        if (
            !is_array($data) || ($data['schema'] ?? '') !== self::SCHEMA
                || !in_array($data['role'] ?? '', [connection::ROLE_PLATFORM, connection::ROLE_TOOL], true)
        ) {
            throw new \moodle_exception('invalidpartnerpackage', 'tool_ltidiagnostics', '', 'schema');
        }
        $entry = function ($e): array {
            $e = is_array($e) ? $e : [];
            $hash = (string) ($e['hash'] ?? '');
            return ['hash' => preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '',
                'display' => clean_param(\core_text::substr((string) ($e['display'] ?? ''), 0, 255), PARAM_TEXT)];
        };
        $entries = function ($list) use ($entry): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $k => $v) {
                $k = clean_param((string) $k, PARAM_ALPHANUMEXT);
                if ($k !== '' && count($out) < 20) {
                    $out[$k] = $entry($v);
                }
            }
            return $out;
        };
        $keys = [];
        foreach ((array) ($data['own']['keys'] ?? []) as $key) {
            if (is_array($key) && count($keys) < 10) {
                $keys[] = ['kid' => clean_param(\core_text::substr((string) ($key['kid'] ?? ''), 0, 255), PARAM_TEXT),
                    'thumbprint' => preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($key['thumbprint'] ?? ''))];
            }
        }
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $field => $state) {
            if (
                is_string($field) && preg_match('/^[a-z0-9_.\-]{1,80}$/', $field) && is_string($state)
                    && preg_match('/^[a-z_]{1,30}$/', $state) && count($fields) < 100
            ) {
                $fields[$field] = $state;
            }
        }
        $lti = (array) ($data['lti'] ?? []);
        return [
            'schema' => self::SCHEMA,
            'generated' => clean_param((string) ($data['generated'] ?? ''), PARAM_TEXT),
            'role' => $data['role'],
            'diagnosticid' => clean_param((string) ($data['diagnosticid'] ?? ''), PARAM_ALPHANUMEXT),
            'software' => ['moodle' => clean_param((string) ($data['software']['moodle'] ?? ''), PARAM_ALPHANUMEXT),
                'plugin' => clean_param((string) ($data['software']['plugin'] ?? ''), PARAM_TEXT)],
            'lti' => [
                'version' => clean_param((string) ($lti['version'] ?? ''), PARAM_TEXT),
                'state' => clean_param((string) ($lti['state'] ?? ''), PARAM_ALPHANUMEXT),
                'issuer' => $entry($lti['issuer'] ?? []),
                'clientid' => $entry($lti['clientid'] ?? []),
                'deploymentid' => $entry($lti['deploymentid'] ?? []),
            ],
            'own' => ['issuer' => $entry($data['own']['issuer'] ?? []), 'endpoints' => $entries($data['own']['endpoints'] ?? []),
                'keys' => $keys],
            'expects' => $entries($data['expects'] ?? []),
            'statickey' => preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($data['statickey'] ?? '')),
            'fields' => $fields,
        ];
    }

    /**
     * Compare the own view with a partner package.
     *
     * @param connection $c Local connection.
     * @param array $partner Parsed partner package.
     * @param int $now Time.
     * @return array findings (finding[]), keys (match bool|null, time), mismatches (list of codes)
     */
    public static function compare(connection $c, array $partner, int $now): array {
        $local = self::export($c, [], $now);
        $out = [];
        $opt = ['evidencekind' => status::KIND_CONFIG, 'evidence' => 'partner_package:' . $partner['diagnosticid'],
            'checkedat' => $now, 'certainty' => finding::CERTAIN];
        $add = function (string $code, string $layer, string $sev, string $who, array $extra = []) use (&$out, $opt) {
            $out[] = new finding($code, $layer, $sev, $who, $extra + $opt);
        };
        if ($partner['role'] === $c->role) {
            $add(
                'partner_role_conflict',
                finding::L_CONFIG,
                finding::BLOCKING,
                finding::ADMIN,
                ['a' => $c->role, 'system' => finding::SYS_BOTH]
            );
            return ['findings' => $out, 'keys' => null, 'mismatches' => ['partner_role_conflict']];
        }
        $platform = $c->role === connection::ROLE_PLATFORM ? $local : $partner;
        $tool = $c->role === connection::ROLE_PLATFORM ? $partner : $local;
        $same = function (array $a, array $b): ?bool {
            if (($a['hash'] ?? '') === '' || ($b['hash'] ?? '') === '') {
                return null;
            }
            return hash_equals($a['hash'], $b['hash']);
        };
        // The tool registered the platform's issuer, client id and deployment id: every one must equal the platform's.
        if ($tool['lti']['state'] !== '' && $tool['lti']['state'] !== 'complete') {
            $add(
                'partner_registration_incomplete',
                finding::L_CONFIG,
                finding::BLOCKING,
                $c->role === connection::ROLE_TOOL ? finding::ADMIN : finding::PARTNER,
                ['system' => $c->role === connection::ROLE_TOOL ? finding::SYS_LOCAL : finding::SYS_PARTNER]
            );
        }
        $checks = [
            'partner_iss_mismatch' => [$platform['own']['issuer'], $tool['lti']['issuer']],
            'partner_aud_mismatch' => [$platform['lti']['clientid'], $tool['lti']['clientid']],
        ];
        foreach ($checks as $code => [$a, $b]) {
            if ($same($a, $b) === false) {
                $add($code, finding::L_OIDC, finding::BLOCKING, finding::PARTNER, ['system' => finding::SYS_BOTH,
                    'a' => (object) ['platform' => $a['display'], 'tool' => $b['display']]]);
            }
        }
        if (($tool['lti']['deploymentid']['hash'] ?? '') === '') {
            $add(
                'partner_registration_missing',
                finding::L_CONFIG,
                finding::BLOCKING,
                $c->role === connection::ROLE_TOOL ? finding::ADMIN : finding::PARTNER,
                ['system' => $c->role === connection::ROLE_TOOL ? finding::SYS_LOCAL : finding::SYS_PARTNER]
            );
        } else if ($same($platform['lti']['deploymentid'], $tool['lti']['deploymentid']) === false) {
            $add(
                'partner_deployment_mismatch',
                finding::L_OIDC,
                finding::BLOCKING,
                finding::PARTNER,
                ['system' => finding::SYS_BOTH, 'a' => (object) ['platform' => $platform['lti']['deploymentid']['display'],
                'tool' => $tool['lti']['deploymentid']['display']]]
            );
        }
        // Endpoints each side expects of the other.
        $pairs = [
            // Tool side expectations of the platform.
            'auth' => [$tool['expects']['auth'] ?? null, $platform['own']['endpoints']['auth'] ?? null, 'tool'],
            'oauth' => [$tool['expects']['oauth'] ?? null, $platform['own']['endpoints']['oauth'] ?? null, 'tool'],
            'jwks_platform' => [$tool['expects']['jwks'] ?? null, $platform['own']['endpoints']['jwks'] ?? null, 'tool'],
            // Platform side expectations of the tool.
            'initiatelogin' => [$platform['expects']['initiatelogin'] ?? null, $tool['own']['endpoints']['initiatelogin'] ?? null,
                'platform'],
            'jwks_tool' => [$platform['expects']['jwks'] ?? null, $tool['own']['endpoints']['jwks'] ?? null, 'platform'],
        ];
        $wrong = [];
        foreach ($pairs as $name => [$expected, $actual, $holder]) {
            if ($expected !== null && $actual !== null && $same($expected, $actual) === false) {
                $wrong[$holder][] = $name;
            }
        }
        foreach ($wrong as $holder => $names) {
            $localholder = $holder === $c->role;
            $add(
                'partner_endpoint_mismatch',
                finding::L_CONFIG,
                finding::BLOCKING,
                $localholder ? finding::ADMIN : finding::PARTNER,
                ['system' => $localholder ? finding::SYS_LOCAL : finding::SYS_PARTNER, 'a' => implode(', ', $names)]
            );
        }
        // Keys: a static key on the platform must be the tool's current key.
        $keys = null;
        if ($platform['statickey'] !== '') {
            $thumbs = array_filter(array_column($tool['own']['keys'], 'thumbprint'));
            if ($thumbs) {
                $keys = ['match' => in_array($platform['statickey'], $thumbs, true), 'time' => $now];
                if (!$keys['match']) {
                    $add(
                        'partner_key_stale',
                        finding::L_OIDC,
                        finding::BLOCKING,
                        $c->role === connection::ROLE_PLATFORM ? finding::ADMIN : finding::PARTNER,
                        ['system' => $c->role === connection::ROLE_PLATFORM ? finding::SYS_LOCAL : finding::SYS_PARTNER]
                    );
                }
            }
        }
        $mismatches = [];
        foreach ($out as $f) {
            $mismatches[] = $f->code;
        }
        if (!$out) {
            $add('partner_ok', finding::L_CONFIG, finding::OK, finding::ADMIN, ['system' => finding::SYS_BOTH]);
        }
        return ['findings' => $out, 'keys' => $keys, 'mismatches' => $mismatches];
    }

    /**
     * Two-system live check, read only: fetch the key set URL the partner declares for itself through the SSRF guard
     * and check that the key it declares is served there (and, for the tool's key, that this platform's configured key
     * set URL is the same document).
     *
     * @param connection $c Local connection.
     * @param array $partner Parsed partner package.
     * @param string $jwksurl The key set URL to fetch: the one this site has configured for the partner (it is what the
     *        LTI flow really uses); the package only carries its hash, which is compared first.
     * @param int $now Time.
     * @param active_checker|null $checker Checker (default: real one with the plugin settings).
     * @return array probe (active check result), findings (finding[]), keys (match, time)
     */
    public static function live_check(
        connection $c,
        array $partner,
        string $jwksurl,
        int $now,
        ?active_checker $checker = null
    ): array {
        $declared = $partner['own']['keys'][0] ?? ['kid' => '', 'thumbprint' => ''];
        $checker = $checker ?? new active_checker();
        $probe = $checker->check($jwksurl, active_checker::PURPOSE_JWKS, $now, (string) $declared['kid']);
        $opt = ['evidencekind' => status::KIND_PROBE, 'evidence' => 'partner_live:jwks', 'checkedat' => $now];
        $findings = [];
        $keys = null;
        $urlmatch = ($partner['own']['endpoints']['jwks']['hash'] ?? '') === ''
            || hash_equals($partner['own']['endpoints']['jwks']['hash'], hash('sha256', self::normalise_url($jwksurl)));
        if (!$urlmatch) {
            $findings[] = new finding(
                'partner_endpoint_mismatch',
                finding::L_CONFIG,
                finding::BLOCKING,
                finding::ADMIN,
                $opt + ['a' => 'jwks', 'certainty' => finding::CERTAIN]
            );
        }
        if (($probe['code'] ?? '') !== 'ok' || empty($probe['jwks'])) {
            $findings[] = new finding(
                'partner_live_unreachable',
                finding::L_NETWORK,
                finding::BLOCKING,
                finding::NETWORK,
                $opt + ['a' => (string) ($probe['code'] ?? '')]
            );
        } else {
            $thumbs = array_filter(array_column($probe['jwks']['keys'], 'thumbprint'));
            $kidok = $declared['kid'] === '' || $probe['jwks']['haskid'] !== false;
            $thumbok = $declared['thumbprint'] === '' || in_array($declared['thumbprint'], $thumbs, true);
            $keys = ['match' => $kidok && $thumbok, 'time' => $now];
            $findings[] = $keys['match']
                ? new finding('partner_live_kid_ok', finding::L_OIDC, finding::OK, finding::PARTNER, $opt)
                : new finding(
                    'partner_live_kid_missing',
                    finding::L_OIDC,
                    finding::BLOCKING,
                    finding::PARTNER,
                    $opt + ['certainty' => finding::CERTAIN, 'system' => finding::SYS_PARTNER]
                );
        }
        return ['probe' => $probe, 'findings' => $findings, 'keys' => $keys];
    }
}
