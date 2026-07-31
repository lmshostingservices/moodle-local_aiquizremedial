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
 * Version metadata for AI Quiz Remedial Learning.
 *
 * v1.2.48: FIX-RL-FEEDBACK-AUTOPLAY (submit.php).
 *   Quick Check feedback voiceover was NOT auto-playing when "Voiceover playback mode"
 *   was set to "Auto-play". Root cause: the auto-play logic introduced in v1.2.47 only
 *   applied to the module explanation audio in view.php — submit.php (which renders the
 *   correct/incorrect feedback page after a student selects an answer) never read the
 *   voiceoverplayback setting and never set the HTML5 autoplay attribute on its audio
 *   elements. Fix: submit.php now reads the voiceoverplayback setting, excludes teacher
 *   views defensively (same guard as view.php), and conditionally adds autoplay to both
 *   feedback audio paths: (1) the first-attempt feedback audio ($fb['audio_url']), and
 *   (2) the freshly-generated TTS audio for the second wrong-attempt reveal. A JS
 *   canplaythrough fallback script is emitted before the footer when autoplay is enabled,
 *   using the same pattern as view.php to handle browsers that buffer before firing play().
 *   Result: module voiceover and Quick Check feedback voiceover now both auto-play
 *   consistently when the admin setting is "Auto-play". No DB schema changes. PHP only
 *   (submit.php). version.php → 2026042200048.
 *
 * v1.2.47: FEAT-RL-VOICEOVER-PLAYBACK (settings.php, view.php, lang).
 *   New admin setting: "Voiceover playback mode" (voiceoverplayback) under Feature Options.
 *   Two options: "manual" (default — student presses play, existing behaviour preserved on
 *   all sites that never configure the setting) and "auto" (voiceover starts automatically
 *   when the student opens a Revision Module). Auto mode adds the HTML5 autoplay attribute
 *   to the <audio> element (teachers are excluded from auto-play) and also sets a JS flag
 *   (aiqrAutoplay) so the language-switcher calls aud.play() after loading a new audio
 *   source when the student changes language. A canplaythrough listener provides a reliable
 *   fallback for browsers that buffer before firing play(). No DB schema changes.
 *   PHP + lang only. version.php → 2026042200047.
 *
 * v1.2.46: FIX-PROTIP-COLOUR (styles.css). The "Pro Tip:" paragraph rendered by
 *   view.php inside <div class="aiqr-pro-tip"> had no CSS rule of its own, so it
 *   inherited Moodle's default body/muted text colour and looked grey/washed-out
 *   compared to the explanation text directly above it (.aiqr-explain-text uses
 *   colour #333). Added a .aiqr-pro-tip rule that forces the same colour (#333),
 *   font-size (1rem) and line-height (1.7) so the Pro Tip reads as part of the
 *   same content block. CSS only — no PHP, JS, AMD or DB schema changes.
 *   version.php → 2026042200046.
 *
 * v1.2.35: THREE FIX GROUPS (recording analysis 15 Apr 2026 — teacher overview).
 *   BUG-REM-CREDITS-FAILED (credits_client.php): credits were charged BEFORE calling
 *       generate_text_remediation(). When the AI API returned no explain_text the fallback
 *       placeholder ("Remediation content could not be generated...") was stored as a valid
 *       module and 8 credits were consumed for nothing. Fix: (1) generate_text_remediation()
 *       now throws \moodle_exception('remediationfailed') instead of returning the fallback
 *       string — process_jobs.php catch block sets the job to status='failed', no module
 *       record is created. (2) charge_credits() is called AFTER generate_text_remediation()
 *       succeeds, so a generation failure never reaches the charge call. New lang string:
 *       remediationfailed. No DB schema changes.
 *   BUG-REM-STATUS-FAILED (index.php): failed question-level jobs (j.status='failed') were
 *       not shown in the teacher overview because the SQL filtered WHERE j.status='ready'.
 *       Teachers could not tell why a student was missing a module. Fix: a second query
 *       fetches all failed question-level jobs for the same course/quiz/student filters and
 *       renders them as cards with a red "Generation Failed" badge (badge-danger), "0 credits
 *       used", and no Review Module button — visually distinct from "Not Started" (grey).
 *       New lang strings: state_failed, generation_failed_desc. No DB schema changes.
 *   BUG-REM-QUESTION-PREVIEW (index.php): the card preview in the teacher overview was the
 *       first 150 chars of explain_text, which always starts "The correct answer is [X]…"
 *       — revealing the answer in the list and giving no indication of WHICH question the
 *       student got wrong. Fix: both SQL queries now LEFT JOIN {question} qq ON qq.id=j.questionid
 *       and select qq.name AS question_name. Each card now shows "Question: [name]" as a
 *       subtitle below the quiz name. The explain_text preview is removed from the list view
 *       (teacher clicks Review Module to read the full content). New lang string: question_label.
 *       No DB schema changes. version.php → 2026041500035.
 *
 * v1.2.34: TWO FIX GROUPS.
 *   FIX-RL-TEACHER-FOOTER (before_footer.php): Teachers viewing a quiz page (mod-quiz-view or
 *       mod-quiz-report) now see a "View Remedial Learnings" button injected by the before_footer
 *       hook. The button shows how many ready remedial modules exist for that quiz and links to
 *       /local/aiquizremedial/index.php?quizid=X&courseid=Y so teachers can review all remedial
 *       modules generated for students in that quiz. Tester request from video session 14 Apr 2026:
 *       "when they see the attempts of the students they should have an extra button over here".
 *       No DB schema changes. PHP only (before_footer.php). version.php → 2026041400034.
 *   FIX-RL-QUIZID-HEADING (index.php): When index.php is opened with ?quizid=X, the page heading
 *       now shows the quiz name ("Remedial Learnings — [Quiz Name]") instead of the generic
 *       teacherviewheading string so the teacher knows which quiz they are reviewing.
 *       No DB schema changes. PHP only (index.php). version.php → 2026041400034.
 *
 * v1.2.30: MULTI-LANGUAGE SUPPORT.
 *   New feature: teachers can select additional content languages in plugin settings
 *   (Site Administration > Plugins > AI Quiz Remedial Learning). Each extra language
 *   generates a translated version of the explanation text and a new voiceover in that
 *   language using GPT-4o translation + Google Cloud WaveNet TTS. Each additional language
 *   costs 5 credits per question. All translations are generated before the slide becomes
 *   available, exactly like the English voiceover. Student view: a "Choose language" dropdown
 *   appears next to the audio player allowing students to switch text and voiceover on the fly
 *   with no page reload. DB: added translations_json (TEXT NULLABLE) to local_aiqr_module.
 *   version.php → 2026041000130.
 *
 * v1.2.29: FIX-VOICEOVER-MISMATCH.
 *   submit.php second wrong attempt: the stored $correctFb['audio_url'] was generated at
 *   content-creation time from "That's correct. [explanation]". The on-screen text strips
 *   "That's correct." and prepends "Your answer is incorrect." so audio contradicted text.
 *   Fix: added local_aiquizremedial_tts_generate(string $text): ?string helper to lib.php.
 *   submit.php now calls it with the plain-text reveal sentence (no s() encoding) so audio
 *   exactly matches what is displayed. Voiceover guard: credit_calculator::is_voiceover_enabled()
 *   is checked before every TTS call; silent fallback (no audio element) if disabled or fails.
 *   No DB schema changes. version.php → 2026041000129.
 *
 * v1.2.28: THREE CONTENT QUALITY FIXES.
 *   FIX-1 (routes.ts + view.php): "Here is what you need to know. Listen & Learn:" prefix removed.
 *       routes.ts no longer injects the prefix and strips any AI-echoed "Listen & Learn:" from
 *       explain_text before storing. view.php strips old prefixes at display time for backwards
 *       compatibility. Pro Tip section now rendered as a styled bold paragraph, separated from
 *       the main text, with nl2br for proper line breaks.
 *   FIX-2 (submit.php): Second wrong attempt reveal sentence changed from awkward
 *       "Your answer is not correct, the Stick is The stick is used..." to clean
 *       "Your answer is incorrect. The stick is used by players to hit and control the puck."
 *       Removed redundant "the [X] is" prefix — explanation already names the correct answer.
 *       Also changed "not correct" to "incorrect" throughout.
 *   FIX-3 (question_payload.php): Standard Moodle quiz multichoice questions sent
 *       {answer: 1} (option index) with no answertext field. routes.ts fell back to
 *       Object.values({answer:1}) = "1" → AI generated "1 is incorrect". Fix: added
 *       get_response_summary() call to resolve the index to the actual option text (e.g. "Bat")
 *       before building the API payload. AI now uses the real option name.
 *   Also: AI prompt updated — "Listen & Learn:" removed from explain_text template,
 *       feedback "not correct because" changed to "incorrect because", new rule explicitly
 *       bans bare number references like "1 is incorrect".
 *   No DB schema changes. version.php → 2026040900128.
 *
 * v1.2.25: TWO BUG FIXES.
 *   FIX-1 (submit.php): Second wrong attempt (Quick Answer) now shows a single clean sentence:
 *       "Your answer is not correct, the [correct answer] is [correct explanation]."
 *       Replaces the old two-section (wrong-explain + correct-reveal heading) approach that
 *       the tester found confusing. The correct explanation is extracted from the Correct
 *       feedback item with the "That's correct." prefix stripped.
 *   FIX-2 (server/routes.ts + voiceover_script prompt): Voiceover newline stripping added.
 *       The PHP TTS player was stopping at the first newline character in voiceover_script,
 *       reading only the first line. Server now replaces all \n with spaces and collapses
 *       whitespace runs before returning. Prompt also updated to explicitly ban newlines.
 *   No DB schema changes. PHP + server fix. version.php → 2026040900125.
 *
 * v1.2.21: TWO BUG FIXES.
 *   FIX-1 (submit.php): "Back to My Modules" link (index.php) added alongside the existing
 *       "Back to Module" link. Previously students had no direct route back to their full
 *       module list from the Quick Check feedback page.
 *   FIX-2 (server/routes.ts): explain_text system prompt strengthened — Listen & Learn must
 *       NOT begin with a question, must NOT mirror the quiz question wording, and must NOT
 *       copy language from check_question.prompt. Correct Concept section now starts with
 *       teaching language ("The key concept here is...").
 *   No DB schema changes. PHP/server only. version.php → 2026040700121.
 *
 * v1.2.19: BUG FIX (FIX-QRL-STUDENT-ANSWER-INDEX): view.php displayed a raw numeric
 *          index (e.g. "0", "1") instead of the student's chosen answer text in teacher
 *          review when the source question type is AI Knowledge Check (KC). Root cause:
 *          (1) The guard was "$studentIndex1 >= 1" which skipped index 0 — any student
 *          who selected the first answer option had their response silently dropped.
 *          (2) The column access used 'answer' . $studentIndex1 (0-based from JS) but
 *          the KC DB columns are 1-indexed (answer1..answer4), causing an off-by-one
 *          for all options. Fix: renamed variable to $studentIndex0; guard changed to
 *          >= 0 && <= 3; column access changed to 'answer' . ($studentIndex0 + 1).
 *          No DB schema changes. PHP-only fix: view.php. version.php → 2026040300119.
 *
 * v1.2.18: BUG FIX (FIX-QRL-EXPLAIN): Three bugs in teacher review panel fixed:
 *          (1) view.php read $check['correct'] but the JSON uses key 'correct_index' — correctindex
 *          was always -1 so no option was highlighted as correct in teacher review.
 *          (2) .aiqr-option-correct CSS class was applied in view.php but never defined in
 *          styles.css — no green highlight was visible. (3) Bootstrap 4 badge classes
 *          (badge-success, ml-1) used in Moodle 4.x (Bootstrap 5); updated to bg-success
 *          text-white ms-1. No DB schema changes. PHP + CSS only. version.php → 2026040200118.
 *
 * v1.2.17: INVESTIGATION — tester feedback review completed. Code audit confirmed
 *          question_payload.php sends structured question+student answer+correct answer
 *          to the server; server prompt is anchored (temperature 0.3, structured JSON
 *          schema, minimum explanation validation). No code issues found. Maintenance
 *          version bump. No DB schema changes. version.php → 2026033101700.
 *
 * v1.2.15: VERSION BUMP — Clean maintenance release. No code, DB schema, or AMD changes.
 *          All 6 sync locations updated. version.php → 2026033001500.
 *
 * v1.2.14: GENERATION RELIABILITY + TEACHER FILTER FIXES — cleanRemediationInput() strips
 *          Option A/B/C/D: and VOICEOVER: markers; temperature 0.6→0.3, max_tokens 6000→1500;
 *          explain_text validation; .aiqr-explain-image max-height; quizid + userid teacher
 *          filters in index.php. No DB schema changes. version.php → 2026033001214.
 *
 * v1.2.13: BUG FIX — (1) CSS max-width/max-height constraint on explain images prevents
 *          oversized images from breaking the remedial review layout. (2) "Back to Quiz"
 *          button added to view.php: for KC-type remedial modules the cmid of the originating
 *          quiz is looked up from the DB so students can return directly without navigating
 *          manually. New lang string: backtoquiz. No DB schema changes. version.php → 2026032801201.
 *
 * v1.2.12: FIX-IMAGE-CREDIT-PRECHECK — /api/image/generate had a secondary credit balance
 *          reduced the balance. For accounts with exactly enough credits the post-charge balance
 *          triggered the secondary check, returning 402 and silently preventing image generation.
 *          Secondary check removed; authentication via API key + siteId is the correct gate for
 *          pre-charged endpoints. No DB schema changes. version.php → 2026032701200.
 *
 * v1.2.11: FIX-TEACHER-REVIEW-BLANK — view.php loaded completion record for $USER->id
 *          (the teacher) instead of the student's userid when a teacher clicked "Review Module".
 *          Result: teacher saw their own completion (not started) and got a blank interactive
 *          check-question form — they could not see what the student had answered.
 *          Fix: $teacherview flag set when $userid param > 0 and viewer has viewall capability.
 *          $subjectuserid drives the completion lookup. In teacher-review mode: no new completion
 *          record is created; the quick check section renders read-only (question + options with
 *          correct answer highlighted) and shows the student's completion state as an alert.
 *          Five new lang strings: teacherreview_readonly, teacherreview_correctanswer,
 *          teacherreview_studentcomplete, teacherreview_studentinprogress,
 *          teacherreview_studentnotstarted. version.php → 2026032700403.
 *
 * v1.2.8: FIX-TEACHER-BUTTON-LABEL — Teachers were shown "Start Fix Module" button
 *         on index.php in both teacher-overview (Mode 2) and specific-student (Mode 1)
 *         views. The label implies the teacher is beginning a learning activity, which
 *         is misleading — they are reviewing a student's module. Fixed: index.php now
 *         shows "Review Module" (viewmodule_teacher lang string) whenever $canviewall
 *         is true. New lang string added: $string['viewmodule_teacher']. version.php → 2026032601408.
 *
 * v1.2.7: FIX-TEACHER-NO-COURSEID — Two compounding bugs caused teachers to see
 *         "No remedial learning modules yet" even when students had generated fix modules:
 *         BUG 1: Mode 2 (teacher overview) required $courseid > 0. When the teacher
 *         navigated to /local/aiquizremedial/index.php with no URL params, $courseid=0
 *         so the condition was false — code fell through to Mode 3 (own modules).
 *         The teacher has no quiz attempts themselves → empty list.
 *         BUG 2: Teacher SQL hardcoded "AND j.courseid = :courseid" always, so even if
 *         Mode 2 had been reached with courseid=0, the WHERE clause matched no rows
 *         (real course IDs start at 1).
 *         FIXES: (1) Mode 2 condition changed from ($canviewall && $userid===0 && $courseid>0)
 *         to ($canviewall && $userid===0) — teacher mode always activates for any teacher
 *         without a specific userid. (2) courseid WHERE clause is now conditional in
 *         teacher SQL (only added when $courseid > 0). ALSO: ajax.php PARAM_ALPHA →
 *         PARAM_ALPHANUMEXT (future-proofing, same fix as aiactivities v1.5.56).
 *         version.php → 2026032601407.
 *
 * v1.2.5: TEACHER VIEW FIX — index.php always queried $USER->id, so when a teacher
 *         navigated to /local/aiquizremedial/index.php (with or without ?courseid=X)
 *         they saw "No remedial learning modules yet" because the teacher has no quiz
 *         attempt jobs themselves. Three modes now supported:
 *         (1) ?userid=X with viewall capability → specific student's modules.
 *         (2) ?courseid=X with viewall (no userid) → all students in course, with
 *             student names shown above each card.
 *         (3) Default → own modules (unchanged student behaviour).
 *         view.php updated to accept ?userid param and pass it through the back link
 *         so teachers return to the correct view after reading a student's module.
 *         Four lang strings added: teacherviewheading, teacherstudentview,
 *         student_label, nomodules_course. version.php → 2026032501405.
 *
 * v1.2.0: AI Knowledge Check integration — remediation modules are now generated for
 *         questions answered incorrectly in AI Knowledge Check (mod_aiknowledgecheck)
 *         activities, in addition to the existing standard Moodle™ Quiz integration.
 *         DB: added sourcetype (CHAR 20, default 'quiz') and kcid (INT) fields to
 *         local_aiqr_job. New kc_question_payload class builds the AI payload from
 *         aiknowledgecheck_questions / aiknowledgecheck_attempts tables.
 *         process_jobs task branches on sourcetype='knowledgecheck'.
 *         view.php and index.php show KC activity name and KC question context.
 *
 * v1.1.7: VOICEOVER CONTENT MISMATCH FIX — TTS was using AI-generated voiceover_script
 *         which could differ from explain_text (the text displayed to the student), causing
 *         audio to read words not on screen. Fix: always strip HTML from explain_text and use
 *         that as the TTS source for both main explanation and feedback items. voiceover_script
 *         field is intentionally ignored going forward.
 *
 * v1.1.6: Bug fixes — (1) Banner count and index.php now filter by current attempt ID only, not all
 *         prior attempts. (2) Fix module view now shows the original question and student's selected
 *         wrong answer before the AI explanation. (3) Server TTS MIME-type double-prefix fixed
 *         ("audio/audio/ogg" -> "audio/ogg") so voiceover audio plays correctly.
 * v1.1.1: Migrated before_footer callback from legacy lib.php function to new Moodle hook system
 *         (core\hook\output\before_footer_html_generation). Fixes deprecation warning on Moodle 4.4+.
 * v1.1.0: Global enable/disable toggle, quiz review banner, and show-on-review setting.
 * v1.0.1: Removed client-facing credit cost and batch size settings. Costs are now hardcoded (text=2, voiceover=+2, image=+4, batch=10).
 *
 * @package    local_aiquizremedial
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_aiquizremedial';
$plugin->version   = 2026072300208;
$plugin->requires  = 2022041900; // Moodle 4.0.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.2.57';
