# Changelog - AI Quiz Remedial Learning

All notable changes to this plugin will be documented in this file.

## [1.4.1] — 2026-09-21

Plugin-side changes for the LMS Labs GPT Image 2 image service (handoff dated 21 Sep 2026). No change to prices or credit amounts.

### Fixed
- **Background image retries lost the original question.** Each module now stores a sanitised snapshot of the original question (`question_json`), and initial generation and backfill send the same snapshot.
  - Older Moodle quiz modules: the question is rebuilt from the attempt's own question version.
  - Older AI Knowledge Check modules: those questions are edited in place, so they are not rebuilt. They use context-only generation, which is reported to teachers. Nothing is invented.
- **Image failures became a blank image with no reason.** `generate_image()` now checks, in order:
  1. cURL transport
  2. HTTP status
  3. that the response is valid JSON
  4. `success`
  5. that `image_url` is an HTTPS link on the service host (never base64 or `data:`)
- Every failure is classified and recorded (`image_status`, `image_error`, `image_attempts`, `image_nextattempt`, `image_meta` holding the provenance):
  - **retry:** timeout, 5xx, 429, malformed response. Retried with back-off, up to 3 attempts in total.
  - **rejected:** 400 (input bounds), 401/403 (auth), 404/413/422. Not retried unchanged.
- The generated text is always kept, and an existing image is never cleared.
- The backfill cap stays at 3 per cron run. The 180-second timeout is kept.
- No second charge and no automatic refund.
- Moodle's `curl` class is now loaded explicitly, so image backfill no longer fails in cron with *Class "curl" not found*.
- Question images with `@@PLUGINFILE@@` links now display on the revision module page (previously broken links and a developer warning).

### Release pipeline fixes
- `sesskey` is now read as `PARAM_ALPHANUM`, and the report's date filters as `PARAM_ALPHANUMEXT`. `PARAM_RAW` is no longer used anywhere.
- The legacy serialised-array fallback for the languages setting is removed (the setting is comma-separated), so `unserialize()` is no longer called.
- Coding style fixes:
  - `function (` spacing
  - no blank line after a class's opening brace
  - comment blocks start with a capital letter
  - multi-line calls put their arguments on the next line
- `BUILD_INFO.json` is excluded from the release ZIP.

### Changed
- The image request sends the full corrective explanation as `context`, plus a `question` object with only `id`, `qtype`, `name` and `text_html`. `question` is omitted when unknown, never sent as `[]`. No learner name, email or attempt data is sent. The v1.4.0 use of `lesson.image_prompt` is removed; the service writes its own prompt.
- Images inside the question are sent as `[Image: <alt text>]`. Images without a description are flagged to teachers (never guessed). `<script>`/`<style>` content is removed.
- Portrait images (864×1536) are shown whole, never cropped, and open full size.
- Teachers see the image status, the reason for a failure, and the provenance (e.g. `gpt-image-2 (question_with_context)`) on the module page. The report shows failed or rejected images with a **Retry image** action.
- Admin help and code comments now describe GPT Image 2 with no fallback. Imagen and GPT Image 1 references are removed.

## [1.4.0] — 2026-09-21

### Added — tutor lesson cards
- Revision modules now show a 2x2 card lesson designed with learning-science principles:
  - **Why it seemed right:** names why the learner's option was tempting, then why it fails (refutation).
  - **The key idea:** the rule and the reason for it.
  - **See it at work:** the principle in a new real-world situation, with the AI image.
  - **Lock it in:** one memory hook specific to this concept.
- Order: a neutral answer strip first, the four cards, then a "Remember" takeaway bar. Teachers also see a tutor note describing the learner's misconception.
- Cards have hover lift, shadows and keyboard focus. They collapse to one column on phones and respect reduced motion.
- The Quick Check is labelled as "same idea, new situation".
- The AI service is asked for `responseFormat: lesson_v1` (see LMS-Labs_server_spec_lesson_v1.md). Until the service supports it, and for all older modules, the explanation shows in the same card style: "The key idea" plus "Lock it in" from the Pro Tip.
- Voiceover narrates the service's `tts_script`. Images use the lesson's `image_prompt` so they illustrate the "See it at work" card.
- Translations keep the card structure. The language picker now switches pre-rendered blocks, replacing the old JS text rewriting.
- DB: `local_aiqr_module.lesson_json`.

## [1.3.1] — 2026-09-21

### Added
- **Apply remedial learning to** setting: tick Moodle quiz, AI Knowledge Check, both or neither. Each works independently. If AI Knowledge Check isn't installed, the setting says so. This replaces the two separate checkboxes added in 1.3.0; the upgrade carries their values across.

### Fixed (AI Knowledge Check)
- Survey-mode activities no longer generate revision modules. Their answers have no correct/incorrect flag, which was being read as "wrong".
- Free-text (comment) questions no longer generate revision modules.
- The 5th answer option is now sent to the AI and shown in "Your answer".
- No duplicate module (or credit charge) while the learner still has an unfinished module for the same question, e.g. from a quiz re-attempt or Knowledge Check "Retry wrong answers".

## [1.3.0] — 2026-09-21

### Fixed — students not seeing remedial learning
- **Module list crashed without AI Knowledge Check**: `index.php` always joined the `aiknowledgecheck` tables, so on sites without `mod_aiknowledgecheck` learners (and teachers) clicking "Start Learning Revisions" got *Error reading from database*. Every Knowledge Check join is now guarded (`helper::kc_installed()`). Quiz remediation and Knowledge Check remediation now work fully independently.
- **Learners only saw a link on the quiz review page, and only after cron had finished**: banners now also appear on the quiz page, the course page and AI Knowledge Check pages, and there are course navigation links ("My revision modules" / "Remedial learning report"). While content is still being generated a *Preparing your revision modules* banner is shown; it polls and reloads itself when the modules are ready.
- **Moodle 4.0–4.3 never showed the learner banner**: the legacy `before_footer` callback only rendered the teacher button. It now renders the same HTML as the 4.4+ hook.
- **Unanswered questions got no module**: blank answers (gave up, NULL fraction) were skipped. They are now treated as wrong (new setting *Create modules for unanswered questions*, default on). Essay/manual-grading questions are still skipped.
- **Attempt jobs closed with zero modules**: an attempt that was not yet graded (Moodle 5.0 `submitted` state) was marked `ready` with no modules and never retried. It now stays pending until graded. The plugin also listens to `\mod_quiz\event\attempt_graded` (Moodle 5.0+).
- **Failed / stuck jobs never recovered**: failed question jobs are retried automatically (up to 3 times with back-off). Jobs left in `processing` by a crashed cron run are recovered. Duplicate question jobs are no longer created on re-runs.
- **Extra languages never generated**: the multi-checkbox setting is stored comma-separated but was `unserialize()`d, which always returned nothing.
- **Privacy provider** incorrectly declared that no personal data was stored. It now implements metadata, export and delete.

### Added
- **Remedial learning report** (`report.php`, Site administration > Reports, course "More" menu, quiz page button). Filters: category (optionally with sub-categories), course, cohort, group, teacher (optionally only the teacher's groups), source (quiz / Knowledge Check), quiz/activity, student, status (not started / in progress / complete / being prepared / failed), date range and free-text search (name, email, ID number, username, course, quiz, question). Includes removable filter chips, clickable summary cards (completion rate, credits used), a Modules view and a Student summary view, sortable columns, paging, and CSV/Excel/ODS/JSON/HTML/PDF export. Failed jobs can be retried one at a time or all at once. Scope is respected: teachers only see their own courses, and separate-groups mode is applied.
- Separate settings to turn quiz remediation and AI Knowledge Check remediation on or off.
- `cli/rescan.php` finds finished attempts whose wrong or unanswered questions never got a module and re-queues them (dry run by default).
- Learner module list: course/status filters, a progress bar, and a "being prepared" notice.

### Changed
- Generation is now a two-stage queue: attempt jobs are expanded into question jobs first (fast), then generated within a time budget per cron run.
- Teacher links to `index.php` redirect to the report, with existing parameters mapped across.
- DB: `local_aiqr_job.retries` and two indexes (`courseid,userid` and `attemptid,questionid,sourcetype`).

## [1.2.13] — 2026-03-28

### Fixed
- **CSS-IMAGE-OVERFLOW**: AI-generated explain images had no `max-width`/`max-height` constraint, causing oversized images to break the remedial review layout. Added CSS constraints.
- **BACK-TO-QUIZ**: Added a 'Back to Quiz' button to `view.php`. For KC-type remedial modules the `cmid` of the originating quiz is looked up from the DB so students can return directly without navigating manually. New lang string: `backtoquiz`.

## [1.2.11] — 2026-03-27

### Fixed
- **FIX-TEACHER-REVIEW-BLANK**: When a teacher clicked "Review Module" on a student's Revision Module, `view.php` was loading the completion record for `$USER->id` (the teacher) instead of the student's user ID. The teacher had no completion record, so the module appeared not started and the interactive check-question form was shown blank — as if the teacher were taking the module themselves. The teacher was also inadvertently creating a spurious completion DB record for themselves on each visit.
- **Resolution**: A `$teacherview` flag is set when `?userid=X` is in the URL and the viewer holds the `viewall` capability. `$subjectuserid` drives the completion lookup. In teacher-review mode: no new completion record is created; the check question section renders read-only with options listed, the correct answer highlighted `(correct answer)`, and the student's completion status (Not Started / In Progress / Complete with attempt count) shown as an alert below the options.
- Five new lang strings: `teacherreview_readonly`, `teacherreview_correctanswer`, `teacherreview_studentcomplete`, `teacherreview_studentinprogress`, `teacherreview_studentnotstarted`.
- No DB schema changes.

## [1.2.10] — 2026-03-27
### Changed
- **VERSION BUMP**: Rebump for release alignment following hardened release process (Step 0 reality check, stale artifact sweep, AMD CRC validation). No functional changes, no lang changes, no DB schema changes. version.php → 2026032700402.

## [1.2.9] — 2026-03-27
### Changed
- **LANGUAGE** (`lang/en/local_aiquizremedial.php`): Replaced all student-facing "Fix Module" terminology with clearer, positive language. "Fix Module" → "Revision Module", "Start Fix Module" → "Start Learning Revision", "Start Fix Modules" → "Start Learning Revisions", "Fix Modules Available" → "Revision Modules Available", "All Fix Modules Complete" → "All Revision Modules Complete", "Let's fix this" → "Let's revisit this", "Fix module completed!" → "Revision complete!", "question(s) to fix" → "question(s) to revisit". No PHP logic changes, no DB schema changes. version.php → 2026032700401.

## [1.2.4] - 2026-03-25

### Fixed
- **BUG-BANNER-COUNT**: Quiz review banner showed incorrect count of questions to fix.
  Root cause: the banner's `$pendingcount` query counted only modules from child jobs with
  `status='ready'`. When a child job's AI generation failed (inner try/catch in cron catches the
  error, marks job `status='failed'`, and creates no module), that wrong question produced no
  module and was silently excluded from the count. Result: a student who answered 2 questions
  wrong saw "1 question(s) to fix" instead of "2".

  Fix: a new `$wrongcount` query counts all child jobs with `questionid IS NOT NULL` for the
  current attempt (any status), giving the true number of wrong questions. A derived
  `$displaycount = wrongcount − completedcount` is used for the banner message, while
  `$pendingcount` (module-based) is still used to toggle between the pending and all-complete
  banner states. The all-complete banner still fires correctly once all available modules are
  finished.

## [1.2.0] - 2026-03-19

### Added
- **AI Knowledge Check integration** — remediation modules are now generated for questions answered incorrectly in `mod_aiknowledgecheck` activities, in addition to the standard Moodle™ Quiz (`mod_quiz`). When a student finishes a Knowledge Check attempt, an umbrella job is queued and the cron task expands it into one per-question module for each wrong answer.
- New `classes/kc_question_payload.php` — builds the AI remediation payload from `aiknowledgecheck_questions` and `aiknowledgecheck_attempts` tables (question text, four options, correct answer, student's selected answer).
- New DB fields on `local_aiqr_job`: `sourcetype CHAR(20) DEFAULT 'quiz'` and `kcid INT NULL`. `sourcetype='knowledgecheck'` activates the KC branch; `kcid` references the `aiknowledgecheck` instance.
- `process_jobs.php` dispatches to `expand_and_generate_kc()` when `sourcetype='knowledgecheck'`, reading answers directly from KC tables instead of Moodle's question engine.

### Changed
- `view.php` — shows the KC activity name and KC question text / student's selected option when `sourcetype='knowledgecheck'`.
- `index.php` — LEFT JOINs `aiknowledgecheck` table to resolve KC activity names alongside quiz names.
- `ajax.php getmodules` — accepts optional `kcid` filter parameter; response now includes `kcid` and `sourcetype` fields.

## [1.1.7] - 2026-03-18

### Fixed
- **BUG-TTS-MISMATCH**: The AI generation API returned a separate  field which could contain different content from  (the text rendered on screen), causing TTS audio to read words not present in the displayed explanation. Fix:  now always strips HTML and entity-decodes  to produce the TTS source text;  is intentionally ignored. Same fix applied to per-option feedback TTS text.


## [1.1.3] - 2026-03-12

### Fixed
- Scheduled task "Process AI Quiz Remedial Learning" no longer fails with "Class question_engine not found". Added `require_once($CFG->libdir . '/questionlib.php')` to `expand_and_generate()` so Moodle's question engine is explicitly loaded before `question_engine::load_questions_usage_by_activity()` is called. The class is not autoloaded by Moodle's standard autoloader and must be required manually in scheduled task context.

## [1.1.2] - 2026-03-01

### Fixed
- Added missing /api/tts/generate endpoint so voiceover audio now generates correctly
- Removed double credit deduction from /api/image/generate
- require_login() moved before DB access in view.php and submit.php (security hardening)
- Eliminated N+1 quiz name query in index.php via JOIN
- Added 30-60s curl timeouts to all API calls in credits_client.php

## [1.1.1] - 2026-02-20

### Fixed
- Migrated before_footer callback to new Moodle™ hook system (core\hook\output\before_footer_html_generation)

## [1.1.0] - 2026-02-15

### Added
- Global enable/disable toggle in admin settings
- Quiz review page banner with Start button after wrong-answer submission
- Show-on-review toggle

## [1.0.0] - 2026-02-25

### Added
- Initial release
