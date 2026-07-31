# Changelog - AI Quiz Remedial Learning

All notable changes to this plugin will be documented in this file.

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
