<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_aiquizremedial_upgrade($oldversion) {
    global $DB, $CFG;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026030112) {
        // v1.1.2: No DB schema changes. Bug fixes only:
        // - Added /api/tts/generate server endpoint (voiceover audio was silently failing).
        // - Removed double credit deduction from /api/image/generate.
        // - require_login() moved before DB access in view.php and submit.php.
        // - Eliminated N+1 quiz name query in index.php via JOIN.
        // - Added curl timeouts to all API calls in credits_client.php.
        upgrade_plugin_savepoint(true, 2026030112, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026031900200) {
        // v1.2.0: Add sourcetype and kcid fields to local_aiqr_job to support
        // AI Knowledge Check (mod_aiknowledgecheck) as a remediation source in
        // addition to the standard Moodle™ Quiz (mod_quiz).
        $table = new xmldb_table('local_aiqr_job');

        $sourcetypeField = new xmldb_field('sourcetype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'quiz', 'questionid');
        if (!$dbman->field_exists($table, $sourcetypeField)) {
            $dbman->add_field($table, $sourcetypeField);
        }

        $kcidField = new xmldb_field('kcid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'sourcetype');
        if (!$dbman->field_exists($table, $kcidField)) {
            $dbman->add_field($table, $kcidField);
        }

        upgrade_plugin_savepoint(true, 2026031900200, 'local', 'aiquizremedial');
    }

    // v1.2.1: VERSION BUMP — Maintenance release. No DB schema changes.
    if ($oldversion < 2026032001401) {
        upgrade_plugin_savepoint(true, 2026032001401, 'local', 'aiquizremedial');
    }

    // v1.2.2: VERSION BUMP — Maintenance release. No DB schema changes.
    if ($oldversion < 2026032101402) {
        upgrade_plugin_savepoint(true, 2026032101402, 'local', 'aiquizremedial');
    }

    // v1.2.3: VERSION BUMP — Maintenance release. No DB schema changes.
    if ($oldversion < 2026032201403) {
        upgrade_plugin_savepoint(true, 2026032201403, 'local', 'aiquizremedial');
    }

    // v1.2.4: VERSION BUMP — Maintenance release. No DB schema changes.
    if ($oldversion < 2026032401404) {
        upgrade_plugin_savepoint(true, 2026032401404, 'local', 'aiquizremedial');
    }

    // v1.2.5: TEACHER-VIEW FIX — index.php hardcoded $USER->id in module query.
    //   Now supports ?userid=X&viewall → specific student; ?courseid=X&viewall →
    //   all students in course (with names); default → own modules. No DB schema changes.
    if ($oldversion < 2026032501405) {
        upgrade_plugin_savepoint(true, 2026032501405, 'local', 'aiquizremedial');
    }

    // v1.2.6: VERSION BUMP — Sync release. All version sources aligned.
    //   No DB schema changes.
    if ($oldversion < 2026032501406) {
        upgrade_plugin_savepoint(true, 2026032501406, 'local', 'aiquizremedial');
    }

    // v1.2.7: FIX-TEACHER-NO-COURSEID — index.php Mode 2 required $courseid > 0
    //   (teachers visiting without URL params fell to Mode 3, saw own empty list).
    //   Teacher SQL also hardcoded AND j.courseid = :courseid unconditionally (courseid=0
    //   matched nothing). ajax.php PARAM_ALPHA → PARAM_ALPHANUMEXT. No DB schema changes.
    if ($oldversion < 2026032601407) {
        upgrade_plugin_savepoint(true, 2026032601407, 'local', 'aiquizremedial');
    }

    // v1.2.8: TEACHER-VIEW BUTTON LABEL — backfill missing savepoint.
    //   v1.2.8 added $string['viewmodule_teacher'] lang string so teachers see "Review Module"
    //   instead of the student-facing button label. No DB schema changes.
    if ($oldversion < 2026032601408) {
        upgrade_plugin_savepoint(true, 2026032601408, 'local', 'aiquizremedial');
    }

    // v1.2.9: LANGUAGE — All student/teacher-facing "Fix Module" terminology replaced with
    //   "Revision Module" throughout the lang file. "Start Fix Module" → "Start Learning Revision",
    //   "Fix Modules Available" → "Revision Modules Available", "Let's fix this" → "Let's revisit
    //   this", "Fix module completed!" → "Revision complete!", and related strings updated.
    //   No PHP logic changes, no DB schema changes.
    if ($oldversion < 2026032700401) {
        upgrade_plugin_savepoint(true, 2026032700401, 'local', 'aiquizremedial');
    }

    // v1.2.10: VERSION BUMP — Rebump for release alignment following hardened release process.
    //   No functional changes, no lang changes, no DB schema changes.
    if ($oldversion < 2026032700402) {
        upgrade_plugin_savepoint(true, 2026032700402, 'local', 'aiquizremedial');
    }

    // v1.2.11: FIX-TEACHER-REVIEW-BLANK — view.php was loading completion for $USER->id (teacher)
    //   instead of the student's userid when a teacher clicked "Review Module". Teacher saw a blank
    //   fresh check-question form. Fixed: $teacherview flag + $subjectuserid drive completion lookup;
    //   teacher-review mode renders read-only check question with correct-answer highlight and student
    //   completion status. Five new lang strings added. No DB schema changes.
    if ($oldversion < 2026032700403) {
        upgrade_plugin_savepoint(true, 2026032700403, 'local', 'aiquizremedial');
    }

    // v1.2.12: FIX-IMAGE-CREDIT-PRECHECK — /api/image/generate had a secondary credit balance
    //   check that fired after the plugin's pre-charge via /api/credits/consume had already
    //   reduced the balance. For accounts with exactly enough credits the check always returned
    //   a 402 error, silently preventing image generation entirely. Secondary check removed;
    //   authentication via API key + siteId is the correct gate. No DB schema changes.
    if ($oldversion < 2026032701200) {
        upgrade_plugin_savepoint(true, 2026032701200, 'local', 'aiquizremedial');
    }

    // v1.2.13: BUG FIX — (1) CSS-IMAGE-OVERFLOW: max-width/max-height added to explain images.
    //   (2) BACK-TO-QUIZ: button added to view.php; KC-type modules look up originating quiz
    //   cmid from DB. New lang string: backtoquiz. No DB schema changes.
    if ($oldversion < 2026032801201) {
        upgrade_plugin_savepoint(true, 2026032801201, 'local', 'aiquizremedial');
    }

    // v1.2.14: GENERATION RELIABILITY + TEACHER FILTER FIXES.
    //   SERVER FIX 1 — Input cleaning: strips "Option A/B/C/D:" and VOICEOVER: markers
    //     from the question text + student answer before sending to AI (routes.ts).
    //   SERVER FIX 2+3 — Focused prompt + stable AI settings: temperature lowered
    //     0.6 → 0.3, max_tokens 6000 → 1500, simpler JSON-focused system prompt.
    //   SERVER FIX 5 — Validation: explain_text < 20 chars throws; correctIndex
    //     clamped to 0-3; feedback padded to 4 items instead of throwing.
    //   PLUGIN FIX 7 — CSS: .aiqr-explain-image gains max-height: 300px and
    //     object-fit: contain to prevent tall images overflowing the card.
    //   PLUGIN FIX 10 — Teacher filter: index.php accepts ?quizid=X and filters
    //     both teacher overview SQL and single-user SQL by quiz when provided.
    //   PLUGIN FIX 11 — Student filter: teacher overview (teachermode) also
    //     filters by ?userid=X in the SQL when a userid param is present.
    //   No DB schema changes. version.php → 2026033001214.
    if ($oldversion < 2026033001214) {
        upgrade_plugin_savepoint(true, 2026033001214, 'local', 'aiquizremedial');
    }

    // v1.2.15: VERSION BUMP — Clean maintenance release. No code, DB schema, or AMD changes.
    //   All 6 sync locations updated. version.php → 2026033001500.
    if ($oldversion < 2026033001500) {
        upgrade_plugin_savepoint(true, 2026033001500, 'local', 'aiquizremedial');
    }

    // v1.2.16: INVESTIGATION — tester feedback review completed. Code audit confirmed
    //   structured payload (question+student answer+correct answer), server temperature 0.3,
    //   structured JSON prompt, minimum explanation validation. No code issues found.
    //   Maintenance version bump. No DB schema changes. version.php → 2026033101600.
    if ($oldversion < 2026033101600) {
        upgrade_plugin_savepoint(true, 2026033101600, 'local', 'aiquizremedial');
    }

    // v1.2.17: FIX — Strengthened quiz remediation generation on the server.
    //   (1) check_question prompt changed from "a realistic scenario question" to
    //   "a reworded version of the ORIGINAL question — same scenario, different phrasing
    //   only". Added explicit constraints: DO NOT invent a new scenario, DO NOT change
    //   context, ONLY reword the given question. Prevents AI from inventing new scenarios
    //   unrelated to the original quiz question.
    //   (2) Temperature lowered from 0.3 → 0.2. At 0.3 the model sometimes drifted and
    //   invented new contexts; at 0.2 output stays deterministic and anchored to the question.
    //   (3) explain_text minimum validation raised from 20 → 25 chars.
    //   (4) Added backend validation: check_question.prompt must be >= 10 chars (not empty).
    //   (5) Image generation (/api/image/generate) now uses ORIGINAL QUESTION TEXT as primary
    //   Gemini input, not the explain_text. Previously the explanation drove image generation,
    //   causing images to illustrate the wording of the explanation rather than the actual
    //   quiz question scenario. No DB schema changes. version.php → 2026033101700.
    if ($oldversion < 2026033101700) {
        upgrade_plugin_savepoint(true, 2026033101700, 'local', 'aiquizremedial');
    }

    // v1.2.18 FIX-QRL-EXPLAIN: Three bugs in teacher review panel:
    //   (1) view.php read $check['correct'] but the JSON uses key 'correct_index'
    //       (matching submit.php). Mismatch meant correctindex was always -1 so no
    //       option was ever highlighted as correct in teacher review.
    //   (2) .aiqr-option-correct CSS class applied in view.php but never defined
    //       in styles.css — no green highlight visible.
    //   (3) Bootstrap 4 badge classes (badge-success, ml-1) used in Moodle 4.x
    //       which ships Bootstrap 5; updated to bg-success text-white ms-1.
    //   No DB schema changes. PHP + CSS only. version.php → 2026040200118.
    if ($oldversion < 2026040200118) {
        upgrade_plugin_savepoint(true, 2026040200118, 'local', 'aiquizremedial');
    }

    // v1.2.19: FIX-QRL-STUDENT-ANSWER-INDEX — view.php displayed a raw numeric index
    //   (e.g. "0", "1") instead of the student's chosen answer text in teacher review
    //   when the source question type is AI Knowledge Check (KC).
    //   Root cause: (1) The guard "$studentIndex1 >= 1" skipped index 0 — any student
    //   who selected the first answer option had their response silently omitted.
    //   (2) Column access used 'answer' . $studentIndex1 (0-based from JS) but the KC DB
    //   columns are 1-indexed (answer1..answer4), causing an off-by-one for all options.
    //   Fix: renamed to $studentIndex0; guard changed to >= 0 && <= 3; column access to
    //   'answer' . ($studentIndex0 + 1). No DB schema changes. PHP-only: view.php.
    //   version.php → 2026040300119.
    if ($oldversion < 2026040300119) {
        upgrade_plugin_savepoint(true, 2026040300119, 'local', 'aiquizremedial');
    }

    // v1.2.20: THREE FIXES. FIX-1 (server/routes.ts): studentAnswerStr extraction now uses
    //   the answertext key from the KC payload object ({answer: N, answertext: "text"})
    //   instead of joining all Object.values() — previously produced "0; Option text" which
    //   caused the AI to generate "You chose 0" in explain_text. FIX-2 (server/routes.ts):
    //   explain_text system prompt upgraded to a structured 5-section format: Correct Concept /
    //   Your Answer / Correct Answer / Real-World Example / Pro Tip. feedback system prompt
    //   rewritten — correct starts with "That's correct.", incorrect starts with "Your answer
    //   is not correct because", and "Option A/B/C/D" labels are explicitly banned. FIX-3
    //   (submit.php): second wrong attempt now reveals the correct answer text and correct
    //   feedback explain text in a dedicated green card (previously the student was marked
    //   complete without seeing what the correct answer was). New lang string: correct_answer_reveal.
    //   No DB schema changes. PHP-only: submit.php, lang/en/local_aiquizremedial.php.
    //   version.php → 2026040400120.
    if ($oldversion < 2026040400120) {
        upgrade_plugin_savepoint(true, 2026040400120, 'local', 'aiquizremedial');
    }

    // v1.2.21: TWO BUG FIXES.
    //   FIX-1 (submit.php): Added "Back to My Modules" link pointing to index.php alongside
    //       the existing "Back to Module" link. Previously students had no direct route back to
    //       their full module list from the Quick Check feedback page.
    //   FIX-2 (server/routes.ts): Strengthened explain_text system prompt to prevent the
    //       Listen & Learn section from echoing the quiz question text or copying language from
    //       check_question.prompt. Added explicit instruction: explain_text must NOT begin with
    //       a question, must NOT closely mirror the quiz question wording, and must NOT copy
    //       language from check_question.prompt. Correct Concept section now starts with teaching
    //       language ("The key concept here is...").
    //   No DB schema changes. PHP/server only. version.php → 2026040700121.
    if ($oldversion < 2026040700121) {
        upgrade_plugin_savepoint(true, 2026040700121, 'local', 'aiquizremedial');
    }

    // v1.2.22 — VERSION BUMP: Clean release following full production audit.
    //   Deep 6-location sync check completed and verified: version.php, db/upgrade.php,
    //   BUILD_INFO.json, server/routes.ts zipFile, client/src/lib/pluginConfig.ts, and
    //   public/downloads ZIP all confirmed consistent. No code changes.
    //   No DB schema changes. version.php → 2026040700122.
    if ($oldversion < 2026040700122) {
        upgrade_plugin_savepoint(true, 2026040700122, 'local', 'aiquizremedial');
    }

    // v1.2.23 — TESTER FEEDBACK FIXES (2 bugs):
    //   FIX-QR-FORMAT: Listen & Learn explain_text now uses a 2-section format
    //     (Listen & Learn paragraph + Pro Tip) instead of the verbose 5-section format
    //     (Correct Concept / Your Answer / Correct Answer / Real-World Example / Pro Tip).
    //     Server-side prompt change in routes.ts only.
    //   FIX-QR-QC-FEEDBACK: Quick Check submit.php — on the 2nd wrong attempt, the
    //     student's wrong-choice feedback and the correct answer reveal are now combined
    //     into a single card instead of two separate fragmented cards.
    //   No DB schema changes. version.php → 2026040800123.
    if ($oldversion < 2026040800123) {
        upgrade_plugin_savepoint(true, 2026040800123, 'local', 'aiquizremedial');
    }

    // v1.2.24 - VERSION BUMP: Clean release following full tester-feedback cycle.
    //   All fixes from v1.2.23 (FIX-QR-FORMAT, FIX-QR-QC-FEEDBACK) confirmed in ZIP
    //   and all 6 delivery locations. No code changes. No AMD files.
    //   No DB schema changes. version.php → 2026040800124.
    if ($oldversion < 2026040800124) {
        upgrade_plugin_savepoint(true, 2026040800124, 'local', 'aiquizremedial');
    }

    // v1.2.25 - VERSION BUMP: Clean release following full tester-feedback cycle.
    //   All fixes from v1.2.24 (FIX-QR-FORMAT, FIX-QR-QC-FEEDBACK) confirmed in ZIP.
    //   No AMD files in this plugin. No DB schema changes. version.php → 2026040900125.
    if ($oldversion < 2026040900125) {
        upgrade_plugin_savepoint(true, 2026040900125, 'local', 'aiquizremedial');
    }

    // v1.2.26 - RELEASE SYNC: Full 6-location sync for v1.2.25 clean release.
    //   No AMD files. No new code changes. No DB schema changes. version.php → 2026040900126.
    if ($oldversion < 2026040900126) {
        upgrade_plugin_savepoint(true, 2026040900126, 'local', 'aiquizremedial');
    }

    // v1.2.28 - THREE CONTENT QUALITY FIXES:
    //   FIX-1 (routes.ts + view.php): Removed "Here is what you need to know. Listen & Learn:"
    //       prefix from explain_text. routes.ts strips it before returning; view.php strips
    //       old prefixes at display time. Pro Tip rendered as separate styled paragraph.
    //   FIX-2 (submit.php): Second wrong attempt reveal sentence changed from
    //       "not correct, the [X] is [explanation]" to "incorrect. [explanation]".
    //   FIX-3 (question_payload.php): Added get_response_summary() to resolve quiz
    //       multichoice answer index to actual option text (fixes "1 is incorrect").
    //   No DB schema changes. version.php → 2026040900128.
    if ($oldversion < 2026040900128) {
        upgrade_plugin_savepoint(true, 2026040900128, 'local', 'aiquizremedial');
    }

    // v1.2.29 - FIX-VOICEOVER-MISMATCH:
    //   submit.php second wrong attempt: replaced reuse of $correctFb['audio_url'] (which
    //   contains "That's correct. [explanation]" audio) with on-the-fly TTS generation
    //   from the plain-text $revealSentence ("Your answer is incorrect. [explanation]").
    //   Added local_aiquizremedial_tts_generate() helper to lib.php.
    //   Result: audio and displayed text are always in sync — no more "Correct." audio
    //   playing over "incorrect" text on the second wrong attempt.
    //   No DB schema changes. version.php → 2026041000129.
    if ($oldversion < 2026041000129) {
        upgrade_plugin_savepoint(true, 2026041000129, 'local', 'aiquizremedial');
    }

    // v1.2.27 - FIX-EXPLAIN-FORMAT + FIX-VOICEOVER-ATTEMPT2:
    //   FIX 1 (server/routes.ts): explain_text field value in the AI JSON template was
    //   starting with "Write using EXACTLY this 2-section structure..." — the AI was
    //   outputting these instructions as content. Instructions moved to Rules section.
    //   explain_text now uses only a clean content template: "Listen & Learn:\nThe correct
    //   answer is [X], which [explanation]. [Why wrong answer is wrong]. [Real-world scenario.]
    //   \n\nPro Tip:\n[tip]". The Listen & Learn paragraph always starts with
    //   "The correct answer is [correctAnswer], which..." — the exact format requested.
    //   FIX 2 (submit.php): On the second wrong attempt, $revealSentence text is derived
    //   from the correct answer, but the audio played was $fb['audio_url'] — the audio
    //   for the student's WRONG choice. Fixed: audio now uses $correctFb['audio_url']
    //   so the voiceover matches the text displayed on screen.
    //   No DB schema changes. version.php → 2026040900127.
    if ($oldversion < 2026040900127) {
        upgrade_plugin_savepoint(true, 2026040900127, 'local', 'aiquizremedial');
    }

    // v1.2.30 - MULTI-LANGUAGE SUPPORT.
    // Add translations_json column to local_aiqr_module to store pre-generated
    // translated explanations and voiceover audio for each enabled language.
    if ($oldversion < 2026041000130) {
        $table = new xmldb_table('local_aiqr_module');
        $field = new xmldb_field('translations_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'feedback_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026041000130, 'local', 'aiquizremedial');
    }

    // v1.2.31 - AUTO-TEST CONFIRMATION: All ongoing tester issues (reported at v1.2.22/v1.2.24)
    //   confirmed resolved via code audit. (1) Listen & Learn format: fixed in v1.2.28
    //   (FIX-QR-FORMAT: explain_text uses structured 2-section format — "The correct answer is
    //   [X], which [explanation]... Pro Tip: [tip]" — with heading rendered separately by the UI;
    //   routes.ts strips any AI-echoed "Listen & Learn:" prefix before storing; view.php strips
    //   stale prefixes at display time; Pro Tip rendered as styled bold paragraph). (2) Quick
    //   Answer 2nd attempt: fixed in v1.2.28 (FIX-QR-QUICKANSWER: corrected sentence from
    //   "Your answer is not correct, the [X] is The [X] is..." to "Your answer is incorrect.
    //   The [X] is..."). No code changes. No DB schema changes. version.php → 2026041000131.
    if ($oldversion < 2026041000132) {
        upgrade_plugin_savepoint(true, 2026041000132, 'local', 'aiquizremedial');
    }

    // v1.2.33 - TESTER-FEEDBACK-3-ITEMS.
    //   Fix 1 (routes.ts): Pro Tip generation prompt updated — Pro Tip must now be a genuine
    //   memory device (mnemonic, association, mental hook) NOT a restatement of the correct answer.
    //   Template example: 'Think ice = stick. If the sport uses a puck on ice, players always use
    //   a stick—not a racket.' Trigger phrases required: Think / Picture / Remember:.
    //   Fix 2 (lib.php): Added local_aiquizremedial_before_footer() hook. On mod-quiz-view pages,
    //   teachers (viewall capability) now see a prominent 'View Learning Revisions' button that
    //   links to index.php?courseid=X&quizid=Y filtered to that specific quiz.
    //   Fix 3 (index.php): Added quiz filter and student filter dropdowns in teacher-overview mode.
    //   Quiz dropdown lists only quizzes that have remedial modules in the course.
    //   Student dropdown lists only students who have modules for the selected quiz (or all quizzes).
    //   New ?filteruserid= param keeps teacher-overview mode active while narrowing by student.
    //   New lang strings added: view_learning_revisions, filter_by_quiz, filter_all_quizzes,
    //   filter_by_student, filter_all_students, filter_apply, filter_reset.
    //   No DB schema changes. version.php → 2026041000133.
    if ($oldversion < 2026041000133) {
        upgrade_plugin_savepoint(true, 2026041000133, 'local', 'aiquizremedial');
    }

    // v1.2.34 - TWO FIX GROUPS:
    //   FIX-RL-TEACHER-FOOTER (before_footer.php): Teachers on mod-quiz-view or mod-quiz-report
    //     pages now see a 'View Remedial Learnings' button injected by the before_footer hook.
    //     The button shows the count of ready remedial modules for that quiz and links to
    //     /local/aiquizremedial/index.php?quizid=X&courseid=Y.
    //   FIX-RL-QUIZID-HEADING (index.php): Page heading now shows quiz name when opened with
    //     ?quizid=X so the teacher can confirm which quiz they are reviewing.
    //   No DB schema changes. PHP only. version.php → 2026041400034.
    if ($oldversion < 2026041400034) {
        upgrade_plugin_savepoint(true, 2026041400034, 'local', 'aiquizremedial');
    }

    // v1.2.35 - THREE FIX GROUPS (recording analysis 15 Apr 2026):
    //   BUG-REM-CREDITS-FAILED (credits_client.php): credits were charged BEFORE calling
    //     generate_text_remediation(). When the AI API returned no explain_text the fallback
    //     placeholder was stored and credits were consumed for nothing. Fix: generate_text_remediation()
    //     now throws moodle_exception('remediationfailed') instead of returning the fallback string
    //     — process_jobs.php catch block sets the job to status='failed', no module record is
    //     created. charge_credits() is called AFTER successful generation only.
    //   BUG-REM-STATUS-FAILED (index.php): failed question-level jobs (j.status='failed') were
    //     invisible in the teacher overview. A second SQL query now fetches them and renders red
    //     'Generation Failed' badge cards with 0 credits and no Review Module button.
    //   BUG-REM-QUESTION-PREVIEW (index.php): card preview showed first 150 chars of explain_text
    //     which always starts 'The correct answer is...' — revealing the answer in the list.
    //     Both SQL queries now LEFT JOIN {question} qq ON qq.id=j.questionid; cards show
    //     'Question: [name]' subtitle. New lang strings: remediationfailed, state_failed,
    //     generation_failed_desc, question_label. No DB schema changes. version.php → 2026041500035.
    if ($oldversion < 2026041500035) {
        upgrade_plugin_savepoint(true, 2026041500035, 'local', 'aiquizremedial');
    }

    // v1.2.36: BUG FIX — index.php called format_string() on line 44 before $PAGE->set_context()
    //   was called (line 62). Moodle's format_string() internally accesses $PAGE->context, so
    //   on any page load where ?quizid=X is in the URL, a debugging exception was thrown:
    //   '$PAGE->context was not set'. Fix: moved $PAGE->set_context($context) to immediately
    //   after $context is resolved (line 15), before any mode-detection or heading-building code.
    //   Removed the now-duplicate $PAGE->set_context() call that remained in the URL setup block.
    //   No DB schema changes. PHP only (index.php). version.php → 2026041700036.
    if ($oldversion < 2026041700036) {
        upgrade_plugin_savepoint(true, 2026041700036, 'local', 'aiquizremedial');
    }

    // v1.2.37 - IMPROVEMENT: AI image generation prompt updated to allow educationally
    //   essential text (diagram labels, measurement markings, safety signs) with strict
    //   accuracy and legibility requirements. Previously rule 8 banned ALL text from
    //   generated images, causing diagrams to lose meaningful labels. New rule 8 allows
    //   short labels/numbers/annotations ONLY when educationally essential, requiring
    //   correct spelling, clean sans-serif rendering, and no decorative text.
    //   Prompt word limit extended from 150 to 200 words for greater visual specificity.
    //   Quality rules tightened: explicit bans on AI artifacts, warped faces, distorted
    //   text, lens flare, vignette effects. No DB schema changes. server/routes.ts only.
    //   version.php → 2026041700037.
    if ($oldversion < 2026041700037) {
        upgrade_plugin_savepoint(true, 2026041700037, 'local', 'aiquizremedial');
    }

    // v1.2.38 - TWO QUALITY FIXES:
    //   FIX-1 (server/routes.ts): Image generation prompt rewritten to enforce ZERO text in
    //     images (absolute rule — no labels, captions, numbers, overlays of any kind) and
    //     large-subject close-up composition (subject fills >70% of frame, tight framing,
    //     no wide establishing shots). Previously rule 8 permitted educationally essential
    //     text, which the AI often interpreted too broadly, producing text-heavy output.
    //   FIX-2 (server/routes.ts): Check-question generation is now qtype-aware. True/False
    //     original questions generate a binary check_question with exactly 2 options
    //     ["True","False"] and strict unambiguity rules (plain declarative statement, no
    //     negatives, definitively one correct answer). Matching questions receive explicit
    //     simplicity and clarity rules. All other qtypes continue to use the existing
    //     4-option multichoice template. Validation and feedback-padding logic updated to
    //     use numOptions (2 or 4) instead of the hardcoded 4.
    //   No DB schema changes. server/routes.ts only. version.php → 2026041700038.
    if ($oldversion < 2026041700038) {
        upgrade_plugin_savepoint(true, 2026041700038, 'local', 'aiquizremedial');
    }

    // v1.2.39 — TWO BUG FIXES (Listen & Learn — Pro Tip rendering inconsistency).
    //   FIX-PROTIP-REGEX (view.php): PHP regex to split Pro Tip section required exactly
    //     \n\n before "Pro Tip:" — if the AI generated a single newline or leading whitespace,
    //     the regex failed and "Pro Tip:" appeared as unstyled plain text. Fixed: regex
    //     changed to \n+\s*Pro Tip: to accept 1+ newlines with optional whitespace.
    //   FIX-PROTIP-JS (view.php): setLang() JavaScript function dumped the full explain_text
    //     including "Pro Tip:" as plain text into .aiqr-explain-text when switching languages.
    //     Pro Tip splitting was completely absent from the switcher. Fixed: splitProTip()
    //     helper and applyText() function now mirror the PHP logic — .aiqr-explain-text gets
    //     the main body; .aiqr-pro-tip gets the bold-styled Pro Tip (or is hidden if absent).
    //     English Pro Tip stored in new aiqrEnProTip JS variable for language switch-back.
    //   No DB schema changes. view.php only. version.php → 2026041700039.
    if ($oldversion < 2026041700039) {
        upgrade_plugin_savepoint(true, 2026041700039, 'local', 'aiquizremedial');
    }

    // v1.2.40 — FIX-RL-COURSE-NAV: course navigation tabs and breadcrumbs on index.php.
    //   When a teacher clicked "View Remedial Learnings" from a quiz page there was no
    //   way to navigate back to the course: the page showed no course navigation tabs
    //   or breadcrumbs. Root cause: index.php called $PAGE->set_context() with the course
    //   context but never called $PAGE->set_course(), so Moodle's layout engine did not
    //   know which course to render navigation for. Fix: when courseid > 0 the course
    //   record is loaded and $PAGE->set_course($course) is called immediately after the
    //   capability check. Moodle's standard layout then renders breadcrumbs
    //   (Home > Course > Current Page) and course menu tabs.
    //   No DB schema changes. index.php only. version.php → 2026041700040.
    if ($oldversion < 2026041700040) {
        upgrade_plugin_savepoint(true, 2026041700040, 'local', 'aiquizremedial');
    }

    // v1.2.41 — FIX-RL-COMPLETION-NAV: Back to Quiz / Back to My Modules buttons added to
    //   the completion state in submit.php and view.php.
    //   After completing a Revision Module the student had no visible navigation to return to
    //   the quiz or course. The only buttons were at the very top of view.php and disappeared
    //   as the student scrolled down to complete the check question.
    //   Fix: submit.php now renders "Back to Quiz" (primary) + "Back to My Modules" (secondary)
    //   immediately below the completion alert. view.php adds the same buttons in the already-
    //   complete branch. No DB schema changes. submit.php + view.php only.
    //   version.php → 2026041700041.
    if ($oldversion < 2026041700041) {
        upgrade_plugin_savepoint(true, 2026041700041, 'local', 'aiquizremedial');
    }

    // v1.2.42: FIX-RL-FULLNAME-FIELDS — index.php loaded only id,firstname,lastname for the
    //   $viewinguser record, so calling fullname() on it triggered a Moodle 4.x debugging()
    //   warning ("name fields missing: firstnamephonetic, lastnamephonetic, middlename,
    //   alternatename") that surfaced in the page heading on the teacher view. Fix: include
    //   all six name fields in the get_record() field list. PHP only — no DB schema changes.
    if ($oldversion < 2026042100042) {
        upgrade_plugin_savepoint(true, 2026042100042, 'local', 'aiquizremedial');
    }

    // v1.2.43: FIX-IMG-TIMEOUT (credits_client.php) raised image-generation curl
    // timeout from 60s → 180s so the Imagen 4 Ultra retry chain + OpenAI fallback
    // completes before PHP aborts. FIX-PROTIP-REGEX (view.php) accepts non-newline
    // whitespace before "Pro Tip:" so the marker is always rendered as a styled
    // separate line. PHP only — no DB schema changes.
    if ($oldversion < 2026042100043) {
        upgrade_plugin_savepoint(true, 2026042100043, 'local', 'aiquizremedial');
    }

    // v1.2.44: FIX-RL-FILTER-PERSIST — teacher filter context (quizid + filteruserid)
    // is now propagated from index.php into view.php so the same filter panel can be
    // rendered inside the student review screen and so "Back to My Modules" returns the
    // teacher to the filtered list rather than the unfiltered overview. Files: index.php
    // (viewurl propagation), view.php (filter params, back link, inline filter panel).
    // PHP only — no DB schema changes.
    if ($oldversion < 2026042100044) {
        upgrade_plugin_savepoint(true, 2026042100044, 'local', 'aiquizremedial');
    }

    // v1.2.45: FIX-RL-BACK-BTN — teachers reviewing a student's question reported
    // "no way to go back to the module list". The page only had a back link at the very
    // top, which scrolled out of view on long modules (explanation + image + audio +
    // check question). Added an always-visible "Back to Modules" button after the
    // question content on every render path (teacher review, student in-progress,
    // completed). Reuses the filter-aware $backurl so quiz/student filter context is
    // preserved. Files: view.php, lang/en/local_aiquizremedial.php (new 'backtomodules'
    // string). PHP only — no DB schema changes.
    if ($oldversion < 2026042100045) {
        upgrade_plugin_savepoint(true, 2026042100045, 'local', 'aiquizremedial');
    }

    // v1.2.46 — FIX-PROTIP-COLOUR (styles.css). The "Pro Tip:" paragraph
    //   rendered by view.php inside <div class="aiqr-pro-tip"> had no CSS rule
    //   of its own, so it inherited Moodle's default body/muted text colour and
    //   looked grey/washed-out compared to .aiqr-explain-text (#333). Added a
    //   .aiqr-pro-tip rule that forces the same colour (#333), font-size (1rem)
    //   and line-height (1.7) so the Pro Tip reads as part of the same content
    //   block. CSS only. No PHP, JS, AMD or DB schema changes.
    //   version.php → 2026042200046.
    if ($oldversion < 2026042200046) {
        upgrade_plugin_savepoint(true, 2026042200046, 'local', 'aiquizremedial');
    }

    // v1.2.47 — FEAT-RL-VOICEOVER-PLAYBACK (settings.php, view.php, lang).
    //   New admin setting "Voiceover playback mode" (voiceoverplayback) added
    //   under Feature Options. Options: "manual" (default, preserves existing
    //   click-to-play behaviour on all sites) and "auto" (HTML5 autoplay
    //   attribute added to the <audio> element; JS aiqrAutoplay flag makes the
    //   language-switcher also autoplay after a source change). Teachers are
    //   excluded from auto-play. No DB schema changes. PHP + lang only.
    //   version.php → 2026042200047.
    if ($oldversion < 2026042200047) {
        upgrade_plugin_savepoint(true, 2026042200047, 'local', 'aiquizremedial');
    }

    // v1.2.48: FIX-RL-FEEDBACK-AUTOPLAY — Quick Check feedback audio in submit.php
    //   now respects the voiceoverplayback admin setting. When set to "auto", both
    //   feedback audio paths (first-attempt $fb['audio_url'] and second-attempt
    //   freshly-generated TTS) get the HTML5 autoplay attribute plus a JS
    //   canplaythrough fallback. Teachers are excluded. No DB schema changes.
    //   PHP only (submit.php). version.php → 2026042200048.
    if ($oldversion < 2026042200048) {
        upgrade_plugin_savepoint(true, 2026042200048, 'local', 'aiquizremedial');
    }

    // v1.2.49: ENHANCEMENT — Server-side image realism improvements. Imagen 4 Ultra
    //   meta-prompt rewritten with mandatory camera/lens/lighting anchor as first
    //   sentence; negative prompt added to block cartoon, illustration, CGI, and
    //   deformed anatomy outputs. Benefits AI-generated explanatory images in Revision
    //   Modules. No DB schema changes. Server-side only. version.php → 2026042200049.
    if ($oldversion < 2026042200049) {
        upgrade_plugin_savepoint(true, 2026042200049, 'local', 'aiquizremedial');
    }

    // v1.2.50: FIX-IMG-RESTORE — Removed the negativePrompt parameter added in v1.2.49
    //   from the Imagen 4 Ultra API call. The parameter caused Imagen's content-policy
    //   filter to reject far more prompts than without it, resulting in both the Imagen
    //   and OpenAI gpt-image-1 fallback failing, and explain_image_url being silently
    //   stored as null even when 'Enable explanatory images' was ticked. Style exclusions
    //   (no cartoon, no CGI, etc.) are already enforced by the Gemini meta-prompt (RULE 5)
    //   so the negativePrompt was redundant. Additionally, a fallback image prompt is now
    //   generated from the question/context text when Gemini's meta-prompt step returns an
    //   unusable response, preventing a second silent failure path. No DB schema changes.
    //   Server-side only. version.php → 2026042200050.
    if ($oldversion < 2026042200050) {
        upgrade_plugin_savepoint(true, 2026042200050, 'local', 'aiquizremedial');
    }

    // v1.2.51: FIX-IMG-BACKFILL — Existing student modules created during the v1.2.49
    //   window have explain_image_url = null because the negativePrompt bug caused
    //   every Imagen request to fail silently; v1.2.50 fixed new modules but left
    //   existing ones without images. A backfill loop has been added to the
    //   process_jobs scheduled task: each cron run attempts to generate images for up
    //   to 3 modules whose explain_image_url is null and explain_text is non-empty,
    //   without re-charging credits. A public backfill_image() method was added to
    //   credits_client.php as a thin wrapper around the existing generate_image().
    //   No DB schema changes. PHP only (process_jobs.php, credits_client.php).
    //   version.php → 2026042200051.
    if ($oldversion < 2026042200051) {
        upgrade_plugin_savepoint(true, 2026042200051, 'local', 'aiquizremedial');
    }

    // v1.2.52: FIX-SMW-BLANKS — Select Missing Words question type stores gap
    //   markers as [[1]], [[2]] etc. in questiontext. view.php now strips these
    //   markers and replaces each with ___ so students see a clean blank instead
    //   of raw bracket notation. No DB schema changes. view.php only.
    //   version.php → 2026042200052.
    if ($oldversion < 2026042200052) {
        upgrade_plugin_savepoint(true, 2026042200052, 'local', 'aiquizremedial');
    }

    // v1.2.53: FIX-OPT-CAPS + FIX-EXPLAIN-FULLSTOP — Two display-only fixes in view.php.
    //   (1) Quick Check answer options were sometimes generated without a leading capital.
    //   ucfirst() is now applied to every option at render time in both the student form
    //   and teacher review panel, so all options start with a capital regardless of what
    //   is stored in check_question_json.
    //   (2) The explanation paragraph occasionally ended without a full stop, creating an
    //   abrupt transition before the Pro Tip block. A guard appends '.' if the trimmed
    //   text does not already end with '.', '?' or '!'. The same guard is applied in the
    //   JS applyText() function so translated language versions are also fixed.
    //   No DB schema changes. view.php only. version.php → 2026042200053.
    if ($oldversion < 2026042200053) {
        upgrade_plugin_savepoint(true, 2026042200053, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026042200054) {
        // v1.2.54: FIX-QUESTION-ARRAY — credits_client.php generate_image() now casts
        // $payload['question'] to (object) before json_encode so PHP always serialises it
        // as a JSON object "{}" instead of an empty array "[]". Zod's z.object() on the
        // server rejects JSON arrays, causing every backfill-cron image request to return
        // HTTP 400 "Invalid parameters" in 1ms. No DB schema changes. PHP only.
        upgrade_plugin_savepoint(true, 2026042200054, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026072300206) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072300206, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026072300207) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300207, 'local', 'aiquizremedial');
    }

    if ($oldversion < 2026072300208) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300208, 'local', 'aiquizremedial');
    }

    return true;
}