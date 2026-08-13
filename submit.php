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

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

$moduleid = required_param('moduleid', PARAM_INT);
require_sesskey();

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

$completion = $DB->get_record('local_aiqr_completion', ['moduleid' => $moduleid, 'userid' => $USER->id]);
if (!$completion) {
    $completion = (object) [
        'moduleid'       => $moduleid,
        'userid'         => $USER->id,
        'state'          => 'notstarted',
        'attempts_count' => 0,
        'completed_at'   => null,
        'timecreated'    => time(),
        'timemodified'   => time(),
    ];
    $completion->id = $DB->insert_record('local_aiqr_completion', $completion);
}

$check    = json_decode($module->check_question_json, true);
$feedback = json_decode($module->feedback_json, true);

$choice  = required_param('choice', PARAM_INT);
$correct = (int) ($check['correct_index'] ?? -1);

$completion->attempts_count = (int) $completion->attempts_count + 1;
$completion->state          = 'inprogress';
$completion->timemodified   = time();

$isCorrect = ($choice === $correct);

if ($isCorrect || $completion->attempts_count >= 2) {
    $completion->state        = 'complete';
    $completion->completed_at = time();
}

$DB->update_record('local_aiqr_completion', $completion);

// Build the activity URL so we can offer a "Back to Quiz / Activity" button on the result page.
$activityurl = null;
$sourcetype  = $job->sourcetype ?? 'quiz';
if ($sourcetype === 'knowledgecheck' && !empty($job->kcid)) {
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
        // Silently continue.
    }
} else if (!empty($job->quizid)) {
    $activityurl = new moodle_url('/mod/quiz/view.php', ['q' => $job->quizid]);
}

$PAGE->set_url(new moodle_url('/local/aiquizremedial/submit.php', ['moduleid' => $moduleid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('feedback_title', 'local_aiquizremedial'));
$PAGE->set_heading(get_string('feedback_title', 'local_aiquizremedial'));
$PAGE->set_pagelayout('standard');

// FIX-RL-FEEDBACK-AUTOPLAY: Read the playback mode setting so feedback audio
// auto-plays when the admin has enabled it. Teachers are excluded (they never
// reach submit.php via normal flow, but we guard defensively).
$voiceoverplayback = get_config('local_aiquizremedial', 'voiceoverplayback') ?: 'manual';
$isTeacherView     = ((int) $job->userid !== (int) $USER->id)
                     && has_capability('local/aiquizremedial:viewall', $context);
$autoplayEnabled   = !$isTeacherView && ($voiceoverplayback === 'auto');

echo $OUTPUT->header();

if ($isCorrect) {
    echo html_writer::tag('h2', get_string('correct_heading', 'local_aiquizremedial'), ['class' => 'aiqr-result-correct']);
} else {
    echo html_writer::tag('h2', get_string('incorrect_heading', 'local_aiquizremedial'), ['class' => 'aiqr-result-incorrect']);
}

$fb = $feedback[$choice] ?? null;

// FIX-QR-QA-FORMAT: On the second wrong attempt, show a single clean sentence that
// immediately reveals the correct answer in the format:
// "Your answer is not correct, the [correct answer] is [correct explanation]."
// This replaces the old two-section (wrong-explain + correct-reveal) approach.
if (!$isCorrect && $completion->attempts_count >= 2) {
    $options     = $check['options'] ?? [];
    $correctText = isset($options[$correct]) ? (string) $options[$correct] : '';
    $correctFb   = $feedback[$correct] ?? null;

    // Strip the "That's correct." prefix from the correct feedback explanation.
    $correctExplain = '';
    if ($correctFb && !empty($correctFb['explain'])) {
        $correctExplain = preg_replace('/^That\'?s correct\.?\s*/i', '', $correctFb['explain']);
        $correctExplain = trim($correctExplain);
    }

    // Build the unified reveal sentence.
    // Use the explanation directly (it already names the correct answer and explains it),
    // so there is no need to prepend "the [X] is" — that creates a doubled/awkward phrase.
    $revealSentence = '';
    if (!empty($correctExplain)) {
        $revealSentence = 'Your answer is incorrect. ' . s($correctExplain);
    } elseif (!empty($correctText)) {
        $revealSentence = 'Your answer is incorrect. The correct answer is ' . s($correctText) . '.';
    }

    echo html_writer::start_div('card mb-3 aiqr-section-card border-warning');
    echo html_writer::start_div('card-body');

    if (!empty($revealSentence)) {
        echo html_writer::tag('p', $revealSentence, ['class' => 'aiqr-feedback-text mb-0']);
    } elseif ($fb && !empty($fb['explain'])) {
        echo html_writer::div(format_text($fb['explain'], FORMAT_HTML), 'aiqr-feedback-text');
    }

    // v1.2.29 FIX-VOICEOVER-MISMATCH: The stored $correctFb['audio_url'] was generated
    // at content-creation time from "That's correct. [explanation]". The displayed
    // $revealSentence strips "That's correct." and prepends "Your answer is incorrect."
    // so playing the stored audio creates an audio/text mismatch (audio says "Correct.",
    // screen says "Incorrect.").
    //
    // Fix: generate fresh TTS from the same plain-text reveal string that is displayed,
    // so audio and text are guaranteed to match. If voiceover is disabled or TTS fails,
    // show no audio player (silence is better than "Correct." playing on an incorrect answer).
    $attempt2AudioUrl = '';
    if (\local_aiquizremedial\credit_calculator::is_voiceover_enabled()) {
        // Build the plain-text TTS input — same content as $revealSentence but without
        // the s() HTML encoding that is applied for on-screen display.
        $ttsPlainText = '';
        if (!empty($correctExplain)) {
            $ttsPlainText = 'Your answer is incorrect. ' . $correctExplain;
        } elseif (!empty($correctText)) {
            $ttsPlainText = 'Your answer is incorrect. The correct answer is ' . $correctText . '.';
        }
        if (!empty($ttsPlainText)) {
            $attempt2AudioUrl = local_aiquizremedial_tts_generate($ttsPlainText) ?? '';
        }
    }
    if (!empty($attempt2AudioUrl)) {
        $attempt2Attrs = [
            'controls' => 'controls',
            'src'      => $attempt2AudioUrl,
            'class'    => 'aiqr-audio-player mt-2',
            'id'       => 'aiqr-feedback-audio',
        ];
        if ($autoplayEnabled) {
            $attempt2Attrs['autoplay'] = 'autoplay';
        }
        echo html_writer::tag('audio', '', $attempt2Attrs);
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

} else if ($fb) {
    // First wrong attempt (or correct) — show only the student's feedback card.
    echo html_writer::start_div('card mb-3 aiqr-section-card');
    echo html_writer::start_div('card-body');
    echo html_writer::div(format_text($fb['explain'] ?? '', FORMAT_HTML), 'aiqr-feedback-text');

    if (!empty($fb['audio_url'])) {
        $fbAudioAttrs = [
            'controls' => 'controls',
            'src'      => $fb['audio_url'],
            'class'    => 'aiqr-audio-player',
            'id'       => 'aiqr-feedback-audio',
        ];
        if ($autoplayEnabled) {
            $fbAudioAttrs['autoplay'] = 'autoplay';
        }
        echo html_writer::tag('audio', '', $fbAudioAttrs);
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($completion->state === 'complete') {
    echo html_writer::div(
        get_string('module_completed', 'local_aiquizremedial'),
        'alert alert-success'
    );
    // Prominent navigation buttons after completion.
    echo html_writer::start_div('mt-3 d-flex flex-wrap gap-2');
    if ($activityurl) {
        echo html_writer::link(
            $activityurl,
            get_string('backtoquiz', 'local_aiquizremedial'),
            ['class' => 'btn btn-primary mr-2 mb-2']
        );
    }
    $mymodulesurl = new moodle_url('/local/aiquizremedial/index.php', ['courseid' => $job->courseid]);
    echo html_writer::link($mymodulesurl, get_string('backtomymodules', 'local_aiquizremedial'), ['class' => 'btn btn-secondary mr-2 mb-2']);
    echo html_writer::end_div();
} else {
    echo html_writer::div(
        get_string('tryagain', 'local_aiquizremedial'),
        'alert alert-info'
    );
    // Navigation buttons when not yet complete.
    echo html_writer::start_div('mt-3 d-flex flex-wrap gap-2');
    $returnurl = new moodle_url('/local/aiquizremedial/view.php', ['moduleid' => $moduleid]);
    echo html_writer::link($returnurl, get_string('backtomodule', 'local_aiquizremedial'), ['class' => 'btn btn-primary mr-2 mb-2']);
    $mymodulesurl = new moodle_url('/local/aiquizremedial/index.php', ['courseid' => $job->courseid]);
    echo html_writer::link($mymodulesurl, get_string('backtomymodules', 'local_aiquizremedial'), ['class' => 'btn btn-outline-secondary mb-2']);
    echo html_writer::end_div();
}

// FIX-RL-FEEDBACK-AUTOPLAY: JS canplaythrough fallback — mirrors the same
// pattern used in view.php for the module voiceover. Browsers that block
// the HTML autoplay attribute will still play once enough data is buffered.
if ($autoplayEnabled) {
    echo html_writer::tag('script', '
(function (){
  var aud = document.getElementById("aiqr-feedback-audio");
  if (!aud) { return; }
  aud.addEventListener("canplaythrough", function onCpt() {
    aud.removeEventListener("canplaythrough", onCpt);
    aud.play().catch(function (){});
  }, {once: true});
  aud.play().catch(function (){});
})();
', ['type' => 'text/javascript']);
}

echo $OUTPUT->footer();
