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
 * Part of the local_aiquizremedial plugin.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once('../../config.php');

$moduleid = required_param('moduleid', PARAM_INT);
$userid   = optional_param('userid',   0, PARAM_INT); // Passed when teacher opens from teacher view.
// FIX-RL-FILTER-PERSIST (v1.2.44): teacher filter context — passed through from
// index.php so the same filter panel can be rendered here and so "Back to My
// Modules" returns the teacher to the filtered list instead of the unfiltered overview.
$filterquizid = optional_param('filterquizid', 0, PARAM_INT);
$filteruserid = optional_param('filteruserid', 0, PARAM_INT);
// Version 1.3.0: the teacher report passes its full filter state as returnurl so "Back" restores it.
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

global $DB, $USER, $OUTPUT, $PAGE;

// Require login before any DB access.
require_login();

$module = $DB->get_record('local_aiqr_module', ['id' => $moduleid], '*', MUST_EXIST);
$job    = $DB->get_record('local_aiqr_job', ['id' => $module->jobid], '*', MUST_EXIST);

require_login($job->courseid);

$context = context_course::instance($job->courseid);

if ((int) $job->userid !== (int) $USER->id && !has_capability('local/aiquizremedial:viewall', $context)) {
    throw new required_capability_exception($context, 'local/aiquizremedial:viewown', 'nopermissions', '');
}

// Determine if a teacher is reviewing a student's module, or the student is viewing their own.
// When $userid is passed in the URL and the viewer has viewall, we are in teacher-review mode.
$teacherview = false;
if ($userid > 0 && (int) $job->userid !== (int) $USER->id && has_capability('local/aiquizremedial:viewall', $context)) {
    $teacherview = true;
}
// The subject user whose completion data we should display.
$subjectuserid = $teacherview ? (int) $userid : (int) $USER->id;

$completion = $DB->get_record('local_aiqr_completion', ['moduleid' => $moduleid, 'userid' => $subjectuserid]);
if (!$completion && !$teacherview) {
    // Only create a completion record when the student is viewing their own module.
    $completion = (object) [
        'moduleid'       => $moduleid,
        'userid'         => $subjectuserid,
        'state'          => 'notstarted',
        'attempts_count' => 0,
        'completed_at'   => null,
        'timecreated'    => time(),
        'timemodified'   => time(),
    ];
    $completion->id = $DB->insert_record('local_aiqr_completion', $completion);
}
if (!$completion) {
    // Teacher viewing a module the student has not yet opened — use a stub for display only.
    $completion = (object) ['state' => 'notstarted', 'attempts_count' => 0, 'completed_at' => null];
}

$check    = json_decode($module->check_question_json, true);
$feedback = json_decode($module->feedback_json, true);

$sourcetype = $job->sourcetype ?? 'quiz';

$activityname = '';
$originalQuestionText = '';
$questionTextFormatted = false;
$studentResponseText  = '';

if ($sourcetype === 'knowledgecheck' && \local_aiquizremedial\helper::kc_installed()) {
    // Load activity name from the KC table.
    $kc = $DB->get_record('aiknowledgecheck', ['id' => $job->kcid], 'name');
    if ($kc) {
        $activityname = format_string($kc->name);
    }

    // Load original KC question and student answer.
    if (!empty($job->questionid) && !empty($job->attemptid)) {
        try {
            $kcquestion = $DB->get_record('aiknowledgecheck_questions', ['id' => $job->questionid]);
            $kcattempt  = $DB->get_record('aiknowledgecheck_attempts',  ['id' => $job->attemptid]);

            if ($kcquestion) {
                $originalQuestionText = (string) ($kcquestion->questiontext ?? '');
            }
            if ($kcquestion && $kcattempt) {
                $answers = json_decode($kcattempt->answers, true) ?: [];
                $ans = $answers[(string) $job->questionid] ?? $answers[$job->questionid] ?? null;
                if ($ans !== null) {
                    // Answer is stored 0-based (JS sends answerIndex 0-3).
                    // KC DB columns are 1-indexed: answer1..answer4.
                    // Old guard "$studentIndex1 >= 1" wrongly skipped option 0 and
                    // had an off-by-one for all other options.
                    $studentIndex0 = (int) ($ans['answer'] ?? -1);
                    if ($studentIndex0 >= 0 && $studentIndex0 <= 4) {
                        $studentResponseText = (string) ($kcquestion->{'answer' . ($studentIndex0 + 1)} ?? '');
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently continue if KC data is unavailable.
        }
    }
} else if ($sourcetype !== 'knowledgecheck') {
    // Standard Moodle quiz.
    $quiz = $DB->get_record('quiz', ['id' => $job->quizid], 'name');
    if ($quiz) {
        $activityname = format_string($quiz->name);
    }

    if (!empty($job->attemptid) && !empty($job->questionid)) {
        try {
            require_once($CFG->libdir  . '/questionlib.php');
            require_once($CFG->dirroot . '/question/engine/lib.php');
            $quizattempt = $DB->get_record('quiz_attempts', ['id' => $job->attemptid]);
            if ($quizattempt) {
                $quba = \question_engine::load_questions_usage_by_activity($quizattempt->uniqueid);
                foreach ($quba->get_slots() as $slot) {
                    $qa = $quba->get_question_attempt($slot);
                    if ((int) $qa->get_question()->id === (int) $job->questionid) {
                        // Version 1.4.1: format through the question engine so @@PLUGINFILE@@ image
                        // links in the question resolve (raw format_text() left them broken).
                        try {
                            $originalQuestionText = $qa->get_question()->format_questiontext($qa);
                            $questionTextFormatted = true;
                        } catch (\Throwable $e) {
                            $originalQuestionText = $qa->get_question()->questiontext ?? '';
                        }
                        $studentResponseText  = $qa->get_response_summary();
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently continue if question data is unavailable.
        }
    }
}

$pageurlparams = ['moduleid' => $moduleid];
if ($teacherview) {
    $pageurlparams['userid'] = $userid;
}
$PAGE->set_url(new moodle_url('/local/aiquizremedial/view.php', $pageurlparams));
$PAGE->set_context($context);
$PAGE->set_title(get_string('fixmodule_title', 'local_aiquizremedial'));
$PAGE->set_heading(get_string('fixmodule_title', 'local_aiquizremedial'));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

// Back link — preserve teacher context so the back button returns to the right view.
// FIX-RL-FILTER-PERSIST (v1.2.44): when the teacher opened this module from a filtered
// list, send them BACK to that same filtered list (carry filterquizid + filteruserid)
// instead of the unfiltered overview. attemptid is intentionally omitted from the back
// URL when filters are present so the filter values, not the single attempt, drive the
// returned list.
$backparams = ['courseid' => $job->courseid];
if ($filterquizid > 0 || $filteruserid > 0) {
    if ($filterquizid > 0) { $backparams['quizid']       = $filterquizid; }
    if ($filteruserid > 0) { $backparams['filteruserid'] = $filteruserid; }
} else if ($userid > 0 && (int) $job->userid !== (int) $USER->id) {
    // Single-student deep-link (no broader filter active) — keep the original behaviour.
    $backparams['userid']    = (int) $job->userid;
    $backparams['attemptid'] = $job->attemptid;
} else {
    $backparams['attemptid'] = $job->attemptid;
}
$backurl = new moodle_url('/local/aiquizremedial/index.php', $backparams);
if ($returnurl !== '' && has_capability('local/aiquizremedial:viewall', $context)) {
    $backurl = new moodle_url($returnurl);
}
echo html_writer::link($backurl, get_string('backtomymodules', 'local_aiquizremedial'), ['class' => 'btn btn-secondary btn-sm mb-3 mr-2']);

// Back to Quiz / Activity button.
$activityurl = null;
if ($sourcetype === 'knowledgecheck' && !empty($job->kcid) && \local_aiquizremedial\helper::kc_installed()) {
    try {
        $kccmrecord = $DB->get_record_sql(
            "SELECT cm.id FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.instance = :kcid AND m.name = 'aiknowledgecheck'",
            ['kcid' => $job->kcid]
        );
        if ($kccmrecord) {
            $activityurl = new moodle_url('/mod/aiknowledgecheck/view.php', ['id' => $kccmrecord->id]);
        }
    } catch (\Throwable $e) {
        // Silently continue if lookup fails.
    }
} else if (!empty($job->quizid)) {
    $activityurl = new moodle_url('/mod/quiz/view.php', ['q' => $job->quizid]);
}
if ($activityurl) {
    echo html_writer::link(
        $activityurl,
        get_string('backtoquiz', 'local_aiquizremedial'),
        ['class' => 'btn btn-primary btn-sm mb-3']
    );
}

echo html_writer::tag('h2', get_string('fixmodule_heading', 'local_aiquizremedial'));

if (!empty($activityname)) {
    echo html_writer::tag(
        'p', get_string(
        $sourcetype === 'knowledgecheck' ? 'fromkc' : 'fromquiz', 'local_aiquizremedial',
        $activityname), ['class' => 'text-muted']);
}

// Replace [[N]] placeholders (Select Missing Words question type) with a visible blank
// so students see ___ instead of [[1]], [[2]], etc.
$originalQuestionText = preg_replace('/\[\[\d+\]\]/', '___', $originalQuestionText);

// Section 0: Your answer — show the original question and what the student selected.
if (!empty($originalQuestionText) || !empty($studentResponseText)) {
    echo html_writer::start_div('card mb-3 aiqr-section-card aiqr-your-answer-card');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h4', get_string('youranswer_heading', 'local_aiquizremedial'));
    if (!empty($originalQuestionText)) {
        echo html_writer::div(
            $questionTextFormatted ? $originalQuestionText
                : format_text(str_replace('@@PLUGINFILE@@/', '', $originalQuestionText), FORMAT_HTML),
            'aiqr-original-question'
        );
    }
    if (!empty($studentResponseText)) {
        echo html_writer::start_div('aiqr-student-response');
        echo html_writer::tag(
            'span',
            get_string('youselected_label', 'local_aiquizremedial'),
            ['class' => 'aiqr-student-response-label']
        );
        echo html_writer::tag('span', s($studentResponseText), ['class' => 'aiqr-student-response-value']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Section 1: Tutor lesson (v1.4.0) — 2x2 cards. Older modules render the same card style
// from explain_text. Each language is rendered server-side as its own block and the language
// picker simply shows/hides blocks (the old JS text-rewriting switcher is gone).
$lessondata = !empty($module->lesson_json)
    ? \local_aiquizremedial\lesson::normalise(json_decode($module->lesson_json, true))
    : null;
$translationsData = !empty($module->translations_json) ? (json_decode($module->translations_json, true) ?: []) : [];
$imageurl = !empty($module->explain_image_url) ? $module->explain_image_url : null;
$hasAudio = !empty($module->explain_audio_url);
$hasTranslations = !empty($translationsData);

// FIX-RL-VOICEOVER-PLAYBACK: default 'manual'; teachers never auto-play.
$voiceoverplayback = get_config('local_aiquizremedial', 'voiceoverplayback') ?: 'manual';
$autoplayEnabled   = !$teacherview && ($voiceoverplayback === 'auto');

echo html_writer::start_tag('div', ['class' => 'aiqr-lesson', 'id' => 'aiqr-lesson']);

if ($hasAudio || $hasTranslations) {
    echo html_writer::start_div('aiqr-media-bar');
    if ($hasAudio) {
        echo html_writer::span(get_string('listen_label', 'local_aiquizremedial'), 'aiqr-media-label');
        $audioAttrs = [
            'controls' => 'controls',
            'src'      => $module->explain_audio_url,
            'class'    => 'aiqr-audio-player',
            'id'       => 'aiqr-audio-main',
            'preload'  => 'metadata',
        ];
        if ($autoplayEnabled) {
            $audioAttrs['autoplay'] = 'autoplay';
        }
        echo html_writer::tag('audio', '', $audioAttrs);
    }
    if ($hasTranslations) {
        $langoptions = ['en' => get_string('lang_english', 'local_aiquizremedial')];
        foreach (array_keys($translationsData) as $code) {
            $langoptions[$code] = get_string_manager()->string_exists('lang_' . $code, 'local_aiquizremedial')
                ? get_string('lang_' . $code, 'local_aiquizremedial') : strtoupper($code);
        }
        echo html_writer::select($langoptions, 'aiqrlang', 'en', false, [
            'id' => 'aiqr-lang-select', 'class' => 'form-select custom-select aiqr-lang-select',
            'aria-label' => get_string('choose_language', 'local_aiquizremedial'),
        ]);
    }
    echo html_writer::end_div();
}

// English block.
echo html_writer::start_div('aiqr-lesson-lang', ['data-lang' => 'en', 'lang' => 'en']);
echo $lessondata
    ? \local_aiquizremedial\lesson::render($lessondata, $imageurl, $teacherview)
    : \local_aiquizremedial\lesson::render_legacy((string) ($module->explain_text ?? ''), $imageurl);
echo html_writer::end_div();

// Translated blocks (hidden until chosen).
$audiomap = ['en' => (string) ($module->explain_audio_url ?? '')];
foreach ($translationsData as $code => $t) {
    $code = clean_param($code, PARAM_ALPHANUMEXT);
    $audiomap[$code] = (string) ($t['audio_url'] ?? '');
    $tlesson = ($lessondata && !empty($t['lesson'])) ? \local_aiquizremedial\lesson::normalise($t['lesson']) : null;
    echo html_writer::start_div('aiqr-lesson-lang', ['data-lang' => $code, 'lang' => $code, 'hidden' => 'hidden']);
    echo $tlesson
        ? \local_aiquizremedial\lesson::render($tlesson, $imageurl, $teacherview)
        : \local_aiquizremedial\lesson::render_legacy((string) ($t['explain_text'] ?? ''), $imageurl);
    echo html_writer::end_div();
}
echo html_writer::end_tag('div');

// Version 1.4.1: image status for teachers (never shown to learners).
if (has_capability('local/aiquizremedial:viewall', $context)
        && \local_aiquizremedial\credit_calculator::is_images_enabled()) {
    $notes = [];
    $imgstatus = (string) ($module->image_status ?? '');
    if (empty($module->explain_image_url) && in_array($imgstatus, ['', 'retry', 'failed', 'rejected'], true)) {
        $notes[] = get_string('imagestatus_' . ($imgstatus === '' ? 'retry' : $imgstatus), 'local_aiquizremedial');
        if (!empty($module->image_error)) {
            $notes[] = get_string('imagestatus_reason', 'local_aiquizremedial', s($module->image_error));
        }
    }
    $meta = !empty($module->image_meta) ? (json_decode($module->image_meta, true) ?: []) : [];
    $prov = $meta['provenance'] ?? [];
    if (!empty($module->explain_image_url) && ($prov['promptMode'] ?? '') === 'context_only') {
        $notes[] = get_string('imagestatus_contextonly', 'local_aiquizremedial');
    }
    $qsnap = !empty($module->question_json) ? (json_decode($module->question_json, true) ?: []) : [];
    if (!empty($qsnap['undescribed_images'])) {
        $notes[] = get_string('imagestatus_undescribed', 'local_aiquizremedial', (int) $qsnap['undescribed_images']);
    }
    if (!empty($module->explain_image_url) && !empty($prov['model'])) {
        $notes[] = get_string(
            'imagestatus_provenance', 'local_aiquizremedial',
            (object) ['model' => s($prov['model']), 'mode' => s($prov['promptMode'] ?? '')]);
    }
    if ($notes) {
        echo html_writer::div(implode('<br>', $notes), 'aiqr-image-status');
    }
}

if ($hasTranslations) {
    echo html_writer::script('(function (){
  var audio = ' . json_encode($audiomap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
  var autoplay = ' . ($autoplayEnabled ? 'true' : 'false') . ';
  var sel = document.getElementById("aiqr-lang-select");
  if (!sel) { return; }
  sel.addEventListener("change", function () {
    var lang = sel.value;
    document.querySelectorAll("#aiqr-lesson .aiqr-lesson-lang").forEach(function (el) {
      el.hidden = el.getAttribute("data-lang") !== lang;
    });
    var aud = document.getElementById("aiqr-audio-main");
    if (aud) {
      if (audio[lang]) {
        aud.src = audio[lang]; aud.load(); aud.hidden = false;
        if (autoplay) { aud.play().catch(function () {}); }
      } else {
        aud.pause(); aud.hidden = true;
      }
    }
  });
})();');
}

// Section 2: Quick check.
if ($teacherview) {
    // Teacher review mode: show the check question and options read-only, with student's completion status.
    // v1.2.18 FIX-QRL-EXPLAIN: view.php was reading $check['correct'] but the JSON key
    // written by the generator and used by submit.php is 'correct_index'. The mismatch
    // meant $correctindex was always -1, so no option was ever highlighted as correct
    // in the teacher review panel.
    $correctindex = isset($check['correct_index']) ? (int) $check['correct_index'] : (isset($check['correct']) ? (int) $check['correct'] : -1);
    $options = $check['options'] ?? [];

    echo html_writer::start_div('card mb-3 aiqr-section-card');
    echo html_writer::start_div('card-body');
    echo html_writer::tag(
        'h4',
        get_string('section_quickcheck', 'local_aiquizremedial') .
        ' ' . html_writer::tag('small', get_string('teacherreview_readonly', 'local_aiquizremedial'), ['class' => 'text-muted'])
    );

    echo html_writer::tag('p', s($check['prompt'] ?? ''));

    foreach ($options as $idx => $opt) {
        $iscorrect = ($idx === $correctindex);
        // FIX-OPT-CAPS (v1.2.53): Capitalise first letter of every option for consistency.
        $label = s(ucfirst((string) $opt));
        if ($iscorrect) {
            // Use Bootstrap 5 classes (Moodle 4.x) — badge-success and ml-1 are Bootstrap 4 only.
            $label .= ' ' . html_writer::tag(
                'span',
                get_string('teacherreview_correctanswer', 'local_aiquizremedial'),
                ['class' => 'badge bg-success text-white ms-1']
            );
        }
        echo html_writer::start_div('aiqr-option-wrapper' . ($iscorrect ? ' aiqr-option-correct' : ''));
        echo html_writer::tag('span', $label);
        echo html_writer::end_div();
    }

    // Student completion status.
    $state = $completion->state ?? 'notstarted';
    $attempts = (int) ($completion->attempts_count ?? 0);
    if ($state === 'complete') {
        echo html_writer::div(
            get_string('teacherreview_studentcomplete', 'local_aiquizremedial', $attempts),
            'alert alert-success mt-3'
        );
    } else if ($state === 'inprogress') {
        echo html_writer::div(
            get_string('teacherreview_studentinprogress', 'local_aiquizremedial', $attempts),
            'alert alert-warning mt-3'
        );
    } else {
        echo html_writer::div(
            get_string('teacherreview_studentnotstarted', 'local_aiquizremedial'),
            'alert alert-info mt-3'
        );
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
} else if ($completion->state !== 'complete') {
    echo html_writer::start_div('card mb-3 aiqr-section-card');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h4', get_string('section_quickcheck', 'local_aiquizremedial'));
    if ($lessondata) {
        echo html_writer::tag('p', get_string('quickcheck_transfer', 'local_aiquizremedial'), ['class' => 'aiqr-quickcheck-sub']);
    }

    $action = new moodle_url('/local/aiquizremedial/submit.php', [
        'moduleid' => $moduleid,
        'sesskey'  => sesskey(),
    ]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $action]);

    echo html_writer::tag('p', s($check['prompt'] ?? 'Choose an answer'));

    $options = $check['options'] ?? [];
    foreach ($options as $idx => $opt) {
        $id = 'opt_' . $idx;
        echo html_writer::start_div('aiqr-option-wrapper');
        echo html_writer::empty_tag('input', [
            'type'     => 'radio',
            'name'     => 'choice',
            'value'    => $idx,
            'id'       => $id,
            'required' => 'required',
        ]);
        // FIX-OPT-CAPS (v1.2.53): Capitalise first letter of every option for consistency.
        echo html_writer::tag('label', s(ucfirst((string) $opt)), ['for' => $id]);
        echo html_writer::end_div();
    }

    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => get_string('checkanswer', 'local_aiquizremedial'),
        'class' => 'btn btn-primary mt-3',
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    echo html_writer::div(
        get_string('module_completed', 'local_aiquizremedial'),
        'alert alert-success'
    );
    // Prominent navigation buttons after completion so students have a clear path forward.
    echo html_writer::start_div('mt-3 d-flex flex-wrap gap-2');
    if ($activityurl) {
        echo html_writer::link(
            $activityurl,
            get_string('backtoquiz', 'local_aiquizremedial'),
            ['class' => 'btn btn-primary mr-2 mb-2']
        );
    }
    // FIX-RL-BACK-BTN (v1.2.45): reuse the filter-aware $backurl built at the top
    // of the page so the completion-state back link also preserves the teacher's
    // active quiz/student filter context (previously rebuilt without filters).
    echo html_writer::link($backurl, get_string('backtomymodules', 'local_aiquizremedial'), ['class' => 'btn btn-secondary mb-2']);
    echo html_writer::end_div();
}

// FIX-RL-BACK-BTN (v1.2.45): always-visible bottom navigation. The previous layout only
// rendered a back link at the very top of the page and (for completed modules) inside the
// completion alert. Teachers reviewing a student's question had to scroll all the way
// back to the top to navigate to the next question in the module list — and on long
// modules with explanation text + image + audio + check question they often missed the
// top button entirely and reported "no way to go back". This block adds a clearly
// labelled "Back to Modules" button after the question content (still above the status
// bar) on every render path: teacher review, student in-progress, student completed.
// Uses $backurl which already preserves courseid + filter context (filterquizid +
// filteruserid) so position in the filtered module list is retained.
echo html_writer::start_div('mt-4 mb-3 aiqr-bottom-nav d-flex flex-wrap gap-2');
echo html_writer::link(
    $backurl,
    get_string('backtomodules', 'local_aiquizremedial'),
    ['class' => 'btn btn-secondary mr-2 mb-2']
);
if ($activityurl) {
    echo html_writer::link(
        $activityurl,
        get_string('backtoquiz', 'local_aiquizremedial'),
        ['class' => 'btn btn-outline-primary mb-2']
    );
}
echo html_writer::end_div();

// Status.
$statestr = get_string('state_' . ($completion->state ?? 'notstarted'), 'local_aiquizremedial');
echo html_writer::tag(
    'p',
    get_string('status_label', 'local_aiquizremedial') . ': ' . $statestr .
    ' | ' . get_string('attempts_label', 'local_aiquizremedial') . ': ' . (int) $completion->attempts_count,
    ['class' => 'aiqr-status-bar']
);

echo $OUTPUT->footer();
