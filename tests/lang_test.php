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

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\local\finding;
use tool_ltidiagnostics\local\matrix_builder;
use tool_ltidiagnostics\local\status;
use tool_ltidiagnostics\local\status_engine;

/**
 * Language file completeness: English and German, every string used by the code, no hard-coded UI text.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\finding
 */
final class lang_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * Load a language file of the plugin.
     *
     * @param string $lang Language code.
     * @return string[] Strings.
     */
    private function strings(string $lang): array {
        $string = [];
        include(__DIR__ . "/../lang/$lang/tool_ltidiagnostics.php");
        return $string;
    }

    /**
     * Source files of the plugin that can contain UI strings.
     *
     * @return string[]
     */
    private function sources(): array {
        $root = realpath(__DIR__ . '/..');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $skipped = strpos($path, '/tests/') !== false || strpos($path, '/lang/') !== false;
            if (!preg_match('/\.(php|mustache)$/', $path) || $skipped) {
                continue;
            }
            if (strpos($path, '/node_modules/') !== false || strpos($path, '/.git/') !== false) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);
        return $files;
    }

    /**
     * Both languages have exactly the same keys, sorted, and no empty value.
     */
    public function test_languages_match(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($de))), 'Missing in German');
        $this->assertSame([], array_values(array_diff(array_keys($de), array_keys($en))), 'Missing in English');
        $keys = array_keys($en);
        $sorted = $keys;
        usort($sorted, 'strcmp');
        $this->assertSame($sorted, $keys, 'Keys must be in alphabetical order.');
        foreach (['en' => $en, 'de' => $de] as $lang => $strings) {
            foreach ($strings as $key => $value) {
                $this->assertNotSame('', trim($value), "$lang:$key is empty");
            }
        }
        $this->assertArrayHasKey('pluginname', $en);
    }

    /**
     * Placeholders must agree between the two languages.
     */
    public function test_placeholders_match(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        foreach ($en as $key => $value) {
            preg_match_all('/\{\$a(?:->\w+)?\}/', $value, $a);
            preg_match_all('/\{\$a(?:->\w+)?\}/', $de[$key], $b);
            $x = array_unique($a[0]);
            $y = array_unique($b[0]);
            sort($x);
            sort($y);
            $this->assertSame($x, $y, "Placeholders of $key differ between en and de");
        }
    }

    /**
     * German strings are really translated for the strings that carry meaning (not a copy of English).
     */
    public function test_german_is_translated(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        $same = [];
        foreach ($en as $key => $value) {
            if ($value === $de[$key] && \core_text::strlen($value) > 40 && strpos($key, 'feature_') !== 0) {
                $same[] = $key;
            }
        }
        $this->assertSame([], $same, 'Long strings identical in en and de.');
    }

    /**
     * Every finding code used by the health check has title, detail and fix in both languages.
     */
    public function test_every_finding_code_has_strings(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        $source = file_get_contents(__DIR__ . '/../classes/local/health_check.php');
        preg_match_all("/'((?:cfg|net|fw|tls|oidc|oauth|bs|jwks|skew|adv|core|bg|obs|priv)_[a-z0-9_]+)'/", $source, $m);
        $codes = array_unique($m[1]);
        // Findings of the partner comparison (0.3.0) and of the tool_ltigroupautoenrol adapter (GA-03).
        foreach (['partner_package.php', 'groupenrol_source.php'] as $file) {
            $source = file_get_contents(__DIR__ . '/../classes/local/' . $file);
            preg_match_all("/new finding\(\s*'([a-z0-9_]+)'/", $source, $m);
            preg_match_all("/'((?:partner|ga)_[a-z0-9_]+)'/", $source, $m2);
            $codes = array_merge($codes, $m[1], $m2[1]);
        }
        // Probe failures are built from the classifier codes.
        $probecodes = ['dns_failure', 'connection_refused', 'timeout', 'proxy_407', 'proxy_error', 'transfer_error',
            'blocked_by_policy', 'cert_expired', 'cert_hostname', 'cert_chain_incomplete', 'cert_untrusted', 'cert_invalid',
            'tls_handshake', 'http_denied', 'http_notfound', 'http_client_error', 'http_server_error', 'redirect',
            'firewall_drop_suspected'];
        foreach ($probecodes as $code) {
            $codes[] = 'probe_' . $code;
        }
        // Codes that health_check builds from a loop.
        $codes = array_merge($codes, ['priv_send_name', 'priv_send_email', 'skew_blocking', 'skew_warn']);
        $codes = array_unique(array_diff($codes, ['skew_', 'probe_', 'tls_handshake']));
        $this->assertGreaterThan(70, count($codes));
        foreach ($codes as $code) {
            foreach (['title', 'detail', 'fix'] as $part) {
                $id = "f_{$code}_{$part}";
                $this->assertArrayHasKey($id, $en, $id);
                $this->assertArrayHasKey($id, $de, $id);
            }
        }
        // The reverse: no string family without a code.
        $known = array_flip($codes);
        foreach (array_keys($en) as $key) {
            if (preg_match('/^f_(.+)_(title|detail|fix)$/', $key, $mm)) {
                $this->assertArrayHasKey($mm[1], $known, "Unused finding strings: $key");
            }
        }
    }

    /**
     * Finding strings can be rendered with their parameters.
     */
    public function test_finding_strings_render(): void {
        $f = new finding('adv_localoff', 'advantage', 'blocking', 'admin', ['a' => (object) ['feature' => 'AGS', 'status' => 'x']]);
        $strings = $f->strings();
        $this->assertStringContainsString('AGS', $strings['title'] . $strings['detail']);
        $this->assertStringNotContainsString('{$a', json_encode($strings));
        $this->assertSame(['title', 'detail', 'fix', 'verify', 'where', 'uncertain', 'alt'], array_keys($strings));
        foreach (finding::layers() as $layer) {
            $g = new finding('cfg_ok', $layer, 'ok', 'admin');
            $this->assertNotSame('', $g->strings()['verify']);
            $this->assertNotSame('', $g->strings()['where'], "where_$layer");
            $this->assertNotSame('', $g->strings()['uncertain'], "uncertain_$layer");
        }
    }

    /**
     * Every string identifier requested with a literal in the code exists in both languages.
     */
    public function test_literal_string_ids_exist(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        $core = ['name', 'time', 'user', 'search', 'yes', 'no', 'delete', 'cancel', 'edit'];
        $missing = [];
        foreach ($this->sources() as $file) {
            $text = file_get_contents($file);
            preg_match_all("/get_string\(\s*'([a-z0-9_:]+)'\s*(?:,\s*'([a-z_]+)')?/i", $text, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $component = $hit[2] ?? '';
                if ($component !== 'tool_ltidiagnostics') {
                    continue;
                }
                if (!isset($en[$hit[1]]) || !isset($de[$hit[1]])) {
                    $missing[] = $hit[1] . ' in ' . basename($file);
                }
            }
            preg_match_all("/\{\{#str\}\}\s*([a-z0-9_]+)\s*,\s*tool_ltidiagnostics/", $text, $t);
            foreach ($t[1] as $id) {
                if (!isset($en[$id]) || !isset($de[$id])) {
                    $missing[] = $id . ' (template ' . basename($file) . ')';
                }
            }
        }
        $this->assertSame([], $missing);
        $this->assertNotEmpty($core);
    }

    /**
     * Dynamically composed string families are complete over their value sets.
     */
    public function test_dynamic_string_families(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        $families = [
            'status_' => status::all(),
            'state_' => status::states(),
            'step_' => status::steps(),
            'kind_' => [status::KIND_CONFIG, status::KIND_CODE, status::KIND_DBRECORD, status::KIND_OBSERVED,
                status::KIND_LOCALEFFECT, status::KIND_TEST, status::KIND_ACCESSLOG, status::KIND_TASKLOG, status::KIND_PROBE,
                status::KIND_NONE],
            'layer_' => finding::layers(),
            'severity_' => [finding::BLOCKING, finding::WARNING, finding::INFO, finding::OK, finding::NOTCHECKED],
            'responsible_' => [finding::ADMIN, finding::NETWORK, finding::PARTNER, finding::TEACHER],
            'risk_' => ['low', 'medium', 'high'],
            'light_' => ['green', 'amber', 'red', 'grey'],
            'feature_' => matrix_builder::FEATURES,
            'stage_' => local\timeline::STAGES,
            'source_' => ['observer', 'accesslog', 'logstore', 'tasklog'],
            'role_' => ['platform', 'tool'],
            'tab_' => ['matrix', 'health', 'messages', 'timeline', 'evidence', 'history'],
            'system_' => [finding::SYS_LOCAL, finding::SYS_PARTNER, finding::SYS_NETWORK, finding::SYS_BOTH],
            'certainty_' => [finding::CERTAIN, finding::LIKELY, finding::POSSIBLE],
            'msg_' => ['oidc_login_init', 'oidc_auth_request', 'id_token_launch', 'local_effects', 'group_assignment',
                'jwks_served', 'jwks_fetch', 'oauth_token', 'ags_lineitems', 'ags_score', 'ags_results', 'nrps',
                'deeplinking_request', 'deeplinking_response', 'completion_roundtrip', 'custom_claims', 'service_permissions',
                'key_rotation'],
            'msgdir_' => ['in', 'out', 'local'],
            'msgconf_' => ['yes', 'no', 'na'],
            'msgbooked_' => [local\message_matrix::B_YES, local\message_matrix::B_NO, local\message_matrix::B_REMOTE,
                local\message_matrix::B_NA],
            'msgstate_' => local\message_matrix::STATES,
            'msglimit_' => ['no_hook', 'browser_channel', 'transport_only', 'own_probe_only', 'not_observable_remote',
                'task_not_acceptance', 'paging_internal', 'partner_trace', 'declared_not_granted', 'partner_package',
                'fallback_signals', 'plugin_absent'],
            'chain_' => ['launch', 'grades', 'roster', 'completion'],
            'verdict_' => local\message_matrix::VERDICTS,
            'chainreason_' => ['leg_failed', 'booked_remote', 'transport_only', 'not_observable_here', 'not_proven',
                'not_configured'],
            'fieldcat_' => array_merge(array_values(array_unique(local\field_matrix::FIELDS)), ['custom', 'unknown']),
            'fieldstate_' => ['always', 'yes', 'no', 'per_activity', 'unknown', 'validated', 'present', 'absent',
                'not_observable'],
            'fieldagree_' => ['match', 'mismatch', 'na'],
            'transfers_stage_' => local\transfer_report::STAGES,
            'transfers_state_' => ['seen', 'missing', 'not_observable', 'failed'],
            'transfers_verdict_' => ['complete', 'failed', 'limited_no_partner_mode', 'limited_one_side', 'open'],
            'transfers_side_' => ['sender', 'receiver'],
            'transfers_mode_' => ['one_sided', 'two_sided'],
            'transfers_limit_' => ['partner_side_not_observable', 'logstore_truncated', 'logstore_unavailable'],
            'transfers_export_' => ['md', 'json', 'trace'],
            'setting_' => ['retentionaccdays', 'retentionauditdays', 'retentionsnapdays', 'retentionpartnerdays',
                'retentionaccdays_desc', 'retentionauditdays_desc', 'retentionsnapdays_desc', 'retentionpartnerdays_desc'],
            'export_' => local\report_exporter::FORMATS,
            'cron_' => ['never', 'stale', 'ok'],
            'logstore_' => ['noreader', 'notlogging', 'readererror'],
            'filter_' => ['courseid', 'cmid', 'from', 'to'],
            'expect_' => local\desired_profile::EXPECTATIONS,
            'logformat_' => array_merge(local\accesslog\parser::formats('access'), local\accesslog\parser::formats('error')),
            'probe_' => ['jwks', 'launch', 'auth', 'oauth', 'initiatelogin'],
            'verify_' => finding::layers(),
            'eventtype_' => ['launch', 'launch_sync', 'account_created', 'enrolment', 'group', 'grade', 'view', 'service_unknown',
                'other'],
            'profileerror_' => ['notjson', 'schema', 'noname', 'badfeature', 'badscope', 'badexpect', 'scopesnoags', 'badkey'],
            'progress_' => ['inventory', 'matrix', 'probes', 'evidence', 'profile', 'background'],
            'where_' => finding::layers(),
            'uncertain_' => finding::layers(),
            'obslevel_' => local\observability::levels(),
            'obsdir_' => [local\observability::OUTBOUND, local\observability::INBOUND],
            'obsneeds_' => array_values(array_filter(local\observability::switches())),
            'obs_tx_' => array_keys(local\observability::specification('platform') + local\observability::specification('tool')),
            'blockreason_' => ['empty', 'toolong', 'malformed', 'scheme', 'credentials', 'host', 'blocked', 'resolved_private',
                'pin_mismatch', 'redirect_limit'],
            'sync_' => ['grades', 'members'],
        ];
        $missing = [];
        foreach ($families as $prefix => $values) {
            foreach ($values as $value) {
                foreach ([$prefix . $value] as $id) {
                    if (!isset($en[$id]) || !isset($de[$id])) {
                        $missing[] = $id;
                    }
                }
            }
        }
        $this->assertSame([], $missing);
    }

    /**
     * Reasons, next steps, notes and evidence details that the engine and the builders can produce all have strings.
     */
    public function test_engine_vocabulary_has_strings(): void {
        $en = $this->strings('en');
        $de = $this->strings('de');
        $engine = new status_engine();
        $reasons = [];
        $nexts = [];
        $notes = [];
        $states = status::states();
        $kinds = [status::KIND_OBSERVED, status::KIND_ACCESSLOG, status::KIND_NONE];
        foreach ($states as $implemented) {
            foreach ($states as $enabled) {
                foreach (['yes', 'no', 'unknown', 'not_observable'] as $declared) {
                    foreach ($states as $authorised) {
                        foreach ($kinds as $kind) {
                            foreach (['yes', 'no', 'unknown'] as $observed) {
                                foreach ([false, true] as $specified) {
                                    foreach ($states as $reachable) {
                                        $ladder = [
                                            'specified' => new local\evidence($specified ? 'yes' : 'no'),
                                            'implemented' => new local\evidence($implemented),
                                            'enabled' => new local\evidence($enabled),
                                            'declared' => new local\evidence($declared),
                                            'authorised' => new local\evidence($authorised),
                                            'reachable' => new local\evidence($reachable),
                                            'observed' => new local\evidence($observed, $kind),
                                        ];
                                        foreach ([false, true] as $incompatible) {
                                            $r = $engine->evaluate($ladder, $incompatible);
                                            $reasons[$r->reason] = true;
                                            $nexts[$r->next] = true;
                                            foreach ($r->notes as $n) {
                                                $notes[$n] = true;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(10, count($reasons));
        foreach (array_keys($reasons) as $reason) {
            $this->assertArrayHasKey("reason_$reason", $en);
            $this->assertArrayHasKey("reason_$reason", $de);
        }
        foreach (array_keys($nexts) as $next) {
            $this->assertArrayHasKey("next_$next", $en);
            $this->assertArrayHasKey("next_$next", $de);
        }
        foreach (array_keys($notes) as $note) {
            $this->assertArrayHasKey("note_$note", $en);
            $this->assertArrayHasKey("note_$note", $de);
        }
        // Evidence detail keys named in the builders.
        $found = [];
        foreach (glob(__DIR__ . '/../classes/local/*.php') as $file) {
            preg_match_all("/'(ev_[a-z0-9]+)'/", file_get_contents($file), $m);
            $found = array_merge($found, $m[1]);
        }
        $this->assertGreaterThan(40, count(array_unique($found)));
        foreach (array_unique($found) as $id) {
            $this->assertArrayHasKey($id, $en, $id);
            $this->assertArrayHasKey($id, $de, $id);
        }
    }

    /**
     * No user-visible English literal is passed to the output without a string identifier.
     */
    public function test_no_hardcoded_ui_text_in_pages(): void {
        $offenders = [];
        foreach (glob(__DIR__ . '/../*.php') as $file) {
            $text = file_get_contents($file);
            // Headings, notifications and paragraphs must come from get_string.
            $pattern = "/(?:heading|notification|html_writer::tag\('(?:p|h[1-6]|label|strong)')\(?\s*'([A-Z][a-z]+ [^']{6,})'/";
            if (preg_match_all($pattern, $text, $m)) {
                foreach ($m[1] as $literal) {
                    $offenders[] = basename($file) . ': ' . $literal;
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
