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

/**
 * local_aiquizremedial file.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_aiquizremedial;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB;

        if (!get_config('local_aiquizremedial', 'enabled')) {
            return;
        }

        $attemptid = (int) $event->objectid;
        $userid    = (int) ($event->relateduserid ?? $event->userid);
        $courseid  = (int) $event->courseid;
        $quizid    = isset($event->other['quizid']) ? (int) $event->other['quizid'] : null;

        // Check for an existing quiz umbrella job for this attempt.
        // sourcetype='quiz' is included so that KC umbrella jobs (which also have
        // questionid=null) with the same attemptid integer do not suppress this record —
        // quiz_attempts and aiknowledgecheck_attempts are separate tables with independent
        // auto-increment sequences, so their IDs regularly collide.
        if ($DB->record_exists('local_aiqr_job', [
            'attemptid'  => $attemptid,
            'questionid' => null,
            'sourcetype' => 'quiz',
        ])) {
            return;
        }

        $record = (object) [
            'userid'       => $userid,
            'courseid'     => $courseid,
            'quizid'       => $quizid,
            'kcid'         => null,
            'attemptid'    => $attemptid,
            'questionid'   => null,
            'sourcetype'   => 'quiz',
            'status'       => 'pending',
            'errormsg'     => null,
            'timecreated'  => time(),
            'timemodified' => time(),
        ];

        $DB->insert_record('local_aiqr_job', $record);
    }
}
