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
            if (!$rows) {
                continue;
            }
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
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_aiquizremedial')], (object) ['modules' => $data]);
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
        if ($userids !== null) {
            if (!$userids) {
                return;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $usersql = " AND userid $insql";
            $params += $inparams;
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
