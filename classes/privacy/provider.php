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

namespace tool_ltidiagnostics\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider of tool_ltidiagnostics.
 *
 * Personal data: observations (a user id per recorded LTI event), the audit log of who viewed or exported
 * diagnostics, snapshot and import authorship, and the author of desired-state profiles.
 * Deleting a user removes their observations and audit entries and unlinks authorship from the rest.
 * Grade channel (0.4.0): the grade attempt journal holds the learner (user id, score, subject hash) and the operator of a
 * replay; partner receipts hold the importer. A replay sends the learner's LTI subject and score to the consumer (external
 * location). Deleting a learner removes their journal rows and receipts; deleting an operator detaches them.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the stored data.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tool_ltidiagnostics_obs', [
            'userid' => 'privacy:metadata:obs:userid',
            'eventtype' => 'privacy:metadata:obs:eventtype',
            'eventname' => 'privacy:metadata:obs:eventname',
            'courseid' => 'privacy:metadata:obs:courseid',
            'cmid' => 'privacy:metadata:obs:cmid',
            'entrypoint' => 'privacy:metadata:obs:entrypoint',
            'timecreated' => 'privacy:metadata:obs:timecreated',
        ], 'privacy:metadata:obs');
        $collection->add_database_table('tool_ltidiagnostics_audit', [
            'userid' => 'privacy:metadata:audit:userid',
            'action' => 'privacy:metadata:audit:action',
            'connkey' => 'privacy:metadata:audit:connkey',
            'timecreated' => 'privacy:metadata:audit:timecreated',
        ], 'privacy:metadata:audit');
        $collection->add_database_table('tool_ltidiagnostics_snap', [
            'userid' => 'privacy:metadata:snap:userid',
            'timecreated' => 'privacy:metadata:snap:timecreated',
        ], 'privacy:metadata:snap');
        $collection->add_database_table('tool_ltidiagnostics_imp', [
            'userid' => 'privacy:metadata:imp:userid',
            'timecreated' => 'privacy:metadata:imp:timecreated',
        ], 'privacy:metadata:imp');
        $collection->add_database_table('tool_ltidiagnostics_profile', [
            'usermodified' => 'privacy:metadata:profile:usermodified',
        ], 'privacy:metadata:profile');
        $collection->add_database_table('tool_ltidiagnostics_acc', [
            'ipmask' => 'privacy:metadata:acc:ipmask',
            'path' => 'privacy:metadata:acc:path',
            'logtime' => 'privacy:metadata:acc:logtime',
        ], 'privacy:metadata:acc');
        $collection->add_database_table('tool_ltidiagnostics_gatt', [
            'userid' => 'privacy:metadata:gatt:userid',
            'subjecthash' => 'privacy:metadata:gatt:subjecthash',
            'scoregiven' => 'privacy:metadata:gatt:scoregiven',
            'scoremax' => 'privacy:metadata:gatt:scoremax',
            'state' => 'privacy:metadata:gatt:state',
            'operatorid' => 'privacy:metadata:gatt:operatorid',
            'purpose' => 'privacy:metadata:gatt:purpose',
            'timecreated' => 'privacy:metadata:gatt:timecreated',
        ], 'privacy:metadata:gatt');
        $collection->add_database_table('tool_ltidiagnostics_gstate', [
            'targethash' => 'privacy:metadata:gstate:targethash',
            'lastissued' => 'privacy:metadata:gstate:lastissued',
        ], 'privacy:metadata:gstate');
        $collection->add_database_table('tool_ltidiagnostics_grcpt', [
            'userid' => 'privacy:metadata:grcpt:userid',
            'scoregiven' => 'privacy:metadata:grcpt:scoregiven',
            'timecreated' => 'privacy:metadata:grcpt:timecreated',
        ], 'privacy:metadata:grcpt');
        $collection->add_external_location_link('lti_ags_consumer', [
            'userid' => 'privacy:metadata:agsconsumer:userid',
            'scoregiven' => 'privacy:metadata:agsconsumer:scoregiven',
            'scoremaximum' => 'privacy:metadata:agsconsumer:scoremaximum',
            'timestamp' => 'privacy:metadata:agsconsumer:timestamp',
        ], 'privacy:metadata:agsconsumer');
        return $collection;
    }

    /**
     * Contexts holding data of a user.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $system = \context_system::instance()->id;
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.id = :systemid
                   AND (EXISTS (SELECT 1 FROM {tool_ltidiagnostics_audit} a WHERE a.userid = :userid2)
                     OR EXISTS (SELECT 1 FROM {tool_ltidiagnostics_snap} s WHERE s.userid = :userid3)
                     OR EXISTS (SELECT 1 FROM {tool_ltidiagnostics_imp} i WHERE i.userid = :userid4)
                     OR EXISTS (SELECT 1 FROM {tool_ltidiagnostics_profile} p WHERE p.usermodified = :userid5)
                     OR EXISTS (SELECT 1 FROM {tool_ltidiagnostics_gatt} g WHERE g.userid = :userid8 OR g.operatorid = :userid9)
                     OR EXISTS (SELECT 1 FROM {tool_ltidiagnostics_grcpt} r WHERE r.userid = :userid10))
                UNION
                SELECT ctx.id
                  FROM {context} ctx
                  JOIN {tool_ltidiagnostics_obs} ob ON ob.contextid = ctx.id
                 WHERE ob.userid = :userid7";
        $contextlist->add_from_sql($sql, ['systemid' => $system,
            'userid2' => $userid, 'userid3' => $userid, 'userid4' => $userid, 'userid5' => $userid,
            'userid7' => $userid, 'userid8' => $userid, 'userid9' => $userid, 'userid10' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {tool_ltidiagnostics_obs} WHERE contextid = :cid',
            ['cid' => $context->id]
        );
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_ltidiagnostics_audit}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_ltidiagnostics_snap} WHERE userid <> 0', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_ltidiagnostics_imp} WHERE userid <> 0', []);
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {tool_ltidiagnostics_profile} WHERE usermodified <> 0',
                []
            );
            $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_ltidiagnostics_gatt} WHERE userid <> 0', []);
            $userlist->add_from_sql(
                'operatorid',
                'SELECT operatorid FROM {tool_ltidiagnostics_gatt} WHERE operatorid <> 0',
                []
            );
            $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_ltidiagnostics_grcpt} WHERE userid <> 0', []);
        }
    }

    /**
     * Export the data of a user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $subcontext = [get_string('pluginname', 'tool_ltidiagnostics')];
            $rows = $DB->get_records('tool_ltidiagnostics_obs', ['userid' => $userid, 'contextid' => $context->id], 'timecreated');
            if ($rows) {
                writer::with_context($context)->export_data(array_merge($subcontext, ['observations']), (object) [
                    'observations' => array_values(array_map(function ($r) {
                        return ['event' => $r->eventname, 'type' => $r->eventtype, 'course' => $r->courseid, 'cm' => $r->cmid,
                            'entrypoint' => $r->entrypoint, 'time' => transform::datetime($r->timecreated)];
                    }, $rows)),
                ]);
            }
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            $audit = $DB->get_records('tool_ltidiagnostics_audit', ['userid' => $userid], 'timecreated');
            if ($audit) {
                writer::with_context($context)->export_data(array_merge($subcontext, ['audit']), (object) [
                    'audit' => array_values(array_map(function ($r) {
                        return ['action' => $r->action, 'connection' => $r->connkey, 'detail' => $r->detail,
                            'time' => transform::datetime($r->timecreated)];
                    }, $audit)),
                ]);
            }
            $authored = [
                'snapshots' => $DB->count_records('tool_ltidiagnostics_snap', ['userid' => $userid]),
                'imports' => $DB->count_records('tool_ltidiagnostics_imp', ['userid' => $userid]),
                'profiles' => $DB->count_records('tool_ltidiagnostics_profile', ['usermodified' => $userid]),
            ];
            $grades = $DB->get_records('tool_ltidiagnostics_gatt', ['userid' => $userid], 'timecreated', 'id, attemptid,
                origin, state, scoregiven, scoremax, httpstatus, errorclass, bookingstate, agstimestamp, timecreated');
            if ($grades) {
                writer::with_context($context)->export_data(array_merge($subcontext, ['gradeattempts']), (object) [
                    'gradeattempts' => array_values(array_map(function ($r) {
                        return ['attempt' => $r->attemptid, 'origin' => $r->origin, 'state' => $r->state,
                            'score' => $r->scoregiven, 'maximum' => $r->scoremax, 'httpstatus' => $r->httpstatus,
                            'errorclass' => $r->errorclass, 'booking' => $r->bookingstate,
                            'agstimestamp' => $r->agstimestamp ? transform::datetime($r->agstimestamp) : '',
                            'time' => transform::datetime($r->timecreated)];
                    }, $grades)),
                ]);
            }
            $operated = $DB->get_records(
                'tool_ltidiagnostics_gatt',
                ['operatorid' => $userid],
                'timecreated',
                'id, attemptid, connkey, purpose, state, timecreated'
            );
            if ($operated) {
                writer::with_context($context)->export_data(array_merge($subcontext, ['gradereplays']), (object) [
                    'gradereplays' => array_values(array_map(function ($r) {
                        return ['attempt' => $r->attemptid, 'connection' => $r->connkey, 'purpose' => $r->purpose,
                            'state' => $r->state, 'time' => transform::datetime($r->timecreated)];
                    }, $operated)),
                ]);
            }
            $authored['gradereceipts'] = $DB->count_records('tool_ltidiagnostics_grcpt', ['userid' => $userid]);
            if (array_sum($authored)) {
                writer::with_context($context)->export_data(array_merge($subcontext, ['authorship']), (object) $authored);
            }
        }
    }

    /**
     * Delete the data of all users in a context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        $DB->delete_records('tool_ltidiagnostics_obs', ['contextid' => $context->id]);
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records('tool_ltidiagnostics_audit');
            $DB->set_field('tool_ltidiagnostics_snap', 'userid', 0);
            $DB->set_field('tool_ltidiagnostics_imp', 'userid', 0);
            $DB->set_field('tool_ltidiagnostics_profile', 'usermodified', 0);
            $DB->delete_records('tool_ltidiagnostics_gatt');
            $DB->delete_records('tool_ltidiagnostics_gstate');
            $DB->delete_records('tool_ltidiagnostics_grcpt');
        }
    }

    /**
     * Delete the data of one user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $DB->delete_records('tool_ltidiagnostics_obs', ['userid' => $userid, 'contextid' => $context->id]);
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                self::unlink_user($userid);
            }
        }
    }

    /**
     * Delete the data of several users in a context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            'tool_ltidiagnostics_obs',
            "contextid = :cid AND userid $insql",
            $params + ['cid' => $context->id]
        );
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            foreach ($userlist->get_userids() as $userid) {
                self::unlink_user($userid);
            }
        }
    }

    /**
     * Remove the audit entries of a user and detach the authorship of everything else.
     *
     * @param int $userid User id.
     */
    private static function unlink_user(int $userid): void {
        global $DB;
        $DB->delete_records('tool_ltidiagnostics_audit', ['userid' => $userid]);
        $DB->set_field('tool_ltidiagnostics_snap', 'userid', 0, ['userid' => $userid]);
        $DB->set_field('tool_ltidiagnostics_imp', 'userid', 0, ['userid' => $userid]);
        $DB->set_field('tool_ltidiagnostics_profile', 'usermodified', 0, ['usermodified' => $userid]);
        // Learner: the attempts about them and the receipts of those attempts go. Operator: detached, reason removed.
        $attempts = $DB->get_fieldset_select('tool_ltidiagnostics_gatt', 'attemptid', 'userid = :u', ['u' => $userid]);
        foreach (array_chunk($attempts, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->delete_records_select('tool_ltidiagnostics_grcpt', "attemptid $insql", $params);
        }
        $DB->delete_records('tool_ltidiagnostics_gatt', ['userid' => $userid]);
        $DB->execute(
            "UPDATE {tool_ltidiagnostics_gatt} SET operatorid = 0, purpose = :p WHERE operatorid = :u",
            ['p' => '', 'u' => $userid]
        );
        $DB->set_field('tool_ltidiagnostics_grcpt', 'userid', 0, ['userid' => $userid]);
    }
}
