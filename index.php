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
 * Learner's own revision modules.
 *
 * v1.3.0: teachers are redirected to report.php (filterable report) with their old URL
 * parameters mapped across, so existing links and bookmarks keep working. The learner view
 * no longer joins the aiknowledgecheck tables unless that plugin is installed (the
 * unguarded join caused "Error reading from database" on sites without it), shows modules
 * that are still being prepared, and can be filtered by course, quiz and status.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once('../../config.php');

use local_aiquizremedial\helper;

$courseid     = optional_param('courseid', 0, PARAM_INT);
$attemptid    = optional_param('attemptid', 0, PARAM_INT);
$userid       = optional_param('userid', 0, PARAM_INT);
$quizid       = optional_param('quizid', 0, PARAM_INT);
$kcid         = optional_param('kcid', 0, PARAM_INT);
$filteruserid = optional_param('filteruserid', 0, PARAM_INT);
$status       = optional_param('status', '', PARAM_ALPHA);
$mine         = optional_param('mine', 0, PARAM_BOOL);

require_login();

$context = $courseid > 0 ? context_course::instance($courseid) : context_system::instance();

// ── Teachers → report.php ───────────────────────────────────────────────────
$isteacher = has_capability('local/aiquizremedial:viewall', $context);
if (!$isteacher && !$courseid && !$mine) {
    $isteacher = (bool) get_user_capability_course('local/aiquizremedial:viewall', $USER->id, true, '', '', 1);
}
if ($isteacher && !$mine && $userid !== (int) $USER->id) {
    $params = ['courseid' => $courseid ?: null, 'attemptid' => $attemptid ?: null];
    if ($quizid) {
        $params['activity[0]'] = 'quiz-' . $quizid;
    } else if ($kcid) {
        $params['activity[0]'] = 'kc-' . $kcid;
    }
    if ($userid || $filteruserid) {
        $params['student[0]'] = $userid ?: $filteruserid;
    }
    redirect(new moodle_url('/local/aiquizremedial/report.php', array_filter($params)));
}

// ── Learner view ────────────────────────────────────────────────────────────
if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    require_capability('local/aiquizremedial:viewown', $context);
} else {
    $PAGE->set_context($context);
}

$urlparams = array_filter(
    ['courseid' => $courseid, 'attemptid' => $attemptid, 'quizid' => $quizid, 'kcid' => $kcid,
    'status' => $status,
    'mine' => $mine ?: null]);
$PAGE->set_url(new moodle_url('/local/aiquizremedial/index.php', $urlparams));
$pageheading = get_string('myremedialmodules', 'local_aiquizremedial');
$PAGE->set_title($pageheading);
$PAGE->set_heading($courseid ? format_string($course->fullname, true, ['context' => $context]) : $pageheading);
$PAGE->set_pagelayout($courseid ? 'incourse' : 'standard');

$kc = helper::kc_installed();
$statussql = helper::status_sql();
$where = ['j.userid = :userid', 'j.questionid IS NOT NULL'];
$params = ['userid' => $USER->id, 'userid2' => $USER->id];
if ($courseid) {
    $where[] = 'j.courseid = :courseid';
    $params['courseid'] = $courseid;
}
if ($attemptid) {
    $where[] = 'j.attemptid = :attemptid';
    $params['attemptid'] = $attemptid;
}
if ($quizid) {
    $where[] = "j.quizid = :quizid AND j.sourcetype = 'quiz'";
    $params['quizid'] = $quizid;
}
if ($kcid) {
    $where[] = "j.kcid = :kcid AND j.sourcetype = 'knowledgecheck'";
    $params['kcid'] = $kcid;
}
$basewhere = implode(' AND ', $where);
if (in_array($status, ['notstarted', 'inprogress', 'complete'])) {
    $where[] = "($statussql) = :status";
    $params['status'] = $status;
} else if ($status === 'outstanding') {
    $where[] = "($statussql) IN ('notstarted', 'inprogress')";
}

$sql = "SELECT m.id AS moduleid, m.credits_used, m.timecreated AS module_created,
               j.id AS jobid, j.quizid, j.kcid, j.questionid, j.attemptid, j.courseid, j.sourcetype, j.timecreated,
               c.state, c.attempts_count, c.completed_at,
               ($statussql) AS rstatus,
               q.name AS quizname, qq.name AS question_name, co.fullname AS coursename,
               " . ($kc ? 'kc.name AS kcname' : 'NULL AS kcname') . "
          FROM {local_aiqr_job} j
          JOIN {local_aiqr_module} m ON m.jobid = j.id
          JOIN {course} co ON co.id = j.courseid
     LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = :userid2
     LEFT JOIN {quiz} q ON q.id = j.quizid AND j.sourcetype = 'quiz'
     LEFT JOIN {question} qq ON qq.id = j.questionid AND j.sourcetype = 'quiz'
     " . ($kc ? "LEFT JOIN {aiknowledgecheck} kc ON kc.id = j.kcid AND j.sourcetype = 'knowledgecheck'" : '') . "
         WHERE " . implode(' AND ', $where) . " AND j.status = 'ready'
      ORDER BY CASE WHEN c.state = 'complete' THEN 1 ELSE 0 END, m.timecreated DESC";
$modules = $DB->get_records_sql($sql, $params);

$summary = helper::learner_summary((int) $USER->id, $courseid, $quizid, $attemptid, $kcid);

echo $OUTPUT->header();
echo $OUTPUT->heading($pageheading, 2);

// ── Simple learner filters ──────────────────────────────────────────────────
$mycourses = $DB->get_records_sql_menu("SELECT DISTINCT co.id, co.fullname
                                          FROM {local_aiqr_job} j
                                          JOIN {course} co ON co.id = j.courseid
                                         WHERE j.userid = :userid AND j.status = 'ready' AND j.questionid IS NOT NULL
                                      ORDER BY co.fullname", ['userid' => $USER->id]);
echo html_writer::start_tag(
    'form', ['method' => 'get', 'action' => (new moodle_url('/local/aiquizremedial/index.php'))->out(false),
    'class' => 'aiqr-learner-filters']);
if ($mine) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mine', 'value' => 1]);
}
if (count($mycourses) > 1 || !$courseid) {
    $copts = [0 => get_string('filter_anycourse', 'local_aiquizremedial')];
    foreach ($mycourses as $id => $name) {
        $copts[$id] = format_string($name);
    }
    echo html_writer::label(get_string('course'), 'aiqr-l-course', true, ['class' => 'sr-only visually-hidden']);
    echo html_writer::select(
        $copts, 'courseid', $courseid, false, ['id' => 'aiqr-l-course',
        'class' => 'form-select custom-select', 'onchange' => 'this.form.submit()']);
} else {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
}
$sopts = [
    '' => get_string('filter_anystatus', 'local_aiquizremedial'),
    'outstanding' => get_string('col_outstanding', 'local_aiquizremedial'),
    'notstarted' => get_string('state_notstarted', 'local_aiquizremedial'),
    'inprogress' => get_string('state_inprogress', 'local_aiquizremedial'),
    'complete' => get_string('state_complete', 'local_aiquizremedial'),
];
echo html_writer::label(
    get_string('status_label', 'local_aiquizremedial'), 'aiqr-l-status', true,
    ['class' => 'sr-only visually-hidden']);
echo html_writer::select(
    $sopts, 'status', $status, false, ['id' => 'aiqr-l-status', 'class' => 'form-select custom-select',
    'onchange' => 'this.form.submit()']);
echo html_writer::tag(
    'noscript', html_writer::tag(
    'button', get_string('filter_apply', 'local_aiquizremedial'),
    ['type' => 'submit', 'class' => 'btn btn-secondary']));
if ($attemptid || $quizid || $kcid || $status !== '') {
    echo html_writer::link(
        new moodle_url('/local/aiquizremedial/index.php', array_filter(['courseid' => $courseid])),
        get_string('filter_showall', 'local_aiquizremedial'), ['class' => 'btn btn-link']);
}
echo html_writer::end_tag('form');

// ── Progress summary + "being prepared" notice ──────────────────────────────
if ($summary->ready > 0) {
    $pct = (int) round($summary->complete * 100 / $summary->ready);
    echo html_writer::div(
        html_writer::div(get_string('learner_progress', 'local_aiquizremedial', $summary), 'aiqr-learner-progress-text') .
        html_writer::div(
            html_writer::div('', 'aiqr-bar-fill', ['style' => 'width:' . $pct . '%']), 'aiqr-bar aiqr-bar-lg',
            ['role' => 'progressbar', 'aria-valuenow' => $pct, 'aria-valuemin' => 0, 'aria-valuemax' => 100]),
        'aiqr-learner-progress'
    );
}
$preparing = $summary->generating + $summary->pendingumbrellas;
if ($preparing > 0) {
    echo $OUTPUT->notification($summary->generating > 0
        ? get_string('banner_preparing_message_n', 'local_aiquizremedial', $summary->generating)
        : get_string('banner_preparing_message', 'local_aiquizremedial'), 'info', false);
    $pollurl = new moodle_url(
        '/local/aiquizremedial/ajax.php', ['action' => 'summary', 'sesskey' => sesskey(),
        'courseid' => $courseid, 'quizid' => $quizid, 'attemptid' => $attemptid, 'kcid' => $kcid]);
    echo html_writer::script('(function (){var n=' . (int) $summary->ready . ',t=0;function p(){if(++t>60)return;'
        . 'fetch(' . json_encode($pollurl->out(false)) . ',{credentials:"same-origin"}).then(function (r){return r.json();})'
        . '.then(function (d){if(d&&d.success&&(d.ready>n||(d.generating===0&&d.pendingumbrellas===0))){location.reload();}'
        . 'else{setTimeout(p,15000);}}).catch(function (){setTimeout(p,30000);});}setTimeout(p,15000);})();');
}

// ── Module cards ────────────────────────────────────────────────────────────
if (empty($modules)) {
    if ($preparing === 0) {
        echo html_writer::div(get_string('nomodules', 'local_aiquizremedial'), 'alert alert-info');
    }
} else {
    echo html_writer::start_div('local-aiqr-modules');
    foreach ($modules as $mod) {
        $state = $mod->rstatus;
        $badge = html_writer::span(
            get_string('state_' . $state, 'local_aiquizremedial'),
            'aiqr-status aiqr-status-' . $state);
        $activityname = $mod->sourcetype === 'knowledgecheck' ? (string) $mod->kcname : (string) $mod->quizname;

        echo html_writer::start_div('card mb-3 aiqr-section-card aiqr-module-card aiqr-module-' . $state);
        echo html_writer::start_div('card-body');
        echo html_writer::tag(
            'h5', get_string('fixmodule_title', 'local_aiquizremedial') . ' ' . $badge,
            ['class' => 'card-title']);
        if ($activityname !== '') {
            echo html_writer::tag(
                'p', get_string(
                $mod->sourcetype === 'knowledgecheck' ? 'fromkc' : 'fromquiz',
                'local_aiquizremedial', format_string($activityname)),
                ['class' => 'card-text text-muted mb-1']);
        }
        if (!$courseid) {
            echo html_writer::tag('p', s(format_string($mod->coursename)), ['class' => 'card-text text-muted small mb-1']);
        }
        if (!empty($mod->question_name)) {
            echo html_writer::tag(
                'p', get_string('question_label', 'local_aiquizremedial', s(format_string($mod->question_name))),
                ['class' => 'card-text text-muted small mb-2']);
        }
        $label = $state === 'complete' ? get_string('reviewagain', 'local_aiquizremedial')
            : get_string('viewmodule', 'local_aiquizremedial');
        echo html_writer::link(
            new moodle_url('/local/aiquizremedial/view.php', ['moduleid' => $mod->moduleid]), $label,
            ['class' => 'btn btn-sm ' . ($state === 'complete' ? 'btn-outline-primary' : 'btn-primary')]);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
