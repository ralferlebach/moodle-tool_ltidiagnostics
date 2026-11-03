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
 * Audit of views and exports of diagnostic data.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit {
    /**
     * Write an audit entry for the current user.
     *
     * @param string $action Short action name (letters, digits, underscore).
     * @param string $connkey Connection key or empty.
     * @param string $detail Short machine readable detail. Never a secret.
     * @param int|null $userid User id (default: current user).
     * @return int Record id.
     */
    public static function log(string $action, string $connkey = '', string $detail = '', ?int $userid = null): int {
        global $DB, $USER;
        return (int) $DB->insert_record('tool_ltidiagnostics_audit', (object) [
            'userid' => $userid ?? (int) $USER->id,
            'action' => \core_text::substr(clean_param($action, PARAM_ALPHANUMEXT), 0, 40),
            'connkey' => connection::parse_key($connkey) !== null ? $connkey : '',
            'detail' => \core_text::substr(redactor::text($detail), 0, 255),
            'timecreated' => time(),
        ]);
    }

    /** @var array Action groups of the audit filter: group => [exact codes, code prefixes]. */
    public const GROUPS = [
        'view' => [['viewconnection', 'viewunmasked'], ['view_']],
        'export' => [['export', 'export_support', 'partner_export', 'trace_export', 'transfers_export'], []],
        'import' => [['logimport', 'deleteimport', 'partner_import', 'trace_import'], []],
        'grades' => [[], ['graderepair_', 'graderesult_', 'gradereceipt_']],
        'probes' => [['runchecks', 'runchecks_private', 'partner_live', 'partner_live_private'], []],
        'config' => [['modeenable', 'modedisable', 'profilesave'], []],
    ];

    /** @var string[] Prefixes whose suffix is shown as parameter of a generic label. */
    private const PREFIXES = ['view_', 'graderepair_', 'graderesult_', 'gradereceipt_'];

    /**
     * Recent audit entries.
     *
     * @param int $limit Maximum number (max 500).
     * @return \stdClass[] Newest first.
     */
    public static function recent(int $limit = 100): array {
        return self::page([], 0, $limit)['rows'];
    }

    /**
     * One page of the audit, filtered on the server; total and rows come from the same WHERE clause.
     *
     * @param array $filters userid (int), usersearch (name/email), group (key of GROUPS), connkey, from, to (UTC).
     * @param int $offset Offset.
     * @param int $limit Page size (1..500).
     * @return array rows (\stdClass[] with user name fields), total
     */
    public static function page(array $filters, int $offset, int $limit): array {
        global $DB;
        [$where, $params] = self::where($filters);
        $names = \core_user\fields::for_name()->get_sql('u', false, '', '', true);
        $total = (int) $DB->count_records_sql("SELECT COUNT(1)
                                                 FROM {tool_ltidiagnostics_audit} a
                                            LEFT JOIN {user} u ON u.id = a.userid
                                                WHERE $where", $params);
        $rows = $DB->get_records_sql("SELECT a.*{$names->selects}
                                        FROM {tool_ltidiagnostics_audit} a
                                   LEFT JOIN {user} u ON u.id = a.userid
                                       WHERE $where
                                    ORDER BY a.timecreated DESC, a.id DESC", $params, max(0, $offset), max(1, min(500, $limit)));
        return ['rows' => array_values($rows), 'total' => $total];
    }

    /**
     * WHERE clause of the audit filters.
     *
     * @param array $filters Filters (see page()).
     * @return array [sql, params]
     */
    private static function where(array $filters): array {
        global $DB;
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['userid'])) {
            $where[] = 'a.userid = :userid';
            $params['userid'] = (int) $filters['userid'];
        }
        $search = trim((string) ($filters['usersearch'] ?? ''));
        if ($search !== '') {
            $like = [];
            foreach (['firstname', 'lastname', 'email'] as $i => $field) {
                $like[] = $DB->sql_like('u.' . $field, ':us' . $i, false, false);
                $params['us' . $i] = '%' . $DB->sql_like_escape(\core_text::substr($search, 0, 100)) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
        $group = (string) ($filters['group'] ?? '');
        if (isset(self::GROUPS[$group])) {
            [$codes, $prefixes] = self::GROUPS[$group];
            $or = [];
            if ($codes) {
                [$in, $inparams] = $DB->get_in_or_equal($codes, SQL_PARAMS_NAMED, 'ac');
                $or[] = 'a.action ' . $in;
                $params += $inparams;
            }
            foreach ($prefixes as $i => $prefix) {
                $or[] = $DB->sql_like('a.action', ':ap' . $i);
                $params['ap' . $i] = $DB->sql_like_escape($prefix) . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if (!empty($filters['connkey'])) {
            $where[] = 'a.connkey = :connkey';
            $params['connkey'] = (string) $filters['connkey'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'a.timecreated >= :tfrom';
            $params['tfrom'] = (int) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'a.timecreated <= :tto';
            $params['tto'] = (int) $filters['to'];
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Localised name of an audit action; the machine code stays visible next to it for unknown codes.
     *
     * @param string $action Action code.
     * @return string Plain text.
     */
    public static function action_label(string $action): string {
        $sm = get_string_manager();
        if ($sm->string_exists('auditaction_' . $action, 'tool_ltidiagnostics')) {
            return get_string('auditaction_' . $action, 'tool_ltidiagnostics');
        }
        foreach (self::PREFIXES as $prefix) {
            if (strpos($action, $prefix) === 0) {
                $suffix = substr($action, strlen($prefix));
                return get_string('auditaction_' . $prefix, 'tool_ltidiagnostics', self::suffix_label($prefix, $suffix));
            }
        }
        return get_string('auditaction_unknown', 'tool_ltidiagnostics', $action);
    }

    /**
     * Readable name of the variable part of an action code (a tab name or a verdict).
     *
     * @param string $prefix Prefix.
     * @param string $suffix Suffix.
     * @return string
     */
    private static function suffix_label(string $prefix, string $suffix): string {
        $sm = get_string_manager();
        if ($prefix === 'view_' && $sm->string_exists('tab_' . $suffix, 'tool_ltidiagnostics')) {
            return get_string('tab_' . $suffix, 'tool_ltidiagnostics');
        }
        if ($prefix === 'graderepair_' && $sm->string_exists('gstate_' . $suffix, 'tool_ltidiagnostics')) {
            return get_string('gstate_' . $suffix, 'tool_ltidiagnostics');
        }
        if ($prefix !== 'view_' && $sm->string_exists('gbook_' . $suffix, 'tool_ltidiagnostics')) {
            return get_string('gbook_' . $suffix, 'tool_ltidiagnostics');
        }
        if ($sm->string_exists('auditsuffix_' . $suffix, 'tool_ltidiagnostics')) {
            return get_string('auditsuffix_' . $suffix, 'tool_ltidiagnostics');
        }
        return $suffix;
    }
}
