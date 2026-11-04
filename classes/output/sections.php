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

namespace tool_ltidiagnostics\output;

use html_writer;
use moodle_url;
use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\identity;
use tool_ltidiagnostics\local\scope;

/**
 * Filterable, paged sections of the connection report (issues #21, #23, #25, #27).
 *
 * One code path for both ways of loading: the page renders a section from its own GET parameters (works without
 * JavaScript, every state has a URL); the AMD module tool_ltidiagnostics/sections asks the external function
 * tool_ltidiagnostics_render_section for the same section with the query of a link or a form and replaces only the
 * section. Every filter is read here with an explicit type; unknown values are ignored. Page sizes are 20, 50, 100 and
 * 500; there is no unbounded "all" in a table.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sections {
    /** @var int[] Page sizes. */
    public const SIZES = [20, 50, 100, 500];

    /** @var string[][] URL parameters owned by each section (the browser history keeps those of the other sections). */
    public const PARAMS = [
        'grades_rows' => ['fgradesync', 'fgrade', 'ftransfer', 'fbooking', 'fuser', 'flink', 'fsearch', 'perpage', 'gpage'],
        'grades_journal' => ['jorigin', 'jtransfer', 'jattr', 'jsearch', 'jlink', 'juser', 'jperpage', 'jpage', 'jmax'],
        'evidence' => ['view', 'service', 'text', 'assign', 'eperpage', 'epage'],
        'timeline' => ['service', 'text', 'tperpage', 'tpage'],
    ];

    /** @var string[] Sections and the tab they belong to. */
    public const SECTIONS = [
        'grades_rows' => 'grades',
        'grades_journal' => 'grades',
        'evidence' => 'evidence',
        'timeline' => 'timeline',
    ];

    /**
     * Page index inside the population (0-based), clamped to the last page so that a tampered or outdated page number
     * never shows an empty page of a non-empty population.
     *
     * @param int $page Requested page.
     * @param int $perpage Page size.
     * @param int $total Population.
     * @return int
     */
    public static function clamp_page(int $page, int $perpage, int $total): int {
        $last = $total > 0 ? intdiv($total - 1, max(1, $perpage)) : 0;
        return max(0, min($page, $last));
    }

    /**
     * Allowed page size.
     *
     * @param int $n Requested size.
     * @param int $default Default.
     * @return int
     */
    public static function size(int $n, int $default = 50): int {
        return in_array($n, self::SIZES, true) ? $n : $default;
    }

    /**
     * Typed value from a parameter array.
     *
     * @param array $q Parameters.
     * @param string $name Name.
     * @param mixed $default Default.
     * @param string $type PARAM_* type.
     * @return mixed
     */
    public static function param(array $q, string $name, $default, string $type) {
        if (!array_key_exists($name, $q) || is_array($q[$name])) {
            return $default;
        }
        return clean_param($q[$name], $type);
    }

    /**
     * Parameters of the current request that sections read (for the page load).
     *
     * @return array
     */
    public static function request_params(): array {
        $out = [];
        foreach (array_merge($_GET, $_POST) as $name => $value) {
            if (is_string($value) && preg_match('/^[a-z]{1,20}$/', $name)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * Wrap a section for the AMD module (container, live region of the module is global).
     *
     * @param string $name Section.
     * @param string $key Connection key.
     * @param string $html Content.
     * @return string
     */
    public static function wrap(string $name, string $key, string $html): string {
        return html_writer::div($html, 'tool-ltidiagnostics-section', ['id' => 'tool_ltidiagnostics_section_' . $name,
            'data-ltidiag-section' => $name, 'data-key' => $key, 'data-params' => implode(',', self::PARAMS[$name] ?? [])]);
    }

    /**
     * Render one section.
     *
     * @param connection $conn Connection.
     * @param string $section Section name.
     * @param array $q Parameters (raw, typed here).
     * @param scope $scope Scope.
     * @return array html (string, unwrapped), url (moodle_url of the page state), total (int)
     */
    public static function render(connection $conn, string $section, array $q, scope $scope): array {
        global $PAGE;
        $tab = self::SECTIONS[$section] ?? null;
        if ($tab === null) {
            throw new \invalid_parameter_exception('section');
        }
        // The links and forms of a section keep the state of the other sections of the same tab, so that paging one
        // block without JavaScript does not reset the other (issue #31). Those values are only passed on, as text.
        $others = [];
        foreach (self::SECTIONS as $other => $othertab) {
            if ($othertab !== $tab || $other === $section) {
                continue;
            }
            foreach (self::PARAMS[$other] as $name) {
                if (isset($q[$name]) && is_scalar($q[$name]) && (string) $q[$name] !== '') {
                    $others[$name] = \core_text::substr(clean_param((string) $q[$name], PARAM_TEXT), 0, 100);
                }
            }
        }
        $base = new moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $conn->key, 'tab' => $tab] +
            $scope->params() + $others);
        switch ($section) {
            case 'grades_rows':
                return self::grades_rows($conn, $q, $scope, $base, $PAGE->get_renderer('tool_ltidiagnostics', 'grades'));
            case 'grades_journal':
                return self::grades_journal($conn, $q, $scope, $base, $PAGE->get_renderer('tool_ltidiagnostics', 'grades'));
            case 'evidence':
                return self::evidence($conn, $q, $scope, $base, $PAGE->get_renderer('tool_ltidiagnostics'));
            default:
                return self::timeline($conn, $q, $scope, $base, $PAGE->get_renderer('tool_ltidiagnostics'));
        }
    }

    /**
     * Grade context of the current user.
     *
     * @param connection $conn Connection.
     * @return array
     */
    public static function grade_ctx(connection $conn): array {
        $context = \context_system::instance();
        return [
            'key' => $conn->key,
            'showscores' => has_capability('tool/ltidiagnostics:viewgrades', $context),
            'names' => identity::can_see_names(),
            'canrepair' => \tool_ltidiagnostics\local\grades\repair_service::can_repair($conn->key),
            'canresult' => has_capability('tool/ltidiagnostics:runchecks', $context),
        ];
    }

    /**
     * Resource links and learners: filters, counters, rows, selection.
     *
     * @param connection $conn Connection.
     * @param array $q Parameters.
     * @param scope $scope Scope.
     * @param moodle_url $base Page URL with scope.
     * @param grades_renderer $out Renderer.
     * @return array
     */
    private static function grades_rows(connection $conn, array $q, scope $scope, moodle_url $base, grades_renderer $out): array {
        $ctx = self::grade_ctx($conn);
        $filters = [
            'gradesync' => self::param($q, 'fgradesync', '', PARAM_RAW),
            'grade' => self::param($q, 'fgrade', '', PARAM_ALPHA),
            'transfer' => self::param($q, 'ftransfer', '', PARAM_ALPHA),
            'booking' => self::param($q, 'fbooking', '', PARAM_ALPHA),
            'userid' => self::param($q, 'fuser', 0, PARAM_INT),
            'linkid' => self::param($q, 'flink', 0, PARAM_INT),
            'usersearch' => $ctx['names'] ? self::param($q, 'fsearch', '', PARAM_TEXT) : '',
        ];
        $filters = \tool_ltidiagnostics\local\grades\pair_query::clean($filters);
        $perpage = self::size((int) self::param($q, 'perpage', 50, PARAM_INT));
        $page = max(0, (int) self::param($q, 'gpage', 0, PARAM_INT));
        $diag = new \tool_ltidiagnostics\local\grades\grade_diagnosis();
        $options = ['limit' => $perpage, 'showscores' => $ctx['showscores'], 'filters' => $filters,
            'courseid' => $scope->courseid, 'cmid' => $scope->cmid, 'invalidscope' => $scope->invalid,
            'importtasklog' => false];
        $d = $diag->run($conn, $options + ['offset' => $page * $perpage]);
        $clamped = self::clamp_page($page, $perpage, (int) $d['total']);
        if ($clamped !== $page) {
            $page = $clamped;
            $d = $diag->run($conn, $options + ['offset' => $page * $perpage]);
        }
        $offset = $page * $perpage;
        $params = array_filter([
            'fgradesync' => $filters['gradesync'], 'fgrade' => $filters['grade'], 'ftransfer' => $filters['transfer'],
            'fbooking' => $filters['booking'], 'fuser' => $filters['userid'], 'flink' => $filters['linkid'],
            'fsearch' => $filters['usersearch'], 'perpage' => $perpage === 50 ? 0 : $perpage,
        ]);
        $url = new moodle_url($base, $params + ($page ? ['gpage' => $page] : []));
        $html = $out->rows_section($d, $ctx + ['baseurl' => $base, 'params' => $params, 'filters' => $filters,
            'perpage' => $perpage, 'offset' => $offset, 'page' => $page, 'scope' => $scope]);
        return ['html' => $html, 'url' => $url, 'total' => (int) $d['total']];
    }

    /**
     * Journal with filters.
     *
     * @param connection $conn Connection.
     * @param array $q Parameters.
     * @param scope $scope Scope.
     * @param moodle_url $base Page URL with scope.
     * @param grades_renderer $out Renderer.
     * @return array
     */
    private static function grades_journal(
        connection $conn,
        array $q,
        scope $scope,
        moodle_url $base,
        grades_renderer $out
    ): array {
        $ctx = self::grade_ctx($conn);
        $perpage = self::size((int) self::param($q, 'jperpage', 50, PARAM_INT));
        $params = array_filter([
            'jorigin' => in_array(self::param($q, 'jorigin', '', PARAM_ALPHA), ['core', 'replay'], true)
                ? self::param($q, 'jorigin', '', PARAM_ALPHA) : '',
            'jtransfer' => in_array(
                self::param($q, 'jtransfer', '', PARAM_ALPHA),
                ['skipped', 'pending', 'accepted', 'failed'],
                true
            ) ? self::param($q, 'jtransfer', '', PARAM_ALPHA) : '',
            'jattr' => in_array(self::param($q, 'jattr', '', PARAM_ALPHA), ['attributed', 'unattributed'], true)
                ? self::param($q, 'jattr', '', PARAM_ALPHA) : '',
            'jlink' => (int) self::param($q, 'jlink', 0, PARAM_INT),
            'juser' => (int) self::param($q, 'juser', 0, PARAM_INT),
            'jsearch' => \core_text::substr(trim((string) self::param($q, 'jsearch', '', PARAM_TEXT)), 0, 100),
            'jperpage' => $perpage === 50 ? 0 : $perpage,
        ]);
        $filters = [
            'origin' => $params['jorigin'] ?? '', 'transfer' => $params['jtransfer'] ?? '',
            'attribution' => $params['jattr'] ?? '', 'resourcelinkrowid' => $params['jlink'] ?? 0,
            'userid' => $params['juser'] ?? 0, 'from' => $scope->from, 'to' => $scope->to,
        ];
        // Search: a number is a resource link row; a text searches learners' names (only with the right to see them).
        $search = $params['jsearch'] ?? '';
        if ($search !== '' && ctype_digit($search)) {
            $filters['resourcelinkrowid'] = (int) $search;
        } else if ($search !== '' && $ctx['names']) {
            $filters['usersearch'] = $search;
        } else if ($search !== '') {
            unset($params['jsearch']);
        }
        $toolids = (new \tool_ltidiagnostics\local\grades\grade_diagnosis())->scope_toolids($conn, $scope);
        if ($toolids !== null) {
            $filters['toolids'] = $toolids;
        }
        // Stable page numbers (issue #31): the pages are counted over the journal as it was when the first page was
        // shown (jmax: newest row id then), so rows written while paging neither shift pages nor appear twice.
        $max = (int) self::param($q, 'jmax', 0, PARAM_INT);
        $page = max(0, (int) self::param($q, 'jpage', 0, PARAM_INT));
        $result = \tool_ltidiagnostics\local\grades\journal::numbered_page($conn->key, $filters, $page, $perpage, $max);
        $params['jmax'] = $result['max'];
        $url = new moodle_url($base, $params + ($result['page'] ? ['jpage' => $result['page']] : []));
        $html = $out->journal_section($result, $ctx + ['baseurl' => $base, 'params' => $params, 'perpage' => $perpage,
            'page' => $result['page'], 'scope' => $scope]);
        return ['html' => $html, 'url' => $url, 'total' => (int) $result['total']];
    }

    /**
     * Evidence sources with filters and sub-views.
     *
     * @param connection $conn Connection.
     * @param array $q Parameters.
     * @param scope $scope Scope.
     * @param moodle_url $base Page URL with scope.
     * @param renderer $out Renderer.
     * @return array
     */
    private static function evidence(connection $conn, array $q, scope $scope, moodle_url $base, renderer $out): array {
        $view = self::param($q, 'view', 'observer', PARAM_ALPHA);
        if (!in_array($view, \tool_ltidiagnostics\local\evidence_query::VIEWS, true)) {
            $view = 'observer';
        }
        $params = array_filter([
            'view' => $view === 'observer' ? '' : $view,
            'service' => in_array(
                self::param($q, 'service', '', PARAM_ALPHA),
                \tool_ltidiagnostics\local\evidence_query::SERVICES,
                true
            ) ? self::param($q, 'service', '', PARAM_ALPHA) : '',
            'text' => \core_text::substr(trim((string) self::param($q, 'text', '', PARAM_TEXT)), 0, 100),
            'assign' => in_array(self::param($q, 'assign', '', PARAM_ALPHA), ['assigned', 'unassigned'], true)
                ? self::param($q, 'assign', '', PARAM_ALPHA) : '',
            'eperpage' => self::size((int) self::param($q, 'eperpage', 50, PARAM_INT)) === 50 ? 0
                : self::size((int) self::param($q, 'eperpage', 50, PARAM_INT)),
        ]);
        $perpage = self::size((int) ($params['eperpage'] ?? 50));
        $pagenum = max(0, (int) self::param($q, 'epage', 0, PARAM_INT));
        $query = new \tool_ltidiagnostics\local\evidence_query();
        $filters = ['service' => $params['service'] ?? '', 'text' => $params['text'] ?? '', 'assign' => $params['assign'] ?? '',
            'limit' => $perpage];
        $page = $query->page($conn, $scope, $view, $filters + ['offset' => $pagenum * $perpage], time());
        $clamped = self::clamp_page($pagenum, $perpage, (int) $page['total']);
        if ($clamped !== $pagenum) {
            $pagenum = $clamped;
            $page = $query->page($conn, $scope, $view, $filters + ['offset' => $pagenum * $perpage], time());
        }
        $url = new moodle_url($base, $params + ($pagenum ? ['epage' => $pagenum] : []));
        $html = $out->evidence_section($page, ['baseurl' => $base, 'params' => $params, 'view' => $view, 'key' => $conn->key,
            'page' => $pagenum, 'perpage' => $perpage]);
        return ['html' => $html, 'url' => $url, 'total' => (int) $page['total']];
    }

    /**
     * Timeline with filters and paging.
     *
     * @param connection $conn Connection.
     * @param array $q Parameters.
     * @param scope $scope Scope.
     * @param moodle_url $base Page URL with scope.
     * @param renderer $out Renderer.
     * @return array
     */
    private static function timeline(connection $conn, array $q, scope $scope, moodle_url $base, renderer $out): array {
        $params = array_filter([
            'service' => in_array(
                self::param($q, 'service', '', PARAM_ALPHA),
                \tool_ltidiagnostics\local\evidence_query::SERVICES,
                true
            ) ? self::param($q, 'service', '', PARAM_ALPHA) : '',
            'text' => \core_text::substr(trim((string) self::param($q, 'text', '', PARAM_TEXT)), 0, 100),
            'tperpage' => self::size((int) self::param($q, 'tperpage', 50, PARAM_INT)) === 50 ? 0
                : self::size((int) self::param($q, 'tperpage', 50, PARAM_INT)),
        ]);
        $tl = (new \tool_ltidiagnostics\local\timeline())->build($conn, \context_system::instance(), $scope->filters(), time());
        $tl = \tool_ltidiagnostics\local\evidence_query::filter_timeline($tl, $params['service'] ?? '', $params['text'] ?? '');
        $limit = self::size((int) ($params['tperpage'] ?? 50));
        $total = count($tl['items']);
        $pagenum = self::clamp_page(max(0, (int) self::param($q, 'tpage', 0, PARAM_INT)), $limit, $total);
        $offset = $pagenum * $limit;
        $url = new moodle_url($base, $params + ($pagenum ? ['tpage' => $pagenum] : []));
        $html = $out->timeline_section($tl, ['baseurl' => $base, 'params' => $params, 'limit' => $limit, 'offset' => $offset,
            'page' => $pagenum, 'total' => $total]);
        return ['html' => $html, 'url' => $url, 'total' => $total];
    }
}
