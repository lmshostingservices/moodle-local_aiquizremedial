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
$string['enableimages_desc'] = 'Generate AI images to accompany explanations. Uses additional credits per question.';
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

$string['privacy:metadata'] = 'The local_aiquizremedial plugin does not store any personal data.';
