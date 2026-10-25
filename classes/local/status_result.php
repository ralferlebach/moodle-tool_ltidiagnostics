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

/**
 * Result of the status engine for one feature.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_result {
    /** @var string One of the status::* constants. */
    public $status;
    /** @var string Machine readable reason code (also a language string suffix "reason_<code>"). */
    public $reason;
    /** @var string The step that decided the outcome, or an empty string. */
    public $decidingstep;
    /** @var string[] Advisory notes, e.g. rejected evidence ("evidence_insufficient"). */
    public $notes;
    /** @var string Next safe diagnostic step as a language string suffix "next_<code>". */
    public $next;

    /**
     * Constructor.
     *
     * @param string $status Status.
     * @param string $reason Reason code.
     * @param string $decidingstep Deciding step.
     * @param string $next Next safe step code.
     * @param string[] $notes Notes.
     */
    public function __construct(
        string $status,
        string $reason,
        string $decidingstep = '',
        string $next = '',
        array $notes = []
    ) {
        $this->status = $status;
        $this->reason = $reason;
        $this->decidingstep = $decidingstep;
        $this->next = $next;
        $this->notes = $notes;
    }
}
