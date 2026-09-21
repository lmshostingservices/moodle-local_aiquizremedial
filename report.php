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
 * Remedial learning report with filtering (v1.3.0).
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

$options = [
    'category' => $q->courseid ? [] : $q->category_options(),
    'course'   => $q->courseid ? [] : $q->course_options(),
    'cohort'   => $q->cohort_options(),
    'group'    => $q->group_options(),
    'teacher'  => $q->teacher_options(),
    'activity' => $q->activity_options(),
    'student'  => $q->student_options(),
];

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

// ── Filter form ─────────────────────────────────────────────────────────────
$selectfield = function (string $name, string $label, array $opts, array $selected, string $placeholder,
        bool $multiple = true, string $extra = '') {
    $id = 'aiqr-f-' . $name;
    $html = html_writer::start_div('aiqr-filter-field');
    $html .= html_writer::tag('label', s($label), ['for' => $id, 'class' => 'aiqr-filter-label']);
    $attrs = ['id' => $id, 'class' => 'form-select custom-select aiqr-filter-select', 'data-placeholder' => $placeholder];
    if ($multiple) {
        $attrs['multiple'] = 'multiple';
        $attrs['name'] = $name . '[]';
    } else {
        $attrs['name'] = $name;
    }
    $html .= html_writer::start_tag('select', $attrs);
    if (!$multiple) {
        $html .= html_writer::tag('option', s($placeholder), ['value' => 0]);
    }
    foreach ($opts as $value => $text) {
        $oattrs = ['value' => $value];
        if (in_array((string) $value, array_map('strval', $selected), true)) {
            $oattrs['selected'] = 'selected';
        }
        $html .= html_writer::tag('option', s($text), $oattrs);
    }
    $html .= html_writer::end_tag('select');
    $html .= $extra;
    $html .= html_writer::end_div();
    return $html;
};
$checkbox = function (string $name, string $label, bool $checked) {
    $id = 'aiqr-f-' . $name;
    return html_writer::div(
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => 0]) .
        html_writer::empty_tag(
            'input', ['type' => 'checkbox', 'name' => $name, 'value' => 1, 'id' => $id,
            'class' => 'form-check-input'] + ($checked ? ['checked' => 'checked'] : [])) .
        html_writer::tag('label', s($label), ['for' => $id, 'class' => 'form-check-label']),
        'form-check aiqr-filter-check'
    );
};

$activecount = $q->active_count();
echo html_writer::start_tag('details', ['class' => 'aiqr-filters card mb-3', 'open' => 'open']);
echo html_writer::tag(
    'summary',
    html_writer::span(get_string('filters', 'local_aiquizremedial'), 'aiqr-filters-title') .
    ($activecount ? html_writer::span(
        get_string('filtersactive', 'local_aiquizremedial', $activecount),
        'aiqr-pill aiqr-pill-active') : ''),
    ['class' => 'card-header']);
echo html_writer::start_tag(
    'form', ['method' => 'get', 'action' => (new moodle_url('/local/aiquizremedial/report.php'))->out(false),
    'class' => 'card-body aiqr-filter-form', 'id' => 'aiqr-filter-form']);
foreach (['courseid' => $q->courseid, 'view' => $q->view, 'sort' => $q->sort, 'dir' => $q->sort ? $q->dir : '',
        'attemptid' => $q->attemptid] as $n => $v) {
    if ($v) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $n, 'value' => $v]);
    }
}
echo html_writer::start_div('aiqr-filter-grid');

// Search.
echo html_writer::start_div('aiqr-filter-field aiqr-filter-wide');
echo html_writer::tag(
    'label', get_string('filter_search', 'local_aiquizremedial'), ['for' => 'aiqr-f-search',
    'class' => 'aiqr-filter-label']);
echo html_writer::empty_tag(
    'input', ['type' => 'search', 'name' => 'search', 'id' => 'aiqr-f-search', 'value' => $q->search,
    'class' => 'form-control', 'placeholder' => get_string('filter_search_placeholder', 'local_aiquizremedial')]);
echo html_writer::end_div();

if (!$q->courseid) {
    echo $selectfield(
        'category', get_string('category'), $options['category'], [$q->category],
        get_string('filter_anycategory', 'local_aiquizremedial'), false,
        $checkbox('subcats', get_string('filter_subcats', 'local_aiquizremedial'), $q->subcats));
    echo $selectfield(
        'course', get_string('course'), $options['course'], $q->courses,
        get_string('filter_anycourse', 'local_aiquizremedial'));
}
echo $selectfield(
    'cohort', get_string('cohort', 'cohort'), $options['cohort'], $q->cohorts,
    get_string('filter_anycohort', 'local_aiquizremedial'));
echo $selectfield(
    'group', get_string('group'), $options['group'], $q->groups,
    get_string('filter_anygroup', 'local_aiquizremedial'));
echo $selectfield(
    'teacher', get_string('filter_teacher', 'local_aiquizremedial'), $options['teacher'], $q->teachers,
    get_string('filter_anyteacher', 'local_aiquizremedial'), true,
    $checkbox('teachergroups', get_string('filter_teachergroups', 'local_aiquizremedial'), $q->teachergroups));
$sourcelabels = ['quiz' => get_string('source_quiz', 'local_aiquizremedial'),
    'knowledgecheck' => get_string('source_knowledgecheck', 'local_aiquizremedial')];
if (helper::kc_installed()) {
    echo $selectfield(
        'source', get_string('filter_source', 'local_aiquizremedial'), $sourcelabels, $q->sources,
        get_string('filter_anysource', 'local_aiquizremedial'));
}
echo $selectfield(
    'activity', get_string('col_activity', 'local_aiquizremedial'), $options['activity'], $q->activities,
    get_string('filter_anyactivity', 'local_aiquizremedial'));
echo $selectfield(
    'student', get_string('col_student', 'local_aiquizremedial'), $options['student'], $q->students,
    get_string('filter_anystudent', 'local_aiquizremedial'));
echo $selectfield(
    'status', get_string('status_label', 'local_aiquizremedial'), $statuslabels, $q->statuses,
    get_string('filter_anystatus', 'local_aiquizremedial'));

// Dates.
echo html_writer::start_div('aiqr-filter-field');
echo html_writer::tag(
    'label', get_string('filter_dates', 'local_aiquizremedial'), ['class' => 'aiqr-filter-label',
    'for' => 'aiqr-f-datefrom']);
echo html_writer::start_div('aiqr-filter-daterow');
echo html_writer::empty_tag(
    'input', ['type' => 'date', 'name' => 'datefrom', 'id' => 'aiqr-f-datefrom',
    'value' => $q->datefrom, 'class' => 'form-control', 'aria-label' => get_string('filter_datefrom', 'local_aiquizremedial')]);
echo html_writer::span('–', 'aiqr-filter-dash');
echo html_writer::empty_tag(
    'input', ['type' => 'date', 'name' => 'dateto', 'id' => 'aiqr-f-dateto',
    'value' => $q->dateto, 'class' => 'form-control', 'aria-label' => get_string('filter_dateto', 'local_aiquizremedial')]);
echo html_writer::end_div();
echo html_writer::end_div();

// Per page.
$ppopts = array_combine(report_query::PERPAGE, report_query::PERPAGE);
echo html_writer::start_div('aiqr-filter-field');
echo html_writer::tag(
    'label', get_string('filter_perpage', 'local_aiquizremedial'), ['for' => 'aiqr-f-perpage',
    'class' => 'aiqr-filter-label']);
echo html_writer::select(
    $ppopts, 'perpage', $q->perpage, false, ['id' => 'aiqr-f-perpage',
    'class' => 'form-select custom-select']);
echo html_writer::end_div();

echo html_writer::end_div(); // Grid.

echo html_writer::start_div('aiqr-filter-actions');
echo html_writer::tag(
    'button', get_string('filter_apply', 'local_aiquizremedial'), ['type' => 'submit',
    'class' => 'btn btn-primary']);
$reseturl = new moodle_url(
    '/local/aiquizremedial/report.php', array_filter(
    ['courseid' => $q->courseid,
    'view' => $q->view === 'students' ? 'students' : null]));
echo html_writer::link($reseturl, get_string('filter_reset', 'local_aiquizremedial'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_tag('details');

foreach (['course', 'cohort', 'group', 'teacher', 'source', 'activity', 'student', 'status'] as $name) {
    if (($q->courseid && $name === 'course') || ($name === 'source' && !helper::kc_installed())) {
        continue;
    }
    $PAGE->requires->js_call_amd(
        'core/form-autocomplete', 'enhance', ['#aiqr-f-' . $name, false, false,
        get_string('filter_typetosearch', 'local_aiquizremedial'), false, true,
        get_string('filter_noselection', 'local_aiquizremedial')]);
}

// ── Active filter chips ─────────────────────────────────────────────────────
if ($activecount) {
    $chips = [];
    $chip = function (string $label, string $value, array $override) use ($q) {
        $url = $q->url($override + ['page' => null]);
        return html_writer::link(
            $url, html_writer::span(s($label) . ': ', 'aiqr-chip-label') . s($value) .
            html_writer::span('×', 'aiqr-chip-x', ['aria-hidden' => 'true']),
            ['class' => 'aiqr-chip', 'title' => get_string('filter_remove', 'local_aiquizremedial')]);
    };
    $multichips = function (string $key, string $label, array $values, array $opts) use ($chip) {
        $out = [];
        foreach ($values as $v) {
            $rest = array_values(array_diff($values, [$v]));
            $out[] = $chip($label, $opts[$v] ?? (string) $v, [$key => $rest]);
        }
        return $out;
    };
    if ($q->search !== '') {
        $chips[] = $chip(get_string('filter_search', 'local_aiquizremedial'), '"' . $q->search . '"', ['search' => null]);
    }
    if ($q->category) {
        $chips[] = $chip(
            get_string('category'), ($options['category'][$q->category] ?? $q->category) .
            ($q->subcats ? ' ' . get_string('filter_andsubcats', 'local_aiquizremedial') : ''), ['category' => null]);
    }
    $chips = array_merge(
        $chips,
        $multichips('course', get_string('course'), $q->courses, $options['course']),
        $multichips('cohort', get_string('cohort', 'cohort'), $q->cohorts, $options['cohort']),
        $multichips('group', get_string('group'), $q->groups, $options['group']),
        $multichips('teacher', get_string('filter_teacher', 'local_aiquizremedial'), $q->teachers, $options['teacher']),
        $multichips('source', get_string('filter_source', 'local_aiquizremedial'), $q->sources, $sourcelabels),
        $multichips('activity', get_string('col_activity', 'local_aiquizremedial'), $q->activities, $options['activity']),
        $multichips('student', get_string('col_student', 'local_aiquizremedial'), $q->students, $options['student']),
        $multichips('status', get_string('status_label', 'local_aiquizremedial'), $q->statuses, $statuslabels)
    );
    if ($q->datefrom !== '') {
        $chips[] = $chip(get_string('filter_datefrom', 'local_aiquizremedial'), $q->datefrom, ['datefrom' => null]);
    }
    if ($q->dateto !== '') {
        $chips[] = $chip(get_string('filter_dateto', 'local_aiquizremedial'), $q->dateto, ['dateto' => null]);
    }
    if ($q->attemptid) {
        $chips[] = $chip(get_string('filter_attempt', 'local_aiquizremedial'), '#' . $q->attemptid, ['attemptid' => null]);
    }
    echo html_writer::div(
        implode('', $chips) .
        html_writer::link($reseturl, get_string('filter_clearall', 'local_aiquizremedial'), ['class' => 'aiqr-chip-clear']),
        'aiqr-chips');
}

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
$tabs = [
    new tabobject(
        'modules', $q->url(['view' => null, 'sort' => null, 'dir' => null, 'page' => null]),
        get_string('view_modules', 'local_aiquizremedial')),
    new tabobject(
        'students', $q->url(['view' => 'students', 'sort' => null, 'dir' => null, 'page' => null]),
        get_string('view_students', 'local_aiquizremedial')),
];
echo $OUTPUT->tabtree($tabs, $q->view);

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
