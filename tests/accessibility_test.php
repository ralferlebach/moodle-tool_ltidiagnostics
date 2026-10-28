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

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\finding;
use tool_ltidiagnostics\local\health_check;
use tool_ltidiagnostics\output\renderer;

/**
 * Structural accessibility checks of the rendered output (audit item DIA-05).
 *
 * These tests parse the real HTML that the renderer produces and check what can be checked without a browser:
 * landmarks and labels, table headers and captions, heading order, text alternatives for every colour, no
 * positive tab index, no empty link or badge. Keyboard operation and computed contrast are checked in a browser by
 * tests/playwright/accessibility.spec.js and by the @javascript scenarios of the Behat feature, which run in CI.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\output\renderer
 */
final class accessibility_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /** @var int Fixed "now". */
    private const NOW = 1792800000;

    /**
     * The plugin renderer.
     *
     * @return renderer
     */
    private function renderer(): renderer {
        global $PAGE;
        $PAGE->set_url('/admin/tool/ltidiagnostics/connection.php');
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('tool_ltidiagnostics');
    }

    /**
     * Parse an HTML fragment.
     *
     * @param string $html HTML.
     * @return \DOMXPath
     */
    private function dom(string $html): \DOMXPath {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>');
        libxml_clear_errors();
        return new \DOMXPath($doc);
    }

    /**
     * A health check result with findings of every severity, a root cause and the observability table.
     *
     * @return array
     */
    private function diagnosis_result(): array {
        $c = new connection();
        $c->role = connection::ROLE_TOOL;
        $c->localid = 3;
        $c->deploymentrowid = 4;
        $c->key = 'tool:3:4';
        $c->name = 'Platform';
        $c->ltiversion = '1.3.0';
        $c->issuer = 'https://p.example.org';
        $c->clientid = 'cid';
        $c->deploymentid = 'dep1';
        $c->state = 'complete';
        $c->endpoints = ['auth' => 'https://p.example.org/auth', 'jwks' => 'https://p.example.org/jwks',
            'oauth' => 'https://p.example.org/token'];
        $c->facts = ['issuerhash' => hash('sha256', 'https://p.example.org'), 'toolcount' => 1];
        $facts = [
            'now' => self::NOW,
            'implemented' => array_fill_keys(\tool_ltidiagnostics\local\matrix_builder::FEATURES, true),
            'obs' => ['launch' => 0, 'enrolment' => 0, 'group' => 0, 'grade_in' => 0, 'grade_local' => 0, 'view_out' => 0,
                'account_created' => 0, 'service_unknown' => 0, 'count' => 0],
            'access' => ['launch' => ['ok' => 0, 'fail' => 2, 'last' => self::NOW, 'codes' => [400 => 2]]],
            'tasklog' => [],
            'tokens' => ['ags' => 0, 'nrps' => 0, 'any' => 0],
            'tool' => ['complete' => true, 'hasauth' => true, 'hasjwks' => true, 'hasoauth' => true, 'tools' => 1,
                'gradesync' => false, 'membersync' => false, 'links' => 0, 'agsclaim' => false, 'scorescope' => false,
                'nrpsclaim' => false, 'lastgrade' => false],
        ];
        $probe = ['purpose' => 'jwks', 'code' => 'cert_chain_incomplete', 'checkedat' => self::NOW, 'skew' => null,
            'certexpiry' => null, 'jwks' => null, 'framing' => null, 'viaproxy' => false, 'blockreason' => ''];
        return (new health_check())->evaluate($c, [
            'now' => self::NOW, 'facts' => $facts, 'probes' => ['jwks' => $probe], 'profile' => null,
            'env' => ['readers' => [], 'cron' => ['lastcronstart' => 0, 'age' => -1, 'status' => 'never'], 'scheduled' => [],
                'adhoc' => [], 'tasklogfailed' => [], 'recordevents' => true, 'activechecks' => true, 'imports' => 0],
        ]);
    }

    /**
     * Generic rules that every fragment of the plugin must satisfy.
     *
     * @param string $html Rendered HTML.
     * @param string $what Description for messages.
     */
    private function assert_generic_rules(string $html, string $what): void {
        $xpath = $this->dom($html);
        foreach ($xpath->query('//table') as $table) {
            $this->assertGreaterThan(0, $xpath->query('.//caption', $table)->length, "$what: table without caption");
            $this->assertGreaterThan(0, $xpath->query('.//th', $table)->length, "$what: table without header cells");
            foreach ($xpath->query('.//th', $table) as $th) {
                $this->assertNotSame('', trim($th->textContent), "$what: empty header cell");
                $this->assertContains($th->getAttribute('scope'), ['col', 'row'], "$what: header cell without scope");
            }
        }
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " badge ")]') as $badge) {
            $this->assertNotSame('', trim($badge->textContent), "$what: badge without text (colour must not carry meaning)");
        }
        foreach ($xpath->query('//a') as $link) {
            $text = trim($link->textContent) . $link->getAttribute('aria-label');
            $this->assertNotSame('', $text, "$what: link without accessible name");
        }
        foreach ($xpath->query('//*[@tabindex]') as $el) {
            $this->assertLessThanOrEqual(0, (int) $el->getAttribute('tabindex'), "$what: positive tabindex");
        }
        foreach (['onclick', 'onkeydown', 'onmouseover'] as $handler) {
            $this->assertSame(0, $xpath->query('//*[@' . $handler . ']')->length, "$what: inline $handler");
        }
        foreach ($xpath->query('//*[@aria-labelledby]') as $el) {
            foreach (explode(' ', $el->getAttribute('aria-labelledby')) as $id) {
                $this->assertSame(1, $xpath->query('//*[@id="' . $id . '"]')->length, "$what: dangling aria-labelledby $id");
            }
        }
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $el) {
            $id = $el->getAttribute('id');
            $this->assertArrayNotHasKey($id, $ids, "$what: duplicate id $id");
            $ids[$id] = true;
        }
        foreach ($xpath->query('//img') as $img) {
            $this->assertTrue($img->hasAttribute('alt'), "$what: image without alt");
        }
    }

    /**
     * Heading levels never skip a level inside the health check.
     *
     * @param string $html Rendered HTML.
     */
    private function assert_heading_order(string $html): void {
        $xpath = $this->dom($html);
        $previous = 2; // The page heading is level 2.
        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $h) {
            $level = (int) substr($h->nodeName, 1);
            $this->assertLessThanOrEqual($previous + 1, $level, 'Heading level skipped before: ' . trim($h->textContent));
            $previous = $level;
        }
    }

    /**
     * The health check page fragment.
     */
    public function test_healthcheck_is_structurally_accessible(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->diagnosis_result();
        $html = $this->renderer()->healthcheck($result);
        $this->assert_generic_rules($html, 'healthcheck');
        $this->assert_heading_order($html);
        $xpath = $this->dom($html);

        // The root cause is one landmark region with a heading, listing team, place, verification and uncertainty.
        $root = $xpath->query('//*[@id="tool_ltidiagnostics_rootcause"]');
        $this->assertSame(1, $root->length);
        $text = $root->item(0)->textContent;
        foreach (['wherelabel', 'verification', 'uncertainlabel', 'intervention', 'responsible'] as $label) {
            $this->assertStringContainsString(get_string($label, 'tool_ltidiagnostics'), $text, $label);
        }
        $this->assertSame('probe_cert_chain_incomplete', $result['rootcause']->code);

        // Every finding card names its severity in words and has guidance, so colour is never the only signal.
        $cards = $xpath->query('//*[@data-severity]');
        $this->assertGreaterThan(3, $cards->length);
        foreach ($cards as $card) {
            $severity = $card->getAttribute('data-severity');
            $this->assertStringContainsString(
                get_string('severity_' . $severity, 'tool_ltidiagnostics'),
                $card->textContent,
                $card->getAttribute('data-testid')
            );
            $this->assertStringContainsString(get_string('wherelabel', 'tool_ltidiagnostics'), $card->textContent);
            $this->assertStringContainsString(get_string('uncertainlabel', 'tool_ltidiagnostics'), $card->textContent);
        }
        // Every layer light is shown with its word.
        foreach ($xpath->query('//*[@data-light]') as $item) {
            $this->assertStringContainsString(
                get_string('light_' . $item->getAttribute('data-light'), 'tool_ltidiagnostics'),
                $item->textContent
            );
        }
        // Consequences are announced in text.
        $flagged = array_filter($result['findings'], function (finding $f) {
            return $f->consequence;
        });
        if ($flagged) {
            $this->assertStringContainsString(get_string('consequencenote', 'tool_ltidiagnostics'), $html);
        }
        // The observability table is part of the page and understandable in text.
        $this->assertStringContainsString('tool_ltidiagnostics_observability', $html);
    }

    /**
     * Without any fault the page says that there is no root cause and does not draw a cause box.
     */
    public function test_no_root_cause_box_without_fault(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = $this->diagnosis_result();
        $result['rootcause'] = null;
        $html = $this->renderer()->healthcheck($result);
        $xpath = $this->dom($html);
        $this->assertSame(0, $xpath->query('//*[@id="tool_ltidiagnostics_rootcause"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="tool_ltidiagnostics_norootcause"]')->length);
    }

    /**
     * Matrix, timeline with coverage table and connection list.
     */
    public function test_other_views_are_structurally_accessible(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $output = $this->renderer();
        $result = $this->diagnosis_result();
        $matrix = $output->matrix($result['matrix']);
        $this->assert_generic_rules($matrix, 'matrix');

        $tl = ['items' => [
            ['time' => self::NOW, 'stage' => 'launch', 'source' => 'observer', 'label' => 'x', 'traceid' => '', 'refs' => []],
            ['time' => self::NOW + 1, 'stage' => 'service', 'source' => 'accesslog', 'label' => 'y', 'traceid' => '', 'refs' => []],
        ], 'edges' => [['from' => 0, 'to' => 1, 'confidence' => 'direct']],
            'sources' => ['observer' => true, 'logstore' => false, 'accesslog' => true], 'stages' => [],
            'coverage' => ['observer' => ['count' => 1, 'first' => self::NOW, 'last' => self::NOW, 'incomplete' => false],
                'accesslog' => ['count' => 0, 'first' => 0, 'last' => 0, 'incomplete' => false],
                'tasklog' => ['count' => 3, 'first' => self::NOW, 'last' => self::NOW, 'incomplete' => true]]];
        $timeline = $output->timeline($tl);
        $this->assert_generic_rules($timeline, 'timeline');
        $this->assertStringContainsString(get_string('cov_incomplete', 'tool_ltidiagnostics'), $timeline);
        $this->assertStringContainsString('aria-hidden="true"', $timeline, 'The arrow glyph is hidden from screen readers.');
        $xpath = $this->dom($timeline);
        $this->assertSame(1, $xpath->query('//*[@id="tool_ltidiagnostics_coverage"]')->length);

        $c = new connection();
        $c->role = connection::ROLE_PLATFORM;
        $c->key = 'platform:7';
        $c->name = 'Tool';
        $c->state = 'active';
        $c->issuer = 'https://i.example.org';
        $c->facts = ['ags' => 2];
        $list = $output->connection_list([$c], false);
        $this->assert_generic_rules($list, 'connection list');
    }

    /**
     * The import list: every delete link names the import it deletes.
     */
    public function test_import_table_links_are_unambiguous(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        $ids = [];
        foreach ([1, 2] as $i) {
            $ids[] = $DB->insert_record('tool_ltidiagnostics_imp', (object) [
                'kind' => 'access', 'format' => 'combined', 'sourcetype' => 'upload', 'sourcename' => "f$i.log",
                'fingerprint' => str_repeat((string) $i, 64), 'timezone' => 'UTC', 'skew' => 0, 'rangefrom' => 0, 'rangeto' => 0,
                'linesread' => 1, 'linesstored' => 1, 'linesskipped' => 0, 'linesinvalid' => 0, 'status' => 'done',
                'errormessage' => '', 'userid' => 2, 'timecreated' => $now,
            ]);
        }
        $html = $this->renderer()->imports_table($DB->get_records('tool_ltidiagnostics_imp'), true);
        $this->assert_generic_rules($html, 'imports');
        $labels = [];
        foreach ($this->dom($html)->query('//a[@aria-label]') as $a) {
            $labels[] = $a->getAttribute('aria-label');
            $this->assertStringContainsString('sesskey=', $a->getAttribute('href'));
        }
        $this->assertCount(2, array_unique($labels));
    }

    /**
     * The badge colour pairs reach WCAG AA (4.5:1) on the default colours of Moodle 4.5 (Boost preset). The real
     * computed contrast on both Moodle versions is measured in the browser by tests/playwright/accessibility.spec.js.
     */
    public function test_badge_colours_reach_aa_on_boost_defaults(): void {
        $boost = ['success' => '#357a32', 'warning' => '#f0ad4e', 'danger' => '#ca3120', 'secondary' => '#ced4da'];
        $text = ['white' => '#ffffff', 'dark' => '#1d2125'];
        $classes = [
            'green' => ['success', 'white'], 'amber' => ['warning', 'dark'], 'red' => ['danger', 'white'],
            'grey' => ['secondary', 'dark'],
        ];
        foreach ($classes as $light => [$bg, $fg]) {
            $class = renderer::badge_class($light);
            $this->assertStringContainsString('bg-' . $bg, $class, $light);
            $this->assertStringContainsString('text-' . $fg, $class, $light);
            $this->assertGreaterThanOrEqual(4.5, self::contrast($text[$fg], $boost[$bg]), "$light: $class");
        }
    }

    /**
     * WCAG contrast ratio.
     *
     * @param string $a Colour like #rrggbb.
     * @param string $b Colour like #rrggbb.
     * @return float
     */
    private static function contrast(string $a, string $b): float {
        $lum = function (string $hex): float {
            $rgb = array_map(function ($v) {
                $v = hexdec($v) / 255;
                return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
            }, str_split(ltrim($hex, '#'), 2));
            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };
        $l1 = $lum($a);
        $l2 = $lum($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /**
     * Report exports are plain, ordered text: headings in Markdown, no layout tricks, German and English available.
     */
    public function test_markdown_export_is_structured_text(): void {
        $this->resetAfterTest();
        $result = $this->diagnosis_result();
        $c = new connection();
        $c->role = connection::ROLE_TOOL;
        $c->key = 'tool:3:4';
        $c->name = 'Platform';
        $c->ltiversion = '1.3.0';
        $c->state = 'complete';
        $report = (new \tool_ltidiagnostics\local\report())->build($c, $result, ['now' => self::NOW, 'timezone' => 'UTC']);
        $md = \tool_ltidiagnostics\local\report_exporter::to_markdown($report);
        $this->assertMatchesRegularExpression('/^# /m', $md);
        $this->assertMatchesRegularExpression('/^## /m', $md);
        $this->assertStringContainsString(get_string('report_rootcause', 'tool_ltidiagnostics'), $md);
        $this->assertStringContainsString($result['rootcause']->testid, $md);
        $this->assertStringContainsString(get_string('wherelabel', 'tool_ltidiagnostics'), $md);
        $this->assertStringContainsString(get_string('uncertainlabel', 'tool_ltidiagnostics'), $md);
        $this->assertStringNotContainsString('<', $md, 'No HTML in the Markdown export.');
    }
}
