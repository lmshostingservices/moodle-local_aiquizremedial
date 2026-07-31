<?php
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
