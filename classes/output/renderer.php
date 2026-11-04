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

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\finding;
use tool_ltidiagnostics\local\redactor;
use tool_ltidiagnostics\local\status;
use tool_ltidiagnostics\local\timeline;

/**
 * Renderer of tool_ltidiagnostics. Builds template contexts and renders them.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Bootstrap badge classes for a traffic light colour. Colour never carries meaning alone: the label is always shown.
     *
     * @param string $light green, amber, red or grey
     * @return string
     */
    public static function badge_class(string $light): string {
        // The text colour is set explicitly: Moodle 4.5 (Bootstrap 4) and 5.x (Bootstrap 5) differ in the default badge colour.
        $map = ['green' => 'bg-success text-white', 'amber' => 'bg-warning text-dark', 'red' => 'bg-danger text-white',
            'grey' => 'bg-secondary text-dark'];
        return $map[$light] ?? 'bg-secondary text-dark';
    }

    /**
     * The connection list.
     *
     * @param connection[] $connections Connections.
     * @param bool $reveal True when identifiers may be shown.
     * @param int $courseid Course of the overview filter (0: none); the links keep it and "Used in" shows only its use.
     * @param int $cmid Activity of the overview filter (0: none).
     * @return string
     */
    public function connection_list(array $connections, bool $reveal, int $courseid = 0, int $cmid = 0): string {
        $rows = [];
        // The shown use of every connection first, then all its names in bulk (issue #30 AK6).
        $shown = [];
        foreach ($connections as $c) {
            $shown[$c->key] = self::usage_shown($c, $courseid, $cmid);
        }
        \tool_ltidiagnostics\local\names::for_usage(array_merge([], ...array_column($shown, 'items')));
        $scopeparams = array_filter(['courseid' => $courseid, 'cmid' => $cmid]);
        foreach ($connections as $c) {
            $services = [];
            if ($c->role === connection::ROLE_PLATFORM) {
                if (!empty($c->facts['ags'])) {
                    $services[] = get_string('feature_ags', 'tool_ltidiagnostics');
                }
                if (!empty($c->facts['nrps'])) {
                    $services[] = get_string('feature_nrps', 'tool_ltidiagnostics');
                }
                if (!empty($c->facts['deeplinking'])) {
                    $services[] = get_string('feature_deeplinking', 'tool_ltidiagnostics');
                }
            } else {
                $services[] = get_string('toolcount', 'tool_ltidiagnostics', count($c->tools));
            }
            $actions = [['url' => (new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $c->key]
                + $scopeparams))->out(false),
                'label' => get_string('open', 'tool_ltidiagnostics'), 'aria' => get_string(
                    'open_for',
                    'tool_ltidiagnostics',
                    $c->name
                )]];
            $edit = \tool_ltidiagnostics\local\navigation::edit_connection($c);
            // Connection identities are LTI configuration of this site, not secrets: shown in full to everybody who may
            // open this page (issue #12). Tokens and keys are never part of the list.
            $rows[] = [
                'name' => \tool_ltidiagnostics\local\navigation::label($c),
                'role' => get_string('role_' . $c->role, 'tool_ltidiagnostics'),
                'issuer' => $c->issuer,
                'clientid' => $c->clientid,
                'deploymentid' => $c->deploymentid,
                'state' => $c->state,
                'services' => implode(', ', $services),
                'url' => $actions[0]['url'],
                'actionlabel' => get_string('open', 'tool_ltidiagnostics'),
                'actions' => $actions,
                'edithtml' => $edit ? \tool_ltidiagnostics\local\navigation::pencil($edit['url'], $edit['label']) : '',
                'usagehtml' => $this->usage_cell($shown[$c->key]),
                'key' => $c->key,
            ];
        }
        return $this->render_from_template('tool_ltidiagnostics/connection_list', [
            'caption' => get_string('connections', 'tool_ltidiagnostics'),
            'empty' => $rows ? '' : get_string('noconnections', 'tool_ltidiagnostics'),
            'headers' => [
                'name' => get_string('name'), 'role' => get_string('role', 'tool_ltidiagnostics'),
                'issuer' => get_string('issuer', 'tool_ltidiagnostics'), 'client' => get_string('clientid', 'tool_ltidiagnostics'),
                'deployment' => get_string('deploymentid', 'tool_ltidiagnostics'),
                'state' => get_string('state', 'tool_ltidiagnostics'), 'services' => get_string('services', 'tool_ltidiagnostics'),
                'usage' => get_string('usedin', 'tool_ltidiagnostics'),
                'action' => get_string('action', 'tool_ltidiagnostics'),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * Filter of the connection overview (issues #29, #32): search, role, course and activity in one compact row. The
     * courses are those the listed connections are proven to be used in; the activities are those of the chosen course.
     *
     * @param connection[] $candidates Connections before the course/activity filter.
     * @param array $params Current filter (search, role, courseid, cmid).
     * @param \tool_ltidiagnostics\local\scope $scope Validated course/activity.
     * @return string
     */
    public function overview_filter(array $candidates, array $params, \tool_ltidiagnostics\local\scope $scope): string {
        $t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);
        $courseids = [];
        $cms = [];
        foreach ($candidates as $c) {
            foreach ($c->usage as $u) {
                $courseids[$u['courseid']] = $u['courseid'];
                if ($u['cmid'] && $scope->courseid && $u['courseid'] === (int) $scope->courseid) {
                    $cms[$u['cmid']] = $u['courseid'];
                }
            }
        }
        \tool_ltidiagnostics\local\names::preload(array_values($courseids), $cms);
        $select = 'custom-select-sm form-select-sm';
        $field = fn(string $id, string $label, string $control): string => \html_writer::div(
            \html_writer::label($label, $id, false, ['class' => 'small mb-0']) . $control,
            'ltidiag-field'
        );
        // Option labels are HTML-safe (formatted names, escaped strings); html_writer::select() does not escape them.
        $courses = [];
        foreach ($courseids as $id) {
            $courses[$id] = \tool_ltidiagnostics\local\names::course((int) $id);
        }
        \core_collator::asort($courses);
        $courses = [0 => s($t('scope_allcourses'))] + $courses;
        if ($scope->courseid && !isset($courses[$scope->courseid])) {
            $courses[$scope->courseid] = \tool_ltidiagnostics\local\names::course((int) $scope->courseid);
        }
        $cmoptions = [];
        foreach ($cms as $id => $courseid) {
            $cmoptions[$id] = \tool_ltidiagnostics\local\names::cm((int) $id, (int) $courseid);
        }
        \core_collator::asort($cmoptions);
        $cmoptions = [0 => s($scope->courseid ? $t('scope_allcms') : $t('overview_cm_choosecourse'))] + $cmoptions;
        if ($scope->cmid && !isset($cmoptions[$scope->cmid])) {
            $cmoptions[$scope->cmid] = \tool_ltidiagnostics\local\names::cm((int) $scope->cmid, (int) $scope->courseid);
        }
        $f = $field('id_ov_search', get_string('search'), \html_writer::empty_tag('input', ['type' => 'search',
            'name' => 'search', 'id' => 'id_ov_search', 'value' => $params['search'] ?? '',
            'class' => 'form-control form-control-sm']));
        $f .= $field('id_ov_role', $t('role'), \html_writer::select([
            '' => s($t('allroles')), 'platform' => s($t('role_platform')), 'tool' => s($t('role_tool')),
        ], 'role', $params['role'] ?? '', false, ['id' => 'id_ov_role', 'class' => $select]));
        $f .= $field('id_ov_courseid', $t('scope_course_label'), \html_writer::select(
            $courses,
            'courseid',
            (int) $scope->courseid,
            false,
            ['id' => 'id_ov_courseid', 'class' => $select]
        ));
        $f .= $field('id_ov_cmid', $t('scope_cm_label'), \html_writer::select(
            $cmoptions,
            'cmid',
            (int) $scope->cmid,
            false,
            ['id' => 'id_ov_cmid', 'class' => $select]
        ));
        $buttons = \html_writer::tag('button', $t('filter'), ['type' => 'submit', 'class' => 'btn btn-primary btn-sm']);
        if ($params) {
            $buttons .= \html_writer::link(
                new \moodle_url('/admin/tool/ltidiagnostics/index.php'),
                $t('filter_reset'),
                ['class' => 'btn btn-link btn-sm', 'id' => 'tool_ltidiagnostics_overview_reset']
            );
        }
        $f .= \html_writer::div($buttons, 'ltidiag-field ltidiag-buttons');
        $o = \html_writer::tag('form', \html_writer::div($f, 'ltidiag-toolbar'), ['method' => 'get',
            'action' => (new \moodle_url('/admin/tool/ltidiagnostics/index.php'))->out_omit_querystring(),
            'class' => 'mb-2', 'id' => 'tool_ltidiagnostics_overview_filter', 'role' => 'search',
            'aria-label' => $t('overview_filter_label')]);
        foreach ($scope->errors as $error) {
            $o .= $this->notification($t($error), \core\output\notification::NOTIFY_WARNING);
        }
        return $o;
    }

    /** @var int Use entries shown per connection in the overview. */
    private const USAGESHOWN = 5;

    /**
     * The use of a connection shown in the overview: inside the filter, without duplicates, at most USAGESHOWN entries.
     *
     * @param connection $c Connection.
     * @param int $courseid Course filter (0: none).
     * @param int $cmid Activity filter (0: none).
     * @return array items (usage entries), more (count not shown)
     */
    private static function usage_shown(connection $c, int $courseid, int $cmid): array {
        $items = [];
        $more = 0;
        foreach ($c->usage as $u) {
            if (($courseid > 0 && $u['courseid'] !== $courseid) || ($cmid > 0 && $u['cmid'] !== $cmid)) {
                continue;
            }
            $key = $u['kind'] . ':' . $u['courseid'] . ':' . $u['cmid'] . ':' . $u['toolid'];
            if (isset($items[$key])) {
                continue;
            }
            if (count($items) >= self::USAGESHOWN) {
                $more++;
                $items[$key] = null;
                continue;
            }
            $items[$key] = $u;
        }
        return ['items' => array_values(array_filter($items)), 'more' => $more];
    }

    /**
     * Where a connection is used (issues #29, #30): its publications (tool role), activities and course-bound tool type
     * (platform role) by name and id, each with its own edit pencil when the user may edit it. With an overview filter
     * only the use inside that course or activity is listed.
     *
     * @param array $shown Result of usage_shown().
     * @return string HTML.
     */
    private function usage_cell(array $shown): string {
        $items = [];
        foreach ($shown['items'] as $u) {
            if ($u['kind'] === 'publication') {
                $name = \tool_ltidiagnostics\local\names::publication($u);
                $html = $name;
                $edit = \tool_ltidiagnostics\local\navigation::edit_publication((int) $u['courseid'], (int) $u['enrolid']);
                if ($edit) {
                    $html .= \tool_ltidiagnostics\local\navigation::pencil($edit, get_string(
                        'nav_edit_publication_of',
                        'tool_ltidiagnostics',
                        \tool_ltidiagnostics\local\names::plain($name)
                    ));
                }
            } else if ($u['kind'] === 'activity') {
                $html = \tool_ltidiagnostics\local\identity::cm_html((int) $u['cmid'], (int) $u['courseid']);
            } else {
                $html = \tool_ltidiagnostics\local\identity::course_html((int) $u['courseid']);
            }
            if (!empty($u['cmid'])) {
                // An activity is named together with its course.
                $html .= \html_writer::div(get_string(
                    'gpre_incourse',
                    'tool_ltidiagnostics',
                    \tool_ltidiagnostics\local\names::course((int) $u['courseid'])
                ), 'text-muted');
            }
            $items[] = \html_writer::tag('li', $html, ['class' => 'mb-1']);
        }
        if (!$items) {
            return \html_writer::span(get_string('usedin_none', 'tool_ltidiagnostics'), 'text-muted');
        }
        $o = \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0 small']);
        if ($shown['more'] > 0) {
            $o .= \html_writer::span(get_string('usedin_more', 'tool_ltidiagnostics', $shown['more']), 'small text-muted');
        }
        return $o;
    }

    /**
     * Header of a connection page (issues #12, #30): the way back, the readable name with its local ids and the one
     * contextual action (the edit pencil of the connection), then one short line of facts.
     *
     * @param \tool_ltidiagnostics\local\connection $c Connection.
     * @return string
     */
    public function connection_header(connection $c): string {
        $o = \tool_ltidiagnostics\local\navigation::back_link();
        $title = s(\tool_ltidiagnostics\local\navigation::label($c));
        $edit = \tool_ltidiagnostics\local\navigation::edit_connection($c);
        if ($edit) {
            $title .= \tool_ltidiagnostics\local\navigation::pencil($edit['url'], $edit['label']);
        }
        $o .= $this->heading($title, 2, '', 'tool_ltidiagnostics_connheading');
        $facts = [get_string('connectionsummary', 'tool_ltidiagnostics', (object) [
            'role' => get_string('role_' . $c->role, 'tool_ltidiagnostics'),
            'lti' => s($c->ltiversion),
            'state' => s($c->state),
            'id' => \tool_ltidiagnostics\local\support::diagnostic_id($c->key),
        ])];
        if ($c->issuer !== '') {
            $facts[] = get_string('id_issuer', 'tool_ltidiagnostics', s($c->issuer));
        }
        if ($c->clientid !== '') {
            $facts[] = get_string('id_client', 'tool_ltidiagnostics', s($c->clientid));
        }
        if ($c->deploymentid !== '') {
            $facts[] = get_string('id_deployment', 'tool_ltidiagnostics', s($c->deploymentid));
        }
        $o .= \html_writer::tag('p', implode(' · ', $facts), ['class' => 'small text-muted',
            'id' => 'tool_ltidiagnostics_connids']);
        return $o;
    }

    /**
     * Scope bar above the tabs (issue #13): course, activity and period for every tab, chips of the active scope, reset.
     *
     * @param \tool_ltidiagnostics\local\connection $c Connection.
     * @param \tool_ltidiagnostics\local\scope $scope Scope.
     * @param string $tab Current tab.
     * @return string
     */
    public function scope_bar(connection $c, \tool_ltidiagnostics\local\scope $scope, string $tab): string {
        $t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);
        $related = \tool_ltidiagnostics\local\navigation::related($c);
        $base = new \moodle_url('/admin/tool/ltidiagnostics/connection.php');
        $f = \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'key', 'value' => $c->key])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);
        // Option labels are HTML-safe already (names are formatted, invalid ids are shown as missing): html_writer::select()
        // does not escape them again.
        $courses = [0 => s($t('scope_allcourses'))] + $related['courses'];
        if ($scope->courseid && !isset($courses[$scope->courseid])) {
            $courses[$scope->courseid] = \tool_ltidiagnostics\local\names::course($scope->courseid);
        }
        $select = 'custom-select-sm form-select-sm';
        $f .= \html_writer::div(\html_writer::label($t('scope_course_label'), 'id_scope_courseid', false, ['class' => 'small mb-0'])
            . \html_writer::select($courses, 'courseid', $scope->courseid, false, ['id' => 'id_scope_courseid',
                'class' => $select]), 'ltidiag-field');
        $cms = [0 => s($t('scope_allcms'))];
        foreach ($related['cms'] as $cmid => $info) {
            if (!$scope->courseid || $info['courseid'] === $scope->courseid) {
                $cms[$cmid] = $info['name'];
            }
        }
        if ($scope->cmid && !isset($cms[$scope->cmid])) {
            $cms[$scope->cmid] = \tool_ltidiagnostics\local\names::cm($scope->cmid, (int) $scope->courseid);
        }
        $f .= \html_writer::div(\html_writer::label($t('scope_cm_label'), 'id_scope_cmid', false, ['class' => 'small mb-0'])
            . \html_writer::select($cms, 'cmid', $scope->cmid, false, ['id' => 'id_scope_cmid',
                'class' => $select]), 'ltidiag-field');
        $p = $scope->params();
        foreach (['from', 'to'] as $name) {
            $f .= \html_writer::div(\html_writer::label($t('scope_' . $name), 'id_scope_' . $name, false, ['class' => 'small mb-0'])
                . \html_writer::empty_tag('input', ['type' => 'date', 'name' => $name, 'id' => 'id_scope_' . $name,
                    'value' => $p[$name] ?? '', 'class' => 'form-control form-control-sm']), 'ltidiag-field');
        }
        $f .= \html_writer::div(\html_writer::tag('button', $t('scope_apply'), ['type' => 'submit',
            'class' => 'btn btn-primary btn-sm']), 'ltidiag-field ltidiag-buttons');
        $o = \html_writer::tag('form', \html_writer::div($f, 'ltidiag-toolbar'), ['method' => 'get',
            'action' => $base->out_omit_querystring(), 'class' => 'mb-1',
            'id' => 'tool_ltidiagnostics_scope', 'role' => 'search', 'aria-label' => $t('scope_label')]);
        $chips = [];
        foreach ($scope->describe() as $kind => $text) {
            // The names in the text are already formatted (escaped) by format_string(); the rest are numbers and dates.
            $chips[] = \html_writer::span($text, 'badge bg-info text-dark mr-1 me-1', ['data-chip' => $kind]);
        }
        $info = $chips ? implode('', $chips) . ' ' . \html_writer::link(
            new \moodle_url($base, ['key' => $c->key, 'tab' => $tab]),
            $t('scope_reset'),
            ['id' => 'tool_ltidiagnostics_scope_reset']
        ) : \html_writer::span($t('scope_none'), 'text-muted');
        $o .= \html_writer::div($info, 'mb-1', ['id' => 'tool_ltidiagnostics_scope_chips', 'aria-live' => 'polite']);
        $o .= \html_writer::tag('p', $t('scope_tz', \core_date::get_user_timezone()), ['class' => 'small text-muted mb-1']);
        foreach ($scope->errors as $error) {
            $o .= $this->notification($t($error), \core\output\notification::NOTIFY_WARNING);
        }
        return \html_writer::div($o, 'border rounded p-2 mb-3', ['id' => 'tool_ltidiagnostics_scopebar']);
    }

    /**
     * Note of how the scope applies to a tab (or that it does not).
     *
     * @param string $kind applies, partial or systemwide.
     * @param \tool_ltidiagnostics\local\scope $scope Scope.
     * @return string
     */
    public function scope_note(string $kind, \tool_ltidiagnostics\local\scope $scope): string {
        if ($scope->is_empty()) {
            return '';
        }
        $text = get_string('scope_note_' . $kind, 'tool_ltidiagnostics');
        return \html_writer::tag('p', $text, ['class' => 'alert alert-secondary py-1',
            'data-scopenote' => $kind]);
    }

    /**
     * The function check (issue #18): per service the evidence ladder with source, time and evidence kind, what the
     * check needs, how to produce the missing evidence, and safe drill-down links. Read-only and mutating actions are
     * labelled; mutating ones exist only in their secured workflow (grade replay).
     *
     * @param array $matrix Rows from the matrix builder.
     * @param connection|null $c Connection (for the drill-down links).
     * @param \tool_ltidiagnostics\local\scope|null $scope Scope.
     * @return string
     */
    public function matrix(array $matrix, ?connection $c = null, ?\tool_ltidiagnostics\local\scope $scope = null): string {
        $context = \context_system::instance();
        $sm = get_string_manager();
        $rows = [];
        foreach ($matrix as $feature => $row) {
            $steps = [];
            foreach ($row['ladder'] as $step => $ev) {
                $steps[] = [
                    'step' => $step,
                    'stepname' => get_string('step_' . $step, 'tool_ltidiagnostics'),
                    'state' => get_string('state_' . $ev->state, 'tool_ltidiagnostics'),
                    'kind' => get_string('kind_' . $ev->kind, 'tool_ltidiagnostics'),
                    'detail' => $ev->detailkey !== '' ? get_string($ev->detailkey, 'tool_ltidiagnostics', $ev->detailparam) : '',
                    'time' => $ev->time ? userdate($ev->time, '%Y-%m-%d %H:%M:%S', 'UTC') . ' UTC' : '',
                ];
            }
            $howto = [];
            if ($sm->string_exists('fc_howto_' . $feature, 'tool_ltidiagnostics')) {
                foreach (explode("\n", get_string('fc_howto_' . $feature, 'tool_ltidiagnostics')) as $line) {
                    if (trim($line) !== '') {
                        $howto[] = ['text' => trim($line)];
                    }
                }
            }
            $actions = $c ? $this->function_actions($feature, $c, $scope, $context) : [];
            $rows[] = [
                'feature' => $feature,
                'status' => $row['status'],
                'label' => get_string('feature_' . $feature, 'tool_ltidiagnostics'),
                'statuslabel' => get_string('status_' . $row['status'], 'tool_ltidiagnostics'),
                'badge' => self::badge_class($row['light']),
                'reason' => get_string('reason_' . $row['reason'], 'tool_ltidiagnostics'),
                'next' => get_string('next_' . $row['next'], 'tool_ltidiagnostics'),
                'checks' => $sm->string_exists('fc_checks_' . $feature, 'tool_ltidiagnostics')
                    ? get_string('fc_checks_' . $feature, 'tool_ltidiagnostics') : '',
                'howto' => $howto,
                'hashowto' => !empty($howto),
                'actions' => $actions,
                'hasactions' => !empty($actions),
                'notes' => array_map(function ($n) {
                    return ['text' => get_string('note_' . $n, 'tool_ltidiagnostics')];
                }, $row['notes']),
                'steps' => $steps,
            ];
        }
        return $this->render_from_template('tool_ltidiagnostics/matrix', [
            'caption' => get_string('matrix', 'tool_ltidiagnostics'),
            'intro' => get_string('fc_intro', 'tool_ltidiagnostics'),
            'limits' => get_string('matrix_limits', 'tool_ltidiagnostics'),
            'headers' => [
                'feature' => get_string('feature', 'tool_ltidiagnostics'), 'status' => get_string('status', 'tool_ltidiagnostics'),
                'reason' => get_string('reasoncol', 'tool_ltidiagnostics'), 'next' => get_string('nextstep', 'tool_ltidiagnostics'),
                'ladder' => get_string('ladder', 'tool_ltidiagnostics'), 'step' => get_string('step', 'tool_ltidiagnostics'),
                'state' => get_string('state', 'tool_ltidiagnostics'), 'kind' => get_string('evidence', 'tool_ltidiagnostics'),
                'detail' => get_string('detail', 'tool_ltidiagnostics'), 'howto' => get_string('fc_howto', 'tool_ltidiagnostics'),
                'actions' => get_string('fc_actions', 'tool_ltidiagnostics'),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * Drill-down and test actions of a function, each with its effect (read only or mutating) and only when the user may
     * use it and the target exists.
     *
     * @param string $feature Feature.
     * @param connection $c Connection.
     * @param \tool_ltidiagnostics\local\scope|null $scope Scope.
     * @param \context $context System context.
     * @return array[] label, url, effect, effectlabel
     */
    private function function_actions(
        string $feature,
        connection $c,
        ?\tool_ltidiagnostics\local\scope $scope,
        \context $context
    ): array {
        $base = ['key' => $c->key] + ($scope ? $scope->params() : []);
        $url = fn(string $tab, array $extra = []) => (new \moodle_url(
            '/admin/tool/ltidiagnostics/connection.php',
            ['tab' => $tab] + $base + $extra
        ))->out(false);
        $ro = get_string('fc_effect_readonly', 'tool_ltidiagnostics');
        $out = [];
        $add = function (string $label, string $target, string $effect = 'readonly') use (&$out, $ro) {
            $out[] = ['label' => $label, 'url' => $target, 'effect' => $effect,
                'effectlabel' => $effect === 'readonly' ? $ro : get_string('fc_effect_mutating', 'tool_ltidiagnostics')];
        };
        $service = ['lti_core' => 'launch', 'ags' => 'ags', 'nrps' => 'nrps', 'course_group' => 'groups'][$feature] ?? '';
        if (in_array($feature, ['lti_core', 'ags', 'nrps', 'deeplinking'], true)) {
            $add(get_string('fc_go_messages', 'tool_ltidiagnostics'), $url('messages'));
        }
        if ($service !== '') {
            $add(get_string('fc_go_evidence', 'tool_ltidiagnostics'), $url('evidence', ['service' => $service]));
            $add(get_string('fc_go_timeline', 'tool_ltidiagnostics'), $url('timeline', ['service' => $service]));
        }
        if ($feature === 'ags' || $feature === 'nrps') {
            $add(get_string('fc_go_tasks', 'tool_ltidiagnostics'), $url('evidence', ['view' => 'tasks', 'service' => $service]));
        }
        if ($feature === 'ags' && $c->role === connection::ROLE_TOOL) {
            $add(get_string('fc_go_grades', 'tool_ltidiagnostics'), $url('grades'));
            if (has_capability('tool/ltidiagnostics:runchecks', $context)) {
                $add(get_string('fc_go_resultcheck', 'tool_ltidiagnostics'), $url('grades', ['ftransfer' => 'accepted']));
            }
            if (\tool_ltidiagnostics\local\grades\repair_service::can_repair($c->key)) {
                $add(get_string('fc_go_replay', 'tool_ltidiagnostics'), $url('grades'), 'mutating');
            }
        }
        if (
            in_array($feature, ['lti_core', 'ags', 'nrps'], true) && has_capability('tool/ltidiagnostics:runchecks', $context)
            && \tool_ltidiagnostics\local\config::get('activechecks')
        ) {
            $add(get_string('fc_go_probes', 'tool_ltidiagnostics'), $url('health'));
        }
        $add(get_string('fc_go_diagnosis', 'tool_ltidiagnostics'), $url('health'));
        return $out;
    }


    /**
     * The health check result.
     *
     * @param array $result Result of health_check::evaluate().
     * @return string
     */
    public function healthcheck(array $result): string {
        $progress = [];
        foreach ($result['progress'] as $p) {
            $progress[] = [
                'label' => get_string($p['step'], 'tool_ltidiagnostics'),
                'done' => $p['done'],
                'statelabel' => get_string($p['done'] ? 'progress_done' : 'progress_skipped', 'tool_ltidiagnostics'),
            ];
        }
        $layers = [];
        foreach ($result['layers'] as $layer => $info) {
            $layers[] = [
                'layer' => $layer,
                'light' => $info['light'],
                'label' => get_string('layer_' . $layer, 'tool_ltidiagnostics'),
                'badge' => self::badge_class($info['light']),
                'lightlabel' => get_string('light_' . $info['light'], 'tool_ltidiagnostics'),
            ];
        }
        $buckets = [];
        $root = null;
        foreach ($result['findings'] as $f) {
            $str = $f->strings();
            $entry = $this->finding_context($f, $str);
            if (!empty($result['rootcause']) && $result['rootcause'] === $f) {
                $root = $entry;
            }
            $buckets[$f->severity][] = $entry;
        }
        $groups = [];
        foreach ([finding::BLOCKING, finding::WARNING, finding::NOTCHECKED, finding::INFO, finding::OK] as $sev) {
            if (!empty($buckets[$sev])) {
                $groups[] = ['heading' => get_string('severity_' . $sev, 'tool_ltidiagnostics'), 'findings' => $buckets[$sev]];
            }
        }
        return $this->render_from_template('tool_ltidiagnostics/healthcheck', [
            'labels' => [
                'intervention' => get_string('intervention', 'tool_ltidiagnostics'),
                'risk' => get_string('risk', 'tool_ltidiagnostics'),
                'verification' => get_string('verification', 'tool_ltidiagnostics'),
                'where' => get_string('wherelabel', 'tool_ltidiagnostics'),
                'alt' => get_string('alternativecause', 'tool_ltidiagnostics'),
                'uncertain' => get_string('uncertainlabel', 'tool_ltidiagnostics'),
                'responsible' => get_string('responsible', 'tool_ltidiagnostics'),
                'consequence' => get_string('consequencenote', 'tool_ltidiagnostics'),
            ],
            'headings' => [
                'rootcause' => get_string('hc_rootcause', 'tool_ltidiagnostics'),
                'observability' => get_string('obs_heading', 'tool_ltidiagnostics'),
                'progress' => get_string('hc_progress', 'tool_ltidiagnostics'),
                'layers' => get_string('hc_layers', 'tool_ltidiagnostics'),
                'findings' => get_string('hc_findings', 'tool_ltidiagnostics'),
                'nofindings' => get_string('hc_nofindings', 'tool_ltidiagnostics'),
            ],
            'progress' => $progress,
            'layers' => $layers,
            'rootcause' => $root,
            'norootcause' => $root === null ? get_string('hc_norootcause', 'tool_ltidiagnostics') : '',
            'groups' => $groups,
            'observability' => $this->observability_table($result['observability'] ?? []),
        ]);
    }

    /**
     * Status per service (issue #20): separate ratings with source and time.
     *
     * @param array $services Result of health_check (services).
     * @return string
     */
    public function services_table(array $services): string {
        $t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);
        $rows = [];
        $light = ['ok' => 'green', 'warning' => 'amber', 'failed' => 'red', 'unknown' => 'grey', 'notapplicable' => 'grey'];
        foreach ($services as $service => $sv) {
            $h = new \html_table_cell($t('svc_' . $service));
            $h->header = true;
            $h->scope = 'row';
            $row = new \html_table_row([$h, \html_writer::span($t('svcstate_' . $sv['state']), 'badge ' .
                self::badge_class($light[$sv['state']] ?? 'grey')), $t($sv['detail'], $sv['param'] ?? null),
                $sv['source'] !== '' ? $t('svcsrc_' . $sv['source']) : '-',
                $sv['time'] ? userdate($sv['time'], '%Y-%m-%d %H:%M:%S', 'UTC') . ' UTC' : '-']);
            $row->attributes['data-service'] = $service;
            $row->attributes['data-state'] = $sv['state'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_services';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('svc_caption');
        $table->captionhide = true;
        $table->head = [$t('svc_service'), $t('state'), $t('detail'), $t('source'), $t('time')];
        $table->data = $rows;
        return $this->heading($t('svc_caption'), 3) . \html_writer::tag('p', $t('svc_intro'), ['class' => 'small text-muted'])
            . \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * Results of the stored network checks (issue #16): target, method, executor, UTC time, address and proxy path, code,
     * and what such a check can and cannot prove.
     *
     * @param array $probes Stored probes keyed by endpoint.
     * @return string
     */
    public function probes_table(array $probes): string {
        $t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);
        if (!$probes) {
            return '';
        }
        $rows = [];
        foreach ($probes as $key => $p) {
            if (!is_array($p) || empty($p['code'])) {
                continue;
            }
            $h = new \html_table_cell(s(redactor::url((string) ($p['url'] ?? ''), true)) . \html_writer::empty_tag('br') .
                \html_writer::span($t('probe_endpoint_' . (in_array($key, ['jwks', 'launch', 'auth', 'oauth', 'initiatelogin',
                'custom'], true) ? $key : 'custom')), 'small text-muted'));
            $h->header = true;
            $h->scope = 'row';
            $path = [];
            if (!empty($p['dns'])) {
                $path[] = $t('probe_dns', s(implode(', ', (array) $p['dns'])));
            }
            if (($p['peer'] ?? '') !== '') {
                $path[] = $t('probe_peer', s($p['peer']));
            }
            $path[] = !empty($p['viaproxy']) ? $t('probe_viaproxy') : $t('probe_direct');
            $result = get_string_manager()->string_exists('probecode_' . $p['code'], 'tool_ltidiagnostics')
                ? $t('probecode_' . $p['code']) : s($p['code']);
            if ((int) ($p['httpstatus'] ?? 0)) {
                $result .= ' · ' . $t('ghttpstatus', (int) $p['httpstatus']);
            }
            if ((int) ($p['errno'] ?? 0)) {
                $result .= ' · ' . $t('probe_errno', (int) $p['errno']);
            }
            $limits = $key === 'oauth' ? $t('probe_limit_post') : $t('probe_limit_get');
            $row = new \html_table_row([$h, s($p['method'] ?? 'GET'), $t('probe_executor_' . ($p['executor'] ?? 'this_site')),
                userdate((int) $p['checkedat'], '%Y-%m-%d %H:%M:%S', 'UTC') . ' UTC', implode(\html_writer::empty_tag('br'), $path),
                $result, $limits]);
            $row->attributes['data-endpoint'] = $key;
            $row->attributes['data-code'] = $p['code'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_probes';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('probe_caption');
        $table->captionhide = true;
        $table->head = [$t('probe_target'), $t('probe_method'), $t('probe_executor'), $t('time'), $t('probe_path'),
            $t('result'), $t('probe_limits')];
        $table->data = $rows;
        return $this->heading($t('probe_caption'), 3) . \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * Template context of one finding.
     *
     * @param finding $f Finding.
     * @param array $str Strings of the finding.
     * @return array
     */
    private function finding_context(finding $f, array $str): array {
        return [
            'severity' => $f->severity,
            'severitylabel' => get_string('severity_' . $f->severity, 'tool_ltidiagnostics'),
            'testid' => $f->testid,
            'title' => $str['title'],
            'layer' => get_string('layer_' . $f->layer, 'tool_ltidiagnostics'),
            'responsible' => get_string('responsible_' . $f->responsible, 'tool_ltidiagnostics'),
            'detail' => $str['detail'],
            'fix' => $str['fix'],
            'risklabel' => get_string('risk_' . $f->risk, 'tool_ltidiagnostics'),
            'verify' => $str['verify'],
            'where' => $str['where'],
            'uncertain' => $str['uncertain'],
            'alt' => $str['alt'],
            'consequence' => $f->consequence,
            'evidence' => get_string('kind_' . $f->evidencekind, 'tool_ltidiagnostics')
                . ($f->confidence !== '' ? ' / ' . $f->confidence : ''),
            'system' => $f->system,
            'systemlabel' => get_string('system_' . $f->system, 'tool_ltidiagnostics'),
            'certainty' => $f->certainty,
            'certaintylabel' => get_string('certainty_' . $f->certainty, 'tool_ltidiagnostics'),
            'check' => $f->diagnosis()['check'],
        ];
    }

    /**
     * Messages and fields (issue #19): per protocol message configured / observed / verified / booked, filterable by
     * message and state, with an explanation per message; per launch claim its requirement, the configuration or
     * runtime evidence of this site, the validation by Moodle core and the partner's statement.
     *
     * @param array $result Result of health_check::evaluate().
     * @param array $opts message (filter), state (filter), baseurl (moodle_url).
     * @return string
     */
    public function messages_tab(array $result, array $opts = []): string {
        $t = function (string $id, $a = null): string {
            return get_string($id, 'tool_ltidiagnostics', $a);
        };
        $sm = get_string_manager();
        $out = \html_writer::tag('p', $t('msg_intro'), ['class' => 'text-muted']);
        if (!empty($result['partner'])) {
            $out .= \html_writer::tag('p', $t('msg_partnerinfo', (object) [
                'id' => s($result['partner']['diagnosticid']),
                'generated' => s($result['partner']['generated']),
            ]), ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_partnerinfo']);
        }
        $messages = $result['messages'] ?? [];
        $filtermsg = (string) ($opts['message'] ?? '');
        $filterstate = (string) ($opts['state'] ?? '');
        if (!empty($opts['baseurl'])) {
            /** @var grades_renderer $g */
            $g = $this->page->get_renderer('tool_ltidiagnostics', 'grades');
            $msgoptions = ['' => $t('gf_any')];
            $stateoptions = ['' => $t('gf_any')];
            foreach ($messages as $m) {
                $msgoptions[$m['id']] = $t('msg_' . $m['id']);
                $stateoptions[$m['state']] = $t('msgstate_' . $m['state']);
            }
            $base = new \moodle_url($opts['baseurl']);
            $base->remove_params(['message', 'mstate']);
            $out .= $g->filter_form($base, 'messages', [['message', $t('msg_message'), $msgoptions, $filtermsg],
                ['mstate', $t('msg_state'), $stateoptions, $filterstate]], [], []);
        }
        $rows = [];
        foreach ($messages as $m) {
            if (($filtermsg !== '' && $m['id'] !== $filtermsg) || ($filterstate !== '' && $m['state'] !== $filterstate)) {
                continue;
            }
            $label = $t('msg_' . $m['id']);
            if ($sm->string_exists('msgdesc_' . $m['id'], 'tool_ltidiagnostics')) {
                $label .= \html_writer::tag('details', \html_writer::tag('summary', $t('msg_details')) .
                    \html_writer::div($t('msgdesc_' . $m['id']), 'small'), ['class' => 'small font-weight-normal fw-normal']);
            }
            $head = new \html_table_cell($label);
            $head->header = true;
            $head->scope = 'row';
            $observed = $t('obslevel_' . $m['observed']) . ($m['seen'] ? ' - ' . userdate($m['seen']) : '');
            if ($m['transportonly']) {
                $observed .= ' ' . $t('msg_transportonly');
            }
            if ($m['paged'] > 0) {
                $observed .= ' ' . $t('msg_paged', $m['paged']);
            }
            $light = self::state_light($m['state']);
            $state = \html_writer::span($t('msgstate_' . $m['state']), 'badge ' . self::badge_class($light));
            if ($m['limit'] !== '') {
                $state .= ' ' . \html_writer::span($t('msglimit_' . $m['limit']), 'small text-muted');
            }
            if ($m['missingscopes']) {
                $state .= ' ' . \html_writer::span($t('msg_missingscopes', s(implode(', ', $m['missingscopes']))), 'small');
            }
            $row = new \html_table_row([$head, $t('msgdir_' . $m['direction']), $t('msgconf_' . $m['configured']), $observed,
                $m['verified'] ? $t('yes') : $t('no'), $t('msgbooked_' . $m['booked']), $state]);
            $row->attributes['data-message'] = $m['id'];
            $row->attributes['data-state'] = $m['state'];
            $row->attributes['data-verified'] = $m['verified'] ? '1' : '0';
            $row->attributes['data-booked'] = $m['booked'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_messages';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('report_messages');
        $table->captionhide = true;
        $table->head = [$t('msg_message'), $t('direction'), $t('msg_configured'), $t('msg_observed'), $t('msg_verified'),
            $t('msg_booked'), $t('msg_state')];
        $table->data = $rows;
        $body = \html_writer::tag('p', $t('msg_nomatch'), ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_msg_none']);
        if ($rows) {
            $body = \html_writer::div(\html_writer::table($table), 'table-responsive');
        }
        $out .= $this->heading($t('report_messages'), 3) . $body;

        $items = '';
        foreach ($result['chains'] ?? [] as $ch) {
            $text = \html_writer::span($t('verdict_' . $ch['verdict']), 'badge '
                . self::badge_class($ch['verdict'] === 'end_to_end' ? 'green' : ($ch['verdict'] === 'failed' ? 'red'
                : ($ch['verdict'] === 'not_configured' ? 'grey' : 'amber')))) . ' ' . $t('chain_' . $ch['id']);
            if ($ch['weakest'] !== '') {
                $text .= ' - ' . $t('msg_' . $ch['weakest']) . ': ' . $t('chainreason_' . $ch['reason']);
            }
            $items .= \html_writer::tag('li', $text, ['data-chain' => $ch['id'], 'data-verdict' => $ch['verdict']]);
        }
        $out .= $this->heading($t('report_chains'), 3) . \html_writer::tag('p', $t('chain_intro'), ['class' => 'text-muted'])
            . \html_writer::tag('ul', $items, ['id' => 'tool_ltidiagnostics_chains', 'class' => 'list-unstyled']);

        $rows = [];
        foreach ($result['fields'] ?? [] as $f) {
            $head = new \html_table_cell(\html_writer::tag('code', s($f['field'])));
            $head->header = true;
            $head->scope = 'row';
            $requirement = \tool_ltidiagnostics\local\field_matrix::REQUIREMENT[$f['field']] ?? 'optional';
            $validated = $f['local'] === 'validated' ? $t('field_validated_yes')
                : (in_array($f['local'], ['present'], true) ? $t('field_validated_received')
                : (in_array($f['local'], ['always', 'yes', 'per_activity', 'no'], true) ? $t('field_validated_config')
                : $t('field_validated_none')));
            $missing = $requirement === 'required' && in_array($f['local'], ['absent', 'no'], true);
            $row = new \html_table_row([$head, $t('fieldcat_' . $f['category']),
                \html_writer::span($t('fieldreq_' . $requirement), 'badge ' . self::badge_class($missing ? 'red' :
                ($requirement === 'required' ? 'amber' : 'grey'))),
                $t('fieldstate_' . $f['local']), $validated,
                $f['partner'] !== '' ? $t('fieldstate_' . $f['partner']) : $t('field_nopartner'),
                $t('fieldagree_' . $f['agreement'])]);
            $row->attributes['data-field'] = $f['field'];
            $row->attributes['data-local'] = $f['local'];
            $row->attributes['data-requirement'] = $requirement;
            $row->attributes['data-agreement'] = $f['agreement'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_fields';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('report_fields');
        $table->captionhide = true;
        $table->head = [$t('field'), $t('fieldcategory'), $t('fieldrequirement'), $t('field_local'), $t('field_validation'),
            $t('field_partner'), $t('field_agreement')];
        $table->data = $rows;
        $out .= $this->heading($t('report_fields'), 3) . \html_writer::tag('p', $t('field_intro'), ['class' => 'text-muted'])
            . \html_writer::div(\html_writer::table($table), 'table-responsive');
        return $out;
    }


    /**
     * Findings as a table with the four parts of a diagnosis: cause, uncertainty, responsible system, safe check.
     *
     * @param \tool_ltidiagnostics\local\finding[] $findings Findings.
     * @param string $id Table id.
     * @return string
     */
    public function findings_table(array $findings, string $id): string {
        $t = function (string $sid, $a = null): string {
            return get_string($sid, 'tool_ltidiagnostics', $a);
        };
        $rows = [];
        foreach ($findings as $f) {
            $d = $f->diagnosis();
            $head = new \html_table_cell(\html_writer::span($t('severity_' . $f->severity), 'badge '
                . self::badge_class($f->severity === 'ok' ? 'green' : ($f->severity === 'blocking' ? 'red' : 'amber')))
                . ' ' . s($d['cause']));
            $head->header = true;
            $head->scope = 'row';
            $row = new \html_table_row([$head, s($d['detail']), $t('system_' . $d['system']), $t('certainty_' . $d['certainty'])
                . ' - ' . s($d['uncertainty']), s($d['check'])]);
            $row->attributes['data-code'] = $f->code;
            $row->attributes['data-system'] = $f->system;
            $row->attributes['data-certainty'] = $f->certainty;
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = $id;
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('hc_findings');
        $table->captionhide = true;
        $table->head = [$t('diag_cause'), $t('detail'), $t('systemlabel'), $t('uncertainlabel'), $t('checklabel')];
        $table->data = $rows;
        return \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * Traffic light of a message state.
     *
     * @param string $state State.
     * @return string green, amber, red or grey
     */
    public static function state_light(string $state): string {
        $map = ['booked' => 'green', 'verified' => 'green', 'observed' => 'amber', 'configured' => 'amber',
            'failed' => 'red', 'not_configured' => 'grey', 'not_observable' => 'grey'];
        return $map[$state] ?? 'grey';
    }

    /**
     * Completion sync transfer report (DIA-05).
     *
     * @param array $report Result of transfer_report::build().
     * @return string
     */
    public function transfers(array $report): string {
        $t = function (string $id, $a = null): string {
            return get_string($id, 'tool_ltidiagnostics', $a);
        };
        $out = \html_writer::tag('p', $t('transfers_intro'), ['class' => 'text-muted']);
        $mode = $t('transfers_mode_' . $report['mode']);
        $out .= \html_writer::tag('p', $t('transfers_side') . ': ' . $t('transfers_side_' . $report['side']) . ' &middot; '
            . $t('transfers_mode') . ': ' . $mode, ['id' => 'tool_ltidiagnostics_transfermode', 'data-mode' => $report['mode']]);
        foreach ($report['limits'] as $limit) {
            $out .= \html_writer::tag('p', $t('transfers_limit_' . $limit), ['class' => 'alert alert-warning',
                'data-limit' => $limit]);
        }
        $rows = [];
        foreach ($report['transactions'] as $tx) {
            $head = new \html_table_cell(s($tx['correlationid']));
            $head->header = true;
            $head->scope = 'row';
            $cells = [$head, s($tx['person'])];
            foreach (\tool_ltidiagnostics\local\transfer_report::STAGES as $stage) {
                $st = $tx['stages'][$stage];
                $text = $t('transfers_state_' . $st['state']);
                if ($st['local'] !== null) {
                    $text .= ' ' . userdate($st['local']['time'], get_string('strftimetime', 'langconfig'))
                        . ($st['local']['code'] !== '' ? ' (' . s($st['local']['code']) . ')' : '');
                    if (!empty($st['local']['native'])) {
                        $text .= ' ' . $t('transfers_native_' . $st['local']['native']);
                    }
                }
                if ($st['partner'] !== null) {
                    $text .= ' / ' . $t('transfers_partnerseen');
                }
                $cell = new \html_table_cell($text);
                $cell->attributes['data-stage'] = $stage;
                $cell->attributes['data-state'] = $st['state'];
                $cells[] = $cell;
            }
            $cells[] = \html_writer::span($t('transfers_verdict_' . $tx['verdict']), 'badge ' . self::badge_class(
                $tx['verdict'] === 'complete' ? 'green' : ($tx['verdict'] === 'failed' ? 'red' : 'amber')
            ));
            $row = new \html_table_row($cells);
            $row->attributes['data-correlationid'] = $tx['correlationid'];
            $row->attributes['data-verdict'] = $tx['verdict'];
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_transfers';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('transfers_title');
        $table->captionhide = true;
        $table->head = array_merge([$t('transfers_correlation'), $t('transfers_person')], array_map(function ($stage) use ($t) {
            return $t('transfers_stage_' . $stage);
        }, \tool_ltidiagnostics\local\transfer_report::STAGES), [$t('transfers_verdict')]);
        $table->data = $rows;
        if (!$rows) {
            return $out . \html_writer::tag('p', $t('transfers_none'), ['class' => 'alert alert-info',
                'id' => 'tool_ltidiagnostics_notransfers']);
        }
        return $out . \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * The observability table: for each transaction and direction, how much of the request and the response this system
     * can see, and whether anything was seen at all.
     *
     * @param array $rows Result of observability::build().
     * @return string
     */
    public function observability_table(array $rows): string {
        if (!$rows) {
            return '';
        }
        $tr = [];
        foreach (\tool_ltidiagnostics\local\report::observability_rows($rows) as $row) {
            $cells = [];
            foreach (['request', 'response'] as $part) {
                $cell = $row[$part];
                $text = get_string('obslevel_' . $cell['level'], 'tool_ltidiagnostics') . ' - '
                    . ($cell['seen'] !== '' ? get_string('obs_seenat', 'tool_ltidiagnostics', $cell['seen'])
                        : get_string('obs_notseen', 'tool_ltidiagnostics'));
                if ($cell['needs']) {
                    $text .= ' ' . get_string('obs_needs', 'tool_ltidiagnostics', implode(', ', array_map(function ($n) {
                        return get_string('obsneeds_' . $n, 'tool_ltidiagnostics');
                    }, $cell['needs'])));
                }
                $cells[$part] = new \html_table_cell($text);
                $cells[$part]->attributes['data-level'] = $cell['level'];
            }
            $head = new \html_table_cell($row['label']);
            $head->header = true;
            $head->scope = 'row';
            $tr[] = new \html_table_row([$head, get_string('obsdir_' . $row['direction'], 'tool_ltidiagnostics'),
                $cells['request'], $cells['response']]);
            $tr[count($tr) - 1]->attributes['data-transaction'] = $row['transaction'];
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_observability';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('obs_heading', 'tool_ltidiagnostics');
        $table->captionhide = true;
        $table->head = [get_string('obs_transaction', 'tool_ltidiagnostics'), get_string('direction', 'tool_ltidiagnostics'),
            get_string('obs_request', 'tool_ltidiagnostics'), get_string('obs_response', 'tool_ltidiagnostics')];
        $table->data = $tr;
        return \html_writer::tag('p', get_string('obs_intro', 'tool_ltidiagnostics'), ['class' => 'text-muted'])
            . \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * The timeline.
     *
     * @param array $tl Result of timeline::build().
     * @return string
     */
    public function timeline(array $tl): string {
        $links = [];
        foreach ($tl['edges'] as $e) {
            if (!isset($tl['items'][$e['to']], $tl['items'][$e['from']])) {
                continue;
            }
            $links[$e['from']][] = ['text' => get_string('tl_linked', 'tool_ltidiagnostics', (object) [
                'stage' => get_string('stage_' . $tl['items'][$e['to']]['stage'], 'tool_ltidiagnostics'),
                'confidence' => get_string('confidence_' . $e['confidence'], 'tool_ltidiagnostics'),
            ])];
        }
        $rows = [];
        foreach ($tl['items'] as $i => $item) {
            $rows[] = [
                'time' => userdate($item['time'], '%Y-%m-%d %H:%M:%S', 'UTC'),
                'stagecode' => $item['stage'],
                'stage' => get_string('stage_' . $item['stage'], 'tool_ltidiagnostics'),
                'sourcecode' => $item['source'],
                'source' => get_string('source_' . $item['source'], 'tool_ltidiagnostics'),
                'event' => redactor::text($item['label']) . (!empty($item['failed']) ? ' ' . get_string(
                    'tl_failed',
                    'tool_ltidiagnostics'
                ) : ''),
                'links' => $links[$i] ?? [],
            ];
        }
        $sources = [];
        foreach (['observer', 'logstore', 'accesslog'] as $name) {
            $available = !empty($tl['sources'][$name]);
            $sources[] = [
                'label' => get_string('source_' . $name, 'tool_ltidiagnostics'),
                'available' => $available,
                'statelabel' => get_string($available ? 'available' : 'unavailable', 'tool_ltidiagnostics'),
            ];
        }
        $coverage = [];
        foreach ($tl['coverage'] ?? [] as $name => $cov) {
            $coverage[] = [
                'label' => get_string('source_' . $name, 'tool_ltidiagnostics'),
                'count' => $cov['count'],
                'first' => $cov['first'] ? userdate($cov['first'], '%Y-%m-%d %H:%M:%S', 'UTC') : '-',
                'last' => $cov['last'] ? userdate($cov['last'], '%Y-%m-%d %H:%M:%S', 'UTC') : '-',
                'note' => get_string(
                    $cov['incomplete'] ? 'cov_incomplete' : ($cov['count'] ? 'cov_ok' : 'cov_empty'),
                    'tool_ltidiagnostics'
                ),
                'incomplete' => $cov['incomplete'],
            ];
        }
        $transactions = [];
        foreach ($tl['transactions'] ?? [] as $t) {
            $transactions[] = [
                // Plain values: the template escapes them.
                'correlationid' => $t['correlationid'],
                'component' => $t['component'],
                'connection' => $t['connkey'] !== '' ? $t['connkey'] : get_string('fam_connunknown', 'tool_ltidiagnostics'),
                'first' => userdate($t['first'], '%Y-%m-%d %H:%M:%S', 'UTC'),
                'last' => userdate($t['last'], '%Y-%m-%d %H:%M:%S', 'UTC'),
                'steps' => implode(' > ', $t['steps']),
                'outcomecode' => $t['outcome'],
                'outcome' => get_string('fam_outcome_' . $t['outcome'], 'tool_ltidiagnostics'),
            ];
        }
        return $this->render_from_template('tool_ltidiagnostics/timeline', [
            'hastransactions' => !empty($transactions),
            'transactions' => $transactions,
            'txheaders' => [
                'caption' => get_string('fam_caption', 'tool_ltidiagnostics'),
                'correlationid' => get_string('fam_correlationid', 'tool_ltidiagnostics'),
                'component' => get_string('fam_component', 'tool_ltidiagnostics'),
                'connection' => get_string('fam_connection', 'tool_ltidiagnostics'),
                'first' => get_string('cov_first', 'tool_ltidiagnostics'),
                'last' => get_string('cov_last', 'tool_ltidiagnostics'),
                'steps' => get_string('fam_steps', 'tool_ltidiagnostics'),
                'outcome' => get_string('fam_outcome', 'tool_ltidiagnostics'),
            ],
            'txintro' => get_string('fam_intro', 'tool_ltidiagnostics'),
            'covheaders' => [
                'caption' => get_string('cov_caption', 'tool_ltidiagnostics'),
                'source' => get_string('source', 'tool_ltidiagnostics'),
                'count' => get_string('cov_count', 'tool_ltidiagnostics'),
                'first' => get_string('cov_first', 'tool_ltidiagnostics'),
                'last' => get_string('cov_last', 'tool_ltidiagnostics'), 'note' => get_string('cov_note', 'tool_ltidiagnostics'),
            ],
            'coverage' => $coverage,
            'hascoverage' => !empty($coverage),
            'covintro' => get_string('cov_intro', 'tool_ltidiagnostics'),
            'caption' => get_string('timeline', 'tool_ltidiagnostics'),
            'headers' => [
                'time' => get_string('timeutc', 'tool_ltidiagnostics'), 'stage' => get_string('stage', 'tool_ltidiagnostics'),
                'source' => get_string('source', 'tool_ltidiagnostics'), 'event' => get_string('event', 'tool_ltidiagnostics'),
                'link' => get_string('correlatedwith', 'tool_ltidiagnostics'),
            ],
            'empty' => $rows ? '' : get_string('notimeline', 'tool_ltidiagnostics'),
            'rows' => $rows,
            'sources' => $sources,
        ]);
    }

    /**
     * The evidence tab: every source on its own, never merged into one verdict.
     *
     * @param connection $c Connection.
     * @param array $filters Filters.
     * @param int $now Time.
     * @return string
     */
    public function evidence_tab(connection $c, array $filters, int $now): string {
        $out = '';
        $context = \context_system::instance();

        // How much of each transaction can be seen at all (separate from what was seen).
        $facts = (new \tool_ltidiagnostics\local\fact_collector())->collect($c, $now, $filters);
        global $DB;
        $imports = (int) $DB->count_records('tool_ltidiagnostics_imp', ['status' => 'done']);
        $out .= $this->heading(get_string('obs_heading', 'tool_ltidiagnostics'), 3);
        $out .= $this->observability_table(\tool_ltidiagnostics\local\observability::build(
            $c,
            $facts,
            \tool_ltidiagnostics\local\snapshot::latest_probes($c->key),
            \tool_ltidiagnostics\local\observability::enabled_sources($imports)
        ));

        // Observer rows. No user column: the evidence needs the event, not the person.
        $out .= $this->heading(get_string('ev_observer', 'tool_ltidiagnostics'), 3);
        $rows = \tool_ltidiagnostics\local\observation_store::get_for_connection($c, $filters + ['limit' => 100]);
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_observer', 'tool_ltidiagnostics');
        $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('eventtype', 'tool_ltidiagnostics'),
            get_string('direction', 'tool_ltidiagnostics'), get_string('event', 'tool_ltidiagnostics'),
            get_string('entrypoint', 'tool_ltidiagnostics'), get_string('courseid', 'tool_ltidiagnostics')];
        foreach ($rows as $o) {
            $table->data[] = [userdate($o->timecreated, '%Y-%m-%d %H:%M:%S', 'UTC'), s($o->eventtype), s($o->direction),
                s($o->eventname), s($o->entrypoint), (int) $o->courseid ?: ''];
        }
        $out .= $rows ? \html_writer::table($table) : \html_writer::tag('p', get_string('norows', 'tool_ltidiagnostics'));

        // Logstore.
        $out .= $this->heading(get_string('ev_logstore', 'tool_ltidiagnostics'), 3);
        $log = (new \tool_ltidiagnostics\local\logstore_source())->fetch($context, $filters + ['limit' => 50]);
        if (!$log['available']) {
            $out .= $this->notification(
                get_string('logstore_' . $log['reason'], 'tool_ltidiagnostics'),
                \core\output\notification::NOTIFY_WARNING
            );
        } else {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable table-sm';
            $table->caption = get_string('ev_logstore', 'tool_ltidiagnostics');
            $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('event', 'tool_ltidiagnostics'),
                get_string('courseid', 'tool_ltidiagnostics')];
            foreach ($log['rows'] as $r) {
                $table->data[] = [userdate($r['time'], '%Y-%m-%d %H:%M:%S', 'UTC'), s($r['eventname']), $r['courseid'] ?: ''];
            }
            $out .= $log['rows'] ? \html_writer::table($table) : \html_writer::tag('p', get_string(
                'norows',
                'tool_ltidiagnostics'
            ));
        }

        // Transfer events of LTI family plugins (for example completion sync), with their correlation id.
        $out .= $this->heading(get_string('ev_family', 'tool_ltidiagnostics'), 3);
        $familyrows = [];
        foreach ($log['rows'] as $r) {
            if (!empty($r['family']) && ($r['connkey'] === '' || $r['connkey'] === $c->key)) {
                $familyrows[] = $r;
            }
        }
        if (!$log['available']) {
            $out .= \html_writer::tag('p', get_string('fam_nolog', 'tool_ltidiagnostics'));
        } else if (!$familyrows) {
            $out .= \html_writer::tag('p', get_string(
                \tool_ltidiagnostics\local\family_events::emitter_installed() ? 'fam_none' : 'fam_noemitter',
                'tool_ltidiagnostics'
            ));
        } else {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable table-sm';
            $table->id = 'tool_ltidiagnostics_familyevents';
            $table->caption = get_string('ev_family', 'tool_ltidiagnostics');
            $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('fam_step', 'tool_ltidiagnostics'),
                get_string('fam_component', 'tool_ltidiagnostics'), get_string('fam_correlationid', 'tool_ltidiagnostics'),
                get_string('fam_connection', 'tool_ltidiagnostics'), get_string('fam_code', 'tool_ltidiagnostics')];
            foreach ($familyrows as $r) {
                $table->data[] = [userdate($r['time'], '%Y-%m-%d %H:%M:%S', 'UTC'), s($r['step']), s($r['component']),
                    s($r['correlationid']), $r['connkey'] !== '' ? s($r['connkey']) :
                    get_string('fam_connunknown', 'tool_ltidiagnostics'), s($r['code'])];
            }
            $out .= \html_writer::table($table);
        }

        // Tasks.
        $out .= $this->heading(get_string('ev_tasks', 'tool_ltidiagnostics'), 3);
        $tasks = new \tool_ltidiagnostics\local\task_source();
        $cron = $tasks->cron_status($now);
        $out .= \html_writer::tag('p', get_string(
            'cron_' . $cron['status'],
            'tool_ltidiagnostics',
            $cron['age'] >= 0 ? (int) floor($cron['age'] / 60) : 0
        ));
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_tasks', 'tool_ltidiagnostics');
        $table->head = [get_string('task', 'tool_ltidiagnostics'), get_string('lastrun', 'tool_ltidiagnostics'),
            get_string('nextrun', 'tool_ltidiagnostics'), get_string('state', 'tool_ltidiagnostics')];
        foreach ($tasks->scheduled($now) as $t) {
            $state = $t['disabled'] ? get_string('disabled', 'tool_ltidiagnostics') : ($t['faildelay'] > 0 ?
                get_string('failing', 'tool_ltidiagnostics') : ($t['overdue'] ? get_string('overdue', 'tool_ltidiagnostics') :
                get_string('ok', 'tool_ltidiagnostics')));
            $table->data[] = [s($t['classname']), $t['lastrun'] ? userdate($t['lastrun']) : '-', $t['nextrun'] ?
                userdate($t['nextrun']) : '-', $state];
        }
        foreach ($tasks->adhoc() as $t) {
            $table->data[] = [s($t['classname']) . ' (' . get_string('adhoc', 'tool_ltidiagnostics') . ')', '-', '-',
                get_string('adhocsummary', 'tool_ltidiagnostics', (object) $t)];
        }
        $out .= $table->data ? \html_writer::table($table) : \html_writer::tag('p', get_string('norows', 'tool_ltidiagnostics'));
        $tl = $tasks->task_log(['limit' => 20, 'from' => $filters['from'] ?? 0, 'to' => $filters['to'] ?? 0]);
        if ($tl) {
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable table-sm';
            $table->caption = get_string('ev_tasklog', 'tool_ltidiagnostics');
            $table->head = [get_string('timeutc', 'tool_ltidiagnostics'), get_string('task', 'tool_ltidiagnostics'),
                get_string('result', 'tool_ltidiagnostics')];
            foreach ($tl as $row) {
                $table->data[] = [userdate($row['start'], '%Y-%m-%d %H:%M:%S', 'UTC'), s($row['classname']),
                    get_string($row['failed'] ? 'failed' : 'ok', 'tool_ltidiagnostics')];
            }
            $out .= \html_writer::table($table);
        }

        // Imported logs.
        $out .= $this->heading(get_string('ev_imports', 'tool_ltidiagnostics'), 3);
        $imports = $DB->get_records('tool_ltidiagnostics_imp', null, 'timecreated DESC, id DESC', '*', 0, 10);
        if (!$imports) {
            $out .= \html_writer::tag('p', get_string('noimports', 'tool_ltidiagnostics'));
        } else {
            $out .= $this->imports_table($imports);
        }
        $out .= \html_writer::tag('p', get_string('limit_absencenotevidence', 'tool_ltidiagnostics'), ['class' => 'text-muted']);
        return $out;
    }

    /**
     * Section of the evidence tab: sub-view navigation, filters, one paged source (issue #25).
     *
     * @param array $page Result of evidence_query::page().
     * @param array $ctx baseurl, params, view, key.
     * @return string
     */
    public function evidence_section(array $page, array $ctx): string {
        $t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);
        /** @var grades_renderer $g */
        $g = $this->page->get_renderer('tool_ltidiagnostics', 'grades');
        $params = $ctx['params'];
        $nav = [];
        foreach (\tool_ltidiagnostics\local\evidence_query::VIEWS as $view) {
            $url = new \moodle_url($ctx['baseurl'], ['view' => $view] + array_intersect_key($params, ['service' => 1, 'text' => 1,
                'eperpage' => 1]));
            $attrs = ['class' => 'nav-link ltidiag-nav' . ($view === $ctx['view'] ? ' active' : ''),
                'data-view' => $view];
            if ($view === $ctx['view']) {
                $attrs['aria-current'] = 'page';
            }
            $nav[] = \html_writer::tag('li', \html_writer::link($url, $t('ev_view_' . $view), $attrs), ['class' => 'nav-item']);
        }
        $o = \html_writer::tag(
            'nav',
            \html_writer::tag('ul', implode('', $nav), ['class' => 'nav nav-pills mb-2']),
            ['aria-label' => $t('ev_views')]
        );
        $selects = [['service', $t('ev_service'), ['' => $t('gf_any')] + array_combine(
            \tool_ltidiagnostics\local\evidence_query::SERVICES,
            array_map(fn($s) => $t('ev_service_' . $s), \tool_ltidiagnostics\local\evidence_query::SERVICES)
        ), $params['service'] ?? '']];
        if ($ctx['view'] === 'observer') {
            $selects[] = ['assign', $t('ev_assign'), ['' => $t('gf_any'), 'assigned' => $t('ev_assign_assigned'),
                'unassigned' => $t('ev_assign_unassigned')], $params['assign'] ?? ''];
        }
        if ($ctx['view'] !== 'imports') {
            $o .= $g->filter_form($ctx['baseurl'], 'evidence', $selects, ['view' => $ctx['view']], [
                'search' => ['text', $t('ev_text'), $params['text'] ?? ''],
                'perpage' => ['eperpage', $page['limit']],
            ]);
        }
        foreach ($page['notes'] as $note) {
            $o .= \html_writer::tag('p', $t($note), ['class' => 'small text-muted', 'data-note' => $note]);
        }
        if (!$page['scoped']) {
            $attrs = ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_ev_notscoped'];
            $o .= \html_writer::tag('p', $t('ev_notscoped'), $attrs);
        }
        if (!$page['available']) {
            return $o . $this->notification($t($page['reason']), \core\output\notification::NOTIFY_WARNING);
        }
        if ($ctx['view'] === 'tasks') {
            $o .= $this->tasks_overview($page['scheduled'] ?? [], $page['cron'] ?? null);
        }
        if ($ctx['view'] === 'imports') {
            $pager = $g->pager(
                $ctx['baseurl'],
                $params,
                'epage',
                (int) $ctx['page'],
                (int) $page['limit'],
                (int) $page['total']
            );
            return $o . $pager . (!empty($page['imports']) ? $this->imports_table($page['imports'])
                : \html_writer::tag('p', $t('noimports'))) . $pager;
        }
        $o .= \html_writer::tag(
            'p',
            $t('ev_count', (object) ['shown' => count($page['rows']), 'total' => $page['total']]),
            ['id' => 'tool_ltidiagnostics_ev_count', 'data-total' => (int) $page['total']]
        );
        if (!$page['rows']) {
            $attrs = ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_ev_none'];
            return $o . \html_writer::tag('p', $t('ev_none'), $attrs)
                . \html_writer::tag('p', $t('limit_absencenotevidence'), ['class' => 'small text-muted']);
        }
        $rows = [];
        $tz = $t('ev_tz_utc');
        if (\tool_ltidiagnostics\local\identity::can_see_names()) {
            \tool_ltidiagnostics\local\identity::preload(array_map(fn($r) => (int) $r['userid'], $page['rows']));
        }
        $cmids = [];
        foreach ($page['rows'] as $r) {
            if ($r['cmid'] && $r['courseid']) {
                $cmids[(int) $r['cmid']] = (int) $r['courseid'];
            }
        }
        \tool_ltidiagnostics\local\names::preload(array_column($page['rows'], 'courseid'), $cmids);
        foreach ($page['rows'] as $r) {
            $h = new \html_table_cell(userdate($r['time'], '%Y-%m-%d %H:%M:%S', 'UTC'));
            $h->header = true;
            $h->scope = 'row';
            $relation = [];
            if ($r['courseid']) {
                $relation[] = \tool_ltidiagnostics\local\identity::course_html($r['courseid']);
            }
            if ($r['cmid']) {
                $relation[] = \tool_ltidiagnostics\local\identity::cm_html($r['cmid'], (int) $r['courseid']);
            }
            if ($r['userid']) {
                $relation[] = \tool_ltidiagnostics\local\identity::person_html($ctx['key'], $r['userid']);
            }
            if ($r['correlationid'] !== '') {
                $relation[] = $t('ev_correlation', s($r['correlationid']));
            }
            $row = new \html_table_row([$h, $t('ev_source_' . $r['source']), $t('ev_service_' . $r['service']),
                $t('ev_dir_' . ($r['direction'] ?: 'local')), s($r['label']) . ($r['detail'] !== '' ? \html_writer::empty_tag('br')
                . \html_writer::span(s($r['detail']), 'small text-muted') : ''), $t('ev_level_' . $r['level']),
                $relation ? implode(\html_writer::empty_tag('br'), $relation) : $t('ev_systemwide'),
                $t($r['assigned'] ? 'ev_assigned' : 'ev_notassigned')]);
            $row->attributes['data-source'] = $r['source'];
            $row->attributes['data-service'] = $r['service'];
            $row->attributes['data-assigned'] = $r['assigned'] ? '1' : '0';
            $rows[] = $row;
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_ev_rows';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('ev_view_' . $ctx['view']);
        $table->head = [$t('ev_time', $tz), $t('source'), $t('ev_service'), $t('direction'), $t('event'), $t('ev_level'),
            $t('ev_relation'), $t('ev_assignment')];
        $table->data = $rows;
        $pager = $g->pager($ctx['baseurl'], $params, 'epage', (int) $ctx['page'], (int) $page['limit'], (int) $page['total']);
        $o .= $pager . \html_writer::div(\html_writer::table($table), 'table-responsive') . $pager;
        return $o . \html_writer::tag('p', $t('limit_absencenotevidence'), ['class' => 'small text-muted']);
    }

    /**
     * Scheduled tasks and the cron state (system wide).
     *
     * @param array $scheduled Scheduled tasks.
     * @param array|null $cron Cron status.
     * @return string
     */
    private function tasks_overview(array $scheduled, ?array $cron): string {
        $o = '';
        if ($cron) {
            $o .= \html_writer::tag('p', get_string(
                'cron_' . $cron['status'],
                'tool_ltidiagnostics',
                $cron['age'] >= 0 ? (int) floor($cron['age'] / 60) : 0
            ));
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_ev_scheduled';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_scheduled', 'tool_ltidiagnostics');
        $table->head = [get_string('task', 'tool_ltidiagnostics'), get_string('lastrun', 'tool_ltidiagnostics'),
            get_string('nextrun', 'tool_ltidiagnostics'), get_string('state', 'tool_ltidiagnostics')];
        foreach ($scheduled as $task) {
            $state = $task['disabled'] ? get_string('disabled', 'tool_ltidiagnostics') : ($task['faildelay'] > 0 ?
                get_string('failing', 'tool_ltidiagnostics') : ($task['overdue'] ? get_string('overdue', 'tool_ltidiagnostics') :
                get_string('ok', 'tool_ltidiagnostics')));
            $h = new \html_table_cell(s($task['classname']));
            $h->header = true;
            $h->scope = 'row';
            $table->data[] = new \html_table_row([$h, $task['lastrun'] ? userdate($task['lastrun']) : '-', $task['nextrun'] ?
                userdate($task['nextrun']) : '-', $state]);
        }
        return $o . ($table->data ? \html_writer::div(\html_writer::table($table), 'table-responsive') : '');
    }

    /**
     * Section of the timeline tab: filters, paged items (issue #25).
     *
     * @param array $tl Timeline (filtered).
     * @param array $ctx baseurl, params, limit, offset, total.
     * @return string
     */
    public function timeline_section(array $tl, array $ctx): string {
        $t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);
        /** @var grades_renderer $g */
        $g = $this->page->get_renderer('tool_ltidiagnostics', 'grades');
        $params = $ctx['params'];
        $o = $g->filter_form($ctx['baseurl'], 'timeline', [['service', $t('ev_service'), ['' => $t('gf_any')] + array_combine(
            \tool_ltidiagnostics\local\evidence_query::SERVICES,
            array_map(fn($s) => $t('ev_service_' . $s), \tool_ltidiagnostics\local\evidence_query::SERVICES)
        ), $params['service'] ?? '']], [], ['search' => ['text', $t('ev_text'), $params['text'] ?? ''],
            'perpage' => ['tperpage', $ctx['limit']]]);
        $page = $tl;
        $page['items'] = array_slice($tl['items'], $ctx['offset'], $ctx['limit'], true);
        $o .= \html_writer::tag(
            'p',
            $t('ev_count', (object) ['shown' => count($page['items']), 'total' => $ctx['total']]),
            ['id' => 'tool_ltidiagnostics_tl_count', 'data-total' => (int) $ctx['total']]
        );
        $pager = $g->pager($ctx['baseurl'], $params, 'tpage', (int) $ctx['page'], (int) $ctx['limit'], (int) $ctx['total']);
        return $o . $pager . $this->timeline($page) . $pager;
    }

    /**
     * Table of import jobs.
     *
     * @param \stdClass[] $imports Import records.
     * @param bool $candelete True to add a delete link to every row (import page only).
     * @return string
     */
    public function imports_table(array $imports, bool $candelete = false): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = get_string('ev_imports', 'tool_ltidiagnostics');
        $table->head = [get_string('time'), get_string('logformat', 'tool_ltidiagnostics'),
            get_string('source', 'tool_ltidiagnostics'),
            get_string('logtimezone', 'tool_ltidiagnostics'), get_string('range', 'tool_ltidiagnostics'),
            get_string('lines', 'tool_ltidiagnostics'), get_string('state', 'tool_ltidiagnostics')];
        if ($candelete) {
            $table->head[] = get_string('action', 'tool_ltidiagnostics');
        }
        foreach ($imports as $i) {
            $range = $i->rangefrom ? userdate($i->rangefrom, '%Y-%m-%d %H:%M', 'UTC') . ' - ' . userdate(
                $i->rangeto,
                '%Y-%m-%d %H:%M',
                'UTC'
            ) . ' UTC' : '-';
            $row = [userdate($i->timecreated), s($i->kind . ':' . $i->format), s($i->sourcename), s($i->timezone),
                $range, get_string('linessummary', 'tool_ltidiagnostics', $i), s($i->status . ($i->errormessage !== '' ?
                    ' (' . $i->errormessage . ')' : ''))];
            if ($candelete) {
                $row[] = \html_writer::link(
                    new \moodle_url('/admin/tool/ltidiagnostics/logimport.php', ['delete' => $i->id, 'sesskey' => sesskey()]),
                    get_string('deleteimport', 'tool_ltidiagnostics'),
                    ['aria-label' => get_string('deleteimport_aria', 'tool_ltidiagnostics', $i->id)]
                );
            }
            $table->data[] = $row;
        }
        return \html_writer::table($table);
    }

    /**
     * The history tab (issues #17, #26): what changed between stored snapshots of the function check, in words, with
     * before and after, the changed evidence steps and whether the change needs attention. One snapshot is the
     * baseline; content hashes only in the technical details. Snapshots are per connection and not restricted by course.
     *
     * @param string $key Connection key.
     * @param int $page Page of the snapshot list (0-based).
     * @param array $scopeparams Scope of the page (course, activity, period), kept by the pager links.
     * @return string
     */
    public function history_tab(string $key, int $page = 0, array $scopeparams = []): string {
        global $DB;
        $t = fn(string $id, $a = null): string => get_string($id, 'tool_ltidiagnostics', $a);
        $perpage = 10;
        $total = (int) $DB->count_records('tool_ltidiagnostics_snap', ['connkey' => $key, 'kind' => 'matrix']);
        $page = \tool_ltidiagnostics\output\sections::clamp_page(max(0, $page), $perpage, $total);
        $offset = $page * $perpage;
        $o = \html_writer::tag('p', $t('hist_intro'), ['class' => 'text-muted']);
        /** @var grades_renderer $g */
        $g = $this->page->get_renderer('tool_ltidiagnostics', 'grades');
        // The pager keeps the scope, so the tabs after it keep it too.
        $pager = $g->pager(new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key,
            'tab' => 'history'] + $scopeparams), [], 'hpage', $page, $perpage, $total);
        if ($total === 0) {
            $attrs = ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_hist_none'];
            return $o . \html_writer::tag('p', $t('hist_none'), $attrs);
        }
        // One more than the page so the oldest snapshot of the page can be compared with its predecessor.
        $snaps = array_values($DB->get_records(
            'tool_ltidiagnostics_snap',
            ['connkey' => $key, 'kind' => 'matrix'],
            'timecreated DESC, id DESC',
            'id, timecreated, contenthash',
            $offset,
            $perpage + 1
        ));
        $rank = [\tool_ltidiagnostics\local\status::VERIFIED => 5, \tool_ltidiagnostics\local\status::AUTHORISED => 4,
            \tool_ltidiagnostics\local\status::CONFIGURED => 3, \tool_ltidiagnostics\local\status::SUPPORTED => 2,
            \tool_ltidiagnostics\local\status::NOT_OBSERVABLE => 1, \tool_ltidiagnostics\local\status::UNKNOWN => 1,
            \tool_ltidiagnostics\local\status::NOT_SUPPORTED => 0, \tool_ltidiagnostics\local\status::INCOMPATIBLE => 0,
            \tool_ltidiagnostics\local\status::BLOCKED => 0];
        $fcurl = new \moodle_url('/admin/tool/ltidiagnostics/connection.php', ['key' => $key, 'tab' => 'matrix']);
        $rows = [];
        $shown = array_slice($snaps, 0, $perpage);
        foreach ($shown as $i => $snap) {
            $older = $snaps[$i + 1] ?? null;
            $time = userdate((int) $snap->timecreated);
            if ($older === null) {
                $h = new \html_table_cell($time);
                $h->header = true;
                $h->scope = 'row';
                $row = new \html_table_row([$h, \html_writer::tag('em', $t('hist_baseline')), '-', '-', '-',
                    \html_writer::link($fcurl, $t('hist_open_fc'))]);
                $row->attributes['data-kind'] = 'baseline';
                $rows[] = $row;
                continue;
            }
            if ($snap->contenthash === $older->contenthash) {
                $h = new \html_table_cell($time);
                $h->header = true;
                $h->scope = 'row';
                $row = new \html_table_row([$h, $t('hist_nochange'), '-', '-', '-', '-']);
                $row->attributes['data-kind'] = 'unchanged';
                $rows[] = $row;
                continue;
            }
            $new = json_decode((string) $DB->get_field('tool_ltidiagnostics_snap', 'snapjson', ['id' => $snap->id]), true) ?: [];
            $old = json_decode((string) $DB->get_field('tool_ltidiagnostics_snap', 'snapjson', ['id' => $older->id]), true) ?: [];
            foreach (\tool_ltidiagnostics\local\snapshot::compare($old, $new) as $d) {
                $from = $d['from'] ?: 'unknown';
                $relevance = ($rank[$d['to']] ?? 1) < ($rank[$from] ?? 1) ? 'worse'
                    : (($rank[$d['to']] ?? 1) > ($rank[$from] ?? 1) ? 'better' : 'steps');
                $h = new \html_table_cell($time);
                $h->header = true;
                $h->scope = 'row';
                $row = new \html_table_row([$h, get_string('feature_' . $d['feature'], 'tool_ltidiagnostics'),
                    $t('hist_change', (object) ['from' => get_string('status_' . $from, 'tool_ltidiagnostics'),
                        'to' => get_string('status_' . $d['to'], 'tool_ltidiagnostics')]),
                    $d['changedsteps'] ? implode(', ', array_map(
                        fn($step) => get_string('step_' . $step, 'tool_ltidiagnostics'),
                        $d['changedsteps']
                    )) : '-',
                    \html_writer::span($t('hist_rel_' . $relevance), 'badge ' . self::badge_class($relevance === 'worse' ? 'red'
                        : ($relevance === 'better' ? 'green' : 'grey'))),
                    \html_writer::link(new \moodle_url($fcurl), $t('hist_open_fc'))]);
                $row->attributes['data-kind'] = 'change';
                $row->attributes['data-feature'] = $d['feature'];
                $row->attributes['data-relevance'] = $relevance;
                $rows[] = $row;
            }
        }
        $table = new \html_table();
        $table->id = 'tool_ltidiagnostics_history';
        $table->attributes['class'] = 'generaltable table-sm';
        $table->caption = $t('hist_caption');
        $table->head = [$t('time'), $t('feature'), $t('hist_beforeafter'), $t('hist_steps'), $t('hist_relevance'),
            $t('hist_evidence')];
        $table->data = $rows;
        $o .= $pager . \html_writer::div(\html_writer::table($table), 'table-responsive') . $pager;
        if ($total === 1) {
            $attrs = ['class' => 'alert alert-info', 'id' => 'tool_ltidiagnostics_hist_baseline'];
            $o .= \html_writer::tag('p', $t('hist_onlybaseline'), $attrs);
        }
        $hashes = [];
        foreach ($shown as $snap) {
            $hashes[] = userdate((int) $snap->timecreated) . ': ' . s($snap->contenthash);
        }
        $o .= \html_writer::tag('details', \html_writer::tag('summary', $t('hist_technical')) .
            \html_writer::alist($hashes, ['class' => 'small text-muted']), ['id' => 'tool_ltidiagnostics_hist_hashes']);
        return $o;
    }
}
