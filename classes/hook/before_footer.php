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

namespace local_aiquizremedial\hook;

use local_aiquizremedial\helper;

/**
 * Footer banners for learners and the teacher shortcut button.
 *
 * v1.3.0 (FIX-RL-STUDENT-VISIBILITY): previously learners only saw a banner on the quiz
 * REVIEW page and only once cron had already generated the modules. Learners who were sent
 * back to the quiz page (review disabled), who looked before cron ran, or who came back
 * later via the course page never saw anything. Now:
 *  - quiz review page  → banner for that attempt;
 *  - quiz view page    → banner for all attempts of that quiz (learners — teachers still
 *                        get the "View Remedial Learnings" button);
 *  - course page       → banner when there are unfinished modules in the course;
 *  - while modules are still being generated a "being prepared" banner is shown which
 *    polls ajax.php and reloads itself when the modules are ready.
 * The same HTML is produced by the legacy lib.php callback for Moodle 4.0–4.3, where the
 * hook API does not exist.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class before_footer {
    /**
     * Hook callback (Moodle 4.4+).
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function callback($hook): void {
        $html = self::get_html();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }

    /**
     * Build the footer HTML for the current page. Never throws.
     *
     * @return string
     */
    public static function get_html(): string {
        global $PAGE;
        try {
            if (!get_config('local_aiquizremedial', 'enabled') || !isloggedin() || isguestuser()) {
                return '';
            }
            if (during_initial_install() || !$PAGE->has_set_url()) {
                return '';
            }
            $pagetype = $PAGE->pagetype;
            if ($pagetype === 'mod-quiz-view' || $pagetype === 'mod-quiz-report' || $pagetype === 'mod-quiz-review') {
                return self::quiz_page($pagetype);
            }
            if ($PAGE->cm && $PAGE->cm->modname === 'aiknowledgecheck' && helper::kc_installed()) {
                return self::kc_page();
            }
            if (strpos($pagetype, 'course-view-') === 0) {
                return self::course_page();
            }
        } catch (\Throwable $e) {
            debugging('local_aiquizremedial footer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return '';
    }

    /**
     * Quiz view / report / review pages.
     *
     * @param string $pagetype
     * @return string
     */
    protected static function quiz_page(string $pagetype): string {
        global $PAGE, $USER, $DB;

        $cm = $PAGE->cm;
        if (!$cm || $cm->modname !== 'quiz') {
            return '';
        }
        $quizid = (int) $cm->instance;
        $courseid = (int) $cm->course;
        $coursecontext = \context_course::instance($courseid);
        $isteacher = has_capability('local/aiquizremedial:viewall', $PAGE->context)
            || has_capability('local/aiquizremedial:viewall', $coursecontext);

        if ($pagetype === 'mod-quiz-review') {
            $attemptid = optional_param('attempt', 0, PARAM_INT);
            $attemptuser = $attemptid ? (int) $DB->get_field('quiz_attempts', 'userid', ['id' => $attemptid]) : 0;
            if ($attemptuser && $attemptuser !== (int) $USER->id) {
                return ''; // Teacher reviewing someone else's attempt.
            }
            if (!get_config('local_aiquizremedial', 'showonquizreview')) {
                return '';
            }
            $summary = helper::learner_summary((int) $USER->id, $courseid, $quizid, $attemptid);
            $url = new \moodle_url('/local/aiquizremedial/index.php', array_filter([
                'courseid' => $courseid, 'attemptid' => $attemptid,
            ]));
            return self::learner_banner(
                $summary, $url, 'quiz',
                ['courseid' => $courseid, 'quizid' => $quizid, 'attemptid' => $attemptid]);
        }

        if ($isteacher) {
            return self::teacher_button($quizid, $courseid);
        }

        if ($pagetype === 'mod-quiz-view' && self::setting_on('showonquizview')) {
            $summary = helper::learner_summary((int) $USER->id, $courseid, $quizid);
            $url = new \moodle_url('/local/aiquizremedial/index.php', ['courseid' => $courseid, 'quizid' => $quizid]);
            return self::learner_banner($summary, $url, 'quiz', ['courseid' => $courseid, 'quizid' => $quizid]);
        }
        return '';
    }

    /**
     * AI Knowledge Check activity pages: same learner banner / teacher button as quizzes,
     * scoped to that Knowledge Check (v1.3.0 — quiz and Knowledge Check work independently).
     *
     * @return string
     */
    protected static function kc_page(): string {
        global $PAGE, $USER;
        $cm = $PAGE->cm;
        $kcid = (int) $cm->instance;
        $courseid = (int) $cm->course;
        if (has_capability('local/aiquizremedial:viewall', \context_course::instance($courseid))) {
            return self::teacher_button(0, $courseid, $kcid);
        }
        if (!self::setting_on('showonquizview')) {
            return '';
        }
        $summary = helper::learner_summary((int) $USER->id, $courseid, 0, 0, $kcid);
        $url = new \moodle_url('/local/aiquizremedial/index.php', ['courseid' => $courseid, 'kcid' => $kcid]);
        return self::learner_banner($summary, $url, 'quiz', ['courseid' => $courseid, 'kcid' => $kcid]);
    }

    /**
     * Course page banner for learners with unfinished modules.
     *
     * @return string
     */
    protected static function course_page(): string {
        global $PAGE, $USER;
        if (!self::setting_on('showoncoursepage')) {
            return '';
        }
        $course = $PAGE->course;
        if (empty($course->id) || $course->id == SITEID) {
            return '';
        }
        $context = \context_course::instance($course->id);
        if (has_capability('local/aiquizremedial:viewall', $context)) {
            return '';
        }
        $summary = helper::learner_summary((int) $USER->id, (int) $course->id);
        if ($summary->outstanding === 0 && $summary->generating === 0 && $summary->pendingumbrellas === 0) {
            return ''; // Nothing to do — do not nag on the course page.
        }
        $url = new \moodle_url('/local/aiquizremedial/index.php', ['courseid' => $course->id]);
        return self::learner_banner($summary, $url, 'course', ['courseid' => (int) $course->id]);
    }

    /**
     * Read a checkbox setting that defaults to on when never saved.
     *
     * @param string $name
     * @return bool
     */
    protected static function setting_on(string $name): bool {
        $v = get_config('local_aiquizremedial', $name);
        return $v === false ? true : (bool) $v;
    }

    /**
     * Teacher "View Remedial Learnings" button.
     *
     * @param int $quizid
     * @param int $courseid
     * @param int $kcid AI Knowledge Check instance (instead of a quiz)
     * @return string
     */
    protected static function teacher_button(int $quizid, int $courseid, int $kcid = 0): string {
        global $DB;
        if ($kcid) {
            $where = "j.kcid = :id AND j.sourcetype = 'knowledgecheck'";
            $id = $kcid;
            $activity = 'kc-' . $kcid;
        } else {
            $where = "j.quizid = :id AND j.sourcetype = 'quiz'";
            $id = $quizid;
            $activity = 'quiz-' . $quizid;
        }
        $count = (int) $DB->count_records_sql(
            "SELECT COUNT(m.id)
               FROM {local_aiqr_module} m
               JOIN {local_aiqr_job} j ON j.id = m.jobid
              WHERE $where AND j.status = 'ready'",
            ['id' => $id]
        );
        $url = new \moodle_url('/local/aiquizremedial/report.php', ['courseid' => $courseid, 'activity[0]' => $activity]);
        $badge = $count > 0 ? \html_writer::span($count, 'aiqr-teacher-btn-badge') : '';
        $html = \html_writer::start_div('aiqr-teacher-quiz-btn', ['id' => 'aiqr-teacher-quiz-btn']);
        $html .= \html_writer::link(
            $url,
            self::icon_pencil() . s(get_string('viewremediallearnings', 'local_aiquizremedial')) . $badge,
            ['class' => 'aiqr-teacher-btn']);
        // Version 1.5.0: jump straight to this activity's insights.
        $html .= \html_writer::link(
            new \moodle_url('/local/aiquizremedial/insights.php', ['courseid' => $courseid, 'activity[0]' => $activity]),
            s(get_string('quizinsights', 'local_aiquizremedial')), ['class' => 'aiqr-teacher-btn aiqr-teacher-btn-secondary']);
        if ($count === 0) {
            $html .= \html_writer::span(get_string('noremedialforquiz', 'local_aiquizremedial'), 'aiqr-muted');
        }
        $html .= \html_writer::end_div();
        $html .= '<script>
(function (){
    var btn = document.getElementById("aiqr-teacher-quiz-btn");
    if (!btn) { return; }
    var main = document.querySelector("#region-main [role=main]") || document.querySelector("#region-main");
    if (main) { main.insertBefore(btn, main.firstChild); }
})();
</script>';
        return $html;
    }

    /**
     * Learner banner. Four states: preparing, outstanding, all complete, nothing.
     *
     * @param \stdClass $summary from helper::learner_summary()
     * @param \moodle_url $url link to the learner's module list
     * @param string $scope 'quiz' or 'course'
     * @param array $pollparams parameters for the ajax status poll
     * @return string
     */
    protected static function learner_banner(\stdClass $summary, \moodle_url $url, string $scope, array $pollparams): string {
        $preparing = $summary->generating + $summary->pendingumbrellas;

        if ($summary->outstanding > 0) {
            $variant = 'pending';
            $heading = get_string('review_banner_heading', 'local_aiquizremedial');
            $message = get_string('review_banner_message', 'local_aiquizremedial', $summary->outstanding);
            if ($summary->generating > 0) {
                $message .= ' ' . get_string('banner_more_preparing', 'local_aiquizremedial', $summary->generating);
            }
            $btn = get_string('review_banner_button', 'local_aiquizremedial');
        } else if ($preparing > 0) {
            $variant = 'preparing';
            $heading = get_string('banner_preparing_heading', 'local_aiquizremedial');
            $message = $summary->generating > 0
                ? get_string('banner_preparing_message_n', 'local_aiquizremedial', $summary->generating)
                : get_string('banner_preparing_message', 'local_aiquizremedial');
            $btn = get_string('review_banner_complete_button', 'local_aiquizremedial');
        } else if ($summary->ready > 0 && $scope === 'quiz') {
            $variant = 'complete';
            $heading = get_string('review_banner_complete_heading', 'local_aiquizremedial');
            $message = get_string('review_banner_complete_message', 'local_aiquizremedial');
            $btn = get_string('review_banner_complete_button', 'local_aiquizremedial');
        } else {
            return '';
        }

        $id = 'aiqr-review-banner';
        $html = \html_writer::start_div('aiqr-banner aiqr-banner-' . $variant, [
            'id' => $id, 'role' => 'status', 'aria-live' => 'polite',
        ]);
        $html .= \html_writer::div($variant === 'preparing' ? self::icon_spinner() : self::icon_bulb(), 'aiqr-banner-icon');
        $html .= \html_writer::start_div('aiqr-banner-body');
        $html .= \html_writer::div(s($heading), 'aiqr-banner-heading');
        $html .= \html_writer::div(s($message), 'aiqr-banner-message');
        $html .= \html_writer::end_div();
        if ($variant !== 'preparing' || $summary->ready > 0) {
            $html .= \html_writer::link($url, self::icon_bolt() . s($btn), ['class' => 'aiqr-banner-btn']);
        }
        $html .= \html_writer::end_div();

        // Place the banner near the top of the main region instead of the footer.
        $js = '(function () {
    var banner = document.getElementById("' . $id . '");
    if (!banner) { return; }
    var info = document.querySelector("#region-main .quizreviewsummary, #region-main .quizattemptsummary, #region-main .quizinfo");
    if (info && info.parentNode) {
        info.parentNode.insertBefore(banner, info.nextSibling);
    } else {
        var main = document.querySelector("#region-main [role=main], #region-main .card-body, #region-main");
        if (main) { main.insertBefore(banner, main.firstChild); }
    }';

        if ($preparing > 0) {
            // Poll every 15s for up to 15 minutes; reload once more modules are ready.
            $pollurl = new \moodle_url('/local/aiquizremedial/ajax.php', array_merge($pollparams, [
                'action' => 'summary', 'sesskey' => sesskey(),
            ]));
            $js .= '
    var readyNow = ' . (int) $summary->ready . ', tries = 0;
    var poll = function () {
        if (++tries > 60) { return; }
        fetch(' . json_encode($pollurl->out(false)) . ', {credentials: "same-origin"})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success && (d.ready > readyNow || (d.generating === 0 && d.pendingumbrellas === 0))) {
                    window.location.reload();
                } else {
                    setTimeout(poll, 15000);
                }
            })
            .catch(function () { setTimeout(poll, 30000); });
    };
    setTimeout(poll, 15000);';
        }
        $js .= '
})();';
        return $html . \html_writer::script($js);
    }

    /**
     * Inline SVG icon.
     * @return string
     */
    protected static function icon_bulb(): string {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="22" height="22" aria-hidden="true">'
            . '<path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0'
            . 'l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>';
    }

    /**
     * Inline SVG icon.
     * @return string
     */
    protected static function icon_spinner(): string {
        return '<svg class="aiqr-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="22" height="22" '
            . 'aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9"/></svg>';
    }

    /**
     * Inline SVG icon.
     * @return string
     */
    protected static function icon_bolt(): string {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">'
            . '<path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>';
    }

    /**
     * Inline SVG icon.
     * @return string
     */
    protected static function icon_pencil(): string {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">'
            . '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>';
    }
}
