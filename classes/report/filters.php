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

namespace local_aiquizremedial\report;

use html_writer;
use local_aiquizremedial\helper;

/**
 * Filter panel and active-filter chips shared by the report and Insights pages (v1.5.0).
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class filters {
    /** Every filter, in display order. */
    const ALL = ['search', 'category', 'course', 'cohort', 'group', 'teacher', 'source', 'activity', 'student', 'status',
        'dates', 'perpage'];

    /**
     * Status labels.
     *
     * @return array
     */
    public static function status_labels(): array {
        $out = [];
        foreach (report_query::STATUSES as $s) {
            $out[$s] = get_string('state_' . $s, 'local_aiquizremedial');
        }
        return $out;
    }

    /**
     * Source labels.
     *
     * @return array
     */
    public static function source_labels(): array {
        return ['quiz' => get_string('source_quiz', 'local_aiquizremedial'),
            'knowledgecheck' => get_string('source_knowledgecheck', 'local_aiquizremedial')];
    }

    /**
     * Option lists for the chosen filters.
     *
     * @param report_query $q
     * @param array $fields
     * @return array
     */
    public static function options(report_query $q, array $fields): array {
        $has = function ($f) use ($fields) {
            return in_array($f, $fields, true);
        };
        return [
            'category' => $has('category') && !$q->courseid ? $q->category_options() : [],
            'course'   => $has('course') && !$q->courseid ? $q->course_options() : [],
            'cohort'   => $has('cohort') ? $q->cohort_options() : [],
            'group'    => $has('group') ? $q->group_options() : [],
            'teacher'  => $has('teacher') ? $q->teacher_options() : [],
            'activity' => $has('activity') ? $q->activity_options() : [],
            'student'  => $has('student') ? $q->student_options() : [],
        ];
    }

    /**
     * URL that clears every filter but keeps the course and view.
     *
     * @param report_query $q
     * @return \moodle_url
     */
    public static function reset_url(report_query $q): \moodle_url {
        return new \moodle_url($q->script, array_filter([
            'courseid' => $q->courseid,
            'view' => $q->view !== $q->defaultview ? $q->view : null,
        ]));
    }

    /**
     * The filter panel.
     *
     * @param report_query $q
     * @param array $options from options()
     * @param array $fields filters to show
     * @param array $hidden extra hidden inputs name => value
     * @param bool $open start expanded
     * @return string
     */
    public static function form(report_query $q, array $options, array $fields, array $hidden = [], bool $open = true): string {
        global $PAGE;
        $has = function ($f) use ($fields) {
            return in_array($f, $fields, true);
        };
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
        $html = html_writer::start_tag('details', ['class' => 'aiqr-filters card mb-3'] + ($open ? ['open' => 'open'] : []));
        $html .= html_writer::tag(
            'summary',
            html_writer::span(get_string('filters', 'local_aiquizremedial'), 'aiqr-filters-title') .
            ($activecount ? html_writer::span(
                get_string('filtersactive', 'local_aiquizremedial', $activecount),
                'aiqr-pill aiqr-pill-active') : ''),
            ['class' => 'card-header']);
        $html .= html_writer::start_tag(
            'form', ['method' => 'get', 'action' => (new \moodle_url($q->script))->out(false),
            'class' => 'card-body aiqr-filter-form', 'id' => 'aiqr-filter-form']);
        $hidden = ['courseid' => $q->courseid, 'view' => $q->view !== $q->defaultview ? $q->view : ''] + $hidden;
        foreach ($hidden as $n => $v) {
            if ($v) {
                $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $n, 'value' => $v]);
            }
        }
        $html .= html_writer::start_div('aiqr-filter-grid');

        if ($has('search')) {
            $html .= html_writer::start_div('aiqr-filter-field aiqr-filter-wide');
            $html .= html_writer::tag(
                'label', get_string('filter_search', 'local_aiquizremedial'), ['for' => 'aiqr-f-search',
                'class' => 'aiqr-filter-label']);
            $html .= html_writer::empty_tag(
                'input', ['type' => 'search', 'name' => 'search', 'id' => 'aiqr-f-search', 'value' => $q->search,
                'class' => 'form-control', 'placeholder' => get_string('filter_search_placeholder', 'local_aiquizremedial')]);
            $html .= html_writer::end_div();
        }
        if (!$q->courseid && $has('category')) {
            $html .= $selectfield(
                'category', get_string('category'), $options['category'], [$q->category],
                get_string('filter_anycategory', 'local_aiquizremedial'), false,
                $checkbox('subcats', get_string('filter_subcats', 'local_aiquizremedial'), $q->subcats));
        }
        if (!$q->courseid && $has('course')) {
            $html .= $selectfield(
                'course', get_string('course'), $options['course'], $q->courses,
                get_string('filter_anycourse', 'local_aiquizremedial'));
        }
        if ($has('cohort')) {
            $html .= $selectfield(
                'cohort', get_string('cohort', 'cohort'), $options['cohort'], $q->cohorts,
                get_string('filter_anycohort', 'local_aiquizremedial'));
        }
        if ($has('group')) {
            $html .= $selectfield(
                'grp', get_string('group'), $options['group'], $q->groups,
                get_string('filter_anygroup', 'local_aiquizremedial'));
        }
        if ($has('teacher')) {
            $html .= $selectfield(
                'teacher', get_string('filter_teacher', 'local_aiquizremedial'), $options['teacher'], $q->teachers,
                get_string('filter_anyteacher', 'local_aiquizremedial'), true,
                $checkbox('teachergroups', get_string('filter_teachergroups', 'local_aiquizremedial'), $q->teachergroups));
        }
        if ($has('source') && helper::kc_installed()) {
            $html .= $selectfield(
                'source', get_string('filter_source', 'local_aiquizremedial'), self::source_labels(), $q->sources,
                get_string('filter_anysource', 'local_aiquizremedial'));
        }
        if ($has('activity')) {
            $html .= $selectfield(
                'activity', get_string('col_activity', 'local_aiquizremedial'), $options['activity'], $q->activities,
                get_string('filter_anyactivity', 'local_aiquizremedial'));
        }
        if ($has('student')) {
            $html .= $selectfield(
                'student', get_string('col_student', 'local_aiquizremedial'), $options['student'], $q->students,
                get_string('filter_anystudent', 'local_aiquizremedial'));
        }
        if ($has('status')) {
            $html .= $selectfield(
                'status', get_string('status_label', 'local_aiquizremedial'), self::status_labels(), $q->statuses,
                get_string('filter_anystatus', 'local_aiquizremedial'));
        }
        if ($has('dates')) {
            $html .= html_writer::start_div('aiqr-filter-field');
            $html .= html_writer::tag(
                'label', get_string('filter_dates', 'local_aiquizremedial'), ['class' => 'aiqr-filter-label',
                'for' => 'aiqr-f-datefrom']);
            $html .= html_writer::start_div('aiqr-filter-daterow');
            $html .= html_writer::empty_tag(
                'input', ['type' => 'date', 'name' => 'datefrom', 'id' => 'aiqr-f-datefrom',
                'value' => $q->datefrom, 'class' => 'form-control',
                'aria-label' => get_string('filter_datefrom', 'local_aiquizremedial')]);
            $html .= html_writer::span('–', 'aiqr-filter-dash');
            $html .= html_writer::empty_tag(
                'input', ['type' => 'date', 'name' => 'dateto', 'id' => 'aiqr-f-dateto',
                'value' => $q->dateto, 'class' => 'form-control',
                'aria-label' => get_string('filter_dateto', 'local_aiquizremedial')]);
            $html .= html_writer::end_div();
            $html .= html_writer::end_div();
        }
        if ($has('perpage')) {
            $ppopts = array_combine(report_query::PERPAGE, report_query::PERPAGE);
            $html .= html_writer::start_div('aiqr-filter-field');
            $html .= html_writer::tag(
                'label', get_string('filter_perpage', 'local_aiquizremedial'), ['for' => 'aiqr-f-perpage',
                'class' => 'aiqr-filter-label']);
            $html .= html_writer::select(
                $ppopts, 'perpage', $q->perpage, false, ['id' => 'aiqr-f-perpage',
                'class' => 'form-select custom-select']);
            $html .= html_writer::end_div();
        }
        $html .= html_writer::end_div(); // Grid.

        $html .= html_writer::start_div('aiqr-filter-actions');
        $html .= html_writer::tag(
            'button', get_string('filter_apply', 'local_aiquizremedial'), ['type' => 'submit',
            'class' => 'btn btn-primary']);
        $html .= html_writer::link(self::reset_url($q), get_string('filter_reset', 'local_aiquizremedial'),
            ['class' => 'btn btn-outline-secondary']);
        $html .= html_writer::end_div();
        $html .= html_writer::end_tag('form');
        $html .= html_writer::end_tag('details');

        foreach (['course', 'cohort', 'grp', 'teacher', 'source', 'activity', 'student', 'status'] as $name) {
            $field = $name === 'grp' ? 'group' : $name;
            if (!$has($field) || ($q->courseid && $name === 'course') || ($name === 'source' && !helper::kc_installed())) {
                continue;
            }
            $PAGE->requires->js_call_amd(
                'core/form-autocomplete', 'enhance', ['#aiqr-f-' . $name, false, false,
                get_string('filter_typetosearch', 'local_aiquizremedial'), false, true,
                get_string('filter_noselection', 'local_aiquizremedial')]);
        }
        return $html;
    }

    /**
     * Removable chips for the active filters.
     *
     * @param report_query $q
     * @param array $options
     * @return string
     */
    public static function chips(report_query $q, array $options): string {
        if (!$q->active_count()) {
            return '';
        }
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
            $multichips('grp', get_string('group'), $q->groups, $options['group']),
            $multichips('teacher', get_string('filter_teacher', 'local_aiquizremedial'), $q->teachers, $options['teacher']),
            $multichips('source', get_string('filter_source', 'local_aiquizremedial'), $q->sources, self::source_labels()),
            $multichips('activity', get_string('col_activity', 'local_aiquizremedial'), $q->activities, $options['activity']),
            $multichips('student', get_string('col_student', 'local_aiquizremedial'), $q->students, $options['student']),
            $multichips('status', get_string('status_label', 'local_aiquizremedial'), $q->statuses, self::status_labels())
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
        return html_writer::div(
            implode('', $chips) .
            html_writer::link(self::reset_url($q), get_string('filter_clearall', 'local_aiquizremedial'),
                ['class' => 'aiqr-chip-clear']),
            'aiqr-chips');
    }

    /**
     * Page tabs: Insights | Suggested actions | Modules | Students. Filters carry across.
     *
     * @param report_query $q
     * @param string $current
     * @param int $openactions badge count for Suggested actions
     * @return string
     */
    public static function tabs(report_query $q, string $current, int $openactions = -1): string {
        global $OUTPUT;
        $keep = $q->params(['view' => null, 'sort' => null, 'dir' => null, 'page' => null, 'perpage' => null,
            'status' => null, 'student' => null, 'attemptid' => null]);
        $all = $q->params(['view' => null, 'sort' => null, 'dir' => null, 'page' => null]);
        $insights = new \moodle_url('/local/aiquizremedial/insights.php', $keep);
        $actions = new \moodle_url('/local/aiquizremedial/insights.php', ['view' => 'actions'] + $keep);
        $modules = new \moodle_url('/local/aiquizremedial/report.php', $all);
        $students = new \moodle_url('/local/aiquizremedial/report.php', ['view' => 'students'] + $all);
        $label = get_string('suggestedactions', 'local_aiquizremedial');
        if ($openactions > 0) {
            $label .= ' ' . html_writer::span($openactions, 'aiqr-tabcount');
        }
        $tabs = [
            new \tabobject('insights', $insights, get_string('insights', 'local_aiquizremedial')),
            new \tabobject('actions', $actions, $label),
            new \tabobject('modules', $modules, get_string('view_modules', 'local_aiquizremedial')),
            new \tabobject('students', $students, get_string('view_students', 'local_aiquizremedial')),
        ];
        return $OUTPUT->tabtree($tabs, $current);
    }
}
