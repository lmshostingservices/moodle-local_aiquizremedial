<?php
/**
 * local_aiquizremedial file.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

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

namespace local_aiquizremedial\hook;

use core\hook\output\before_footer_html_generation;

defined('MOODLE_INTERNAL') || die();

class before_footer {
    public static function callback(before_footer_html_generation $hook): void {
        global $PAGE, $USER, $DB, $CFG;

        if (!get_config('local_aiquizremedial', 'enabled')) {
            return;
        }

        // FIX-RL-TEACHER-FOOTER (v1.2.34): Teachers on the quiz overview/attempts page get a
        // "View Remedial Learnings" button so they can see all remedial modules generated for
        // students in that quiz. Tester request: when viewing student attempts, teacher should
        // have a button to access the remedial learning records for the quiz.
        $teacherpagetypes = ['mod-quiz-report', 'mod-quiz-view'];
        if (in_array($PAGE->pagetype, $teacherpagetypes) && isloggedin() && !isguestuser()) {
            $cmid = $PAGE->context->instanceid ?? 0;
            if ($cmid > 0) {
                try {
                    $cm = get_coursemodule_from_id('quiz', $cmid);
                    if ($cm) {
                        $quizid   = (int) $cm->instance;
                        $courseid = (int) $cm->course;
                        $context  = \context_module::instance($cmid);
                        if (has_capability('local/aiquizremedial:viewall', $context)
                            || has_capability('local/aiquizremedial:viewall', \context_course::instance($courseid))) {
                            // Count how many remedial modules exist for this quiz.
                            $count = $DB->count_records_sql(
                                "SELECT COUNT(m.id)
                                   FROM {local_aiqr_module} m
                                   JOIN {local_aiqr_job} j ON j.id = m.jobid
                                  WHERE j.quizid = :quizid AND j.status = 'ready'",
                                ['quizid' => $quizid]
                            );
                            $urlparams = ['quizid' => $quizid];
                            if ($courseid > 0) {
                                $urlparams['courseid'] = $courseid;
                            }
                            $rlurl    = new \moodle_url('/local/aiquizremedial/index.php', $urlparams);
                            $badge    = $count > 0 ? ' <span style="background:#6366f1;color:#fff;border-radius:10px;padding:2px 8px;font-size:12px;font-weight:700;margin-left:6px;">' . $count . '</span>' : '';
                            $btnhtml  = '<div id="aiqr-teacher-quiz-btn" style="margin:12px 0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
                                . '<a href="' . $rlurl->out(false) . '" style="display:inline-flex;align-items:center;gap:7px;background:#6366f1;color:#fff;border-radius:8px;padding:10px 18px;font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap;">'
                                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>'
                                . 'View Remedial Learnings' . $badge
                                . '</a>'
                                . ($count === 0 ? '<span style="font-size:13px;color:#6b7280;">No remedial modules generated for this quiz yet.</span>' : '')
                                . '</div>';
                            $btnjs = '<script>
(function (){
    var btn = document.getElementById("aiqr-teacher-quiz-btn");
    if (!btn) return;
    // Insert after the quiz header / attempts table header if present, else top of main region.
    var target = document.querySelector(".gradereport-user-view-page, .quizattemptcounts, .generaltable, #region-main .card-body, #region-main, [role=main]");
    if (target) {
        target.parentNode.insertBefore(btn, target);
    }
})();
</script>';
                            $hook->add_html($btnhtml . $btnjs);
                        }
                    }
                } catch (\Exception $e) {
                    // Silent — never break page rendering.
                }
            }
            return; // Do not continue to student banner logic for teacher pages.
        }

        if (!get_config('local_aiquizremedial', 'showonquizreview')) {
            return;
        }

        if ($PAGE->pagetype !== 'mod-quiz-review') {
            return;
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        $cmid = $PAGE->context->instanceid ?? 0;
        if ($cmid <= 0) {
            return;
        }

        try {
            $cm = get_coursemodule_from_id('quiz', $cmid);
            if (!$cm) {
                return;
            }
            $quizid = (int) $cm->instance;
            $courseid = (int) $cm->course;
        } catch (\Exception $e) {
            return;
        }

        // Get the current attempt ID from the review page URL so we only count
        // modules from this specific attempt, not all previous attempts.
        $attemptid = optional_param('attempt', 0, PARAM_INT);

        if ($attemptid > 0) {
            $modulecount = $DB->count_records_sql(
                "SELECT COUNT(m.id)
                   FROM {local_aiqr_module} m
                   JOIN {local_aiqr_job} j ON j.id = m.jobid
                  WHERE j.userid = :userid AND j.quizid = :quizid AND j.status = 'ready'
                    AND j.attemptid = :attemptid",
                ['userid' => $USER->id, 'quizid' => $quizid, 'attemptid' => $attemptid]
            );
        } else {
            $modulecount = $DB->count_records_sql(
                "SELECT COUNT(m.id)
                   FROM {local_aiqr_module} m
                   JOIN {local_aiqr_job} j ON j.id = m.jobid
                  WHERE j.userid = :userid AND j.quizid = :quizid AND j.status = 'ready'",
                ['userid' => $USER->id, 'quizid' => $quizid]
            );
        }

        if ($modulecount <= 0) {
            return;
        }

        // $pendingcount: modules not yet completed (used to decide pending vs all-complete banner).
        // Counts only modules from 'ready' child jobs — this guards the "all complete" state.
        if ($attemptid > 0) {
            $pendingcount = $DB->count_records_sql(
                "SELECT COUNT(m.id)
                   FROM {local_aiqr_module} m
                   JOIN {local_aiqr_job} j ON j.id = m.jobid
                   LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = :userid2
                  WHERE j.userid = :userid AND j.quizid = :quizid AND j.status = 'ready'
                    AND j.attemptid = :attemptid
                    AND (c.state IS NULL OR c.state != 'complete')",
                ['userid' => $USER->id, 'userid2' => $USER->id, 'quizid' => $quizid, 'attemptid' => $attemptid]
            );
        } else {
            $pendingcount = $DB->count_records_sql(
                "SELECT COUNT(m.id)
                   FROM {local_aiqr_module} m
                   JOIN {local_aiqr_job} j ON j.id = m.jobid
                   LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = :userid2
                  WHERE j.userid = :userid AND j.quizid = :quizid AND j.status = 'ready'
                    AND (c.state IS NULL OR c.state != 'complete')",
                ['userid' => $USER->id, 'userid2' => $USER->id, 'quizid' => $quizid]
            );
        }

        // $wrongcount: the number of wrong questions for this attempt, counted from child jobs
        // (questionid IS NOT NULL). This correctly reflects 2 wrong questions even when 1 child
        // job's module generation failed (failed jobs have no module so $pendingcount undercounts).
        // For the no-attemptid path, fall back to $modulecount (can't isolate per-attempt).
        if ($attemptid > 0) {
            $wrongcount = $DB->count_records_sql(
                "SELECT COUNT(j.id)
                   FROM {local_aiqr_job} j
                  WHERE j.userid = :userid AND j.quizid = :quizid
                    AND j.questionid IS NOT NULL
                    AND j.attemptid = :attemptid",
                ['userid' => $USER->id, 'quizid' => $quizid, 'attemptid' => $attemptid]
            );
        } else {
            $wrongcount = $modulecount;
        }

        $urlparams = ['courseid' => $courseid];
        if ($attemptid > 0) {
            $urlparams['attemptid'] = $attemptid;
        }
        $url = new \moodle_url('/local/aiquizremedial/index.php', $urlparams);

        // $displaycount: questions the student still needs to fix.
        // = total wrong questions (from child jobs) minus already-completed modules.
        // This correctly shows 2 when 2 questions are wrong, even if 1 module generation failed.
        $completedcount = (int) $modulecount - (int) $pendingcount;
        $displaycount   = max((int) $wrongcount - $completedcount, 0);

        if ($pendingcount > 0) {
            $heading = get_string('review_banner_heading', 'local_aiquizremedial');
            $message = get_string('review_banner_message', 'local_aiquizremedial', $displaycount);
            $btnlabel = get_string('review_banner_button', 'local_aiquizremedial');
            $bgcolour = '#fef3c7';
            $bordercolour = '#f59e0b';
            $textcolour = '#92400e';
            $btnbg = '#f59e0b';
        } else {
            $heading = get_string('review_banner_complete_heading', 'local_aiquizremedial');
            $message = get_string('review_banner_complete_message', 'local_aiquizremedial');
            $btnlabel = get_string('review_banner_complete_button', 'local_aiquizremedial');
            $bgcolour = '#d1fae5';
            $bordercolour = '#10b981';
            $textcolour = '#065f46';
            $btnbg = '#10b981';
        }

        $html = '<div id="aiqr-review-banner" style="background:' . $bgcolour . ';border:2px solid ' . $bordercolour . ';border-radius:10px;padding:16px 20px;margin:16px 0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">'
            . '<div style="flex-shrink:0;width:40px;height:40px;background:' . $btnbg . ';border-radius:50%;display:flex;align-items:center;justify-content:center;">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="22" height="22"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>'
            . '</div>'
            . '<div style="flex:1;min-width:200px;">'
            . '<div style="font-weight:700;font-size:15px;color:' . $textcolour . ';margin-bottom:2px;">' . $heading . '</div>'
            . '<div style="font-size:13px;color:' . $textcolour . ';opacity:0.85;">' . $message . '</div>'
            . '</div>'
            . '<a href="' . $url->out(false) . '" style="display:inline-flex;align-items:center;gap:6px;background:' . $btnbg . ';color:white;border:none;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap;">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>'
            . $btnlabel
            . '</a>'
            . '</div>';

        $js = '<script>
(function () {
    var banner = document.getElementById("aiqr-review-banner");
    if (!banner) return;
    var info = document.querySelector(".info, .quizreviewsummary, #review-summary-table, .generaltable.generalbox");
    if (info) {
        info.parentNode.insertBefore(banner, info.nextSibling);
    } else {
        var main = document.querySelector("#region-main .card-body, #region-main, [role=main]");
        if (main && main.firstChild) {
            main.insertBefore(banner, main.firstChild);
        }
    }
})();
</script>';

        $hook->add_html($html . $js);
    }
}
