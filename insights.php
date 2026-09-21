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
 * Quiz Insights and Suggested actions (v1.5.0).
 *
 * Views: insights (default), actions, question (detail for one question). Uses the same
 * filters and viewer scope as the remedial report; per course (?courseid=X) or across every
 * course the viewer can see.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_aiquizremedial\insights\actions;
use local_aiquizremedial\insights\builder;
use local_aiquizremedial\insights\charts;
use local_aiquizremedial\insights\presenter;
use local_aiquizremedial\insights\query;
use local_aiquizremedial\insights\rules;
use local_aiquizremedial\report\filters;
use local_aiquizremedial\report\report_query;

$q = report_query::from_request(['insights', 'actions', 'question'], 'insights', '/local/aiquizremedial/insights.php');
$op = optional_param('op', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$qparam = optional_param('q', '', PARAM_ALPHANUMEXT);
$astatus = optional_param('astatus', 'todo', PARAM_ALPHA);
$asev = optional_param('asev', '', PARAM_ALPHA);
$arule = optional_param('arule', '', PARAM_ALPHANUM);
$mine = optional_param('mine', 0, PARAM_BOOL);
$focus = optional_param('actionid', 0, PARAM_INT);
$showall = optional_param('all', 0, PARAM_BOOL);

// ── Access ──────────────────────────────────────────────────────────────────
if ($q->courseid) {
    $course = get_course($q->courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('local/aiquizremedial:viewall', $context);
} else {
    require_login();
    $context = context_system::instance();
    $PAGE->set_context($context);
}
$PAGE->set_pagelayout('report');
if (!$q->resolve_scope()) {
    redirect(new moodle_url('/local/aiquizremedial/index.php'));
}
$iq = new query($q);

// Question key from the URL: c{course}-{quiz|kc}-{activity}-{qbe}.
$qkey = null;
if (preg_match('/^c(\d+)-(quiz|kc)-(\d+)-(\d+)$/', $qparam, $m)) {
    $qkey = $m[1] . ':' . ($m[2] === 'kc' ? 'knowledgecheck' : 'quiz') . ':' . $m[3] . ':' . $m[4];
}
$qurlkey = function (string $key): string {
    [$c, $s, $a, $b] = explode(':', $key);
    return 'c' . $c . '-' . ($s === 'knowledgecheck' ? 'kc' : 'quiz') . '-' . $a . '-' . $b;
};
$questionurl = function (string $key) use ($q, $qurlkey) {
    return $q->url(['view' => 'question', 'q' => $qurlkey($key), 'page' => null]);
};
$actionsbase = ['view' => 'actions', 'astatus' => $astatus !== 'todo' ? $astatus : null, 'asev' => $asev ?: null,
    'arule' => $arule ?: null, 'mine' => $mine ? 1 : null];
$actionsurl = function (array $override = []) use ($q, $actionsbase) {
    return $q->url(array_merge($actionsbase, $override));
};

$PAGE->set_url($q->url($q->view === 'question' ? ['q' => $qparam] : ($q->view === 'actions' ? $actionsbase : [])));
$title = get_string('insights', 'local_aiquizremedial');
$PAGE->set_title($q->view === 'actions' ? get_string('suggestedactions', 'local_aiquizremedial') : $title);
$PAGE->set_heading($q->courseid ? format_string($course->fullname, true, ['context' => $context])
    : get_string('reporttitle', 'local_aiquizremedial'));
if (!$q->courseid && $q->is_sitewide() && has_capability('moodle/site:config', $context)) {
    $PAGE->navbar->add(get_string('reports'), new moodle_url('/admin/category.php', ['category' => 'reports']));
}
$PAGE->navbar->add($title, $q->url(['view' => null]));

$canmanage = function (int $courseid) {
    static $cache = [];
    if (!isset($cache[$courseid])) {
        $ctx = context_course::instance($courseid, IGNORE_MISSING);
        $cache[$courseid] = $ctx && has_capability('local/aiquizremedial:manageactions', $ctx);
    }
    return $cache[$courseid];
};

// ── Operations ──────────────────────────────────────────────────────────────
if ($op === 'recalc') {
    require_sesskey();
    $cid = $iq->single_course();
    if (!$cid || !$canmanage($cid)) {
        throw new required_capability_exception(context_course::instance($cid ?: SITEID),
            'local/aiquizremedial:manageactions', 'nopermissions', '');
    }
    $last = (int) get_config('local_aiquizremedial', 'recalc_requested_' . $cid);
    if (time() - $last < HOURSECS) {
        redirect($q->url(), get_string('recalc_wait', 'local_aiquizremedial',
            userdate($last + HOURSECS, get_string('strftimetime', 'langconfig'))), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    set_config('recalc_requested_' . $cid, time(), 'local_aiquizremedial');
    $task = new \local_aiquizremedial\task\recalc_course();
    $task->set_custom_data(['courseid' => $cid]);
    $task->set_component('local_aiquizremedial');
    \core\task\manager::queue_adhoc_task($task, true);
    redirect($q->url(), get_string('recalc_queued', 'local_aiquizremedial'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if (in_array($op, ['status', 'assign', 'comment'], true)) {
    require_sesskey();
    $actionid = required_param('actionid', PARAM_INT);
    $a = $iq->actions(['id' => $actionid])[$actionid] ?? null;
    if (!$a) {
        throw new moodle_exception('invalidrecord', 'error');
    }
    if (!$canmanage((int) $a->courseid)) {
        throw new required_capability_exception(context_course::instance((int) $a->courseid),
            'local/aiquizremedial:manageactions', 'nopermissions', '');
    }
    $comment = trim(optional_param('comment', '', PARAM_TEXT));
    $back = $actionsurl(['actionid' => $a->id]);
    $back->set_anchor('aiqr-action-' . $a->id);
    if ($op === 'status') {
        $to = required_param('to', PARAM_ALPHA);
        $reason = optional_param('reason', '', PARAM_ALPHA);
        $until = optional_param('snoozeuntil', '', PARAM_ALPHANUMEXT);
        $untilts = 0;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $until . ' 06:00:00', core_date::get_user_timezone_object());
            $untilts = $dt ? $dt->getTimestamp() : 0;
        }
        if ($to === 'snoozed' && !$untilts) {
            $untilts = time() + 14 * DAYSECS;
        }
        if ($to === 'dismissed' && !in_array($reason, actions::DISMISS_REASONS, true)) {
            redirect($back, get_string('dismissreasonrequired', 'local_aiquizremedial'), null,
                \core\output\notification::NOTIFY_ERROR);
        }
        actions::transition($a, $to, (int) $USER->id, $comment !== '' ? $comment : null, $reason ?: null, $untilts);
        redirect($back, get_string('action_updated', 'local_aiquizremedial'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($op === 'assign') {
        $assignee = required_param('assignee', PARAM_INT);
        if ($assignee) {
            $ctx = context_course::instance((int) $a->courseid);
            if (!has_capability('local/aiquizremedial:manageactions', $ctx, $assignee)) {
                throw new moodle_exception('invaliduser', 'error');
            }
        }
        actions::assign($a, $assignee, (int) $USER->id);
        if ($comment !== '') {
            actions::comment($a, (int) $USER->id, $comment);
        }
        redirect($back, get_string('action_updated', 'local_aiquizremedial'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($comment !== '') {
        actions::comment($a, (int) $USER->id, $comment);
    }
    redirect($back, get_string('action_updated', 'local_aiquizremedial'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Action filters ──────────────────────────────────────────────────────────
$statusgroups = [
    'todo' => ['open', 'acknowledged', 'inprogress'],
    'snoozed' => ['snoozed'],
    'done' => ['done'],
    'dismissed' => ['dismissed'],
    'resolved' => ['resolved'],
    'all' => [],
];
if (!isset($statusgroups[$astatus])) {
    $astatus = 'todo';
}
$actionopts = ['statuses' => $statusgroups[$astatus], 'severities' => isset(rules::SEVERITY[$asev]) ? [$asev] : [],
    'rules' => isset(rules::catalogue()[$arule]) ? [$arule] : [], 'mine' => $mine];

$username = function (int $userid) {
    global $DB;
    static $cache = [];
    if (!$userid) {
        return get_string('system_user', 'local_aiquizremedial');
    }
    if (!isset($cache[$userid])) {
        $u = $DB->get_record('user', ['id' => $userid], implode(',', array_merge(['id'],
            \core_user\fields::get_name_fields())));
        $cache[$userid] = $u ? fullname($u) : '#' . $userid;
    }
    return $cache[$userid];
};
$statuslabel = function (string $s) {
    return get_string('astatus_' . $s, 'local_aiquizremedial');
};
$logtext = function (\stdClass $l) use ($username, $statuslabel) {
    $note = (string) $l->comment;
    if (preg_match('/^assigned:(\d+)$/', $note, $mm)) {
        return [get_string('log_assigned', 'local_aiquizremedial', (int) $mm[1] ? $username((int) $mm[1])
            : get_string('nobody', 'local_aiquizremedial')), ''];
    }
    if (preg_match('/^reason:(\w+)(?: — (.*))?$/su', $note, $mm)) {
        $note = get_string('dismiss_' . $mm[1], 'local_aiquizremedial') . (isset($mm[2]) ? ' — ' . $mm[2] : '');
    } else if (preg_match('/^reopen:(\w+)$/', $note, $mm)) {
        $note = get_string('reopen_' . $mm[1], 'local_aiquizremedial');
    } else if (preg_match('/^until:(\S+)(?: — (.*))?$/su', $note, $mm)) {
        $note = get_string('snoozeduntil', 'local_aiquizremedial', $mm[1]) . (isset($mm[2]) ? ' — ' . $mm[2] : '');
    } else if (preg_match('/^impact:(\w+)$/', $note, $mm)) {
        $note = get_string('impact_' . $mm[1], 'local_aiquizremedial');
    } else if ($note === 'autoresolved') {
        $note = get_string('log_autoresolved', 'local_aiquizremedial');
    }
    if ($l->fromstatus === null || $l->fromstatus === '') {
        $what = get_string('log_created', 'local_aiquizremedial');
    } else if ($l->fromstatus !== $l->tostatus) {
        $what = get_string('log_status', 'local_aiquizremedial', (object) ['from' => $statuslabel($l->fromstatus),
            'to' => $statuslabel($l->tostatus)]);
    } else {
        $what = get_string('log_comment', 'local_aiquizremedial');
    }
    return [$what, $note];
};

// ── Exports ─────────────────────────────────────────────────────────────────
if ($download !== '' && $q->view === 'actions') {
    $export = optional_param('export', 'actions', PARAM_ALPHA);
    $list = $iq->actions($actionopts);
    $fmt = function ($t) {
        return $t ? userdate((int) $t, '%Y-%m-%d %H:%M') : '';
    };
    if ($export === 'log') {
        $columns = ['actionid' => 'Action ID', 'rule' => get_string('col_rule', 'local_aiquizremedial'),
            'action' => get_string('col_action', 'local_aiquizremedial'), 'time' => get_string('time'),
            'user' => get_string('user'), 'change' => get_string('col_change', 'local_aiquizremedial'),
            'note' => get_string('col_note', 'local_aiquizremedial'), 'evidence' => get_string('col_evidence',
                'local_aiquizremedial')];
        $rows = [];
        if ($list) {
            [$in, $params] = $DB->get_in_or_equal(array_keys($list));
            foreach ($DB->get_records_select('local_aiqr_action_log', "actionid $in", $params, 'actionid ASC, id ASC') as $l) {
                [$what, $note] = $logtext($l);
                $a = $list[$l->actionid];
                $rows[] = [(int) $l->actionid, $a->ruleid, presenter::action_title($a), $fmt($l->timecreated),
                    $username((int) $l->userid), $what, $note, (string) $l->evidence];
            }
        }
        \core\dataformat::download_data(clean_filename('suggested-actions-audit-' . date('Y-m-d')), $download, $columns,
            new ArrayIterator($rows), function ($r) {
                return $r;
            });
        exit;
    }
    $columns = ['id' => 'ID', 'severity' => get_string('col_severity', 'local_aiquizremedial'),
        'rule' => get_string('col_rule', 'local_aiquizremedial'), 'status' => get_string('status_label', 'local_aiquizremedial'),
        'title' => get_string('col_action', 'local_aiquizremedial'), 'text' => get_string('col_details', 'local_aiquizremedial'),
        'category' => get_string('category'), 'course' => get_string('course'),
        'assignee' => get_string('col_assignee', 'local_aiquizremedial'), 'created' => get_string('col_created',
            'local_aiquizremedial'),
        'updated' => get_string('col_updated', 'local_aiquizremedial'), 'resolved' => get_string('col_resolved',
            'local_aiquizremedial'),
        'dismiss' => get_string('col_dismissreason', 'local_aiquizremedial'), 'impact' => get_string('col_impact',
            'local_aiquizremedial')];
    \core\dataformat::download_data(clean_filename('suggested-actions-' . date('Y-m-d')), $download, $columns,
        new ArrayIterator(array_values($list)), function ($a) use ($username, $statuslabel, $fmt) {
            return [(int) $a->id, get_string('sev_' . $a->severity, 'local_aiquizremedial'), $a->ruleid, $statuslabel($a->status),
                presenter::action_title($a), presenter::action_text($a), presenter::category_path((int) $a->courseid),
                presenter::course_full((int) $a->courseid), (int) $a->assigneeid ? $username((int) $a->assigneeid) : '',
                $fmt($a->timecreated), $fmt($a->timemodified), $fmt($a->timeresolved),
                $a->dismissreason ? get_string('dismiss_' . $a->dismissreason, 'local_aiquizremedial') : '',
                presenter::impact_text($a)];
        });
    exit;
}

// ── Page ────────────────────────────────────────────────────────────────────
$PAGE->requires->js_init_code(charts::tooltip_js(), true);
$filterfields = ['search', 'category', 'course', 'cohort', 'group', 'teacher', 'source', 'activity', 'dates'];
$options = filters::options($q, $filterfields);
$liveactions = $iq->actions(['live' => true]);

echo $OUTPUT->header();
echo html_writer::start_div('aiqr-ins');
if ($q->courseid) {
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/aiquizremedial/insights.php', $q->view === 'actions' ? ['view' => 'actions'] : []),
            get_string('report_allcourses', 'local_aiquizremedial'), ['class' => 'small']), 'mb-2');
}
echo filters::tabs($q, $q->view === 'question' ? 'insights' : $q->view, count($liveactions));
$filtersopen = $q->active_count() > 0;
echo filters::form($q, $options, $filterfields, $q->view === 'actions' ? array_filter($actionsbase) : [], $filtersopen);
echo filters::chips($q, $options);

// Status line: period, freshness, recalculate.
$lastrun = (int) get_config('local_aiquizremedial', 'insights_lastrun');
$single = $iq->single_course();
if ($single) {
    $lastrun = max($lastrun, (int) get_config('local_aiquizremedial', 'insights_lastrun_' . $single));
}
$fmtshort = get_string('strftimedateshort', 'langconfig');
$statusline = html_writer::span(get_string('period_caption', 'local_aiquizremedial', (object) [
    'from' => userdate($iq->from, $fmtshort), 'to' => userdate($iq->to, $fmtshort), 'days' => $iq->period_days()]),
    'aiqr-period');
$statusline .= html_writer::span($lastrun ? get_string('stats_updated', 'local_aiquizremedial', format_time(time() - $lastrun))
    : get_string('stats_never', 'local_aiquizremedial'), 'aiqr-fresh');
if ($single && $canmanage($single) && $q->view !== 'actions') {
    $statusline .= html_writer::start_tag('form', ['method' => 'post', 'action' => $q->url()->out(false), 'class' => 'd-inline'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'op', 'value' => 'recalc'])
        . html_writer::tag('button', get_string('recalc', 'local_aiquizremedial'), ['type' => 'submit',
            'class' => 'btn btn-sm aiqr-btn-ghost', 'title' => get_string('recalc_help', 'local_aiquizremedial')])
        . html_writer::end_tag('form');
}
echo html_writer::div($statusline, 'aiqr-statusline');

$fmtpts = function (?float $cur, ?float $prev) {
    return ($cur === null || $prev === null) ? null : round(($cur - $prev) * 100);
};
$prevtext = function ($delta) use ($iq) {
    return get_string('delta_pts', 'local_aiquizremedial', (object) ['n' => abs((int) $delta), 'days' => $iq->period_days()]);
};
$severitychip = function (\stdClass $a) use ($actionsurl) {
    $url = $actionsurl(['actionid' => $a->id, 'astatus' => null]);
    $url->set_anchor('aiqr-action-' . $a->id);
    return html_writer::link($url,
        charts::severity($a->severity) . ' ' . html_writer::span(get_string('rule_' . $a->ruleid . '_name', 'local_aiquizremedial'),
        'aiqr-chip-rule'), ['class' => 'aiqr-actchip']);
};

// ════════════════════════════════════════════════════════════════════════════
// View: insights.
// ════════════════════════════════════════════════════════════════════════════
if ($q->view === 'insights') {
    $k = $iq->kpis();
    if (!$k['cur']['n'] && !$k['prev']['n']) {
        echo html_writer::div(
            html_writer::tag('h3', get_string('insights_empty_title', 'local_aiquizremedial')) .
            html_writer::tag('p', get_string($lastrun ? 'insights_empty_filtered' : 'insights_empty_body', 'local_aiquizremedial')),
            'aiqr-empty');
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }

    // Headline numbers.
    $att = $k['attention'];
    $sevbits = '';
    foreach (['critical', 'high', 'medium'] as $sev) {
        if ($att['bysev'][$sev]) {
            $sevbits .= html_writer::span(charts::severity($sev) . ' ' . html_writer::tag('strong', $att['bysev'][$sev]),
                'aiqr-kpi-sev');
        }
    }
    echo html_writer::start_div('aiqr-kpis');
    echo charts::kpi(get_string('kpi_attention', 'local_aiquizremedial'), (string) $att['total'], null,
        $att['total'] ? '' : get_string('kpi_attention_none', 'local_aiquizremedial'), false, '',
        get_string('kpi_attention_help', 'local_aiquizremedial'),
        $sevbits !== '' ? html_writer::div($sevbits, 'aiqr-kpi-sevs') : '');
    $d = $k['prev']['n'] ? $k['cur']['affected'] - $k['prev']['affected'] : null;
    echo charts::kpi(get_string('kpi_affected', 'local_aiquizremedial'), (string) $k['cur']['affected'],
        $d === null ? null : (float) $d, $d === null ? '' : get_string('delta_n', 'local_aiquizremedial', (object) [
        'n' => abs($d), 'days' => $iq->period_days()]), false,
        get_string('kpi_of_learners', 'local_aiquizremedial', $k['cur']['learners']),
        get_string('kpi_affected_help', 'local_aiquizremedial'));
    $d = $fmtpts($k['cur']['median'], $k['prev']['median']);
    echo charts::kpi(get_string('kpi_median', 'local_aiquizremedial'), charts::pct($k['cur']['median']),
        $d === null ? null : (float) $d, $d === null ? '' : $prevtext($d), true,
        get_string('kpi_questions_n', 'local_aiquizremedial', $k['cur']['questions']),
        get_string('kpi_median_help', 'local_aiquizremedial'));
    $d = $fmtpts($k['cur']['completion'], $k['prev']['completion']);
    echo charts::kpi(get_string('kpi_completion', 'local_aiquizremedial'), charts::pct($k['cur']['completion']),
        $d === null ? null : (float) $d, $d === null ? '' : $prevtext($d), true,
        get_string($k['cur']['completionrecent'] && $k['cur']['completionn'] ? 'kpi_modules_recent' : 'kpi_modules_n',
            'local_aiquizremedial', $k['cur']['completionn']),
        get_string('kpi_completion_help', 'local_aiquizremedial'));
    $d = $fmtpts($k['cur']['recovered'], $k['prev']['recovered']);
    echo charts::kpi(get_string('kpi_recovered', 'local_aiquizremedial'), charts::pct($k['cur']['recovered']),
        $d === null ? null : (float) $d, $d === null ? '' : $prevtext($d), true,
        get_string('kpi_recovered_n', 'local_aiquizremedial', $k['cur']['recoveredn']),
        get_string('kpi_recovered_help', 'local_aiquizremedial'));
    echo html_writer::end_div();

    // Problem questions.
    $limit = $showall ? 50 : 10;
    $problems = $iq->problem_questions($limit);
    $classstats = $iq->can_see_class_stats();
    if ($problems) {
        $list = '';
        $trows = [];
        $rank = 0;
        foreach ($problems as $key => $p) {
            $rank++;
            $qname = presenter::question_name($p->sourcetype, (int) $p->questionid);
            $label = presenter::qlabel((int) $p->slot);
            $activity = presenter::activity_name($p->sourcetype, (int) $p->activityid);
            $coursename = presenter::course_short((int) $p->courseid);
            $url = $questionurl($key);
            $top = '';
            if (!empty($p->topwrong)) {
                $top = html_writer::div(html_writer::span(get_string('topwrong', 'local_aiquizremedial'), 'aiqr-pq-toplabel') .
                    ' ' . html_writer::span('“' . s(shorten_text($p->topwrong->label, 60)) . '”', 'aiqr-pq-topans') . ' ' .
                    html_writer::span(charts::pct($p->topwrong->rate), 'aiqr-pq-toppct'), 'aiqr-pq-top');
            }
            $chips = implode('', array_map($severitychip, array_slice($p->actions, 0, 3)));
            $disc = '';
            if ($classstats && $p->stats && $p->stats->discrimination !== null && (int) $p->stats->ndisc >= 30) {
                $dv = (float) $p->stats->discrimination * 100;
                $band = $dv < 0 ? 'problem' : ($dv < 20 ? 'poor' : ($dv < 30 ? 'weak' : 'good'));
                $disc = html_writer::span(get_string('disc_short', 'local_aiquizremedial', round($dv)) . ' · ' .
                    get_string('disc_' . $band, 'local_aiquizremedial'), 'aiqr-pq-disc aiqr-disc-' . $band,
                    charts::tip(get_string('help_disc', 'local_aiquizremedial'), false));
            }
            $pcttip = get_string('tip_pq', 'local_aiquizremedial', (object) ['pct' => charts::pct($p->facility), 'n' => $p->n,
                'wrong' => $p->wrong]);
            $list .= html_writer::tag('li',
                html_writer::div($rank, 'aiqr-pq-rank') .
                html_writer::div(
                    html_writer::div(html_writer::span($label, 'aiqr-qlabel') . ' ' .
                        html_writer::link($url, s(shorten_text($qname, 110)), ['class' => 'aiqr-pq-name']), 'aiqr-pq-titleline') .
                    html_writer::div(s($activity) . ' · ' . s($coursename) . ($p->sourcetype === 'knowledgecheck'
                        ? ' · ' . get_string('source_knowledgecheck', 'local_aiquizremedial') : ''), 'aiqr-pq-meta') .
                    $top . ($chips !== '' || $disc !== '' ? html_writer::div($chips . $disc, 'aiqr-pq-chips') : ''),
                    'aiqr-pq-main') .
                html_writer::div(charts::pctbar($p->facility, $p->lowsample, $pcttip) .
                    html_writer::div(get_string('pq_n', 'local_aiquizremedial', (object) ['wrong' => $p->wrong, 'n' => $p->n]) .
                    ($p->lowsample ? ' · ' . get_string('lowsample', 'local_aiquizremedial') : ''), 'aiqr-pq-n'), 'aiqr-pq-pct') .
                html_writer::div(charts::sparkline($p->spark, get_string('spark_label', 'local_aiquizremedial')), 'aiqr-pq-spark'),
                ['class' => 'aiqr-pq']);
            $trows[] = [$rank, s($label . ' ' . $qname), s($activity), s($coursename), charts::pct($p->facility), $p->n, $p->wrong,
                !empty($p->topwrong) ? s($p->topwrong->label) . ' (' . charts::pct($p->topwrong->rate) . ')' : '–'];
        }
        $body = html_writer::div(
            html_writer::span('', 'aiqr-pq-hrank') . html_writer::span(get_string('col_question', 'local_aiquizremedial'),
            'aiqr-pq-hmain') . html_writer::span(get_string('pct_correct_first', 'local_aiquizremedial'), 'aiqr-pq-hpct') .
            html_writer::span(get_string('spark_head', 'local_aiquizremedial'), 'aiqr-pq-hspark'), 'aiqr-pq-head') .
            html_writer::tag('ol', $list, ['class' => 'aiqr-pq-list']);
        $more = count(array_filter($iq->questions(), function ($r) {
            return $r->n >= query::MINCELL && $r->wrong > 0;
        }));
        if (!$showall && $more > $limit) {
            $body .= html_writer::div(html_writer::link($q->url(['all' => 1]), get_string('showmore', 'local_aiquizremedial',
                min(50, $more))), 'aiqr-card-foot');
        }
        $table = charts::table(['#', get_string('col_question', 'local_aiquizremedial'),
            get_string('col_activity', 'local_aiquizremedial'), get_string('course'), get_string('pct_correct_first',
                'local_aiquizremedial'),
            get_string('col_n', 'local_aiquizremedial'), get_string('col_wrong', 'local_aiquizremedial'),
            get_string('topwrong', 'local_aiquizremedial')], $trows);
        echo charts::card(get_string('pq_title', 'local_aiquizremedial'), $body, get_string('pq_sub', 'local_aiquizremedial'),
            $table, 'aiqr-card-pq', get_string('pq_help', 'local_aiquizremedial'));
    } else {
        echo charts::card(get_string('pq_title', 'local_aiquizremedial'),
            html_writer::div(get_string('pq_none', 'local_aiquizremedial'), 'aiqr-card-empty'));
    }

    // Trend + funnel.
    echo html_writer::start_div('aiqr-grid2');
    $trend = $iq->trend();
    $trows = [];
    foreach ($trend as $t) {
        $trows[] = [userdate($t['start'], $fmtshort), $t['value'] === null ? '–' : charts::pct((float) $t['value']), $t['n']];
    }
    echo charts::card(get_string('trend_title', 'local_aiquizremedial'),
        charts::line($trend, [], get_string('trend_title', 'local_aiquizremedial')),
        get_string('trend_sub', 'local_aiquizremedial'),
        charts::table([get_string('col_week', 'local_aiquizremedial'), get_string('pct_correct_first', 'local_aiquizremedial'),
            get_string('col_answers', 'local_aiquizremedial')], $trows));

    $f = $iq->funnel();
    $steps = [
        ['label' => get_string('fn_generated', 'local_aiquizremedial'), 'n' => $f['generated']],
        ['label' => get_string('fn_opened', 'local_aiquizremedial'), 'n' => $f['opened']],
        ['label' => get_string('fn_completed', 'local_aiquizremedial'), 'n' => $f['completed']],
        ['label' => get_string('fn_qcfirst', 'local_aiquizremedial'), 'n' => $f['qcfirst'], 'of' => $f['completed'],
            'note' => get_string('fn_ofcompleted', 'local_aiquizremedial')],
        ['label' => get_string('fn_recovered', 'local_aiquizremedial'), 'n' => $f['recovered'], 'of' => $f['metagain'],
            'note' => get_string('fn_ofmet', 'local_aiquizremedial', $f['metagain'])],
    ];
    $frows = array_map(function ($s) {
        return [s($s['label']), (int) $s['n'], isset($s['of']) ? (int) $s['of'] : ''];
    }, $steps);
    echo charts::card(get_string('funnel_title', 'local_aiquizremedial'),
        $f['generated'] ? charts::funnel($steps) : html_writer::div(get_string('funnel_none', 'local_aiquizremedial'),
        'aiqr-card-empty'), get_string('funnel_sub', 'local_aiquizremedial'),
        charts::table([get_string('col_stage', 'local_aiquizremedial'), get_string('col_learners', 'local_aiquizremedial'),
            get_string('col_of', 'local_aiquizremedial')], $frows), '', get_string('funnel_help', 'local_aiquizremedial'));
    echo html_writer::end_div();

    // Heatmap (one course).
    if ($single) {
        $hm = $iq->heatmap($single);
        if ($hm['cols'] && $hm['rows']) {
            $rows = [];
            $trows = [];
            foreach ($hm['rows'] as $key => $r) {
                $label = presenter::qlabel((int) $r->slot) . ' ' . shorten_text(
                    presenter::question_name($r->sourcetype, (int) $r->questionid), 48);
                $rows[] = ['label' => $label, 'sub' => presenter::activity_name($r->sourcetype, (int) $r->activityid),
                    'url' => $questionurl($key), 'cells' => $hm['cells'][$key] ?? []];
                $tr = [s($label)];
                foreach ($hm['cols'] as $gid => $gname) {
                    $c = $hm['cells'][$key][$gid] ?? null;
                    $tr[] = $c && $c['n'] >= query::MINCELL ? charts::pct((float) $c['value']) . ' (' . $c['n'] . ')' : '–';
                }
                $trows[] = $tr;
            }
            echo charts::card(get_string('heatmap_title', 'local_aiquizremedial'),
                charts::heatmap($rows, $hm['cols'], query::MINCELL), get_string('heatmap_sub', 'local_aiquizremedial'),
                charts::table(array_merge([get_string('col_question', 'local_aiquizremedial')], array_map('s', $hm['cols'])),
                $trows), 'aiqr-card-wide');
        }
    } else {
        echo html_writer::div(get_string('heatmap_pickcourse', 'local_aiquizremedial'), 'aiqr-hint');
    }

    // Scatter + groups.
    echo html_writer::start_div('aiqr-grid2');
    if ($classstats) {
        $pts = [];
        $srows = [];
        foreach ($iq->scatter() as $s) {
            $key = $s->courseid . ':' . $s->sourcetype . ':' . $s->activityid . ':' . $s->qbeid;
            $name = presenter::qlabel((int) $s->slot) . ' ' . shorten_text(presenter::question_name('quiz',
                (int) $s->questionid), 50);
            $act = presenter::activity_name('quiz', (int) $s->activityid);
            $pts[] = ['x' => (float) $s->facility, 'y' => (float) $s->discrimination, 'n' => (int) $s->n,
                'low' => (int) $s->ndisc < 30, 'url' => $questionurl($key)->out(false),
                'tip' => get_string('tip_scatter', 'local_aiquizremedial', (object) ['name' => $name, 'activity' => $act,
                'pct' => charts::pct((float) $s->facility), 'disc' => round((float) $s->discrimination * 100),
                    'n' => (int) $s->n])];
            $srows[] = [s($name), s($act), charts::pct((float) $s->facility), round((float) $s->discrimination * 100), (int) $s->n];
        }
        echo charts::card(get_string('scatter_title', 'local_aiquizremedial'),
            $pts ? charts::scatter($pts) . html_writer::div(get_string('scatter_note', 'local_aiquizremedial'), 'aiqr-card-note')
            : html_writer::div(get_string('scatter_none', 'local_aiquizremedial'), 'aiqr-card-empty'),
            get_string('scatter_sub', 'local_aiquizremedial'),
            $pts ? charts::table([get_string('col_question', 'local_aiquizremedial'), get_string('col_activity',
                'local_aiquizremedial'),
                get_string('pct_correct_first', 'local_aiquizremedial'), get_string('discrimination', 'local_aiquizremedial'),
                get_string('col_n', 'local_aiquizremedial')], $srows) : '', '', get_string('help_disc', 'local_aiquizremedial'));
    } else {
        echo charts::card(get_string('scatter_title', 'local_aiquizremedial'),
            html_writer::div(get_string('classstats_hidden', 'local_aiquizremedial'), 'aiqr-card-empty'));
    }
    if ($single) {
        $groups = $iq->group_comparison($single);
        $grows = [];
        $trows = [];
        foreach ($groups as $gid => $g) {
            $grows[] = $g + ['ref' => $gid === 0];
            $trows[] = [s($g['name']), charts::pct($g['value']), charts::pct($g['lo']) . ' – ' . charts::pct($g['hi']),
                $g['learners']];
        }
        echo charts::card(get_string('groups_title', 'local_aiquizremedial'),
            count($grows) > 1 ? charts::dotplot($grows) . html_writer::div(get_string('groups_note', 'local_aiquizremedial',
            query::MINCELL), 'aiqr-card-note') : html_writer::div(get_string('groups_none', 'local_aiquizremedial',
            query::MINCELL), 'aiqr-card-empty'),
            get_string('groups_sub', 'local_aiquizremedial'),
            count($grows) > 1 ? charts::table([get_string('group'), get_string('pct_correct_first', 'local_aiquizremedial'),
                get_string('col_range', 'local_aiquizremedial'), get_string('col_learners', 'local_aiquizremedial')], $trows) : '');
    } else {
        echo charts::card(get_string('groups_title', 'local_aiquizremedial'),
            html_writer::div(get_string('groups_pickcourse', 'local_aiquizremedial'), 'aiqr-card-empty'));
    }
    echo html_writer::end_div();
}

// ════════════════════════════════════════════════════════════════════════════
// View: question detail.
// ════════════════════════════════════════════════════════════════════════════
if ($q->view === 'question') {
    $d = $qkey ? $iq->question_detail($qkey) : null;
    echo html_writer::div(html_writer::link($q->url(['view' => null, 'q' => null]), '← ' .
        get_string('backtoinsights', 'local_aiquizremedial'), ['class' => 'aiqr-back']), 'mb-2');
    if (!$d) {
        echo $OUTPUT->notification(get_string('question_notfound', 'local_aiquizremedial'), 'info');
    } else {
        $s = $d['stats'];
        $live = $d['live'];
        $slot = $s ? (int) $s->slot : (int) ($live->slot ?? 0);
        $cmid = presenter::cmid($d['sourcetype'], $d['activityid']);
        $classstats = $iq->can_see_class_stats($d['courseid']);
        echo html_writer::start_div('aiqr-qhead');
        echo html_writer::div(presenter::category_path($d['courseid']) . ' › ' . presenter::course_short($d['courseid']) . ' › ' .
            presenter::activity_name($d['sourcetype'], $d['activityid']), 'aiqr-crumbs');
        echo html_writer::tag('h2', html_writer::span(presenter::qlabel($slot), 'aiqr-qlabel aiqr-qlabel-lg') . ' ' .
            s(presenter::question_name($d['sourcetype'], $d['questionid'])), ['class' => 'aiqr-qtitle']);
        $links = '';
        if ($cmid) {
            $modname = $d['sourcetype'] === 'knowledgecheck' ? 'aiknowledgecheck' : 'quiz';
            $links .= html_writer::link(new moodle_url('/mod/' . $modname . '/view.php', ['id' => $cmid]),
                get_string('openactivity', 'local_aiquizremedial'), ['class' => 'btn btn-sm aiqr-btn-ghost']);
            if ($d['sourcetype'] === 'quiz') {
                $mctx = context_module::instance($cmid);
                if (has_any_capability(['moodle/question:editall', 'moodle/question:editmine'], $mctx)) {
                    $links .= html_writer::link(new moodle_url('/question/bank/editquestion/question.php',
                        ['id' => $d['questionid'],
                        'cmid' => $cmid, 'returnurl' => $PAGE->url->out_as_local_url(false)]),
                        get_string('editquestion', 'local_aiquizremedial'), ['class' => 'btn btn-sm btn-primary']);
                }
            }
        }
        echo html_writer::div($links, 'aiqr-qlinks');
        echo html_writer::end_div();

        // Tiles.
        $n = $live ? (int) $live->n : 0;
        $fac = $live ? $live->facility : null;
        echo html_writer::start_div('aiqr-kpis aiqr-kpis-4');
        echo charts::kpi(get_string('pct_correct_first', 'local_aiquizremedial'), charts::pct($fac), null, '', true,
            get_string('kpi_learners_n', 'local_aiquizremedial', $n), get_string('kpi_median_help', 'local_aiquizremedial'));
        $dtext = '–';
        $dsub = '';
        if ($classstats && $s && $s->discrimination !== null && (int) $s->ndisc >= 30) {
            $dv = (float) $s->discrimination * 100;
            $band = $dv < 0 ? 'problem' : ($dv < 20 ? 'poor' : ($dv < 30 ? 'weak' : 'good'));
            $dtext = (string) round($dv);
            $dsub = get_string('disc_' . $band, 'local_aiquizremedial');
        } else if ($s && $d['sourcetype'] === 'quiz') {
            $dsub = $classstats ? get_string('disc_needs', 'local_aiquizremedial', 30) : get_string('classstats_hidden_short',
                'local_aiquizremedial');
        } else {
            $dsub = get_string('disc_quizonly', 'local_aiquizremedial');
        }
        echo charts::kpi(get_string('discrimination', 'local_aiquizremedial'), $dtext, null, '', true, $dsub,
            get_string('help_disc', 'local_aiquizremedial'));
        $blankrate = $n ? (int) $live->nomitted / $n : null;
        echo charts::kpi(get_string('pct_blank', 'local_aiquizremedial'), charts::pct($blankrate), null, '', false,
            $s && (int) $s->nslots ? get_string('position', 'local_aiquizremedial', (object) ['slot' => (int) $s->slot,
            'of' => (int) $s->nslots]) : '');
        $fq = $d['funnel'];
        echo charts::kpi(get_string('kpi_recovered', 'local_aiquizremedial'),
            charts::pct($fq['metagain'] ? $fq['recovered'] / $fq['metagain'] : null), null, '', true,
            get_string('kpi_recovered_n', 'local_aiquizremedial', $fq['metagain']), get_string('kpi_recovered_help',
                'local_aiquizremedial'));
        echo html_writer::end_div();
        if ($n > 0 && $n < 20) {
            echo html_writer::div(get_string('smallsample_banner', 'local_aiquizremedial', $n), 'aiqr-hint aiqr-hint-warn');
        }

        echo html_writer::start_div('aiqr-grid2 aiqr-grid-detail');
        // Question text + answer breakdown.
        $qtext = presenter::question_text($d['sourcetype'], $d['questionid']);
        $opts = $d['options'];
        $answers = $opts ? charts::answers($opts, $classstats && !$iq->learner_filtered(), $d['versionn'], $d['blank'])
            : html_writer::div(get_string('answers_none', 'local_aiquizremedial'), 'aiqr-card-empty');
        $arows = [];
        foreach ($opts as $o) {
            $arows[] = [s($o->label) . ((int) $o->iscorrect ? ' ✓' : ''), charts::pct((float) $o->rate), $o->n,
                $o->ratetop === null ? '–' : charts::pct((float) $o->ratetop),
                $o->ratebottom === null ? '–' : charts::pct((float) $o->ratebottom)];
        }
        echo charts::card(get_string('answers_title', 'local_aiquizremedial'),
            html_writer::div(nl2br(s(shorten_text($qtext, 1200))), 'aiqr-qtext') . $answers,
            count($d['versions']) > 1 ? get_string('answers_latest', 'local_aiquizremedial', end($d['versions'])['version'])
            : get_string('answers_sub', 'local_aiquizremedial', $d['versionn']),
            $opts ? charts::table([get_string('col_option', 'local_aiquizremedial'), get_string('col_all', 'local_aiquizremedial'),
                get_string('col_n', 'local_aiquizremedial'), get_string('col_stronger', 'local_aiquizremedial'),
                get_string('col_weaker', 'local_aiquizremedial')], $arows) : '');

        // Right column: actions, misconceptions, funnel.
        echo html_writer::start_div('aiqr-stack');
        $acts = '';
        foreach ($d['actions'] as $a) {
            $acts .= html_writer::div(
                html_writer::div(charts::severity($a->severity) . ' ' . html_writer::span($statuslabel($a->status),
                    'aiqr-astatus aiqr-astatus-' . $a->status), 'aiqr-mini-head') .
                html_writer::div(s(presenter::action_text($a)), 'aiqr-mini-text') .
                html_writer::link((function () use ($actionsurl, $a) {
                    $u = $actionsurl(['actionid' => $a->id, 'astatus' => 'all']);
                    $u->set_anchor('aiqr-action-' . $a->id);
                    return $u;
                })(), get_string('openaction', 'local_aiquizremedial'), ['class' => 'aiqr-mini-link']),
                'aiqr-mini');
        }
        echo charts::card(get_string('linkedactions', 'local_aiquizremedial'),
            $acts !== '' ? $acts : html_writer::div(get_string('linkedactions_none', 'local_aiquizremedial'), 'aiqr-card-empty'));
        if ($d['misconceptions']) {
            $items = '';
            foreach ($d['misconceptions'] as $mtext) {
                $items .= html_writer::tag('li', s($mtext));
            }
            echo charts::card(get_string('misconceptions_title', 'local_aiquizremedial'),
                html_writer::tag('ul', $items, ['class' => 'aiqr-miscon']), get_string('misconceptions_sub',
                    'local_aiquizremedial'));
        }
        $steps = [
            ['label' => get_string('fn_generated', 'local_aiquizremedial'), 'n' => $fq['generated']],
            ['label' => get_string('fn_opened', 'local_aiquizremedial'), 'n' => $fq['opened']],
            ['label' => get_string('fn_completed', 'local_aiquizremedial'), 'n' => $fq['completed']],
            ['label' => get_string('fn_qcfirst', 'local_aiquizremedial'), 'n' => $fq['qcfirst'], 'of' => $fq['completed'],
                'note' => get_string('fn_ofcompleted', 'local_aiquizremedial')],
            ['label' => get_string('fn_recovered', 'local_aiquizremedial'), 'n' => $fq['recovered'], 'of' => $fq['metagain'],
                'note' => get_string('fn_ofmet', 'local_aiquizremedial', $fq['metagain'])],
        ];
        echo charts::card(get_string('funnel_title', 'local_aiquizremedial'),
            $fq['generated'] ? charts::funnel($steps) : html_writer::div(get_string('funnel_none', 'local_aiquizremedial'),
            'aiqr-card-empty'), '', charts::table([get_string('col_stage', 'local_aiquizremedial'),
            get_string('col_learners', 'local_aiquizremedial')], array_map(function ($st) {
                return [s($st['label']), (int) $st['n']];
            }, $steps)));
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Trend with edit markers.
        $markers = [];
        $vrows = [];
        foreach ($d['versions'] as $i => $v) {
            if ($i > 0 && !empty($v['created'])) {
                $markers[] = ['time' => (int) $v['created'], 'short' => 'v' . $v['version'],
                    'label' => get_string('tip_edit', 'local_aiquizremedial', (object) ['v' => $v['version'],
                    'date' => userdate((int) $v['created'], $fmtshort), 'pct' => charts::pct($v['facility'] === null ? null
                    : (float) $v['facility']), 'n' => $v['n']])];
            }
            $vrows[] = ['v' . $v['version'], !empty($v['created']) ? userdate((int) $v['created'], $fmtshort) : '–',
                charts::pct($v['facility'] === null ? null : (float) $v['facility']), (int) $v['n']];
        }
        $trows = [];
        foreach ($d['trend'] as $t) {
            $trows[] = [userdate($t['start'], $fmtshort), $t['value'] === null ? '–' : charts::pct((float) $t['value']), $t['n']];
        }
        echo charts::card(get_string('qtrend_title', 'local_aiquizremedial'),
            charts::line($d['trend'], $markers, get_string('qtrend_title', 'local_aiquizremedial'), 1000),
            $markers ? get_string('qtrend_sub_edits', 'local_aiquizremedial') : get_string('qtrend_sub', 'local_aiquizremedial'),
            charts::table([get_string('col_week', 'local_aiquizremedial'), get_string('pct_correct_first', 'local_aiquizremedial'),
                get_string('col_answers', 'local_aiquizremedial')], $trows) .
            ($vrows ? charts::table([get_string('col_version', 'local_aiquizremedial'), get_string('col_edited',
                'local_aiquizremedial'),
                get_string('pct_correct_first', 'local_aiquizremedial'), get_string('col_n', 'local_aiquizremedial')],
                    $vrows) : ''),
            'aiqr-card-wide');
    }
}

// ════════════════════════════════════════════════════════════════════════════
// View: suggested actions.
// ════════════════════════════════════════════════════════════════════════════
if ($q->view === 'actions') {
    $list = $iq->actions($actionopts);
    $counts = [];
    foreach ($statusgroups as $g => $sts) {
        $counts[$g] = count($iq->actions(['statuses' => $sts, 'severities' => $actionopts['severities'],
            'rules' => $actionopts['rules'], 'mine' => $mine]));
    }

    // Status segments + filters.
    $seg = '';
    foreach (array_keys($statusgroups) as $g) {
        $seg .= html_writer::link($actionsurl(['astatus' => $g === 'todo' ? null : $g, 'actionid' => null]),
            get_string('agroup_' . $g, 'local_aiquizremedial') . ' ' . html_writer::span($counts[$g], 'aiqr-seg-count'),
            ['class' => 'aiqr-seg' . ($astatus === $g ? ' aiqr-seg-active' : ''),
            'aria-current' => $astatus === $g ? 'true' : null]);
    }
    $sevopts = ['' => get_string('anyseverity', 'local_aiquizremedial')];
    foreach (array_reverse(array_keys(rules::SEVERITY)) as $sv) {
        $sevopts[$sv] = get_string('sev_' . $sv, 'local_aiquizremedial');
    }
    $ruleopts = ['' => get_string('anyrule', 'local_aiquizremedial')];
    foreach (array_keys(rules::catalogue()) as $rid) {
        $ruleopts[$rid] = $rid . ' · ' . get_string('rule_' . $rid . '_name', 'local_aiquizremedial');
    }
    $form = html_writer::start_tag('form', ['method' => 'get', 'action' => $q->script, 'class' => 'aiqr-afilter']);
    foreach ($q->params(['view' => 'actions', 'page' => null]) as $pn => $pv) {
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $pn, 'value' => $pv]);
    }
    if ($astatus !== 'todo') {
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'astatus', 'value' => $astatus]);
    }
    $form .= html_writer::label(get_string('col_severity', 'local_aiquizremedial'), 'aiqr-asev', false, ['class' => 'sr-only']);
    $form .= html_writer::select($sevopts, 'asev', $asev, false, ['id' => 'aiqr-asev', 'class' => 'form-select custom-select']);
    $form .= html_writer::label(get_string('col_rule', 'local_aiquizremedial'), 'aiqr-arule', false, ['class' => 'sr-only']);
    $form .= html_writer::select($ruleopts, 'arule', $arule, false, ['id' => 'aiqr-arule', 'class' => 'form-select custom-select']);
    $form .= html_writer::div(html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'mine', 'value' => 1,
        'id' => 'aiqr-mine', 'class' => 'form-check-input'] + ($mine ? ['checked' => 'checked'] : [])) .
        html_writer::tag('label', get_string('assignedtome', 'local_aiquizremedial'), ['for' => 'aiqr-mine',
        'class' => 'form-check-label']), 'form-check');
    $form .= html_writer::tag('button', get_string('filter_apply', 'local_aiquizremedial'), ['type' => 'submit',
        'class' => 'btn btn-sm btn-secondary']);
    $form .= html_writer::end_tag('form');
    echo html_writer::div(html_writer::div($seg, 'aiqr-segs', ['role' => 'navigation',
        'aria-label' => get_string('status_label', 'local_aiquizremedial')]) . $form, 'aiqr-atoolbar');

    if ($list) {
        $dl = $OUTPUT->download_dataformat_selector(get_string('export_actions', 'local_aiquizremedial'),
            new moodle_url('/local/aiquizremedial/insights.php'), 'download', $q->params(array_merge($actionsbase,
            ['page' => null])));
        $logurl = $q->url(array_merge($actionsbase, ['download' => 'csv', 'export' => 'log']));
        echo html_writer::div($dl . html_writer::link($logurl, get_string('export_log', 'local_aiquizremedial'),
            ['class' => 'btn btn-sm aiqr-btn-ghost']), 'aiqr-exports');
    }

    if (!$list) {
        echo html_writer::div(html_writer::tag('h3', get_string('actions_empty_title', 'local_aiquizremedial')) .
            html_writer::tag('p', get_string($astatus === 'todo' ? 'actions_empty_todo' : 'actions_empty', 'local_aiquizremedial')),
            'aiqr-empty');
    }

    $perpage = 25;
    $page = max(0, optional_param('apage', 0, PARAM_INT));
    if ($focus && isset($list[$focus])) {
        $page = (int) floor(array_search($focus, array_keys($list)) / $perpage);
    }
    $shown = array_slice($list, $page * $perpage, $perpage, true);
    $logs = [];
    if ($shown) {
        [$in, $params] = $DB->get_in_or_equal(array_keys($shown));
        foreach ($DB->get_records_select('local_aiqr_action_log', "actionid $in", $params, 'id DESC') as $l) {
            $logs[$l->actionid][] = $l;
        }
    }
    $assignees = [];
    foreach ($shown as $a) {
        $cid = (int) $a->courseid;
        $manage = $canmanage($cid);
        if ($manage && !isset($assignees[$cid])) {
            $assignees[$cid] = [0 => get_string('nobody', 'local_aiquizremedial')];
            foreach (get_enrolled_users(context_course::instance($cid), 'local/aiquizremedial:manageactions', 0,
                    'u.id, ' . implode(', ', array_map(function ($f) {
                        return 'u.' . $f;
                    }, \core_user\fields::get_name_fields())), 'u.lastname, u.firstname', 0, 200, true) as $u) {
                $assignees[$cid][$u->id] = fullname($u);
            }
        }
        $ev = json_decode((string) $a->evidence, true) ?: [];
        $isfocus = $focus === (int) $a->id;
        $head = html_writer::div(
            charts::severity($a->severity) .
            html_writer::span($statuslabel($a->status), 'aiqr-astatus aiqr-astatus-' . $a->status) .
            html_writer::span($a->ruleid, 'aiqr-rid', charts::tip(get_string('rule_' . $a->ruleid . '_name',
                'local_aiquizremedial'),
                false)) .
            ((int) $a->groupid ? html_writer::span(get_string('grouponly', 'local_aiquizremedial'), 'aiqr-tag') : ''),
            'aiqr-acard-tags');
        $titleline = html_writer::tag('h3', s(presenter::action_title($a)), ['class' => 'aiqr-acard-title']);
        $text = html_writer::div(s(presenter::action_text($a)), 'aiqr-acard-text');
        $impact = presenter::impact_text($a);
        if ($impact !== '') {
            $iv = json_decode((string) $a->impact, true)['verdict'] ?? '';
            $text .= html_writer::div(s($impact), 'aiqr-impact aiqr-impact-' . $iv);
        } else if ($a->status === 'done') {
            $text .= html_writer::div(get_string('impact_pending', 'local_aiquizremedial'), 'aiqr-impact');
        }
        $meta = [presenter::category_path((int) $a->courseid) . ' › ' . presenter::course_short((int) $a->courseid)];
        $meta[] = get_string('assignedto', 'local_aiquizremedial', (int) $a->assigneeid ? $username((int) $a->assigneeid)
            : get_string('nobody', 'local_aiquizremedial'));
        $meta[] = get_string('raised', 'local_aiquizremedial', userdate((int) $a->timecreated, $fmtshort));
        if ($a->status === 'snoozed') {
            $meta[] = get_string('snoozeduntil', 'local_aiquizremedial', userdate((int) $a->snoozeuntil, $fmtshort));
        }
        if ($a->status === 'dismissed' && $a->dismissreason) {
            $meta[] = get_string('dismissedbecause', 'local_aiquizremedial',
                get_string('dismiss_' . $a->dismissreason, 'local_aiquizremedial'));
        }
        $links = '';
        if ((int) $a->qbeid) {
            $links .= html_writer::link($questionurl($a->courseid . ':' . $a->sourcetype . ':' . $a->activityid . ':' . $a->qbeid),
                get_string('viewquestion', 'local_aiquizremedial'), ['class' => 'btn btn-sm aiqr-btn-ghost']);
        }
        if ((int) $a->activityid && ($cm = presenter::cmid($a->sourcetype, (int) $a->activityid))) {
            $modname = $a->sourcetype === 'knowledgecheck' ? 'aiknowledgecheck' : 'quiz';
            $links .= html_writer::link(new moodle_url('/mod/' . $modname .
                '/view.php', ['id' => $cm]), get_string('openactivity', 'local_aiquizremedial'),
                    ['class' => 'btn btn-sm aiqr-btn-ghost']);
        }
        if ($a->ruleid === 'R8') {
            $links .= html_writer::link(new moodle_url('/local/aiquizremedial/report.php', ['courseid' => $a->courseid,
                'status[0]' => 'notstarted']), get_string('seeoutstanding', 'local_aiquizremedial'),
                    ['class' => 'btn btn-sm aiqr-btn-ghost']);
        }

        // Manage.
        $manageui = '';
        if ($manage) {
            $hidden = function (string $opname) use ($a) {
                return html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'op', 'value' => $opname]) .
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'actionid', 'value' => $a->id]);
            };
            $posturl = $actionsurl(['actionid' => $a->id])->out(false);
            $quick = '';
            $next = ['open' => ['acknowledged', 'inprogress', 'done'], 'acknowledged' => ['inprogress', 'done'],
                'inprogress' => ['done'], 'snoozed' => ['open'], 'done' => ['open'], 'dismissed' => ['open'],
                'resolved' => ['open']][$a->status] ?? [];
            foreach ($next as $to) {
                $quick .= html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'd-inline']) .
                    $hidden('status') . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'to', 'value' => $to]) .
                    html_writer::tag('button', get_string('do_' . $to, 'local_aiquizremedial'), ['type' => 'submit',
                    'class' => 'btn btn-sm ' . ($to === 'done' || ($to === 'open' && count($next) === 1) ? 'btn-primary'
                    : 'aiqr-btn-ghost')]) . html_writer::end_tag('form');
            }
            // Snooze / dismiss / assign / comment in a disclosure.
            $more = '';
            if (in_array($a->status, ['open', 'acknowledged', 'inprogress'], true)) {
                $more .= html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'aiqr-mform']) .
                    $hidden('status') . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'to',
                        'value' => 'dismissed']) .
                    html_writer::tag('label', get_string('dismiss', 'local_aiquizremedial'), ['for' => 'aiqr-reason-' . $a->id,
                    'class' => 'aiqr-mform-label']) .
                    html_writer::select(array_combine(actions::DISMISS_REASONS, array_map(function ($r) {
                        return get_string('dismiss_' . $r, 'local_aiquizremedial');
                    }, actions::DISMISS_REASONS)), 'reason', '', ['' => get_string('choosereason', 'local_aiquizremedial')],
                    ['id' => 'aiqr-reason-' . $a->id, 'class' => 'form-select custom-select', 'required' => 'required']) .
                    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'comment', 'class' => 'form-control',
                    'placeholder' => get_string('optionalnote', 'local_aiquizremedial'), 'maxlength' => 1000,
                    'aria-label' => get_string('optionalnote', 'local_aiquizremedial')]) .
                    html_writer::tag('button', get_string('dismiss', 'local_aiquizremedial'), ['type' => 'submit',
                    'class' => 'btn btn-sm btn-outline-secondary']) . html_writer::end_tag('form');
                $more .= html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'aiqr-mform']) .
                    $hidden('status') . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'to',
                        'value' => 'snoozed']) .
                    html_writer::tag('label', get_string('snooze', 'local_aiquizremedial'), ['for' => 'aiqr-snooze-' . $a->id,
                    'class' => 'aiqr-mform-label']) .
                    html_writer::empty_tag('input', ['type' => 'date', 'name' => 'snoozeuntil', 'id' => 'aiqr-snooze-' . $a->id,
                    'class' => 'form-control', 'value' => date('Y-m-d', time() + 14 * DAYSECS),
                    'min' => date('Y-m-d', time() + DAYSECS)]) .
                    html_writer::tag('button', get_string('snooze', 'local_aiquizremedial'), ['type' => 'submit',
                    'class' => 'btn btn-sm btn-outline-secondary']) . html_writer::end_tag('form');
            }
            $more .= html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'aiqr-mform']) .
                $hidden('assign') .
                html_writer::tag('label', get_string('assign', 'local_aiquizremedial'), ['for' => 'aiqr-assignee-' . $a->id,
                'class' => 'aiqr-mform-label']) .
                html_writer::select($assignees[$cid], 'assignee', (int) $a->assigneeid, false, ['id' => 'aiqr-assignee-' . $a->id,
                'class' => 'form-select custom-select']) .
                html_writer::tag('button', get_string('assign', 'local_aiquizremedial'), ['type' => 'submit',
                'class' => 'btn btn-sm btn-outline-secondary']) . html_writer::end_tag('form');
            $more .= html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'aiqr-mform']) .
                $hidden('comment') .
                html_writer::tag('label', get_string('addcomment', 'local_aiquizremedial'), ['for' => 'aiqr-comment-' . $a->id,
                'class' => 'aiqr-mform-label']) .
                html_writer::empty_tag('input', ['type' => 'text', 'name' => 'comment', 'id' => 'aiqr-comment-' . $a->id,
                'class' => 'form-control', 'required' => 'required', 'maxlength' => 1000]) .
                html_writer::tag('button', get_string('addcomment', 'local_aiquizremedial'), ['type' => 'submit',
                'class' => 'btn btn-sm btn-outline-secondary']) . html_writer::end_tag('form');
            $manageui = html_writer::div($quick . html_writer::tag('details', html_writer::tag('summary',
                get_string('moreoptions', 'local_aiquizremedial')) . html_writer::div($more, 'aiqr-mforms'),
                ['class' => 'aiqr-more']), 'aiqr-acard-manage');
        }

        // Audit trail.
        $hist = '';
        foreach ($logs[$a->id] ?? [] as $l) {
            [$what, $note] = $logtext($l);
            $hist .= html_writer::tag('li', html_writer::span(userdate((int) $l->timecreated,
                get_string('strftimedatetimeshort', 'langconfig')), 'aiqr-log-time') . ' ' .
                html_writer::span(s($username((int) $l->userid)), 'aiqr-log-user') . ' — ' . s($what) .
                ($note !== '' ? html_writer::div(s($note), 'aiqr-log-note') : ''));
        }
        $history = $hist !== '' ? html_writer::tag('details', html_writer::tag('summary', get_string('history',
            'local_aiquizremedial',
            count($logs[$a->id] ?? []))) . html_writer::tag('ol', $hist, ['class' => 'aiqr-log']), ['class' => 'aiqr-history']
            + ($isfocus ? ['open' => 'open'] : [])) : '';

        echo html_writer::tag('article',
            $head . $titleline . $text .
            html_writer::div(implode(' · ', array_map('s', $meta)), 'aiqr-acard-meta') .
            html_writer::div($links . $manageui, 'aiqr-acard-actions') . $history,
            ['class' => 'aiqr-acard aiqr-acard-' . $a->severity . ($isfocus ? ' aiqr-acard-focus' : ''),
            'id' => 'aiqr-action-' . $a->id]);
    }
    if (count($list) > $perpage) {
        echo $OUTPUT->paging_bar(count($list), $page, $perpage, $actionsurl(['actionid' => null]), 'apage');
    }
}

echo html_writer::end_div();
echo $OUTPUT->footer();
