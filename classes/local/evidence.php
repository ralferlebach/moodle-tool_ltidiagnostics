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
 * The verdict for one ladder step of one feature, with its proof.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence {
    /** @var string One of the status::STATE_* values. */
    public $state;
    /** @var string One of the status::KIND_* values. */
    public $kind;
    /** @var string Language string identifier (component tool_ltidiagnostics) explaining the verdict. */
    public $detailkey;
    /** @var mixed Parameter for the language string. */
    public $detailparam;
    /** @var int Time the fact was established (0 = not applicable). */
    public $time;

    /**
     * Constructor.
     *
     * @param string $state Step state.
     * @param string $kind Evidence kind.
     * @param string $detailkey Language string identifier.
     * @param mixed $detailparam Language string parameter.
     * @param int $time Time of the observation.
     */
    public function __construct(
        string $state,
        string $kind = status::KIND_NONE,
        string $detailkey = '',
        $detailparam = null,
        int $time = 0
    ) {
        $this->state = $state;
        $this->kind = $kind;
        $this->detailkey = $detailkey;
        $this->detailparam = $detailparam;
        $this->time = $time;
    }

    /**
     * Shortcut for an unknown step.
     *
     * @param string $detailkey Optional explanation.
     * @return self
     */
    public static function unknown(string $detailkey = ''): self {
        return new self(status::STATE_UNKNOWN, status::KIND_NONE, $detailkey);
    }

    /**
     * Shortcut for a not applicable step.
     *
     * @return self
     */
    public static function na(): self {
        return new self(status::STATE_NA);
    }

    /**
     * Shortcut for a step that cannot be observed from this system.
     *
     * @param string $detailkey Optional explanation.
     * @return self
     */
    public static function not_observable(string $detailkey = 'ev_notobservable'): self {
        return new self(status::STATE_NOT_OBSERVABLE, status::KIND_NONE, $detailkey);
    }
}
