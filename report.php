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
 * Remedial learning report with filtering (v1.3.0; shared filters and Insights tabs since v1.5.0).
 *
 * Replaces the unfiltered teacher card list on index.php. Works per course (?courseid=X,
 * reached from course navigation / the quiz page button) or across every course the viewer
 * can see (Site administration > Reports, or no courseid).
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_aiquizremedial\helper;
use local_aiquizremedial\report\report_query;

$q = report_query::from_request();
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

// ── Access ──────────────────────────────────────────────────────────────────
if ($q->courseid) {
    $course = get_course($q->courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('local/aiquizremedial:viewall', $context);
    $PAGE->set_pagelayout('report');
} else {
    require_login();
    $context = context_system::instance();
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('report');
}
if (!$q->resolve_scope()) {
    // No teaching role anywhere: send learners to their own module list.
    redirect(new moodle_url('/local/aiquizremedial/index.php'));
}

$PAGE->set_url($q->url());
$title = get_string('reporttitle', 'local_aiquizremedial');
$PAGE->set_title($title);
$PAGE->set_heading($q->courseid ? format_string($course->fullname, true, ['context' => $context]) : $title);
if (!$q->courseid && $q->is_sitewide() && has_capability('moodle/site:config', $context)) {
    $PAGE->navbar->add(get_string('reports'), new moodle_url('/admin/category.php', ['category' => 'reports']));
}
$PAGE->navbar->add($title, $q->url());

// ── Actions: retry failed generation ────────────────────────────────────────
if ($action === 'retry' || $action === 'retryall') {
    require_sesskey();
    $jobids = $action === 'retry' ? [required_param('jobid', PARAM_INT)] : $q->failed_jobids();
    $done = 0;
    foreach ($jobids as $jobid) {
        $job = $DB->get_record('local_aiqr_job', ['id' => $jobid, 'status' => 'failed']);
        if (!$job || empty($job->questionid)) {
            continue;
        }
        if (!has_capability('local/aiquizremedial:manage', context_course::instance($job->courseid))) {
            continue;
        }
        $DB->update_record('local_aiqr_job', (object) [
            'id' => $job->id, 'status' => 'queued', 'retries' => 0, 'errormsg' => null, 'timemodified' => time(),
        ]);
        $done++;
    }
    redirect(
        $q->url(), get_string('retryqueued', 'local_aiquizremedial', $done), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Version 1.4.1: put an image that failed or was rejected back in the queue (no extra charge).
if ($action === 'retryimage') {
    require_sesskey();
    $module = $DB->get_record('local_aiqr_module', ['id' => required_param('moduleid', PARAM_INT)], '*', MUST_EXIST);
    $job = $DB->get_record('local_aiqr_job', ['id' => $module->jobid], '*', MUST_EXIST);
    require_capability('local/aiquizremedial:manage', context_course::instance($job->courseid));
    if (empty($module->explain_image_url)) {
        $DB->update_record(
            'local_aiqr_module', (object) ['id' => $module->id, 'image_status' => 'retry',
            'image_attempts' => 0, 'image_nextattempt' => 0, 'timemodified' => time()]);
    }
    redirect(
        $q->url(), get_string('retryimagequeued', 'local_aiquizremedial'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// ── Formatting helpers ──────────────────────────────────────────────────────
$statuslabels = [
    'notstarted' => get_string('state_notstarted', 'local_aiquizremedial'),
    'inprogress' => get_string('state_inprogress', 'local_aiquizremedial'),
    'complete'   => get_string('state_complete', 'local_aiquizremedial'),
    'generating' => get_string('state_generating', 'local_aiquizremedial'),
    'failed'     => get_string('state_failed', 'local_aiquizremedial'),
];
$activityname = function ($r) {
    if (($r->sourcetype ?? 'quiz') === 'knowledgecheck') {
        return (string) ($r->kcname ?? '');
    }
    return (string) ($r->quizname ?? '');
};
$questionname = function ($r) {
    if (($r->sourcetype ?? 'quiz') === 'knowledgecheck') {
        return shorten_text(html_to_text((string) ($r->kcquestiontext ?? ''), 0, false), 80);
    }
    return (string) ($r->questionname ?? '');
};
$fmtdate = function ($t) {
    return $t ? userdate((int) $t, get_string('strftimedatetimeshort', 'langconfig')) : '';
};

// ── Export ──────────────────────────────────────────────────────────────────
if ($download !== '') {
    $filename = clean_filename('remedial-learning-' . ($q->view === 'students' ? 'students-' : '') . date('Y-m-d'));
    if ($q->view === 'students') {
        $columns = [
            'student' => get_string('col_student', 'local_aiquizremedial'),
            'email' => get_string('email'),
            'idnumber' => get_string('idnumber'),
            'courses' => get_string('col_courses', 'local_aiquizremedial'),
            'total' => get_string('col_modules', 'local_aiquizremedial'),
            'complete' => get_string('state_complete', 'local_aiquizremedial'),
            'outstanding' => get_string('col_outstanding', 'local_aiquizremedial'),
            'failed' => get_string('col_notready', 'local_aiquizremedial'),
            'rate' => get_string('col_rate', 'local_aiquizremedial'),
            'last' => get_string('col_lastactivity', 'local_aiquizremedial'),
            'credits' => get_string('col_credits', 'local_aiquizremedial'),
        ];
        $rs = $q->student_rows(true);
        \core\dataformat::download_data($filename, $download, $columns, $rs, function ($r) use ($fmtdate) {
            return [
                fullname($r), $r->email, $r->idnumber, (int) $r->ncourses, (int) $r->ntotal, (int) $r->ncomplete,
                (int) $r->noutstanding, (int) $r->nfailed, round((float) $r->rate) . '%', $fmtdate($r->lastactivity),
                (int) $r->credits,
            ];
        });
    } else {
        $columns = [
            'student' => get_string('col_student', 'local_aiquizremedial'),
            'email' => get_string('email'),
            'idnumber' => get_string('idnumber'),
            'category' => get_string('category'),
            'course' => get_string('course'),
            'activity' => get_string('col_activity', 'local_aiquizremedial'),
            'question' => get_string('col_question', 'local_aiquizremedial'),
            'status' => get_string('status_label', 'local_aiquizremedial'),
            'tries' => get_string('col_tries', 'local_aiquizremedial'),
            'created' => get_string('col_created', 'local_aiquizremedial'),
            'completed' => get_string('col_completed', 'local_aiquizremedial'),
            'credits' => get_string('col_credits', 'local_aiquizremedial'),
            'error' => get_string('col_error', 'local_aiquizremedial'),
        ];
        $rs = $q->module_rows(true);
        \core\dataformat::download_data(
            $filename, $download, $columns, $rs,
            function ($r) use ($statuslabels, $activityname, $questionname, $fmtdate) {
                return [
                    fullname($r), $r->email, $r->idnumber, $r->categoryname, $r->coursename,
                    format_string($activityname($r)), format_string($questionname($r)),
                    $statuslabels[$r->rstatus] ?? $r->rstatus, (int) $r->attempts_count, $fmtdate($r->timecreated),
                    $fmtdate($r->completed_at), (int) $r->credits_used,
                    $r->rstatus === 'failed' ? (string) $r->errormsg : '',
                ];
            });
    }
    $rs->close();
    exit;
}

// ── Data ────────────────────────────────────────────────────────────────────
$stats = $q->stats();
$total = $q->count();
if ($q->page * $q->perpage >= $total && $total > 0) {
    $q->page = (int) floor(($total - 1) / $q->perpage);
}

$filterfields = ['search', 'category', 'course', 'cohort', 'group', 'teacher', 'source', 'activity', 'student', 'status',
    'dates', 'perpage'];
$options = \local_aiquizremedial\report\filters::options($q, $filterfields);

echo $OUTPUT->header();
if ($q->courseid) {
    echo $OUTPUT->heading($title, 2);
}

if ($q->courseid) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/aiquizremedial/report.php'),
            get_string('report_allcourses', 'local_aiquizremedial'), ['class' => 'small']),
        'mb-2'
    );
}

// ── Filter form + chips ─────────────────────────────────────────────────────
$activecount = $q->active_count();
$reseturl = \local_aiquizremedial\report\filters::reset_url($q);
echo \local_aiquizremedial\report\filters::form($q, $options, $filterfields, ['sort' => $q->sort,
    'dir' => $q->sort ? $q->dir : '', 'attemptid' => $q->attemptid]);
echo \local_aiquizremedial\report\filters::chips($q, $options);

// ── Summary cards (click to filter by status) ───────────────────────────────
$card = function (string $label, string $value, string $variant, ?moodle_url $url = null, string $sub = '') {
    $inner = html_writer::div(s($value), 'aiqr-stat-value') . html_writer::div(s($label), 'aiqr-stat-label') .
        ($sub !== '' ? html_writer::div(s($sub), 'aiqr-stat-sub') : '');
    return $url ? html_writer::link($url, $inner, ['class' => 'aiqr-stat aiqr-stat-' . $variant])
        : html_writer::div($inner, 'aiqr-stat aiqr-stat-' . $variant);
};
$statusurl = function (string $s) use ($q) {
    return $q->url(['status' => [$s], 'page' => null]);
};
echo html_writer::start_div('aiqr-stats');
echo $card(
    get_string('stat_modules', 'local_aiquizremedial'), (string) $stats->total, 'total', null,
    get_string('stat_students', 'local_aiquizremedial', $stats->students));
echo $card(
    get_string('stat_rate', 'local_aiquizremedial'), $stats->rate . '%', 'rate', null,
    get_string('stat_rate_sub', 'local_aiquizremedial', $stats));
echo $card($statuslabels['complete'], (string) $stats->complete, 'complete', $statusurl('complete'));
echo $card($statuslabels['inprogress'], (string) $stats->inprogress, 'inprogress', $statusurl('inprogress'));
echo $card($statuslabels['notstarted'], (string) $stats->notstarted, 'notstarted', $statusurl('notstarted'));
echo $card($statuslabels['generating'], (string) $stats->generating, 'generating', $statusurl('generating'));
echo $card($statuslabels['failed'], (string) $stats->failed, 'failed', $statusurl('failed'));
echo $card(get_string('col_credits', 'local_aiquizremedial'), (string) $stats->credits, 'credits');
echo html_writer::end_div();

// ── View tabs + toolbar ─────────────────────────────────────────────────────
echo \local_aiquizremedial\report\filters::tabs($q, $q->view);

echo html_writer::start_div('aiqr-toolbar');
echo html_writer::div(get_string('resultcount', 'local_aiquizremedial', $total), 'aiqr-resultcount');
echo html_writer::start_div('aiqr-toolbar-right');
$canmanageany = $q->courseid ? has_capability('local/aiquizremedial:manage', $context)
    : has_capability('local/aiquizremedial:manage', context_system::instance()) || $q->is_sitewide();
if ($stats->failed > 0 && $canmanageany) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $q->url()->out(false), 'class' => 'd-inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'retryall']);
    echo html_writer::tag(
        'button', get_string('retryall', 'local_aiquizremedial', $stats->failed),
        ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm']);
    echo html_writer::end_tag('form');
}
if ($total > 0) {
    echo $OUTPUT->download_dataformat_selector(
        get_string('downloadas', 'table'),
        new moodle_url('/local/aiquizremedial/report.php'), 'download', $q->params(['page' => null]));
}
echo html_writer::end_div();
echo html_writer::end_div();

// ── Table ───────────────────────────────────────────────────────────────────
$sortheader = function (string $key, string $label) use ($q) {
    $active = $q->sort === $key;
    $dir = $active && $q->dir === 'ASC' ? 'DESC' : 'ASC';
    $arrow = $active ? ($q->dir === 'ASC' ? ' ▲' : ' ▼') : '';
    return html_writer::link(
        $q->url(['sort' => $key, 'dir' => $dir, 'page' => null]), s($label) . $arrow,
        ['class' => 'aiqr-sort' . ($active ? ' aiqr-sort-active' : '')]);
};
$badge = function (string $status) use ($statuslabels) {
    return html_writer::span($statuslabels[$status] ?? $status, 'aiqr-status aiqr-status-' . $status);
};
$returnurl = $q->url()->out_as_local_url(false);

if ($total === 0) {
    echo $OUTPUT->notification($activecount ? get_string('noresults_filtered', 'local_aiquizremedial')
        : get_string('nomodules_course', 'local_aiquizremedial'), 'info');
} else if ($q->view === 'students') {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable aiqr-report-table';
    $table->head = [
        $sortheader('student', get_string('col_student', 'local_aiquizremedial')),
        $sortheader('courses', get_string('col_courses', 'local_aiquizremedial')),
        $sortheader('total', get_string('col_modules', 'local_aiquizremedial')),
        $sortheader('complete', get_string('state_complete', 'local_aiquizremedial')),
        $sortheader('outstanding', get_string('col_outstanding', 'local_aiquizremedial')),
        $sortheader('failed', get_string('col_notready', 'local_aiquizremedial')),
        $sortheader('rate', get_string('col_rate', 'local_aiquizremedial')),
        $sortheader('last', get_string('col_lastactivity', 'local_aiquizremedial')),
    ];
    $rs = $q->student_rows();
    foreach ($rs as $r) {
        $rate = (int) round((float) $r->rate);
        $studenturl = $q->url(['view' => null, 'student' => [(int) $r->id], 'sort' => null, 'dir' => null, 'page' => null]);
        $table->data[] = [
            html_writer::link($studenturl, s(fullname($r)), ['class' => 'aiqr-student-name']) .
                html_writer::div(s($r->email), 'aiqr-muted small'),
            (int) $r->ncourses,
            (int) $r->ntotal,
            (int) $r->ncomplete,
            (int) $r->noutstanding,
            (int) $r->nfailed,
            html_writer::div(
                html_writer::div('', 'aiqr-bar-fill', ['style' => 'width:' . $rate . '%']), 'aiqr-bar',
                ['role' => 'progressbar', 'aria-valuenow' => $rate, 'aria-valuemin' => 0, 'aria-valuemax' => 100]) .
                html_writer::span($rate . '%', 'aiqr-bar-label'),
            $fmtdate($r->lastactivity),
        ];
    }
    $rs->close();
    echo html_writer::div(html_writer::table($table), 'table-responsive');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable aiqr-report-table';
    $head = [
        $sortheader('student', get_string('col_student', 'local_aiquizremedial')),
    ];
    if (!$q->courseid) {
        $head[] = $sortheader('course', get_string('course'));
    }
    $head = array_merge($head, [
        $sortheader('activity', get_string('col_activity', 'local_aiquizremedial')),
        $sortheader('question', get_string('col_question', 'local_aiquizremedial')),
        $sortheader('status', get_string('status_label', 'local_aiquizremedial')),
        $sortheader('tries', get_string('col_tries', 'local_aiquizremedial')),
        $sortheader('created', get_string('col_created', 'local_aiquizremedial')),
        $sortheader('completed', get_string('col_completed', 'local_aiquizremedial')),
        get_string('actions'),
    ]);
    $table->head = $head;
    $managecache = [];
    $rs = $q->module_rows();
    foreach ($rs as $r) {
        $cctx = context_course::instance($r->courseid);
        $studenturl = $q->url(['student' => [(int) $r->userid], 'page' => null]);
        $row = [
            html_writer::link(
                $studenturl, s(fullname($r)), ['class' => 'aiqr-student-name',
                'title' => get_string('filter_bystudent', 'local_aiquizremedial')]) .
                html_writer::div(s($r->email), 'aiqr-muted small'),
        ];
        if (!$q->courseid) {
            $row[] = html_writer::link(
                $q->url(['course' => [(int) $r->courseid], 'page' => null]),
                format_string($r->courseshort, true, ['context' => $cctx]), ['title' => format_string($r->coursename)]) .
                html_writer::div(s($r->categoryname), 'aiqr-muted small');
        }
        $act = format_string($activityname($r), true, ['context' => $cctx]);
        $actkey = ($r->sourcetype === 'knowledgecheck' ? 'kc-' . $r->kcid : 'quiz-' . $r->quizid);
        $row[] = $act !== '' ? html_writer::link($q->url(['activity' => [$actkey], 'page' => null]), $act) : '';
        $row[] = s(format_string($questionname($r), true, ['context' => $cctx]));
        $statuscell = $badge($r->rstatus);
        if ($r->rstatus === 'failed' && !empty($r->errormsg)) {
            $statuscell .= html_writer::div(s(shorten_text($r->errormsg, 120)), 'aiqr-error small', ['title' => $r->errormsg]);
        }
        if ($r->moduleid && empty($r->explain_image_url) && in_array((string) $r->image_status, ['failed', 'rejected'], true)
                && \local_aiquizremedial\credit_calculator::is_images_enabled()) {
            $statuscell .= html_writer::div(
                s(get_string('imagestatus_' . $r->image_status, 'local_aiquizremedial')),
                'aiqr-error small', ['title' => (string) $r->image_error]);
        }
        $row[] = $statuscell;
        $row[] = $r->moduleid ? (int) $r->attempts_count : '';
        $row[] = $fmtdate($r->timecreated);
        $row[] = $fmtdate($r->completed_at);

        $actions = '';
        if ($r->moduleid) {
            $viewurl = new moodle_url(
                '/local/aiquizremedial/view.php', ['moduleid' => $r->moduleid, 'userid' => $r->userid,
                'returnurl' => $returnurl]);
            $actions .= html_writer::link(
                $viewurl, get_string('viewmodule_teacher', 'local_aiquizremedial'),
                ['class' => 'btn btn-sm btn-primary']);
        }
        if ($r->jobstatus === 'failed') {
            if (!isset($managecache[$r->courseid])) {
                $managecache[$r->courseid] = has_capability('local/aiquizremedial:manage', $cctx);
            }
            if ($managecache[$r->courseid]) {
                $actions .= html_writer::start_tag(
                    'form', ['method' => 'post', 'action' => $q->url()->out(false),
                    'class' => 'd-inline']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'retry']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'jobid', 'value' => $r->jobid]) .
                    html_writer::tag(
                        'button', get_string('retry', 'local_aiquizremedial'),
                        ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']) .
                    html_writer::end_tag('form');
            }
        }
        if ($r->moduleid && empty($r->explain_image_url) && in_array((string) $r->image_status, ['failed', 'rejected'], true)
                && \local_aiquizremedial\credit_calculator::is_images_enabled()) {
            if (!isset($managecache[$r->courseid])) {
                $managecache[$r->courseid] = has_capability('local/aiquizremedial:manage', $cctx);
            }
            if ($managecache[$r->courseid]) {
                $actions .= html_writer::start_tag(
                    'form', ['method' => 'post', 'action' => $q->url()->out(false),
                    'class' => 'd-inline']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'retryimage']) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'moduleid', 'value' => $r->moduleid]) .
                    html_writer::tag(
                        'button', get_string('retryimage', 'local_aiquizremedial'),
                        ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary']) .
                    html_writer::end_tag('form');
            }
        }
        $row[] = $actions;
        $table->data[] = $row;
    }
    $rs->close();
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

echo $OUTPUT->paging_bar($total, $q->page, $q->perpage, $q->url(['page' => null]));

echo $OUTPUT->footer();
