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
 * Legacy footer callback — only used on Moodle 4.0–4.3.
 *
 * On Moodle 4.4+ core skips this function because the plugin registers the
 * before_footer_html_generation hook (db/hooks.php). Before v1.3.0 this legacy function
 * only rendered the teacher button, so on Moodle 4.0–4.3 learners NEVER saw the
 * "Revision Modules Available" banner. It now renders exactly the same HTML as the hook.
 *
 * @return string
 */
function local_aiquizremedial_before_footer() {
    return \local_aiquizremedial\hook\before_footer::get_html();
}

/**
 * Add "Revision modules" / "Remedial learning report" to the course navigation
 * (appears in the course "More" menu in Boost).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_aiquizremedial_extend_navigation_course($navigation, $course, $context) {
    if (!get_config('local_aiquizremedial', 'enabled') || !isloggedin() || isguestuser()) {
        return;
    }
    if (has_capability('local/aiquizremedial:viewall', $context)) {
        $navigation->add(
            get_string('reportnav', 'local_aiquizremedial'),
            new moodle_url('/local/aiquizremedial/report.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM, null, 'local_aiquizremedial_report',
            new pix_icon('i/report', '')
        );
    } else if (has_capability('local/aiquizremedial:viewown', $context)) {
        $navigation->add(
            get_string('learnernav', 'local_aiquizremedial'),
            new moodle_url('/local/aiquizremedial/index.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM, null, 'local_aiquizremedial_mine',
            new pix_icon('i/course', '')
        );
    }
}

/**
 * Generate TTS audio for a piece of text.
 *
 * @param string $text
 * @return string|null audio URL
 */
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

