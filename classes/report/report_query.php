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

use local_aiquizremedial\helper;

/**
 * Filter state, permission scope and SQL for the remedial learning report (v1.3.0).
 *
 * Filters: category (optionally with sub-categories), course, cohort, group, teacher
 * (optionally only the teacher's own groups), activity (quiz / knowledge check), student,
 * status, date range and free-text search. All filters combine with AND; values inside a
 * multi-select combine with OR.
 *
 * Scope: users with local/aiquizremedial:viewall at system level see every course; everyone
 * else only sees courses where they hold viewall. In courses using separate groups, viewers
 * without moodle/site:accessallgroups only see learners who share a group with them.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class report_query {
    /** Learner-facing statuses. */
    const STATUSES = ['notstarted', 'inprogress', 'complete', 'generating', 'failed'];

    /** Allowed page sizes. */
    const PERPAGE = [25, 50, 100, 250];

    /** @var int course the report is locked to (course report), 0 = cross-course. */
    public $courseid = 0;
    /** @var int */
    public $category = 0;
    /** @var bool */
    public $subcats = true;
    /** @var int[] */
    public $courses = [];
    /** @var int[] */
    public $cohorts = [];
    /** @var int[] */
    public $groups = [];
    /** @var int[] */
    public $teachers = [];
    /** @var bool */
    public $teachergroups = false;
    /** @var string[] quiz / knowledgecheck */
    public $sources = [];
    /** @var string[] quiz-N / kc-N */
    public $activities = [];
    /** @var int[] */
    public $students = [];
    /** @var string[] */
    public $statuses = [];
    /** @var string YYYY-MM-DD */
    public $datefrom = '';
    /** @var string YYYY-MM-DD */
    public $dateto = '';
    /** @var string */
    public $search = '';
    /** @var int */
    public $attemptid = 0;
    /** @var string modules|students */
    public $view = 'modules';
    /** @var string */
    public $sort = '';
    /** @var string ASC|DESC */
    public $dir = 'ASC';
    /** @var int */
    public $page = 0;
    /** @var int */
    public $perpage = 50;

    /** @var bool viewer sees all courses */
    protected $sitewide = false;
    /** @var int[]|null course ids in scope (null = all) */
    protected $scopecourses = null;
    /** @var array courseid => int[] viewer's group ids, for separate-groups courses */
    protected $grouprestricted = [];
    /** @var int param counter */
    protected $pc = 0;
    /** @var bool build conditions for the insights response table (alias r) instead of jobs (alias j) */
    public $resp = false;
    /** @var string page the filter URLs point at */
    public $script = '/local/aiquizremedial/report.php';
    /** @var string view left out of URLs (the page's default) */
    public $defaultview = 'modules';

    /**
     * Build from the current request.
     *
     * @param array $views views this page accepts
     * @param string $defaultview
     * @param string $script
     * @return self
     */
    public static function from_request(array $views = ['modules', 'students'], string $defaultview = 'modules',
            string $script = '/local/aiquizremedial/report.php'): self {
        $q = new self();
        $q->defaultview = $defaultview;
        $q->script = $script;
        $q->courseid   = optional_param('courseid', 0, PARAM_INT);
        $q->category   = optional_param('category', 0, PARAM_INT);
        $q->subcats    = (bool) optional_param('subcats', 1, PARAM_BOOL);
        $q->courses    = self::ints(optional_param_array('course', [], PARAM_INT));
        $q->cohorts    = self::ints(optional_param_array('cohort', [], PARAM_INT));
        // The URL key is "grp": core reads a scalar "group" parameter on separate-groups course pages.
        $q->groups     = self::ints(optional_param_array('grp', [], PARAM_INT));
        $q->teachers   = self::ints(optional_param_array('teacher', [], PARAM_INT));
        $q->teachergroups = (bool) optional_param('teachergroups', 0, PARAM_BOOL);
        $q->sources    = array_values(
            array_intersect(
            optional_param_array('source', [], PARAM_ALPHA),
            ['quiz', 'knowledgecheck']));
        $q->activities = array_values(
            array_filter(
            optional_param_array('activity', [], PARAM_ALPHANUMEXT),
            function ($a) {
                return (bool) preg_match('/^(quiz|kc)-\d+$/', $a);
            }));
        $q->students   = self::ints(optional_param_array('student', [], PARAM_INT));
        $q->statuses   = array_values(array_intersect(optional_param_array('status', [], PARAM_ALPHA), self::STATUSES));
        $q->datefrom   = self::date(optional_param('datefrom', '', PARAM_ALPHANUMEXT));
        $q->dateto     = self::date(optional_param('dateto', '', PARAM_ALPHANUMEXT));
        $q->search     = trim(optional_param('search', '', PARAM_TEXT));
        $q->attemptid  = optional_param('attemptid', 0, PARAM_INT);
        $view          = optional_param('view', $defaultview, PARAM_ALPHA);
        $q->view       = in_array($view, $views, true) ? $view : $defaultview;
        $q->sort       = optional_param('sort', '', PARAM_ALPHA);
        $q->dir        = strtoupper(optional_param('dir', 'ASC', PARAM_ALPHA)) === 'DESC' ? 'DESC' : 'ASC';
        $q->page       = max(0, optional_param('page', 0, PARAM_INT));
        $perpage       = optional_param('perpage', 50, PARAM_INT);
        $q->perpage    = in_array($perpage, self::PERPAGE) ? $perpage : 50;
        return $q;
    }

    /**
     * Clean an int array.
     *
     * @param array $a
     * @return int[]
     */
    protected static function ints(array $a): array {
        return array_values(array_unique(array_filter(array_map('intval', $a))));
    }

    /**
     * Validate a YYYY-MM-DD date string.
     *
     * @param string $d
     * @return string
     */
    protected static function date(string $d): string {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }

    /**
     * Resolve the viewer's scope. Must be called before any query.
     *
     * @return bool false if the viewer has no course in scope
     */
    public function resolve_scope(): bool {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/grouplib.php');

        if ($this->courseid) {
            $this->scopecourses = [$this->courseid];
            $this->sitewide = false;
        } else if (has_capability('local/aiquizremedial:viewall', \context_system::instance())) {
            $this->sitewide = true;
            $this->scopecourses = null;
        } else {
            $courses = get_user_capability_course('local/aiquizremedial:viewall', $USER->id);
            $this->scopecourses = $courses ? array_values(array_unique(array_map(function ($c) {
                return (int) $c->id;
            }, $courses))) : [];
            if (!$this->scopecourses) {
                return false;
            }
        }

        // Separate groups: restrict viewers without accessallgroups to learners in their groups.
        if (!is_siteadmin()) {
            $select = 'groupmode = :sep';
            $params = ['sep' => SEPARATEGROUPS];
            if ($this->scopecourses !== null) {
                [$insql, $inparams] = $DB->get_in_or_equal($this->scopecourses, SQL_PARAMS_NAMED, 'sc');
                $select .= " AND id $insql";
                $params += $inparams;
            }
            $sepcourses = $DB->get_fieldset_select('course', 'id', $select, $params);
            foreach ($sepcourses as $cid) {
                $ctx = \context_course::instance($cid, IGNORE_MISSING);
                if ($ctx && !has_capability('moodle/site:accessallgroups', $ctx)) {
                    $this->grouprestricted[(int) $cid] = array_map(
                        'intval',
                        array_keys(groups_get_all_groups($cid, $USER->id)));
                }
            }
        }
        return true;
    }

    /**
     * Is the viewer allowed to see every course?
     *
     * @return bool
     */
    public function is_sitewide(): bool {
        return $this->sitewide;
    }

    /**
     * Unique parameter name.
     *
     * @param string $prefix
     * @return string
     */
    protected function p(string $prefix = 'p'): string {
        return 'aiqr' . $prefix . (++$this->pc);
    }

    /**
     * IN() helper with unique prefixes.
     *
     * @param array $values
     * @return array [sql, params]
     */
    protected function in(array $values): array {
        global $DB;
        return $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, $this->p('in') . '_');
    }

    /**
     * WHERE fragment restricting jobs to the viewer's scope (no user filters applied).
     *
     * @return array [sql, params]
     */
    public function scope_where(): array {
        $j = $this->resp ? 'r' : 'j';
        $where = $this->resp ? ['1 = 1'] : ['j.questionid IS NOT NULL'];
        $params = [];
        if ($this->scopecourses !== null) {
            [$sql, $p] = $this->in($this->scopecourses);
            $where[] = "{$j}.courseid $sql";
            $params += $p;
        }
        foreach ($this->grouprestricted as $cid => $groupids) {
            $cp = $this->p('rc');
            $params[$cp] = $cid;
            if (!$groupids) {
                $where[] = "{$j}.courseid <> :$cp";
                continue;
            }
            [$gsql, $gp] = $this->in($groupids);
            $params += $gp;
            $where[] = "({$j}.courseid <> :$cp OR EXISTS (SELECT 1 FROM {groups_members} rgm
                                                        WHERE rgm.userid = {$j}.userid AND rgm.groupid $gsql))";
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Role ids that count as "teacher" for the teacher filter.
     *
     * @return int[]
     */
    public static function teacher_roleids(): array {
        global $DB, $CFG;
        $ids = $DB->get_fieldset_select('role', 'id', "archetype IN ('editingteacher', 'teacher')");
        if (!empty($CFG->coursecontact)) {
            $ids = array_merge($ids, explode(',', $CFG->coursecontact));
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids ?: [-1];
    }

    /**
     * Full WHERE clause: scope + every active filter.
     *
     * @param array $skip filter names to leave out (used to build dependent option lists)
     * @return array [sql, params]
     */
    public function where(array $skip = []): array {
        global $DB;
        $j = $this->resp ? 'r' : 'j';
        [$sql, $params] = $this->scope_where();
        $where = [$sql];

        if ($this->category && !in_array('category', $skip)) {
            $cp = $this->p('cat');
            $params[$cp] = $this->category;
            if ($this->subcats) {
                $path = $DB->get_field('course_categories', 'path', ['id' => $this->category]);
                $lp = $this->p('catpath');
                $params[$lp] = ($path ?: '/-1') . '/%';
                $where[] = "(cc.id = :$cp OR " . $DB->sql_like('cc.path', ":$lp") . ")";
            } else {
                $where[] = "cc.id = :$cp";
            }
        }
        if ($this->courses && !in_array('course', $skip)) {
            [$s, $p] = $this->in($this->courses);
            $where[] = "{$j}.courseid $s";
            $params += $p;
        }
        if ($this->cohorts && !in_array('cohort', $skip)) {
            [$s, $p] = $this->in($this->cohorts);
            $where[] = "EXISTS (SELECT 1 FROM {cohort_members} fchm WHERE fchm.userid = {$j}.userid AND fchm.cohortid $s)";
            $params += $p;
        }
        if ($this->groups && !in_array('group', $skip)) {
            [$s, $p] = $this->in($this->groups);
            $where[] = "EXISTS (SELECT 1 FROM {groups_members} fgm
                                  JOIN {groups} fg ON fg.id = fgm.groupid
                                 WHERE fgm.userid = {$j}.userid AND fg.courseid = {$j}.courseid AND fgm.groupid $s)";
            $params += $p;
        }
        if ($this->teachers && !in_array('teacher', $skip)) {
            [$ts, $tp] = $this->in($this->teachers);
            [$rs, $rp] = $this->in(self::teacher_roleids());
            $params += $tp + $rp;
            $cl = $this->p('cl');
            $params[$cl] = CONTEXT_COURSE;
            $where[] = "EXISTS (SELECT 1 FROM {role_assignments} fra
                                  JOIN {context} fctx ON fctx.id = fra.contextid AND fctx.contextlevel = :$cl
                                 WHERE fctx.instanceid = {$j}.courseid AND fra.userid $ts AND fra.roleid $rs)";
            if ($this->teachergroups) {
                // Only learners sharing a group with the teacher. Courses where the teacher is
                // not in any group are not restricted (the teacher teaches everyone there).
                [$ts2, $tp2] = $this->in($this->teachers);
                [$ts3, $tp3] = $this->in($this->teachers);
                $params += $tp2 + $tp3;
                $where[] = "(NOT EXISTS (SELECT 1 FROM {groups_members} tgm
                                           JOIN {groups} tg ON tg.id = tgm.groupid
                                          WHERE tg.courseid = {$j}.courseid AND tgm.userid $ts2)
                             OR EXISTS (SELECT 1 FROM {groups_members} sgm
                                          JOIN {groups} sg ON sg.id = sgm.groupid AND sg.courseid = {$j}.courseid
                                          JOIN {groups_members} tgm2 ON tgm2.groupid = sgm.groupid
                                         WHERE sgm.userid = {$j}.userid AND tgm2.userid $ts3))";
            }
        }
        if ($this->sources && !in_array('source', $skip)) {
            [$s, $p] = $this->in($this->sources);
            $where[] = "{$j}.sourcetype $s";
            $params += $p;
        }
        if ($this->activities && !in_array('activity', $skip)) {
            $quizids = [];
            $kcids = [];
            foreach ($this->activities as $a) {
                [$type, $id] = explode('-', $a);
                if ($type === 'quiz') {
                    $quizids[] = (int) $id;
                } else {
                    $kcids[] = (int) $id;
                }
            }
            $or = [];
            if ($quizids) {
                [$s, $p] = $this->in($quizids);
                $or[] = $this->resp ? "(r.sourcetype = 'quiz' AND r.activityid $s)" : "(j.sourcetype = 'quiz' AND j.quizid $s)";
                $params += $p;
            }
            if ($kcids) {
                [$s, $p] = $this->in($kcids);
                $or[] = $this->resp ? "(r.sourcetype = 'knowledgecheck' AND r.activityid $s)"
                    : "(j.sourcetype = 'knowledgecheck' AND j.kcid $s)";
                $params += $p;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($this->students && !in_array('student', $skip)) {
            [$s, $p] = $this->in($this->students);
            $where[] = "{$j}.userid $s";
            $params += $p;
        }
        if ($this->statuses && !$this->resp && !in_array('status', $skip)) {
            [$s, $p] = $this->in($this->statuses);
            $where[] = '(' . helper::status_sql() . ") $s";
            $params += $p;
        }
        if ($this->datefrom !== '' && !in_array('date', $skip)) {
            $dp = $this->p('df');
            $params[$dp] = $this->to_timestamp($this->datefrom, false);
            $where[] = $this->resp ? "r.timefinished >= :$dp" : "j.timecreated >= :$dp";
        }
        if ($this->dateto !== '' && !in_array('date', $skip)) {
            $dp = $this->p('dt');
            $params[$dp] = $this->to_timestamp($this->dateto, true);
            $where[] = $this->resp ? "r.timefinished <= :$dp" : "j.timecreated <= :$dp";
        }
        if ($this->attemptid && !in_array('attempt', $skip)) {
            $ap = $this->p('att');
            $params[$ap] = $this->attemptid;
            $where[] = "{$j}.attemptid = :$ap";
        }
        if ($this->search !== '' && !in_array('search', $skip)) {
            $or = [];
            $fields = ['u.firstname', 'u.lastname', 'u.email', 'u.idnumber', 'u.username', 'co.fullname', 'co.shortname',
                'q.name', 'qq.name', $DB->sql_fullname('u.firstname', 'u.lastname')];
            if (helper::kc_installed()) {
                $fields[] = 'kc.name';
                $fields[] = $DB->sql_compare_text('kq.questiontext', 1000);
            }
            foreach ($fields as $f) {
                $sp = $this->p('s');
                $params[$sp] = '%' . $DB->sql_like_escape($this->search) . '%';
                $or[] = $DB->sql_like($f, ":$sp", false, false);
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        return [implode("\n AND ", $where), $params];
    }

    /**
     * Date string to timestamp in the viewer's timezone.
     *
     * @param string $date YYYY-MM-DD
     * @param bool $endofday
     * @return int
     */
    protected function to_timestamp(string $date, bool $endofday): int {
        $tz = \core_date::get_user_timezone_object();
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $date . ($endofday ? ' 23:59:59' : ' 00:00:00'), $tz);
        return $dt ? $dt->getTimestamp() : 0;
    }

    /**
     * Shared FROM clause.
     *
     * @return string
     */
    public static function from(): string {
        $sql = "FROM {local_aiqr_job} j
                JOIN {user} u ON u.id = j.userid AND u.deleted = 0
                JOIN {course} co ON co.id = j.courseid
                JOIN {course_categories} cc ON cc.id = co.category
           LEFT JOIN {local_aiqr_module} m ON m.jobid = j.id
           LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = j.userid
           LEFT JOIN {quiz} q ON q.id = j.quizid AND j.sourcetype = 'quiz'
           LEFT JOIN {question} qq ON qq.id = j.questionid AND j.sourcetype = 'quiz'";
        if (helper::kc_installed()) {
            $sql .= "
           LEFT JOIN {aiknowledgecheck} kc ON kc.id = j.kcid AND j.sourcetype = 'knowledgecheck'
           LEFT JOIN {aiknowledgecheck_questions} kq ON kq.id = j.questionid AND j.sourcetype = 'knowledgecheck'";
        }
        return $sql;
    }

    /**
     * FROM clause for the insights response table (alias r) with the same joined aliases as from().
     *
     * @return string
     */
    public static function resp_from(): string {
        $sql = "FROM {local_aiqr_resp} r
                JOIN {user} u ON u.id = r.userid AND u.deleted = 0
                JOIN {course} co ON co.id = r.courseid
                JOIN {course_categories} cc ON cc.id = co.category
           LEFT JOIN {quiz} q ON q.id = r.activityid AND r.sourcetype = 'quiz'
           LEFT JOIN {question} qq ON qq.id = r.questionid AND r.sourcetype = 'quiz'";
        if (helper::kc_installed()) {
            $sql .= "
           LEFT JOIN {aiknowledgecheck} kc ON kc.id = r.activityid AND r.sourcetype = 'knowledgecheck'
           LEFT JOIN {aiknowledgecheck_questions} kq ON kq.id = r.questionid AND r.sourcetype = 'knowledgecheck'";
        }
        return $sql;
    }

    /**
     * FROM clause for the current mode.
     *
     * @return string
     */
    public function source_from(): string {
        return $this->resp ? self::resp_from() : self::from();
    }

    /**
     * Main table alias for the current mode.
     *
     * @return string
     */
    public function alias(): string {
        return $this->resp ? 'r' : 'j';
    }

    /**
     * Course-level WHERE (scope, category, course, source, activity) for tables without a learner
     * column (statistics, actions). The table alias must be "r" and must be joined to course co
     * and course_categories cc.
     *
     * @param string $activitycol activity id column
     * @return array [sql, params]
     */
    public function course_where(string $activitycol = 'r.activityid'): array {
        $saved = [$this->resp, $this->grouprestricted];
        $this->resp = true;
        $this->grouprestricted = [];
        [$sql, $params] = $this->where(['cohort', 'group', 'teacher', 'student', 'status', 'date', 'search', 'attempt']);
        [$this->resp, $this->grouprestricted] = $saved;
        if ($activitycol !== 'r.activityid') {
            $sql = str_replace('r.activityid', $activitycol, $sql);
        }
        return [$sql, $params];
    }

    /**
     * Is the viewer limited to their own groups in any course in scope (or in the given course)?
     *
     * @param int $courseid 0 = any course
     * @return bool
     */
    public function is_group_restricted(int $courseid = 0): bool {
        return $courseid ? isset($this->grouprestricted[$courseid]) : (bool) $this->grouprestricted;
    }

    /**
     * The viewer's own groups in a separate-groups course (null = not restricted).
     *
     * @param int $courseid
     * @return int[]|null
     */
    public function restricted_groups(int $courseid): ?array {
        return $this->grouprestricted[$courseid] ?? null;
    }

    /**
     * Course ids in scope (null = every course).
     *
     * @return int[]|null
     */
    public function scope_courses(): ?array {
        return $this->scopecourses;
    }

    /**
     * Columns for the modules view.
     *
     * @return string
     */
    public static function module_fields(): string {
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $kc = helper::kc_installed() ? 'kc.name AS kcname, kq.questiontext AS kcquestiontext,' : 'NULL AS kcname, NULL AS kcquestiontext,';
        return "j.id AS jobid, j.userid, j.courseid, j.quizid, j.kcid, j.attemptid, j.questionid, j.sourcetype,
                j.status AS jobstatus, j.errormsg, j.retries, j.timecreated,
                m.id AS moduleid, m.credits_used, m.image_status, m.image_error, m.explain_image_url,
                c.state, c.attempts_count, c.completed_at,
                " . helper::status_sql() . " AS rstatus,
                u.email, u.idnumber, {$userfields},
                co.fullname AS coursename, co.shortname AS courseshort, cc.name AS categoryname,
                q.name AS quizname, qq.name AS questionname, {$kc}
                1 AS dummy";
    }

    /**
     * Sortable columns → SQL for the modules view.
     *
     * @return array
     */
    public static function module_sorts(): array {
        return [
            'student'   => 'u.lastname %1$s, u.firstname %1$s',
            'course'    => 'co.fullname %1$s',
            'activity'  => 'q.name %1$s',
            'question'  => 'qq.name %1$s',
            'status'    => 'rstatus %1$s',
            'created'   => 'j.timecreated %1$s',
            'completed' => 'c.completed_at %1$s',
            'tries'     => 'c.attempts_count %1$s',
            'credits'   => 'm.credits_used %1$s',
        ];
    }

    /**
     * Sortable columns → SQL for the students view.
     *
     * @return array
     */
    public static function student_sorts(): array {
        return [
            'student'   => 'u.lastname %1$s, u.firstname %1$s',
            'email'     => 'u.email %1$s',
            'courses'   => 'ncourses %1$s',
            'total'     => 'ntotal %1$s',
            'complete'  => 'ncomplete %1$s',
            'outstanding' => 'noutstanding %1$s',
            'failed'    => 'nfailed %1$s',
            'rate'      => 'rate %1$s',
            'last'      => 'lastactivity %1$s',
        ];
    }

    /**
     * ORDER BY for the current view.
     *
     * @return string
     */
    public function order_by(): string {
        if ($this->view === 'students') {
            $sorts = self::student_sorts();
            $default = 'student';
        } else {
            $sorts = self::module_sorts();
            $default = 'created';
        }
        $key = isset($sorts[$this->sort]) ? $this->sort : $default;
        $dir = $this->sort === '' && $key === 'created' ? 'DESC' : $this->dir;
        $tiebreak = $this->view === 'students' ? 'u.id ASC' : 'j.id DESC';
        return sprintf($sorts[$key], $dir) . ', ' . $tiebreak;
    }

    /**
     * Rows for the modules view.
     *
     * @param bool $all ignore paging (export)
     * @return \moodle_recordset
     */
    public function module_rows(bool $all = false): \moodle_recordset {
        global $DB;
        [$where, $params] = $this->where();
        $sql = "SELECT " . self::module_fields() . " " . self::from() . " WHERE $where ORDER BY " . $this->order_by();
        return $all ? $DB->get_recordset_sql($sql, $params)
            : $DB->get_recordset_sql($sql, $params, $this->page * $this->perpage, $this->perpage);
    }

    /**
     * Row count for the current view.
     *
     * @return int
     */
    public function count(): int {
        global $DB;
        [$where, $params] = $this->where();
        $select = $this->view === 'students' ? 'COUNT(DISTINCT j.userid)' : 'COUNT(1)';
        return (int) $DB->count_records_sql("SELECT $select " . self::from() . " WHERE $where", $params);
    }

    /**
     * Rows for the students view.
     *
     * @param bool $all ignore paging
     * @return \moodle_recordset
     */
    public function student_rows(bool $all = false): \moodle_recordset {
        global $DB;
        [$where, $params] = $this->where();
        $status = helper::status_sql();
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $groupfields = 'u.id, u.email, u.idnumber, ' . preg_replace('/\s+AS\s+\w+/i', '', $namefields);
        $sql = "SELECT u.id, u.email, u.idnumber, {$namefields},
                       COUNT(DISTINCT j.courseid) AS ncourses,
                       COUNT(1) AS ntotal,
                       SUM(CASE WHEN ($status) = 'complete' THEN 1 ELSE 0 END) AS ncomplete,
                       SUM(CASE WHEN ($status) IN ('notstarted', 'inprogress') THEN 1 ELSE 0 END) AS noutstanding,
                       SUM(CASE WHEN ($status) IN ('failed', 'generating') THEN 1 ELSE 0 END) AS nfailed,
                       SUM(CASE WHEN ($status) = 'complete' THEN 1 ELSE 0 END) * 100.0 / COUNT(1) AS rate,
                       MAX(COALESCE(c.timemodified, j.timecreated)) AS lastactivity,
                       SUM(COALESCE(m.credits_used, 0)) AS credits
                " . self::from() . "
                 WHERE $where
              GROUP BY $groupfields
              ORDER BY " . $this->order_by();
        return $all ? $DB->get_recordset_sql($sql, $params)
            : $DB->get_recordset_sql($sql, $params, $this->page * $this->perpage, $this->perpage);
    }

    /**
     * Summary statistics for the filtered set.
     *
     * @return \stdClass
     */
    public function stats(): \stdClass {
        global $DB;
        [$where, $params] = $this->where();
        $status = helper::status_sql();
        $sql = "SELECT COUNT(1) AS total,
                       COUNT(DISTINCT j.userid) AS students,
                       COUNT(DISTINCT j.courseid) AS courses,
                       SUM(CASE WHEN ($status) = 'complete' THEN 1 ELSE 0 END) AS complete,
                       SUM(CASE WHEN ($status) = 'inprogress' THEN 1 ELSE 0 END) AS inprogress,
                       SUM(CASE WHEN ($status) = 'notstarted' THEN 1 ELSE 0 END) AS notstarted,
                       SUM(CASE WHEN ($status) = 'generating' THEN 1 ELSE 0 END) AS generating,
                       SUM(CASE WHEN ($status) = 'failed' THEN 1 ELSE 0 END) AS failed,
                       SUM(COALESCE(m.credits_used, 0)) AS credits
                " . self::from() . " WHERE $where";
        $r = $DB->get_record_sql($sql, $params);
        foreach ((array) $r as $k => $v) {
            $r->$k = (int) $v;
        }
        $ready = $r->complete + $r->inprogress + $r->notstarted;
        $r->ready = $ready;
        $r->rate = $ready ? round($r->complete * 100 / $ready) : 0;
        return $r;
    }

    /**
     * Failed job ids in the filtered set (for "retry all").
     *
     * @return int[]
     */
    public function failed_jobids(): array {
        global $DB;
        [$where, $params] = $this->where(['status']);
        $sql = "SELECT j.id " . self::from() . " WHERE $where AND j.status = 'failed'";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    // ---------------------------------------------------------------- Option lists.

    /**
     * Jobs in scope (for option lists), ignoring the given filters.
     *
     * @param array $skip
     * @return array [where, params]
     */
    protected function option_where(array $skip): array {
        return $this->where(
            array_merge(
            $skip, ['status', 'date', 'search', 'attempt', 'student', 'cohort', 'group',
            'teacher', 'activity']));
    }

    /**
     * Categories (with their parents) that contain courses with remedial jobs.
     *
     * @return array id => path name
     */
    public function category_options(): array {
        global $DB;
        [$where, $params] = $this->option_where(['category', 'course']);
        $paths = $DB->get_fieldset_sql("SELECT DISTINCT cc.path " . $this->source_from() . " WHERE $where", $params);
        $ids = [];
        foreach ($paths as $path) {
            foreach (explode('/', trim($path, '/')) as $id) {
                $ids[(int) $id] = true;
            }
        }
        $list = \core_course_category::make_categories_list();
        return array_intersect_key($list, $ids);
    }

    /**
     * Courses with remedial jobs (respecting the category filter).
     *
     * @return array id => name
     */
    public function course_options(): array {
        global $DB;
        [$where, $params] = $this->option_where(['course']);
        $rows = $DB->get_records_sql("SELECT DISTINCT co.id, co.fullname, co.shortname " . $this->source_from()
            . " WHERE $where ORDER BY co.fullname", $params);
        $out = [];
        foreach ($rows as $r) {
            $ctx = \context_course::instance($r->id);
            $out[$r->id] = format_string($r->fullname, true, ['context' => $ctx]) . ' (' .
                format_string($r->shortname, true, ['context' => $ctx]) . ')';
        }
        return $out;
    }

    /**
     * Cohorts containing learners with remedial jobs in scope.
     *
     * @return array id => name
     */
    public function cohort_options(): array {
        global $DB;
        [$where, $params] = $this->option_where([]);
        $rows = $DB->get_records_sql("SELECT ch.id, ch.name, ch.idnumber, ch.contextid
                                        FROM {cohort} ch
                                       WHERE EXISTS (SELECT 1 FROM {cohort_members} chm
                                                      WHERE chm.cohortid = ch.id AND chm.userid IN (
                                                            SELECT {$this->alias()}.userid "
                                                            . $this->source_from() . " WHERE $where))
                                    ORDER BY ch.name", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = format_string($r->name, true, ['context' => \context::instance_by_id($r->contextid, IGNORE_MISSING)
                ?: \context_system::instance()]);
        }
        return $out;
    }

    /**
     * Groups in courses with remedial jobs (respecting category/course filters).
     *
     * @return array id => name
     */
    public function group_options(): array {
        global $DB;
        [$where, $params] = $this->option_where([]);
        $rows = $DB->get_records_sql("SELECT g.id, g.name, gc.shortname
                                        FROM {groups} g
                                        JOIN {course} gc ON gc.id = g.courseid
                                       WHERE g.courseid IN (SELECT {$this->alias()}.courseid "
                                             . $this->source_from() . " WHERE $where)
                                    ORDER BY gc.shortname, g.name", $params);
        $multi = count(array_unique(array_map(function ($r) {
            return $r->shortname;
        }, $rows))) > 1;
        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = format_string($r->name) . ($multi ? ' — ' . format_string($r->shortname) : '');
        }
        return $out;
    }

    /**
     * Teachers (editing / non-editing / course contacts) of courses with remedial jobs.
     *
     * @return array id => name
     */
    public function teacher_options(): array {
        global $DB;
        [$where, $params] = $this->option_where([]);
        [$rs, $rp] = $this->in(self::teacher_roleids());
        $params += $rp;
        $cl = $this->p('cl');
        $params[$cl] = CONTEXT_COURSE;
        $namefields = \core_user\fields::for_name()->get_sql('tu', false, '', '', false)->selects;
        $rows = $DB->get_records_sql("SELECT DISTINCT tu.id, {$namefields}
                                        FROM {role_assignments} ra
                                        JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :$cl
                                        JOIN {user} tu ON tu.id = ra.userid AND tu.deleted = 0
                                       WHERE ra.roleid $rs
                                         AND ctx.instanceid IN (SELECT {$this->alias()}.courseid "
                                             . $this->source_from() . " WHERE $where)
                                    ORDER BY tu.lastname, tu.firstname", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = fullname($r);
        }
        return $out;
    }

    /**
     * Quizzes / knowledge checks with remedial jobs (respecting category/course filters).
     *
     * @return array key => name
     */
    public function activity_options(): array {
        global $DB;
        [$where, $params] = $this->option_where([]);
        $out = [];
        $rows = $DB->get_records_sql("SELECT DISTINCT q.id, q.name, co.shortname " . $this->source_from()
            . " WHERE $where AND {$this->alias()}.sourcetype = 'quiz' AND q.id IS NOT NULL ORDER BY co.shortname, q.name", $params);
        $multi = count(array_unique(array_map(function ($r) {
            return $r->shortname;
        }, $rows))) > 1;
        foreach ($rows as $r) {
            $out['quiz-' . $r->id] = format_string($r->name) . ($multi ? ' — ' . format_string($r->shortname) : '');
        }
        if (helper::kc_installed()) {
            [$where, $params] = $this->option_where([]);
            $rows = $DB->get_records_sql("SELECT DISTINCT kc.id, kc.name, co.shortname " . $this->source_from()
                . " WHERE $where AND {$this->alias()}.sourcetype = 'knowledgecheck' AND kc.id IS NOT NULL
               ORDER BY kc.name", $params);
            foreach ($rows as $r) {
                $out['kc-' . $r->id] = format_string($r->name) . ' — ' . format_string($r->shortname);
            }
        }
        return $out;
    }

    /**
     * Learners with remedial jobs (respecting category/course filters). Capped at 5000.
     *
     * @return array id => name
     */
    public function student_options(): array {
        global $DB;
        [$where, $params] = $this->option_where([]);
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $rows = $DB->get_records_sql("SELECT DISTINCT u.id, u.email, {$namefields} " . $this->source_from()
            . " WHERE $where ORDER BY u.lastname, u.firstname", $params, 0, 5000);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = fullname($r) . ' (' . $r->email . ')';
        }
        return $out;
    }

    // ---------------------------------------------------------------- URLs.

    /**
     * URL parameters representing the current filter state.
     *
     * @param array $override
     * @return array
     */
    public function params(array $override = []): array {
        $p = [
            'courseid' => $this->courseid,
            'category' => $this->category,
            'subcats' => $this->category ? (int) $this->subcats : null,
            'course' => $this->courses,
            'cohort' => $this->cohorts,
            'grp' => $this->groups,
            'teacher' => $this->teachers,
            'teachergroups' => $this->teachergroups ? 1 : null,
            'source' => $this->sources,
            'activity' => $this->activities,
            'student' => $this->students,
            'status' => $this->statuses,
            'datefrom' => $this->datefrom,
            'dateto' => $this->dateto,
            'search' => $this->search,
            'attemptid' => $this->attemptid,
            'view' => $this->view === $this->defaultview ? null : $this->view,
            'sort' => $this->sort,
            'dir' => $this->sort ? $this->dir : null,
            'perpage' => $this->perpage === 50 ? null : $this->perpage,
            'page' => $this->page ?: null,
        ];
        $p = array_merge($p, $override);
        $p = array_filter($p, function ($v) {
            return !($v === null || $v === '' || $v === 0 || $v === []);
        });
        return self::flatten($p);
    }

    /**
     * Flatten array values to name[i] keys (moodle_url only accepts scalars before 4.3).
     *
     * @param array $params
     * @return array
     */
    public static function flatten(array $params): array {
        $out = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $item) {
                    $out[$k . '[' . $i . ']'] = $item;
                }
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Report URL with the current filters.
     *
     * @param array $override
     * @return \moodle_url
     */
    public function url(array $override = []): \moodle_url {
        return new \moodle_url($this->script, $this->params($override));
    }

    /**
     * Number of active user-chosen filters.
     *
     * @return int
     */
    public function active_count(): int {
        return (int) (bool) $this->category + count($this->courses) + count($this->cohorts) + count($this->groups)
            + count($this->teachers) + count($this->sources) + count($this->activities) + count($this->students) + count($this->statuses)
            + (int) ($this->datefrom !== '') + (int) ($this->dateto !== '') + (int) ($this->search !== '')
            + (int) (bool) $this->attemptid;
    }
}
