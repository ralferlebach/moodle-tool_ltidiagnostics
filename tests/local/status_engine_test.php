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
 * Exhaustive tests of the pure status engine.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\status_engine
 */
final class status_engine_test extends \advanced_testcase {
    /**
     * Build a ladder from state shorthand.
     *
     * @param array $map Step => state or [state, kind].
     * @return evidence[]
     */
    private function ladder(array $map): array {
        $out = [];
        foreach ($map as $step => $v) {
            [$state, $kind] = is_array($v) ? $v : [$v, status::KIND_NONE];
            $out[$step] = new evidence($state, $kind);
        }
        return $out;
    }

    /**
     * A base ladder in which everything works and the observation is real.
     *
     * @param array $override Overrides.
     * @return evidence[]
     */
    private function full(array $override = []): array {
        return $this->ladder($override + [
            'specified' => 'yes', 'implemented' => 'yes', 'enabled' => 'yes', 'declared' => 'yes',
            'authorised' => 'yes', 'reachable' => 'yes', 'observed' => ['yes', status::KIND_OBSERVED],
        ]);
    }

    /**
     * The status vocabulary is closed and complete.
     */
    public function test_vocabulary(): void {
        $this->assertCount(9, status::all());
        $this->assertSame(
            ['specified', 'implemented', 'enabled', 'declared', 'authorised', 'reachable', 'observed'],
            status::steps()
        );
        $this->assertEqualsCanonicalizing(['observed_transaction', 'authorised_test'], status::verifying_kinds());
    }

    /**
     * Rule table for the main outcomes.
     *
     * @dataProvider rules_provider
     * @param array $ladder Ladder shorthand.
     * @param string $expected Expected status.
     * @param string $reason Expected reason code.
     * @param bool $incompatible Incompatibility flag.
     */
    public function test_rules(array $ladder, string $expected, string $reason, bool $incompatible = false): void {
        $r = (new status_engine())->evaluate($this->ladder($ladder), $incompatible);
        $this->assertSame($expected, $r->status);
        $this->assertSame($reason, $r->reason);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function rules_provider(): array {
        $y = 'yes';
        $base = ['specified' => $y, 'implemented' => $y];
        return [
            'incompatible wins over everything' => [
                $base + ['enabled' => $y, 'observed' => [$y, 'observed_transaction']], 'incompatible', 'incompatible', true,
            ],
            'not specified' => [['specified' => 'no', 'implemented' => $y], 'not_supported', 'not_specified'],
            'not implemented' => [['specified' => $y, 'implemented' => 'no'], 'not_supported', 'not_implemented'],
            'implementation not observable' => [
                ['specified' => $y, 'implemented' => 'not_observable'], 'not_observable', 'notobservable_implemented',
            ],
            'implementation unknown' => [['specified' => $y, 'implemented' => 'unknown'], 'unknown', 'unknown_implemented'],
            'nothing known' => [[], 'unknown', 'unknown_implemented'],
            'implemented but off' => [$base + ['enabled' => 'no'], 'supported', 'supported_not_enabled'],
            'enabled only' => [$base + ['enabled' => $y], 'configured', 'configured_not_observed'],
            'enabled and authorised' => [
                $base + ['enabled' => $y, 'declared' => $y, 'authorised' => $y], 'authorised', 'authorised_not_observed',
            ],
            'remote does not declare' => [$base + ['enabled' => $y, 'declared' => 'no'], 'blocked', 'remote_not_offered'],
            'scope missing' => [$base + ['enabled' => $y, 'declared' => $y, 'authorised' => 'no'], 'blocked', 'scope_missing'],
            'unreachable' => [$base + ['enabled' => $y, 'reachable' => 'blocked'], 'blocked', 'unreachable'],
            'observed failure' => [
                $base + ['enabled' => $y, 'observed' => ['no', 'observed_transaction']], 'blocked', 'observed_failure',
            ],
            'verified by observation' => [
                $base + ['enabled' => $y, 'authorised' => $y, 'observed' => [$y, 'observed_transaction']], 'verified', 'observed',
            ],
            'verified by authorised test' => [
                $base + ['enabled' => $y, 'observed' => [$y, 'authorised_test']], 'verified', 'observed',
            ],
            'log line alone is not verified' => [
                $base + ['enabled' => $y, 'observed' => [$y, 'access_log']], 'configured', 'configured_not_observed',
            ],
            'local effect alone is not verified' => [
                $base + ['enabled' => $y, 'observed' => [$y, 'local_effect']], 'configured', 'configured_not_observed',
            ],
            'task log alone is not verified' => [
                $base + ['enabled' => $y, 'authorised' => $y, 'observed' => [$y, 'task_log']],
                'authorised', 'authorised_not_observed',
            ],
            'probe alone is not verified' => [
                $base + ['enabled' => $y, 'reachable' => $y, 'observed' => [$y, 'active_probe']], 'configured',
                'configured_not_observed',
            ],
            'config alone is not verified' => [
                $base + ['enabled' => [$y, 'config'], 'observed' => [$y, 'config']], 'configured', 'configured_not_observed',
            ],
            'observation without being enabled is not verified' => [
                $base + ['enabled' => 'no', 'observed' => [$y, 'observed_transaction']], 'supported', 'supported_not_enabled',
            ],
        ];
    }

    /**
     * Insufficient evidence is reported as a note.
     */
    public function test_insufficient_evidence_note(): void {
        $r = (new status_engine())->evaluate($this->full(['observed' => ['yes', status::KIND_ACCESSLOG]]));
        $this->assertNotSame(status::VERIFIED, $r->status);
        $this->assertContains('evidence_insufficient', $r->notes);
    }

    /**
     * Unobservable steps only add notes and never block.
     */
    public function test_not_observable_steps_add_notes(): void {
        $r = (new status_engine())->evaluate($this->full([
            'declared' => 'not_observable', 'authorised' => 'not_observable', 'reachable' => 'not_observable',
        ]));
        $this->assertSame(status::VERIFIED, $r->status);
        $this->assertContains('notobservable_declared', $r->notes);
        $this->assertContains('notobservable_reachable', $r->notes);
    }

    /**
     * A blocked step does not block while the feature is switched off.
     */
    public function test_blocked_ignored_when_not_enabled(): void {
        $r = (new status_engine())->evaluate($this->full(['enabled' => 'no', 'declared' => 'no']));
        $this->assertSame(status::SUPPORTED, $r->status);
    }

    /**
     * An invalid state is treated as unknown, never as success.
     */
    public function test_invalid_state_is_unknown(): void {
        $ladder = $this->full();
        $ladder['implemented'] = new evidence('banana');
        $r = (new status_engine())->evaluate($ladder);
        $this->assertSame(status::UNKNOWN, $r->status);
    }

    /**
     * Exhaustive property check over the whole state space of the middle steps.
     *
     * "verified" is reachable only for the one condition the specification allows. The oracle below is written
     * independently of the engine.
     */
    public function test_exhaustive_verified_invariant(): void {
        $engine = new status_engine();
        $states = status::states();
        $kinds = [
            status::KIND_CONFIG, status::KIND_CODE, status::KIND_DBRECORD, status::KIND_OBSERVED, status::KIND_LOCALEFFECT,
            status::KIND_TEST, status::KIND_ACCESSLOG, status::KIND_TASKLOG, status::KIND_PROBE, status::KIND_NONE,
        ];
        $checked = 0;
        $verified = 0;
        foreach ($states as $implemented) {
            foreach ($states as $enabled) {
                foreach ($states as $declared) {
                    foreach ($states as $authorised) {
                        foreach ($states as $reachable) {
                            foreach ($states as $observed) {
                                foreach ($kinds as $kind) {
                                    $ladder = [
                                        'specified' => new evidence('yes'),
                                        'implemented' => new evidence($implemented),
                                        'enabled' => new evidence($enabled),
                                        'declared' => new evidence($declared),
                                        'authorised' => new evidence($authorised),
                                        'reachable' => new evidence($reachable),
                                        'observed' => new evidence($observed, $kind),
                                    ];
                                    $result = $engine->evaluate($ladder);
                                    $expected = $implemented === 'yes' && $enabled === 'yes' && $observed === 'yes'
                                        && in_array($kind, ['observed_transaction', 'authorised_test'], true)
                                        && !in_array($declared, ['no', 'blocked'], true)
                                        && !in_array($authorised, ['no', 'blocked'], true)
                                        && !in_array($reachable, ['no', 'blocked'], true);
                                    $this->assertSame(
                                        $expected,
                                        $result->status === status::VERIFIED,
                                        "$implemented $enabled $declared $authorised $reachable $observed $kind"
                                    );
                                    $this->assertContains($result->status, status::all());
                                    $checked++;
                                    $verified += $expected ? 1 : 0;
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(10000, $checked);
        $this->assertGreaterThan(0, $verified);
    }

    /**
     * The traffic light is defined for every status and never green without verification.
     */
    public function test_light_mapping(): void {
        foreach (status::all() as $s) {
            $this->assertContains(status::light($s), ['green', 'amber', 'red', 'grey']);
        }
        foreach (status::all() as $s) {
            if (status::light($s) === 'green') {
                $this->assertSame(status::VERIFIED, $s);
            }
        }
    }
}
