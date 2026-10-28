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
 * Test case mixin: every test runs inside a {@see deprecation_guard} frame and fails, with source and stack trace, when
 * PHP reported a deprecation during the test (audit item DIA-01: no hidden deprecation on any Moodle version).
 *
 * A class that defines its own setUp()/tearDown() calls {@see self::start_deprecation_guard()} and
 * {@see self::check_deprecation_guard()} itself.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait deprecation_guarded {
    /** @var bool Whether this test opened a guard frame. */
    private $deprecationguardopen = false;

    /**
     * Open the guard frame of this test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->start_deprecation_guard();
    }

    /**
     * Close the guard frame and fail the test when a deprecation was recorded.
     */
    protected function tearDown(): void {
        $records = $this->stop_deprecation_guard();
        parent::tearDown();
        if ($records) {
            $this->fail(deprecation_guard::format($records));
        }
    }

    /**
     * Open the guard frame.
     */
    protected function start_deprecation_guard(): void {
        deprecation_guard::start(true);
        $this->deprecationguardopen = true;
    }

    /**
     * Close the guard frame.
     *
     * @return array[] Recorded deprecations.
     */
    protected function stop_deprecation_guard(): array {
        if (!$this->deprecationguardopen) {
            return [];
        }
        $this->deprecationguardopen = false;
        return deprecation_guard::stop();
    }

    /**
     * For classes with their own tearDown(): close the frame and fail on recorded deprecations.
     */
    protected function check_deprecation_guard(): void {
        $records = $this->stop_deprecation_guard();
        if ($records) {
            $this->fail(deprecation_guard::format($records));
        }
    }
}
