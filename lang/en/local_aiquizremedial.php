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

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Quiz Remedial Learning';
$string['plugindesc'] = 'AI Quiz Remedial Learning automatically detects incorrect quiz answers and generates personalised micro-remediation modules for each wrong question, helping students understand their mistakes and fill knowledge gaps immediately after a quiz attempt.

Each remediation module contains: a plain-English explanation of why the correct answer is right and the chosen answer was wrong, optional Google Chirp 3 HD voiceover narration that reads the explanation aloud, an optional AI-generated explanatory image that illustrates the concept, and a single check question that the student must answer correctly to complete the module. Students access their revision modules from a prominent banner on the quiz review page or from a dedicated revision page linked from their course.

Voiceover audio and explanatory images are each optional feature flags — enabling them uses additional credits per wrong question but significantly increases engagement and accessibility. The plugin reads API credentials from AI Grader Central Config if installed, or can be configured independently with its own Site ID and API Key.';

// Settings - General.
$string['general_heading'] = 'General Settings';
$string['general_heading_desc'] = 'Enable or disable remedial learning and control how it appears to students.';
$string['enabled'] = 'Enable AI Quiz Remedial Learning';
$string['enabled_desc'] = 'When enabled, wrong quiz answers will automatically trigger AI-generated revision modules for students. When disabled, no new revision modules will be created.';
$string['showonquizreview'] = 'Show banner on quiz review page';
$string['showonquizreview_desc'] = 'Display a prominent banner on the quiz review page directing students to their revision modules when they have incorrect answers.';

// Settings - Connection.
$string['connection_heading'] = 'Connection Settings';
$string['connection_heading_desc'] = 'Configure your AI Grader connection. If you have the Central Config plugin installed, these will be used as fallback only.';
$string['siteid'] = 'Site ID';
$string['siteid_desc'] = 'Your AI Grader Site ID. Leave blank to use Central Config.';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Your AI Grader API Key. Leave blank to use Central Config.';

// Settings - Features.
$string['features_heading'] = 'Feature Options';
$string['features_heading_desc'] = 'Choose which features to enable. Each enabled feature uses additional AI credits per wrong question.';
$string['enablevoiceover'] = 'Enable voiceover audio';
$string['enablevoiceover_desc'] = 'Generate text-to-speech audio for explanations and feedback. Uses additional credits per question.';
$string['voiceoverplayback'] = 'Voiceover playback mode';
$string['voiceoverplayback_desc'] = 'Controls how the voiceover audio is triggered when a student opens a Revision Module. "Click to play" (default) leaves the audio player paused — the student presses play. "Auto-play" starts the voiceover immediately when the page loads and also restarts it when the student switches language.';
$string['voiceoverplayback_manual'] = 'Click to play — student presses play to start the voiceover';
$string['voiceoverplayback_auto']   = 'Auto-play — voiceover starts automatically when the module opens';
$string['enableimages'] = 'Enable explanatory images';
$string['enableimages_desc'] = 'Generate an AI illustration for each revision module (OpenAI GPT Image 2 via LMS Labs, portrait format, no fallback model). Uses additional credits per question; the charge is made once with the module and never repeated if the image is retried. If an image fails the revision module is still created and the image is retried automatically when the failure is temporary. Questions that rely on a diagram or photo should give that image a description (alt text) — the image service only reads text.';
$string['extra_languages'] = 'Additional content languages';
$string['extra_languages_desc'] = 'Select additional languages to generate for each remediation module. English is always generated first. Each selected language translates the explanation text and generates a new voiceover in that language. Each additional language costs 5 credits per question. Students see a language picker next to the audio player to switch between available languages.';
$string['lang_english'] = 'English';
$string['lang_fr'] = 'Français (French)';
$string['lang_es'] = 'Español (Spanish)';
$string['lang_zh'] = '中文 (Mandarin Chinese)';
$string['lang_ar'] = 'العربية (Arabic)';
$string['lang_pt'] = 'Português (Portuguese)';
$string['lang_de'] = 'Deutsch (German)';
$string['lang_ja'] = '日本語 (Japanese)';
$string['lang_ko'] = '한국어 (Korean)';
$string['lang_vi'] = 'Tiếng Việt (Vietnamese)';
$string['lang_hi'] = 'हिंदी (Hindi)';
$string['lang_id'] = 'Bahasa Indonesia';
$string['lang_it'] = 'Italiano (Italian)';
$string['choose_language'] = 'Choose language';

// Task.
$string['task_process_jobs'] = 'Process AI Quiz Remedial Learning jobs';

// Errors.
$string['insufficientcredits'] = 'You do not have enough AI credits to generate remediation modules.';
$string['missingconfig'] = 'AI Quiz Remedial Learning is not configured. Please set your Site ID and API Key in the plugin settings or Central Config.';
$string['remediationfailed'] = 'AI remediation generation failed. The AI service did not return valid content.';

// Module pages.
$string['myremedialmodules'] = 'My Remedial Learning Modules';
$string['youranswer_heading'] = 'Your Answer';
$string['youselected_label'] = 'You selected: ';
$string['fixmodule_title'] = 'Revision Module';
$string['fixmodule_heading'] = 'Let\'s revisit this';
$string['section_explain'] = 'Listen & Learn';
$string['section_quickcheck'] = 'Quick Check';
$string['explain_image_alt'] = 'Explanatory illustration';
$string['checkanswer'] = 'Check Answer';
$string['fromquiz'] = 'From quiz: {$a}';
$string['nomodules'] = 'No revision modules yet. Complete a quiz and any incorrect answers will generate revision modules automatically.';
$string['viewmodule'] = 'Start Learning Revision';
$string['viewmodule_teacher'] = 'Review Module';
$string['backtomymodules'] = 'Back to My Modules';
// FIX-RL-BACK-BTN (v1.2.45): shorter, action-oriented label used by the always-visible
// bottom back button in the student review screen so teachers can navigate efficiently
// between questions in the same module list without scrolling back to the top.
$string['backtomodules'] = 'Back to Modules';
$string['backtoquiz'] = 'Back to Quiz';
$string['backtomodule'] = 'Back to Module';
$string['credits_used_label'] = '{$a} credits used';

// States.
$string['state_notstarted'] = 'Not Started';
$string['state_inprogress'] = 'In Progress';
$string['state_complete'] = 'Complete';
$string['state_failed'] = 'Generation Failed';
$string['status_label'] = 'Status';
$string['attempts_label'] = 'Attempts';

// Question preview label (v1.2.35).
$string['question_label'] = 'Question: {$a}';
$string['generation_failed_desc'] = 'AI content generation failed for this question. No credits were consumed.';

// Feedback.
$string['feedback_title'] = 'Revision Feedback';
$string['correct_heading'] = 'Correct!';
$string['incorrect_heading'] = 'Not quite right';
$string['module_completed'] = 'Revision complete! Great work.';
$string['tryagain'] = 'Try once more to complete this module.';
$string['correct_answer_reveal'] = 'The Correct Answer';

// Quiz review banner.
$string['review_banner_heading'] = 'Revision Modules Available';
$string['review_banner_message'] = 'You have {$a} question(s) to revisit. AI has generated personalised learning modules to help you understand what you got wrong.';
$string['review_banner_button'] = 'Start Learning Revisions';
$string['review_banner_complete_heading'] = 'All Revision Modules Complete';
$string['review_banner_complete_message'] = 'Well done! You have completed all the revision modules for this quiz.';
$string['review_banner_complete_button'] = 'View My Modules';

// Teacher view strings (v1.2.5+).
$string['teacherviewheading']  = 'All Student Remedial Learning Modules';
$string['teacherstudentview']  = 'Remedial Learning Modules for {$a}';
$string['student_label']       = 'Student: {$a}';
$string['nomodules_course']    = 'No remedial learning modules found for this course yet.';

// Teacher review — check question read-only view (v1.2.11).
$string['teacherreview_readonly']        = '(teacher review)';
$string['teacherreview_correctanswer']   = '(correct answer)';
$string['teacherreview_studentcomplete'] = 'Student completed this check in {$a} attempt(s).';
$string['teacherreview_studentinprogress'] = 'Student has started but not yet finished the check question ({$a} attempt(s) made).';
$string['teacherreview_studentnotstarted'] = 'Student has not yet attempted the check question.';

// Teacher quiz-page button (v1.2.33).
$string['view_learning_revisions'] = 'View Learning Revisions';

// Index page filters (v1.2.33).
$string['filter_by_quiz']       = 'Filter by quiz:';
$string['filter_all_quizzes']   = 'All quizzes';
$string['filter_by_student']    = 'Filter by student:';
$string['filter_all_students']  = 'All students';
$string['filter_apply']         = 'Apply';
$string['filter_reset']         = 'Reset filters';

// Capabilities.
$string['aiquizremedial:viewown'] = 'View own remedial learning modules';
$string['aiquizremedial:viewall'] = 'View all students\' remedial learning modules';
$string['aiquizremedial:manage'] = 'Manage remedial learning settings';

// Version 1.3.0 — learner visibility.
$string['showonquizview'] = 'Show banner on quiz page';
$string['showonquizview_desc'] = 'Show learners a banner on the quiz page (the page they return to after finishing an attempt) when they have revision modules for that quiz, or while modules are being prepared. Needed when quiz review is not available straight after the attempt.';
$string['showoncoursepage'] = 'Show banner on course page';
$string['showoncoursepage_desc'] = 'Show learners a reminder on the course page while they have unfinished revision modules in that course.';
$string['includeunanswered'] = 'Create modules for unanswered questions';
$string['includeunanswered_desc'] = 'When enabled, questions a learner left blank are treated as incorrect and get a revision module. Questions waiting for manual grading (e.g. essays) are always skipped.';
$string['banner_preparing_heading'] = 'Preparing your revision modules';
$string['banner_preparing_message'] = 'We are checking your answers and preparing personalised revision modules. This page will update automatically.';
$string['banner_preparing_message_n'] = '{$a} revision module(s) are being prepared for you. This page will update automatically when they are ready.';
$string['banner_more_preparing'] = '{$a} more are being prepared.';
$string['learnernav'] = 'My revision modules';
$string['reportnav'] = 'Remedial learning and insights';
$string['learner_progress'] = 'You have completed {$a->complete} of {$a->ready} revision module(s).';
$string['reviewagain'] = 'Review again';
$string['filter_showall'] = 'Show all';
$string['invalidkcid'] = 'The AI Knowledge Check activity for this job could not be found.';
$string['viewremediallearnings'] = 'View Remedial Learnings';
$string['noremedialforquiz'] = 'No remedial modules generated for this quiz yet.';

// Version 1.3.0 — report.
$string['reporttitle'] = 'Remedial learning report';
$string['report_allcourses'] = 'View across all my courses';
$string['filters'] = 'Filters';
$string['filtersactive'] = '{$a} active';
$string['filter_search'] = 'Search';
$string['filter_search_placeholder'] = 'Name, email, ID number, course, quiz or question…';
$string['filter_anycategory'] = 'Any category';
$string['filter_subcats'] = 'Include sub-categories';
$string['filter_andsubcats'] = '(+ sub-categories)';
$string['filter_anycourse'] = 'All courses';
$string['filter_anycohort'] = 'Any cohort';
$string['filter_anygroup'] = 'Any group';
$string['filter_teacher'] = 'Teacher';
$string['filter_anyteacher'] = 'Any teacher';
$string['filter_teachergroups'] = 'Only learners in the teacher\'s groups';
$string['filter_anyactivity'] = 'Any quiz / activity';
$string['filter_anystudent'] = 'Any student';
$string['filter_anystatus'] = 'Any status';
$string['filter_dates'] = 'Generated between';
$string['filter_datefrom'] = 'From';
$string['filter_dateto'] = 'To';
$string['filter_perpage'] = 'Rows per page';
$string['filter_typetosearch'] = 'Type to search…';
$string['filter_noselection'] = 'No selection';
$string['filter_remove'] = 'Remove this filter';
$string['filter_clearall'] = 'Clear all';
$string['filter_attempt'] = 'Attempt';
$string['filter_bystudent'] = 'Show only this student';
$string['state_generating'] = 'Being prepared';
$string['col_student'] = 'Student';
$string['col_activity'] = 'Quiz / activity';
$string['col_question'] = 'Question';
$string['col_tries'] = 'Quick-check tries';
$string['col_created'] = 'Generated';
$string['col_completed'] = 'Completed';
$string['col_credits'] = 'Credits used';
$string['col_error'] = 'Error';
$string['col_courses'] = 'Courses';
$string['col_modules'] = 'Modules';
$string['col_outstanding'] = 'Outstanding';
$string['col_notready'] = 'Not ready / failed';
$string['col_rate'] = 'Completion';
$string['col_lastactivity'] = 'Last activity';
$string['stat_modules'] = 'Remedial modules';
$string['stat_students'] = '{$a} student(s)';
$string['stat_rate'] = 'Completion rate';
$string['stat_rate_sub'] = '{$a->complete} of {$a->ready} ready modules';
$string['view_modules'] = 'Modules';
$string['view_students'] = 'Student summary';
$string['resultcount'] = '{$a} result(s)';
$string['noresults_filtered'] = 'No remedial modules match these filters.';
$string['retry'] = 'Retry';
$string['retryall'] = 'Retry all failed ({$a})';
$string['retryqueued'] = '{$a} job(s) queued for generation. They will be processed on the next cron run.';

// Privacy.
$string['privacy:metadata:local_aiqr_job'] = 'Remedial generation jobs created when a learner answers quiz questions incorrectly.';
$string['privacy:metadata:local_aiqr_job:userid'] = 'The learner the job was created for.';
$string['privacy:metadata:local_aiqr_job:attemptid'] = 'The quiz attempt that triggered the job.';
$string['privacy:metadata:local_aiqr_job:timecreated'] = 'When the job was created.';
$string['privacy:metadata:local_aiqr_completion'] = 'The learner\'s progress through each revision module.';
$string['privacy:metadata:local_aiqr_completion:userid'] = 'The learner.';
$string['privacy:metadata:local_aiqr_completion:state'] = 'Completion state of the revision module.';
$string['privacy:metadata:local_aiqr_completion:attempts_count'] = 'Number of quick-check answers submitted.';
$string['privacy:metadata:local_aiqr_completion:completed_at'] = 'When the module was completed.';
$string['privacy:metadata:aiservice'] = 'Question text and the learner\'s answer are sent to the AI service to generate the revision module. No name or email is sent; the Moodle user id is sent for credit accounting.';
$string['privacy:metadata:aiservice:userid'] = 'Moodle user id (for credit accounting).';
$string['privacy:metadata:aiservice:answer'] = 'The question and the learner\'s answer.';

// Version 1.3.0 — independent sources.
$string['filter_source'] = 'Source';
$string['filter_anysource'] = 'Quizzes and Knowledge Checks';
$string['source_quiz'] = 'Moodle quiz';
$string['source_knowledgecheck'] = 'AI Knowledge Check';
$string['fromkc'] = 'From Knowledge Check: {$a}';
$string['sources'] = 'Apply remedial learning to';
$string['sources_desc'] = 'Tick the activity types that should generate revision modules when a learner answers incorrectly. Tick none, one or both. Each works on its own: Moodle quizzes do not need AI Knowledge Check installed, and vice versa. Existing revision modules stay available to learners if you untick a type — only new ones stop being generated.';
$string['source_notinstalled'] = '(not installed on this site)';

// Version 1.4.0 — tutor lesson cards.
$string['card_tempting'] = 'Why it seemed right';
$string['card_principle'] = 'The key idea';
$string['card_example'] = 'See it at work';
$string['card_hook'] = 'Lock it in';
$string['takeaway_label'] = 'Remember';
$string['tutornote'] = 'Tutor note (teachers only):';
$string['listen_label'] = 'Listen';
$string['quickcheck_transfer'] = 'Same idea, new situation. Use what you just learned.';

// Version 1.4.1 — explanation images.
$string['image_open'] = 'Open image full size';
$string['imagestatus_retry'] = 'Image not ready yet — it will be retried automatically.';
$string['imagestatus_failed'] = 'Image could not be generated after several tries.';
$string['imagestatus_rejected'] = 'Image request was rejected and will not be retried until the cause is fixed.';
$string['imagestatus_contextonly'] = 'Image was generated from the explanation only — the original question could not be recovered for this older module.';
$string['imagestatus_undescribed'] = 'The question contains {$a} image(s) with no description, so the illustration was based on the question text only.';
$string['imagestatus_reason'] = 'Reason: {$a}';
$string['imagestatus_provenance'] = 'Image: {$a->model} ({$a->mode})';
$string['retryimage'] = 'Retry image';
$string['retryimagequeued'] = 'Image queued for another try on the next cron run.';

// Version 1.5.0: Quiz Insights and Suggested actions.
$string['aiquizremedial:manageactions'] = 'Manage suggested actions (change status, assign, comment)';
$string['messageprovider:actionurgent'] = 'Urgent suggested action (possible wrong answer key)';
$string['messageprovider:actiondigest'] = 'Weekly suggested actions digest';
$string['task_compute_insights'] = 'Compute quiz insights and suggested actions';
$string['task_send_action_digest'] = 'Send the weekly suggested actions digest';
$string['insights'] = 'Insights';
$string['suggestedactions'] = 'Suggested actions';
$string['insights_heading'] = 'Quiz insights and suggested actions';
$string['insights_heading_desc'] = 'Insights read every finished quiz attempt and Knowledge Check attempt (right and wrong answers) each night. Each rule below uses a rate AND a minimum number of learners, so small classes do not raise false alarms. Leave a value blank to use the default.';
$string['insights_window'] = 'Insights window';
$string['insights_window_desc'] = 'How far back statistics and rules look by default.';
$string['insights_history'] = 'History kept';
$string['insights_history_desc'] = 'How far back the nightly task reads attempts when it first runs. Older response rows are removed.';
$string['rule_enabled'] = 'Enable rule {$a}';
$string['param_default'] = 'Default: {$a}';
$string['param_minn'] = 'Minimum learners (first attempts)';
$string['param_maxcorrect'] = 'Fires below this % correct';
$string['param_minwrong'] = 'Minimum learners wrong';
$string['param_critical'] = 'Critical below this % correct';
$string['param_margin'] = 'Wrong option ahead of the key by (points)';
$string['param_minlearners'] = 'Minimum learners choosing it';
$string['param_maxdisc'] = 'Discrimination below';
$string['param_mincorrect'] = 'Only when % correct is at least';
$string['param_easy'] = 'Too easy above this % correct';
$string['param_deadminn'] = 'Minimum learners for unused options';
$string['param_deadrate'] = 'Unused option: chosen by less than (%)';
$string['param_blank'] = 'Left blank by at least (%)';
$string['param_minmodules'] = 'Minimum revision modules';
$string['param_agedays'] = 'Only modules at least this many days old';
$string['param_mincompletion'] = 'Fires below this % completed';
$string['param_mincompleted'] = 'Minimum completed modules';
$string['param_minpass'] = 'Fires below this % passing the Quick Check first try';
$string['param_minreenc'] = 'Minimum learners who met the question again';
$string['param_minrecovered'] = 'Fires below this % recovered';
$string['param_mingroup'] = 'Minimum learners in the group (and in the rest of the course)';
$string['param_gap'] = 'Minimum gap (points)';
$string['param_change'] = 'Minimum change after an edit (points)';
$string['param_share'] = 'Share of course credits (%)';

$string['rule_R1_name'] = 'Too hard or confusing';
$string['rule_R1_desc'] = 'A question most learners get wrong first time.';
$string['rule_R1_text'] = 'Review {$a->where}: only {$a->pct} of {$a->n} learners got it right first time. Most wrong answers were “{$a->top}” ({$a->toppct}). Check the wording and whether it was taught.';
$string['rule_R1_text_notop'] = 'Review {$a->where}: only {$a->pct} of {$a->n} learners got it right first time. Check the wording and whether it was taught.';
$string['rule_R2_name'] = 'Possible wrong answer key';
$string['rule_R2_desc'] = 'Stronger learners get the question wrong more often than weaker learners (negative discrimination). Notifies immediately.';
$string['rule_R2_text'] = '{$a->where} may be marked with the wrong answer: stronger learners get it wrong more often than weaker ones (discrimination {$a->disc}, {$a->n} learners). Check the key before more grades are affected.';
$string['rule_R3_name'] = 'A wrong option beats the key';
$string['rule_R3_desc'] = 'One wrong option is chosen more often than the correct answer. Notifies immediately.';
$string['rule_R3_text'] = 'In {$a->where}, more learners chose “{$a->top}” ({$a->toppct}) than the correct answer “{$a->key}” ({$a->keypct}). “{$a->top}” may also be correct, or the wording is ambiguous.';
$string['rule_R4_name'] = 'Doesn\'t separate learners';
$string['rule_R4_desc'] = 'Learners who know the topic do no better on this question than those who don\'t.';
$string['rule_R4_text'] = '{$a->where} doesn\'t separate learners who know the topic from those who don\'t (discrimination {$a->disc}, {$a->pct} correct, {$a->n} learners). Consider sharper options.';
$string['rule_R5_name'] = 'Too easy or unused options';
$string['rule_R5_desc'] = 'Information only: almost everyone gets it right, or some options are almost never chosen. Easy questions are often correct in competency-based training.';
$string['rule_R5_text_easy'] = '{$a->where} is answered correctly by {$a->pct} of {$a->n} learners. Information only: keep it if it assesses required knowledge.';
$string['rule_R5_text_dead'] = 'In {$a->where}, the options {$a->dead} are almost never chosen ({$a->n} learners). Information only: replace unused options with more plausible ones.';
$string['rule_R6_name'] = 'Often left blank';
$string['rule_R6_desc'] = 'Learners skip or run out of time on this question. High when it sits in the last fifth of the quiz.';
$string['rule_R6_text'] = '{$a->blankpct} of learners ({$a->blank} of {$a->n}) left {$a->where} blank. Check it is clear what is being asked.';
$string['rule_R6_text_late'] = '{$a->blankpct} of learners ({$a->blank} of {$a->n}) left {$a->where} blank. It sits near the end of the quiz, so check the time limit.';
$string['rule_R8_name'] = 'Revision not being done';
$string['rule_R8_desc'] = 'Most revision modules in a course are not completed.';
$string['rule_R8_text'] = 'Only {$a->rate} of revision modules in {$a->where} were completed ({$a->completed} of {$a->modules}, counting modules at least two weeks old). Mention them in class or add them to completion requirements.';
$string['rule_R9_name'] = 'Revision not landing';
$string['rule_R9_desc'] = 'Learners complete the revision for a question but fail its Quick Check.';
$string['rule_R9_text'] = 'The revision for {$a->where} isn\'t landing: {$a->qcpct} of learners who completed it passed its Quick Check first try ({$a->qcfirst} of {$a->completed}). Review the question or the revision.';
$string['rule_R10_name'] = 'Improvement not sustained';
$string['rule_R10_desc'] = 'Learners who were given revision still get the question wrong the next time they meet it.';
$string['rule_R10_text'] = 'Learners who were given revision for {$a->where} still got it wrong next time ({$a->recovered} of {$a->reenc} recovered). Address it in class.';
$string['rule_R11_name'] = 'Group finds it harder';
$string['rule_R11_desc'] = 'One group does much worse on a question than the rest of the course (statistically real, not chance). Only that group\'s teachers and people who see all groups are shown it.';
$string['rule_R11_text'] = 'Learners in {$a->group} found {$a->where} harder: {$a->grouprate} correct first try ({$a->groupn} learners) vs {$a->restrate} for the rest of the course. Worth a quick recap with this group.';
$string['rule_R12_name'] = 'Change after an edit';
$string['rule_R12_desc'] = '% correct moved sharply after the question was edited. High if it got worse; information if it improved.';
$string['rule_R12_text'] = 'Since {$a->where} was edited on {$a->edited}, % correct first try fell from {$a->before} to {$a->after}. Check the edit.';
$string['rule_R12_text_up'] = 'Since {$a->where} was edited on {$a->edited}, % correct first try rose from {$a->before} to {$a->after}. Impact confirmed.';
$string['rule_R13_name'] = 'Knowledge Check question too hard';
$string['rule_R13_desc'] = 'A Knowledge Check question most learners get wrong.';
$string['rule_R13_text'] = 'In {$a->where}, only {$a->pct} of {$a->n} learners answered correctly. Most wrong answers were “{$a->top}” ({$a->toppct}). Review the question and options.';
$string['rule_R13_text_notop'] = 'In {$a->where}, only {$a->pct} of {$a->n} learners answered correctly. Review the question and options.';
$string['rule_R14_name'] = 'Credit hotspot';
$string['rule_R14_desc'] = 'One question uses a large share of a course\'s AI credits.';
$string['rule_R14_text'] = '{$a->where} generated {$a->modules} revision modules ({$a->share} of the course\'s AI credits in the last {$a->window} days). Fixing the question is cheaper than remediating it.';
$string['where_activity'] = '{$a->q} in {$a->activity} ({$a->course}, {$a->category})';
$string['where_course'] = '{$a->course} ({$a->category})';
$string['smallsample'] = 'Based on {$a} learners, so treat this as a hint.';
$string['smallsample_banner'] = 'Based on {$a} learners, so treat these figures as a hint.';
$string['fixfailed_prefix'] = 'Fix didn\'t work: after the change, % correct went from {$a->before} to {$a->after}.';
$string['deletedactivity'] = '(deleted activity)';
$string['deletedquestion'] = '(deleted question)';

$string['sev_critical'] = 'Critical';
$string['sev_high'] = 'High';
$string['sev_medium'] = 'Medium';
$string['sev_low'] = 'Low';
$string['sev_info'] = 'Info';
$string['astatus_open'] = 'Open';
$string['astatus_acknowledged'] = 'Acknowledged';
$string['astatus_inprogress'] = 'In progress';
$string['astatus_done'] = 'Done';
$string['astatus_dismissed'] = 'Dismissed';
$string['astatus_snoozed'] = 'Snoozed';
$string['astatus_resolved'] = 'Auto-resolved';
$string['agroup_todo'] = 'To do';
$string['agroup_snoozed'] = 'Snoozed';
$string['agroup_done'] = 'Done';
$string['agroup_dismissed'] = 'Dismissed';
$string['agroup_resolved'] = 'Auto-resolved';
$string['agroup_all'] = 'All';
$string['do_acknowledged'] = 'Acknowledge';
$string['do_inprogress'] = 'Start';
$string['do_done'] = 'Mark done';
$string['do_open'] = 'Reopen';
$string['dismiss'] = 'Dismiss';
$string['dismiss_intended'] = 'Intended difficulty';
$string['dismiss_cohort'] = 'Known cohort issue';
$string['dismiss_falsepositive'] = 'False positive';
$string['dismiss_other'] = 'Other';
$string['dismissreasonrequired'] = 'Choose a reason to dismiss this action.';
$string['dismissedbecause'] = 'Dismissed: {$a}';
$string['choosereason'] = 'Choose a reason…';
$string['optionalnote'] = 'Note (optional)';
$string['snooze'] = 'Snooze until';
$string['snoozeduntil'] = 'Snoozed until {$a}';
$string['assign'] = 'Assign';
$string['assignedto'] = 'Owner: {$a}';
$string['assignedtome'] = 'Assigned to me';
$string['addcomment'] = 'Add comment';
$string['moreoptions'] = 'More…';
$string['nobody'] = 'Nobody';
$string['system_user'] = 'System';
$string['raised'] = 'Raised {$a}';
$string['grouponly'] = 'Group only';
$string['history'] = 'History ({$a})';
$string['action_updated'] = 'Action updated.';
$string['reopen_returned'] = 'Reopened: the problem came back.';
$string['reopen_snoozeended'] = 'Reopened: snooze ended.';
$string['reopen_worse'] = 'Reopened: it got worse, the sample doubled or the question was edited.';
$string['reopen_fixfailed'] = 'Reopened: the fix didn\'t work.';
$string['log_created'] = 'Raised';
$string['log_status'] = '{$a->from} → {$a->to}';
$string['log_comment'] = 'Comment';
$string['log_assigned'] = 'Assigned to {$a}';
$string['log_autoresolved'] = 'The condition cleared on two nightly runs.';
$string['impact_improved'] = 'Improved';
$string['impact_nochange'] = 'No change';
$string['impact_worse'] = 'Worse';
$string['impact_insufficient'] = 'Impact: not enough new attempts in 60 days to measure.';
$string['impact_text'] = 'Before {$a->before} (n {$a->beforen}) → after {$a->after} (n {$a->aftern}): {$a->verdict}.';
$string['impact_pending'] = 'Measuring impact: waiting for 10 new first attempts.';
$string['anyseverity'] = 'Any severity';
$string['anyrule'] = 'Any rule';
$string['export_actions'] = 'Export actions';
$string['export_log'] = 'Export audit log (CSV)';
$string['actions_empty_title'] = 'Nothing here';
$string['actions_empty_todo'] = 'No suggested actions right now. Rules run every night over the last 90 days of first attempts.';
$string['actions_empty'] = 'No actions match these filters.';
$string['viewquestion'] = 'View question';
$string['openactivity'] = 'Open activity';
$string['openaction'] = 'Open action';
$string['seeoutstanding'] = 'See outstanding modules';
$string['editquestion'] = 'Edit question';
$string['col_rule'] = 'Rule';
$string['col_action'] = 'Action';
$string['col_change'] = 'Change';
$string['col_note'] = 'Note';
$string['col_evidence'] = 'Evidence';
$string['col_severity'] = 'Severity';
$string['col_details'] = 'Details';
$string['col_assignee'] = 'Owner';
$string['col_updated'] = 'Updated';
$string['col_resolved'] = 'Closed';
$string['col_dismissreason'] = 'Dismiss reason';
$string['col_impact'] = 'Impact';
$string['msg_urgent_subject'] = 'Check now: {$a}';
$string['msg_open_action'] = 'Open the action';
$string['msg_open_actions'] = 'Open suggested actions';
$string['digest_subject'] = 'Your suggested actions this week';
$string['digest_new'] = 'New high-priority actions ({$a})';
$string['digest_impact'] = 'Fixes that worked ({$a})';
$string['digest_open'] = 'You have {$a} open suggested actions.';

$string['period_caption'] = '{$a->from} – {$a->to} ({$a->days} days), compared with the {$a->days} days before';
$string['stats_updated'] = 'Statistics updated {$a} ago';
$string['stats_never'] = 'Statistics not calculated yet';
$string['recalc'] = 'Recalculate now';
$string['recalc_help'] = 'Recalculate this course\'s statistics and rules on the next cron run (at most once an hour).';
$string['recalc_queued'] = 'Recalculation queued. It runs on the next cron run, usually within a few minutes.';
$string['recalc_wait'] = 'This course was recalculated recently. You can ask again after {$a}.';
$string['insights_empty_title'] = 'No quiz answers to analyse yet';
$string['insights_empty_body'] = 'Insights appear after the nightly task reads finished quiz and Knowledge Check attempts. An administrator can run "Compute quiz insights and suggested actions" from Scheduled tasks to start now.';
$string['insights_empty_filtered'] = 'No first attempts match these filters in this period. Try a longer date range or remove some filters.';
$string['kpi_attention'] = 'Questions needing attention';
$string['kpi_attention_help'] = 'Questions with an open suggested action, counted once at their highest severity.';
$string['kpi_attention_none'] = 'Nothing flagged';
$string['kpi_affected'] = 'Learners affected';
$string['kpi_affected_help'] = 'Learners who got at least one flagged question (medium severity or above) wrong on their first attempt.';
$string['kpi_of_learners'] = 'of {$a} learners with answers';
$string['kpi_median'] = 'Median % correct first try';
$string['kpi_median_help'] = 'Average mark on each learner\'s first attempt at a question (Moodle\'s "facility index"), then the middle value across questions.';
$string['kpi_questions_n'] = 'across {$a} questions';
$string['kpi_learners_n'] = '{$a} learners';
$string['kpi_completion'] = 'Revision completed';
$string['kpi_completion_help'] = 'Revision modules completed ÷ modules generated, counting modules at least 7 days old.';
$string['kpi_modules_n'] = '{$a} modules';
$string['kpi_recovered'] = 'Recovered next attempt';
$string['kpi_recovered_help'] = 'Of learners given revision who met the same question again in a later attempt, the share who got it right.';
$string['kpi_recovered_n'] = '{$a} met the question again';
$string['delta_pts'] = '{$a->n} pts vs previous {$a->days} days';
$string['delta_n'] = '{$a->n} vs previous {$a->days} days';
$string['pq_title'] = 'Problem questions';
$string['pq_sub'] = 'Ranked by learners wrong × how hard the question is. Select a question to diagnose it.';
$string['pq_help'] = 'Only questions answered by at least 5 learners are shown. Figures below 10 learners are greyed as a low sample.';
$string['pq_none'] = 'No question has been answered wrongly by enough learners in this period.';
$string['pq_n'] = '{$a->wrong} of {$a->n} wrong';
$string['tip_pq'] = '{$a->pct} correct first try · {$a->wrong} of {$a->n} learners wrong';
$string['lowsample'] = 'low sample';
$string['showmore'] = 'Show top {$a}';
$string['pct_correct_first'] = '% correct first try';
$string['pct_blank'] = '% left blank';
$string['position'] = 'Question {$a->slot} of {$a->of}';
$string['spark_head'] = '12 weeks';
$string['spark_label'] = '% correct first try, last 12 weeks';
$string['topwrong'] = 'Top wrong answer';
$string['correctanswer'] = 'Correct';
$string['leftblank'] = 'Left blank';
$string['discrimination'] = 'Discrimination';
$string['disc_short'] = 'Discrimination {$a}';
$string['disc_good'] = 'good';
$string['disc_weak'] = 'weak';
$string['disc_poor'] = 'poor';
$string['disc_problem'] = 'problem';
$string['disc_needs'] = 'Needs {$a} learners';
$string['disc_quizonly'] = 'Quizzes only';
$string['help_disc'] = 'How well the question separates stronger from weaker learners (Moodle\'s discrimination index, 0–100). 30+ good, 20–29 weak, below 20 poor, below 0 a problem (often a wrong key). Shown from 30 learners.';
$string['help_thirds'] = 'What the top and bottom third of learners (by total score on the activity) chose. If stronger learners pick a wrong option, the key or wording may be wrong.';
$string['classstats_hidden'] = 'This chart uses every learner in the course, so it is only shown to people who can see all groups.';
$string['classstats_hidden_short'] = 'All groups only';
$string['trend_title'] = '% correct first try over time';
$string['trend_sub'] = 'Weekly, all questions in view. Weeks with fewer than 2 answers are left as gaps.';
$string['tip_week'] = 'Week of {$a->week}: {$a->pct} correct ({$a->n} answers)';
$string['funnel_title'] = 'Does the revision work?';
$string['funnel_sub'] = 'Revision modules created in this period.';
$string['funnel_help'] = 'Generated → opened → completed → passed the Quick Check first try; then, of learners who met the same question again in a later attempt, how many got it right.';
$string['funnel_none'] = 'No revision modules were generated in this period.';
$string['fn_generated'] = 'Generated';
$string['fn_opened'] = 'Opened';
$string['fn_completed'] = 'Completed';
$string['fn_qcfirst'] = 'Quick Check first try';
$string['fn_ofcompleted'] = 'of completed';
$string['fn_recovered'] = 'Recovered next attempt';
$string['fn_ofmet'] = 'of {$a} who met it again';
$string['tip_funnel'] = '{$a->label}: {$a->n} of {$a->of} ({$a->pct})';
$string['heatmap_title'] = 'Where each group goes wrong';
$string['heatmap_sub'] = '% correct first try for the top problem questions, by group. Darker = fewer correct.';
$string['heatmap_pickcourse'] = 'Pick one course in the filters to compare groups question by question.';
$string['legend_morecorrect'] = 'More correct';
$string['legend_fewercorrect'] = 'Fewer correct';
$string['legend_small'] = 'Hidden: fewer than {$a} learners';
$string['tip_cell'] = '{$a->group} · {$a->q}: {$a->pct} correct ({$a->n} learners)';
$string['tip_cell_small'] = '{$a->group}: hidden, fewer than {$a->min} learners';
$string['scatter_title'] = 'Question health';
$string['scatter_sub'] = 'Each dot is a quiz question: difficulty across, discrimination up. Select a dot to open it.';
$string['scatter_x'] = '% correct first try';
$string['scatter_y'] = 'Discrimination';
$string['scatter_note'] = 'Hollow dots have fewer than 30 learners. Uses every learner in the course, whatever the learner filters.';
$string['scatter_none'] = 'Not enough quiz attempts yet (at least 10 learners per question).';
$string['tip_scatter'] = '{$a->name} ({$a->activity}): {$a->pct} correct, discrimination {$a->disc}, {$a->n} learners';
$string['zone_broken'] = 'Probably broken';
$string['zone_easy'] = 'Too easy';
$string['zone_hard'] = 'Challenging';
$string['zone_healthy'] = 'Healthy';
$string['groups_title'] = 'Group comparison';
$string['groups_sub'] = '% correct first try per group, with the likely range (95%).';
$string['groups_note'] = 'Small groups vary by chance: overlapping ranges mean no real difference. Groups under {$a} learners are hidden.';
$string['groups_none'] = 'Needs at least two groups with {$a} or more learners.';
$string['groups_pickcourse'] = 'Pick one course in the filters to compare its groups.';
$string['allgroups_course'] = 'Whole course';
$string['tip_group'] = '{$a->name}: {$a->pct} correct (likely {$a->lo}–{$a->hi}), {$a->n} learners';
$string['viewastable'] = 'View as table';
$string['backtoinsights'] = 'Back to insights';
$string['question_notfound'] = 'This question has no answers in view, or you do not have access to it.';
$string['answers_title'] = 'Answer breakdown';
$string['answers_sub'] = '{$a} first attempts on this version.';
$string['answers_latest'] = 'Latest version (v{$a}) only; answer options can change between versions.';
$string['answers_none'] = 'No answer breakdown for this question type.';
$string['tip_option'] = '“{$a->label}”: {$a->pct} ({$a->n} of {$a->total})';
$string['col_all'] = 'All';
$string['col_stronger'] = 'Top third';
$string['col_weaker'] = 'Bottom third';
$string['col_option'] = 'Option';
$string['col_n'] = 'Learners';
$string['col_wrong'] = 'Wrong';
$string['col_week'] = 'Week';
$string['col_answers'] = 'Answers';
$string['col_stage'] = 'Stage';
$string['col_learners'] = 'Learners';
$string['col_of'] = 'Out of';
$string['col_range'] = 'Likely range';
$string['col_version'] = 'Version';
$string['col_edited'] = 'Edited';
$string['linkedactions'] = 'Suggested actions';
$string['linkedactions_none'] = 'No suggested actions for this question.';
$string['misconceptions_title'] = 'What learners misunderstood';
$string['misconceptions_sub'] = 'From the AI diagnosis in recent revision modules (not attributed to anyone).';
$string['qtrend_title'] = '% correct first try, by week';
$string['qtrend_sub'] = 'This question only.';
$string['qtrend_sub_edits'] = 'Markers show when the question was edited; the shaded area is after the latest edit.';
$string['tip_edit'] = 'Edited (v{$a->v}) on {$a->date}: {$a->pct} correct on this version ({$a->n} learners)';
$string['privacy:metadata:local_aiqr_resp'] = 'One row per learner per question per finished attempt, used for quiz insights (% correct, answer breakdown).';
$string['privacy:metadata:local_aiqr_resp:userid'] = 'The learner.';
$string['privacy:metadata:local_aiqr_resp:attemptid'] = 'The quiz or Knowledge Check attempt.';
$string['privacy:metadata:local_aiqr_resp:fraction'] = 'The mark on the question.';
$string['privacy:metadata:local_aiqr_resp:answerlabel'] = 'The option the learner chose.';
$string['privacy:metadata:local_aiqr_resp:timefinished'] = 'When the attempt was finished.';
$string['privacy:metadata:local_aiqr_action_log'] = 'Audit trail of changes to suggested actions.';
$string['privacy:metadata:local_aiqr_action_log:userid'] = 'The person who made the change.';
$string['privacy:metadata:local_aiqr_action_log:comment'] = 'Their comment.';
$string['privacy:metadata:local_aiqr_action_log:timecreated'] = 'When the change was made.';
$string['privacy:metadata:local_aiqr_action'] = 'Suggested actions and who owns them.';
$string['privacy:metadata:local_aiqr_action:assigneeid'] = 'The person the action is assigned to.';
$string['privacy:responses'] = 'Quiz insights answers';
$string['privacy:actionlog'] = 'Suggested action changes';
$string['quizinsights'] = 'Quiz insights';
$string['kpi_modules_recent'] = '{$a} modules, some under 7 days old';
$string['allgroups_mine'] = 'All my groups';
