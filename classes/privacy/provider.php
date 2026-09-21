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

namespace local_aiquizremedial\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider (v1.3.0 — previously declared a null provider although the plugin
 * stores per-learner jobs and completion records).
 *
 * Data is stored against the course context of the quiz that produced it.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aiqr_job', [
            'userid' => 'privacy:metadata:local_aiqr_job:userid',
            'attemptid' => 'privacy:metadata:local_aiqr_job:attemptid',
            'timecreated' => 'privacy:metadata:local_aiqr_job:timecreated',
        ], 'privacy:metadata:local_aiqr_job');
        $collection->add_database_table('local_aiqr_completion', [
            'userid' => 'privacy:metadata:local_aiqr_completion:userid',
            'state' => 'privacy:metadata:local_aiqr_completion:state',
            'attempts_count' => 'privacy:metadata:local_aiqr_completion:attempts_count',
            'completed_at' => 'privacy:metadata:local_aiqr_completion:completed_at',
        ], 'privacy:metadata:local_aiqr_completion');
        // Version 1.5.0: quiz insights.
        $collection->add_database_table('local_aiqr_resp', [
            'userid' => 'privacy:metadata:local_aiqr_resp:userid',
            'attemptid' => 'privacy:metadata:local_aiqr_resp:attemptid',
            'fraction' => 'privacy:metadata:local_aiqr_resp:fraction',
            'answerlabel' => 'privacy:metadata:local_aiqr_resp:answerlabel',
            'timefinished' => 'privacy:metadata:local_aiqr_resp:timefinished',
        ], 'privacy:metadata:local_aiqr_resp');
        $collection->add_database_table('local_aiqr_action', [
            'assigneeid' => 'privacy:metadata:local_aiqr_action:assigneeid',
        ], 'privacy:metadata:local_aiqr_action');
        $collection->add_database_table('local_aiqr_action_log', [
            'userid' => 'privacy:metadata:local_aiqr_action_log:userid',
            'comment' => 'privacy:metadata:local_aiqr_action_log:comment',
            'timecreated' => 'privacy:metadata:local_aiqr_action_log:timecreated',
        ], 'privacy:metadata:local_aiqr_action_log');
        $collection->add_external_location_link('aiservice', [
            'userid' => 'privacy:metadata:aiservice:userid',
            'answer' => 'privacy:metadata:aiservice:answer',
        ], 'privacy:metadata:aiservice');
        return $collection;
    }

    /**
     * Contexts holding data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql("SELECT ctx.id
                               FROM {context} ctx
                               JOIN {local_aiqr_job} j ON j.courseid = ctx.instanceid AND ctx.contextlevel = :cl
                              WHERE j.userid = :userid",
            ['cl' => CONTEXT_COURSE, 'userid' => $userid]);
        $list->add_from_sql("SELECT ctx.id
                               FROM {context} ctx
                               JOIN {local_aiqr_resp} r ON r.courseid = ctx.instanceid AND ctx.contextlevel = :cl
                              WHERE r.userid = :userid",
            ['cl' => CONTEXT_COURSE, 'userid' => $userid]);
        $list->add_from_sql("SELECT ctx.id
                               FROM {context} ctx
                               JOIN {local_aiqr_action} a ON a.courseid = ctx.instanceid AND ctx.contextlevel = :cl
                              WHERE a.assigneeid = :userid
                                 OR EXISTS (SELECT 1 FROM {local_aiqr_action_log} l
                                             WHERE l.actionid = a.id AND l.userid = :userid2)",
            ['cl' => CONTEXT_COURSE, 'userid' => $userid, 'userid2' => $userid]);
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $userlist->add_from_sql(
            'userid', "SELECT userid FROM {local_aiqr_job} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]);
        $userlist->add_from_sql(
            'userid', "SELECT userid FROM {local_aiqr_resp} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]);
        $userlist->add_from_sql(
            'assigneeid', "SELECT assigneeid FROM {local_aiqr_action} WHERE courseid = :courseid AND assigneeid > 0",
            ['courseid' => $context->instanceid]);
        $userlist->add_from_sql(
            'userid', "SELECT l.userid FROM {local_aiqr_action_log} l JOIN {local_aiqr_action} a ON a.id = l.actionid
                        WHERE a.courseid = :courseid AND l.userid > 0",
            ['courseid' => $context->instanceid]);
    }

    /**
     * Export a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $rows = $DB->get_records_sql(
                "SELECT j.id, j.quizid, j.attemptid, j.questionid, j.status, j.timecreated,
                                                 m.explain_text, c.state, c.attempts_count, c.completed_at
                                            FROM {local_aiqr_job} j
                                       LEFT JOIN {local_aiqr_module} m ON m.jobid = j.id
                                       LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = j.userid
                                           WHERE j.userid = :userid AND j.courseid = :courseid AND j.questionid IS NOT NULL",
                ['userid' => $userid, 'courseid' => $context->instanceid]);
            $data = [];
            foreach ($rows as $r) {
                $data[] = (object) [
                    'quizid' => $r->quizid,
                    'attemptid' => $r->attemptid,
                    'questionid' => $r->questionid,
                    'status' => $r->status,
                    'created' => transform::datetime($r->timecreated),
                    'explanation' => $r->explain_text,
                    'completionstate' => $r->state,
                    'quickcheckattempts' => $r->attempts_count,
                    'completed' => $r->completed_at ? transform::datetime($r->completed_at) : null,
                ];
            }
            if ($data) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aiquizremedial')], (object) ['modules' => $data]);
            }

            // Version 1.5.0: responses used by quiz insights.
            $resp = $DB->get_records('local_aiqr_resp', ['userid' => $userid, 'courseid' => $context->instanceid],
                'timefinished ASC, slot ASC', 'id, sourcetype, activityid, attemptid, attemptno, slot, fraction, iscorrect,
                omitted, answerlabel, timefinished');
            if ($resp) {
                $out = [];
                foreach ($resp as $r) {
                    $out[] = (object) ['source' => $r->sourcetype, 'activityid' => $r->activityid, 'attemptid' => $r->attemptid,
                        'attempt' => $r->attemptno, 'question' => $r->slot, 'mark' => $r->fraction,
                        'correct' => transform::yesno((bool) $r->iscorrect), 'blank' => transform::yesno((bool) $r->omitted),
                        'answer' => $r->answerlabel, 'finished' => transform::datetime($r->timefinished)];
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aiquizremedial'), get_string('privacy:responses', 'local_aiquizremedial')],
                    (object) ['responses' => $out]);
            }
            $logs = $DB->get_records_sql(
                "SELECT l.id, l.actionid, a.ruleid, l.fromstatus, l.tostatus, l.comment, l.timecreated
                   FROM {local_aiqr_action_log} l
                   JOIN {local_aiqr_action} a ON a.id = l.actionid
                  WHERE l.userid = :userid AND a.courseid = :courseid
               ORDER BY l.id", ['userid' => $userid, 'courseid' => $context->instanceid]);
            $assigned = $DB->get_records('local_aiqr_action', ['assigneeid' => $userid, 'courseid' => $context->instanceid],
                'id', 'id, ruleid, status, timecreated');
            if ($logs || $assigned) {
                $out = ['changes' => [], 'assigned' => []];
                foreach ($logs as $l) {
                    $out['changes'][] = (object) ['action' => $l->actionid, 'rule' => $l->ruleid, 'from' => $l->fromstatus,
                        'to' => $l->tostatus, 'comment' => $l->comment, 'time' => transform::datetime($l->timecreated)];
                }
                foreach ($assigned as $a) {
                    $out['assigned'][] = (object) ['action' => $a->id, 'rule' => $a->ruleid, 'status' => $a->status,
                        'created' => transform::datetime($a->timecreated)];
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aiquizremedial'), get_string('privacy:actionlog', 'local_aiquizremedial')],
                    (object) $out);
            }
        }
    }

    /**
     * Delete everything for a set of users in one course.
     *
     * @param int $courseid
     * @param int[]|null $userids null = all users
     */
    protected static function delete_for(int $courseid, ?array $userids): void {
        global $DB;
        $params = ['courseid' => $courseid];
        $usersql = '';
        $inparams = [];
        if ($userids !== null) {
            if (!$userids) {
                return;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $usersql = " AND userid $insql";
            $params += $inparams;
        }
        // Version 1.5.0: insights responses are deleted; the action audit trail is kept but anonymised.
        $DB->delete_records_select('local_aiqr_resp', "courseid = :courseid $usersql", $params);
        $actionids = $DB->get_fieldset_select('local_aiqr_action', 'id', 'courseid = :courseid', ['courseid' => $courseid]);
        foreach (array_chunk($actionids, 500) as $chunk) {
            [$asql, $aparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'act');
            $DB->execute("UPDATE {local_aiqr_action_log} SET userid = 0, comment = NULL
                           WHERE actionid $asql AND userid > 0" . $usersql, $aparams + $inparams);
            $DB->execute("UPDATE {local_aiqr_action} SET assigneeid = 0
                           WHERE id $asql AND assigneeid > 0" . str_replace('userid', 'assigneeid', $usersql),
                $aparams + $inparams);
        }
        $jobids = $DB->get_fieldset_select('local_aiqr_job', 'id', "courseid = :courseid $usersql", $params);
        if (!$jobids) {
            return;
        }
        foreach (array_chunk($jobids, 500) as $chunk) {
            [$jsql, $jparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $moduleids = $DB->get_fieldset_select('local_aiqr_module', 'id', "jobid $jsql", $jparams);
            if ($moduleids) {
                [$msql, $mparams] = $DB->get_in_or_equal($moduleids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('local_aiqr_completion', "moduleid $msql", $mparams);
                $DB->delete_records_select('local_aiqr_module', "id $msql", $mparams);
            }
            $DB->delete_records_select('local_aiqr_job', "id $jsql", $jparams);
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context->contextlevel == CONTEXT_COURSE) {
            self::delete_for((int) $context->instanceid, null);
        }
    }

    /**
     * Delete one user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                self::delete_for((int) $context->instanceid, [$userid]);
            }
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            self::delete_for((int) $context->instanceid, array_map('intval', $userlist->get_userids()));
        }
    }
}
