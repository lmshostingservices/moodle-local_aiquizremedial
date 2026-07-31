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
 * Library functions for AI Quiz Remedial Learning.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get the Site ID, checking Central Config first then local settings.
 *
 * @return string
 */
function local_aiquizremedial_get_siteid(): string {
    global $CFG;
    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
        if (function_exists('local_aiconfig_get_siteid')) {
            $val = local_aiconfig_get_siteid();
            if (!empty($val)) {
                return $val;
            }
        }
    }
    return get_config('local_aiquizremedial', 'siteid') ?: '';
}

/**
 * Get the API Key, checking Central Config first then local settings.
 *
 * @return string
 */
function local_aiquizremedial_get_apikey(): string {
    global $CFG;
    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
        if (function_exists('local_aiconfig_get_apikey')) {
            $val = local_aiconfig_get_apikey();
            if (!empty($val)) {
                return $val;
            }
        }
    }
    return get_config('local_aiquizremedial', 'apikey') ?: '';
}

/**
 * Inject a "View Learning Revisions" button for teachers on quiz view pages.
 *
 * v1.2.33 TEACHER-BUTTON: Hooked via local_aiquizremedial_before_footer().
 * Moodle calls this function automatically before every page footer.
 * We check: (1) current page type is mod-quiz-view, (2) current user has
 * the viewall capability in the module context. If both pass, a prominent
 * button is rendered linking to index.php?courseid=X&quizid=Y so teachers
 * can jump directly to the filtered Learning Revisions list for that quiz.
 */
function local_aiquizremedial_before_footer(): void {
    global $PAGE;

    // Only on quiz view pages.
    if ($PAGE->pagetype !== 'mod-quiz-view') {
        return;
    }

    // Must be a quiz CM.
    $cm = $PAGE->cm;
    if (!$cm || $cm->modname !== 'quiz') {
        return;
    }

    // Only for users with teacher-level capability.
    $context = context_module::instance($cm->id);
    if (!has_capability('local/aiquizremedial:viewall', $context)) {
        return;
    }

    $courseid = $PAGE->course->id;
    $quizid   = (int) $cm->instance;

    $url = new moodle_url('/local/aiquizremedial/index.php', [
        'courseid' => $courseid,
        'quizid'   => $quizid,
    ]);

    echo html_writer::div(
        html_writer::link(
            $url,
            get_string('view_learning_revisions', 'local_aiquizremedial'),
            ['class' => 'btn btn-info']
        ),
        'container-fluid my-3'
    );
}

function local_aiquizremedial_tts_generate(string $text): ?string {
    if (empty($text)) {
        return null;
    }
    $siteid = local_aiquizremedial_get_siteid();
    $apikey = local_aiquizremedial_get_apikey();
    if (empty($siteid) || empty($apikey)) {
        return null;
    }
    try {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 60,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey,
        ]);
        $body = json_encode([
            'siteId' => $siteid,
            'text'   => $text,
            'action' => 'remediation_tts',
        ]);
        $response = $curl->post('https://lms-labs.com/api/tts/generate', $body);
        $data = json_decode($response, true);
        return $data['audio_url'] ?? null;
    } catch (\Throwable $e) {
        // Silently fail — no audio is better than the wrong audio.
        return null;
    }
}

