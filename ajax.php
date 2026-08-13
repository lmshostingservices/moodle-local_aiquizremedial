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

define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/local/aiquizremedial/lib.php');

$action  = required_param('action', PARAM_ALPHANUMEXT);
$sesskey = required_param('sesskey', PARAM_RAW);

confirm_sesskey($sesskey);
$PAGE->set_context(context_system::instance());
require_login();

header('Content-Type: application/json; charset=utf-8');

$siteid = local_aiquizremedial_get_siteid();
$apikey = local_aiquizremedial_get_apikey();

switch ($action) {

    case 'getcredits':
        if (empty($siteid) || empty($apikey)) {
            echo json_encode(['success' => false, 'error' => 'Plugin not configured. Ask your admin to set Site ID and API Key.']);
            break;
        }

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey,
        ]);
        $response = $curl->get('https://lms-labs.com/api/credits?siteId=' . urlencode($siteid));
        $data = json_decode($response, true);

        $breakdown = \local_aiquizremedial\credit_calculator::get_breakdown();

        echo json_encode([
            'success'   => true,
            'credits'   => $data['credits'] ?? 0,
            'unlimited' => $data['unlimited'] ?? false,
            'cost'      => $breakdown,
        ]);
        break;

    case 'getmodules':
        $courseid = optional_param('courseid', 0, PARAM_INT);
        $quizid   = optional_param('quizid',   0, PARAM_INT);
        $kcid     = optional_param('kcid',      0, PARAM_INT);

        $sql = "SELECT m.id AS moduleid, m.explain_text, m.explain_audio_url, m.explain_image_url,
                       m.check_question_json, m.feedback_json, m.credits_used,
                       j.quizid, j.kcid, j.questionid, j.attemptid, j.courseid, j.sourcetype,
                       c.state, c.attempts_count, c.completed_at
                FROM {local_aiqr_module} m
                JOIN {local_aiqr_job} j ON j.id = m.jobid
                LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = :userid
                WHERE j.userid = :userid2 AND j.status = 'ready'";

        $params = ['userid' => $USER->id, 'userid2' => $USER->id];

        if ($courseid > 0) {
            $sql .= " AND j.courseid = :courseid";
            $params['courseid'] = $courseid;
        }
        if ($quizid > 0) {
            $sql .= " AND j.quizid = :quizid";
            $params['quizid'] = $quizid;
        }
        if ($kcid > 0) {
            $sql .= " AND j.kcid = :kcid";
            $params['kcid'] = $kcid;
        }

        $sql .= " ORDER BY m.timecreated DESC";

        $records = $DB->get_records_sql($sql, $params);
        $modules = [];

        foreach ($records as $rec) {
            $modules[] = [
                'moduleid'          => (int) $rec->moduleid,
                'quizid'            => $rec->quizid !== null ? (int) $rec->quizid : null,
                'kcid'              => $rec->kcid   !== null ? (int) $rec->kcid   : null,
                'sourcetype'        => $rec->sourcetype ?? 'quiz',
                'questionid'        => (int) $rec->questionid,
                'attemptid'         => (int) $rec->attemptid,
                'courseid'          => (int) $rec->courseid,
                'explain_text'      => $rec->explain_text,
                'explain_audio_url' => $rec->explain_audio_url,
                'explain_image_url' => $rec->explain_image_url,
                'check_question'    => json_decode($rec->check_question_json, true),
                'feedback'          => json_decode($rec->feedback_json, true),
                'credits_used'      => (int) $rec->credits_used,
                'state'             => $rec->state ?? 'notstarted',
                'attempts_count'    => (int) ($rec->attempts_count ?? 0),
                'completed_at'      => $rec->completed_at,
            ];
        }

        echo json_encode(['success' => true, 'modules' => $modules]);
        break;

    case 'submitanswer':
        $moduleid = required_param('moduleid', PARAM_INT);
        $choice   = required_param('choice', PARAM_INT);

        $module = $DB->get_record('local_aiqr_module', ['id' => $moduleid], '*', MUST_EXIST);
        $job    = $DB->get_record('local_aiqr_job', ['id' => $module->jobid], '*', MUST_EXIST);

        if ((int) $job->userid !== (int) $USER->id) {
            $context = context_course::instance($job->courseid);
            require_capability('local/aiquizremedial:viewall', $context);
        }

        $completion = $DB->get_record('local_aiqr_completion', [
            'moduleid' => $moduleid,
            'userid'   => $USER->id,
        ]);

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

        $check   = json_decode($module->check_question_json, true);
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

        $feedback = json_decode($module->feedback_json, true);
        $fb = $feedback[$choice] ?? null;

        echo json_encode([
            'success'        => true,
            'correct'        => $isCorrect,
            'state'          => $completion->state,
            'attempts_count' => (int) $completion->attempts_count,
            'feedback'       => $fb,
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        break;
}
