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
 * Scripted AGS transport for tests of the grade replay: records every call, answers from queues.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_ags_transport implements \tool_ltidiagnostics\local\grades\ags_transport {
    /** @var array[] Calls: kind (token, post, get), url, scopes, body, token. */
    public $calls = [];
    /** @var array[] Queued token answers (default: granted with the requested scopes). */
    public $tokens = [];
    /** @var array[] Queued POST answers (default: 200). */
    public $posts = [];
    /** @var array[] Queued GET answers. */
    public $gets = [];
    /** @var bool Report granted scopes in the token answer. */
    public $reportscopes = true;

    /**
     * Token.
     *
     * @param mixed $registration Registration.
     * @param array $scopes Scopes.
     * @return array
     */
    public function token($registration, array $scopes): array {
        $this->calls[] = ['kind' => 'token', 'url' => (string) $registration->getAuthTokenUrl(), 'scopes' => $scopes,
            'client' => (string) $registration->getClientId()];
        $answer = array_shift($this->tokens) ?? ['status' => 200, 'errorclass' => '', 'token' => 'tok-' . count($this->calls),
            'scopes' => $this->reportscopes ? $scopes : []];
        return $answer;
    }

    /**
     * POST a score.
     *
     * @param string $url URL.
     * @param string $token Token.
     * @param array $score Score.
     * @return array
     */
    public function post_score(string $url, string $token, array $score): array {
        $this->calls[] = ['kind' => 'post', 'url' => $url, 'token' => $token, 'body' => $score];
        return array_shift($this->posts) ?? ['status' => 200, 'errorclass' => ''];
    }

    /**
     * GET JSON.
     *
     * @param string $url URL.
     * @param string $token Token.
     * @param string $accept Accept.
     * @return array
     */
    public function get_json(string $url, string $token, string $accept): array {
        $this->calls[] = ['kind' => 'get', 'url' => $url, 'token' => $token, 'accept' => $accept];
        return array_shift($this->gets) ?? ['status' => 404, 'errorclass' => 'http_404', 'json' => null];
    }

    /**
     * Calls of a kind.
     *
     * @param string $kind token, post or get.
     * @return array[]
     */
    public function of(string $kind): array {
        return array_values(array_filter($this->calls, fn($c) => $c['kind'] === $kind));
    }
}
