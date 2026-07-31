<?php
require_once('../../config.php');

$moduleid = required_param('moduleid', PARAM_INT);
$userid   = optional_param('userid',   0, PARAM_INT); // Passed when teacher opens from teacher view.
// FIX-RL-FILTER-PERSIST (v1.2.44): teacher filter context — passed through from
// index.php so the same filter panel can be rendered here and so "Back to My
// Modules" returns the teacher to the filtered list instead of the unfiltered overview.
$filterquizid = optional_param('filterquizid', 0, PARAM_INT);
$filteruserid = optional_param('filteruserid', 0, PARAM_INT);

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
$studentResponseText  = '';

if ($sourcetype === 'knowledgecheck') {
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
                    // answer is stored 0-based (JS sends answerIndex 0-3).
                    // KC DB columns are 1-indexed: answer1..answer4.
                    // Old guard "$studentIndex1 >= 1" wrongly skipped option 0 and
                    // had an off-by-one for all other options.
                    $studentIndex0 = (int) ($ans['answer'] ?? -1);
                    if ($studentIndex0 >= 0 && $studentIndex0 <= 3) {
                        $studentResponseText = (string) ($kcquestion->{'answer' . ($studentIndex0 + 1)} ?? '');
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently continue if KC data is unavailable.
        }
    }
} else {
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
                        $originalQuestionText = $qa->get_question()->questiontext ?? '';
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

$PAGE->set_url(new moodle_url('/local/aiquizremedial/view.php', ['moduleid' => $moduleid]));
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
echo html_writer::link($backurl, get_string('backtomymodules', 'local_aiquizremedial'), ['class' => 'btn btn-secondary btn-sm mb-3 mr-2']);

// Back to Quiz / Activity button.
$activityurl = null;
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
    echo html_writer::tag('p', get_string('fromquiz', 'local_aiquizremedial', $activityname), ['class' => 'text-muted']);
}

// FIX-RL-FILTER-PERSIST (v1.2.44): inline filter panel rendered ONLY when a teacher is
// reviewing a student's module from a course context. Mirrors the panel on index.php so
// the teacher can adjust filters or jump directly to another student's module without
// returning to the overview, applying filters, and clicking back in. The form posts to
// index.php so submitting takes the teacher straight to the filtered list.
if ($teacherview && (int) $job->courseid > 0) {
    $courseid = (int) $job->courseid;

    // Distinct quizzes that have ready remedial modules in this course.
    $quizsql = "SELECT DISTINCT q.id, q.name
                  FROM {quiz} q
                  JOIN {local_aiqr_job} j ON j.quizid = q.id
                  JOIN {local_aiqr_module} m ON m.jobid = j.id
                 WHERE j.status = 'ready' AND j.courseid = :courseid
                 ORDER BY q.name";
    $availablequizzes = $DB->get_records_sql($quizsql, ['courseid' => $courseid]);

    // Distinct students with ready remedial modules (limited to the chosen quiz when set).
    $studentsql = "SELECT DISTINCT u.id, u.firstname, u.lastname
                     FROM {user} u
                     JOIN {local_aiqr_job} j ON j.userid = u.id
                     JOIN {local_aiqr_module} m ON m.jobid = j.id
                    WHERE j.status = 'ready' AND j.courseid = :courseid";
    $studentparams = ['courseid' => $courseid];
    if ($filterquizid > 0) {
        $studentsql .= " AND j.quizid = :quizid";
        $studentparams['quizid'] = $filterquizid;
    }
    $studentsql .= " ORDER BY u.lastname, u.firstname";
    $availablestudents = $DB->get_records_sql($studentsql, $studentparams);

    $formaction = new moodle_url('/local/aiquizremedial/index.php');

    echo html_writer::start_div('card mb-3 aiqr-filter-panel');
    echo html_writer::start_div('card-body py-2');
    echo html_writer::tag('h6',
        get_string('teacherviewheading', 'local_aiquizremedial'),
        ['class' => 'card-subtitle text-muted small mb-2']
    );

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $formaction->out(false),
        'class'  => 'form-inline d-flex flex-wrap align-items-center gap-2 mb-0',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);

    // Quiz filter.
    echo html_writer::start_div('form-group mr-3 mb-2');
    echo html_writer::tag('label',
        get_string('filter_by_quiz', 'local_aiquizremedial'),
        ['for' => 'aiqr-filter-quiz', 'class' => 'mr-2 font-weight-bold']
    );
    $quizoptions = [0 => get_string('filter_all_quizzes', 'local_aiquizremedial')];
    foreach ($availablequizzes as $q) {
        $quizoptions[$q->id] = format_string($q->name);
    }
    echo html_writer::select($quizoptions, 'quizid', $filterquizid, false,
        ['id' => 'aiqr-filter-quiz', 'class' => 'custom-select mr-2']);
    echo html_writer::end_div();

    // Student filter.
    echo html_writer::start_div('form-group mr-3 mb-2');
    echo html_writer::tag('label',
        get_string('filter_by_student', 'local_aiquizremedial'),
        ['for' => 'aiqr-filter-student', 'class' => 'mr-2 font-weight-bold']
    );
    $studentoptions = [0 => get_string('filter_all_students', 'local_aiquizremedial')];
    foreach ($availablestudents as $s) {
        $studentoptions[$s->id] = format_string($s->lastname . ', ' . $s->firstname);
    }
    echo html_writer::select($studentoptions, 'filteruserid', $filteruserid, false,
        ['id' => 'aiqr-filter-student', 'class' => 'custom-select mr-2']);
    echo html_writer::end_div();

    echo html_writer::tag('button',
        get_string('filter_apply', 'local_aiquizremedial'),
        ['type' => 'submit', 'class' => 'btn btn-secondary mb-2']
    );

    if ($filterquizid > 0 || $filteruserid > 0) {
        $reseturl = new moodle_url('/local/aiquizremedial/index.php', ['courseid' => $courseid]);
        echo html_writer::link($reseturl,
            get_string('filter_reset', 'local_aiquizremedial'),
            ['class' => 'btn btn-outline-secondary mb-2 ml-2']
        );
    }

    echo html_writer::end_tag('form');
    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card
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
            format_text($originalQuestionText, FORMAT_HTML),
            'aiqr-original-question'
        );
    }
    if (!empty($studentResponseText)) {
        echo html_writer::start_div('aiqr-student-response');
        echo html_writer::tag('span',
            get_string('youselected_label', 'local_aiquizremedial'),
            ['class' => 'aiqr-student-response-label']
        );
        echo html_writer::tag('span', s($studentResponseText), ['class' => 'aiqr-student-response-value']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Section 1: Explanation.
echo html_writer::start_div('card mb-3 aiqr-section-card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', get_string('section_explain', 'local_aiquizremedial'));

// Strip any stale prefixes that may exist in older DB records.
$displayExplain = $module->explain_text ?? '';
$displayExplain = preg_replace('/^Here is what you need to know\.\s*/iu', '', $displayExplain);
$displayExplain = preg_replace('/^Listen\s*&\s*Learn\s*:?\s*\n?/iu', '', $displayExplain);
$displayExplain = trim($displayExplain);

// Split out "Pro Tip:" section so it can be styled separately.
// FIX-PROTIP-REGEX (v1.2.43): Earlier fix required `\n+` (one or more literal newlines)
// before "Pro Tip:". When the AI returned the marker with only a sentence-ending period
// and a single space (no newline) the regex did not match, so "Pro Tip:" rendered as
// plain inline text inside the main paragraph. New regex splits on the literal "Pro Tip:"
// marker regardless of preceding whitespace — newlines, spaces, or punctuation all work
// as boundaries. Case-insensitive to handle "Pro tip:" / "PRO TIP:" variants too.
$proTipText = '';
if (preg_match('/^(.*?)[\s\.]*\bPro\s*Tip\s*:\s*(.+)$/sui', $displayExplain, $tipMatch)) {
    $displayExplain = trim($tipMatch[1]);
    $proTipText     = trim($tipMatch[2]);
}

// FIX-EXPLAIN-FULLSTOP (v1.2.53): Ensure the explanation paragraph ends with a
// full stop. The AI occasionally omits the terminal period, leaving an abrupt
// transition before the Pro Tip block or the image.
if (!empty($displayExplain) && !preg_match('/[.?!]\s*$/u', $displayExplain)) {
    $displayExplain .= '.';
}

echo html_writer::div(nl2br(s($displayExplain)), 'aiqr-explain-text');
if (!empty($proTipText)) {
    echo html_writer::div(
        html_writer::tag('strong', 'Pro Tip: ') . s($proTipText),
        'aiqr-pro-tip mt-2'
    );
}

if (!empty($module->explain_image_url)) {
    echo html_writer::empty_tag('img', [
        'src'   => $module->explain_image_url,
        'alt'   => get_string('explain_image_alt', 'local_aiquizremedial'),
        'class' => 'img-fluid aiqr-explain-image',
    ]);
}

// Language-aware audio player.
$translationsData = [];
if (!empty($module->translations_json)) {
    $translationsData = json_decode($module->translations_json, true) ?: [];
}

$hasAudio       = !empty($module->explain_audio_url);
$hasTranslations = !empty($translationsData);

// FIX-RL-VOICEOVER-PLAYBACK: Read the playback mode setting. Default to 'manual'
// so existing sites that have never set the option behave as before.
$voiceoverplayback = get_config('local_aiquizremedial', 'voiceoverplayback') ?: 'manual';
$autoplayEnabled   = !$teacherview && ($voiceoverplayback === 'auto');

if ($hasAudio || $hasTranslations) {
    $langNameMap = [
        'fr' => 'Fran\u00e7ais (French)',
        'es' => 'Espa\u00f1ol (Spanish)',
        'zh' => '\u4e2d\u6587 (Chinese)',
        'ar' => '\u0627\u0644\u0639\u0631\u0628\u064a\u0629 (Arabic)',
        'pt' => 'Portugu\u00eas (Portuguese)',
        'de' => 'Deutsch (German)',
        'ja' => '\u65e5\u672c\u8a9e (Japanese)',
        'ko' => '\ud55c\uad6d\uc5b4 (Korean)',
        'vi' => 'Ti\u1ebfng Vi\u1ec7t (Vietnamese)',
        'hi' => '\u0939\u093f\u0928\u094d\u0926\u0940 (Hindi)',
        'id' => 'Bahasa Indonesia',
        'it' => 'Italiano (Italian)',
    ];
    // Decode the JSON unicode escapes into actual UTF-8 strings.
    foreach ($langNameMap as $k => $v) {
        $langNameMap[$k] = json_decode('"' . $v . '"') ?: $v;
    }

    echo html_writer::start_div('aiqr-lang-controls d-flex align-items-center flex-wrap gap-2 mt-2');

    if ($hasAudio) {
        $audioAttrs = [
            'controls' => 'controls',
            'src'      => $module->explain_audio_url,
            'class'    => 'aiqr-audio-player flex-shrink-0',
            'id'       => 'aiqr-audio-main',
        ];
        if ($autoplayEnabled) {
            // autoplay is blocked by most browsers unless the page was initiated
            // by user interaction. Remedial pages are only reached via a student
            // click (from quiz review banner or module list), so autoplay fires
            // reliably. The JS fallback below handles edge cases.
            $audioAttrs['autoplay'] = 'autoplay';
        }
        echo html_writer::tag('audio', '', $audioAttrs);
    }

    if ($hasTranslations) {
        $selectOptions = html_writer::tag('option', get_string('lang_english', 'local_aiquizremedial'), ['value' => 'en', 'selected' => 'selected']);
        foreach ($translationsData as $code => $tdata) {
            $label = json_decode('"' . ($langNameMap[$code] ?? strtoupper($code)) . '"');
            $selectOptions .= html_writer::tag('option', $label, ['value' => $code]);
        }
        echo html_writer::tag('select', $selectOptions, [
            'class'       => 'form-select form-select-sm aiqr-lang-select',
            'id'          => 'aiqr-lang-select',
            'style'       => 'width:auto;min-width:160px',
            'title'       => get_string('choose_language', 'local_aiquizremedial'),
            'aria-label'  => get_string('choose_language', 'local_aiquizremedial'),
        ]);

        // Embed data and switcher script.
        // FIX-PROTIP-JS: Original setLang() dumped the full explain_text (including "Pro Tip:"
        // as plain text) into .aiqr-explain-text when switching languages — the Pro Tip
        // splitting logic was missing from the JS switcher. Fix: setLang() now mirrors the
        // PHP logic — it extracts "Pro Tip:" from the raw text and updates .aiqr-pro-tip
        // with the bold-styled Pro Tip, or hides the div if none is present. Also stores
        // the English Pro Tip text (aiqrEnProTip) so switching back to English restores it.
        $jsTranslations = json_encode($translationsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsEnText       = json_encode($displayExplain,   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsEnProTip     = json_encode($proTipText,       JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsEnAudio      = json_encode($module->explain_audio_url ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsAutoplay = $autoplayEnabled ? 'true' : 'false';
        echo html_writer::tag('script',
            'var aiqrTranslations=' . $jsTranslations . ';' .
            'var aiqrEnText=' . $jsEnText . ';' .
            'var aiqrEnProTip=' . $jsEnProTip . ';' .
            'var aiqrEnAudio=' . $jsEnAudio . ';' .
            'var aiqrAutoplay=' . $jsAutoplay . ';',
            ['type' => 'text/javascript']
        );
        echo html_writer::tag('script', '
(function(){
  function escHtml(s){
    return s.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
  }
  // Extract "Pro Tip:" from raw explain_text using same logic as PHP regex (\n+\s*Pro Tip:).
  // Returns {main: string, proTip: string}.
  function splitProTip(raw){
    var m=raw.match(/\n+\s*Pro Tip:\s*([\s\S]+)$/i);
    if(m){
      return {
        main: raw.replace(/\n+\s*Pro Tip:\s*[\s\S]+$/i,"").trim(),
        proTip: m[1].trim()
      };
    }
    return {main:raw.trim(), proTip:""};
  }
  function applyText(mainText, proTipText){
    // FIX-EXPLAIN-FULLSTOP (v1.2.53): Add missing terminal full stop for translated text.
    if(mainText && !/[.?!]\s*$/.test(mainText)){mainText+='.';}
    var txt=document.querySelector(".aiqr-explain-text");
    var proTipEl=document.querySelector(".aiqr-pro-tip");
    if(txt) txt.innerHTML=escHtml(mainText).replace(/\n/g,"<br>");
    if(proTipEl){
      if(proTipText){
        proTipEl.innerHTML="<strong>Pro Tip: <\/strong>"+escHtml(proTipText);
        proTipEl.style.display="";
      } else {
        proTipEl.style.display="none";
      }
    }
  }
  function playIfAuto(aud){
    if(aiqrAutoplay&&aud){
      // canplaythrough fires when enough data is buffered. Fallback: play()
      // immediately in case the event already fired before we attached.
      aud.addEventListener("canplaythrough",function onCpt(){
        aud.removeEventListener("canplaythrough",onCpt);
        aud.play().catch(function(){});
      },{once:true});
      aud.play().catch(function(){});
    }
  }
  function setLang(lang){
    var aud=document.getElementById("aiqr-audio-main");
    if(lang==="en"){
      applyText(aiqrEnText, aiqrEnProTip);
      if(aud&&aiqrEnAudio){aud.src=aiqrEnAudio;aud.load();playIfAuto(aud);}
    } else if(aiqrTranslations[lang]){
      var t=aiqrTranslations[lang];
      var parts=splitProTip(t.explain_text||"");
      applyText(parts.main, parts.proTip);
      if(aud){
        if(t.audio_url){aud.src=t.audio_url;aud.load();playIfAuto(aud);}
        else{aud.removeAttribute("src");}
      }
    }
  }
  document.addEventListener("DOMContentLoaded",function(){
    var sel=document.getElementById("aiqr-lang-select");
    if(sel)sel.addEventListener("change",function(){setLang(sel.value);});
  });
})();
', ['type' => 'text/javascript']);
    }

    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

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
    echo html_writer::tag('h4',
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
            $label .= ' ' . html_writer::tag('span',
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
echo html_writer::tag('p',
    get_string('status_label', 'local_aiquizremedial') . ': ' . $statestr .
    ' | ' . get_string('attempts_label', 'local_aiquizremedial') . ': ' . (int) $completion->attempts_count,
    ['class' => 'aiqr-status-bar']
);

echo $OUTPUT->footer();
