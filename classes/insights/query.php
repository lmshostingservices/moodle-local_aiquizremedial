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

namespace local_aiquizremedial\insights;

use local_aiquizremedial\report\report_query;

/**
 * Live aggregates for the Insights page (v1.5.0).
 *
 * Everything that depends on the learner filters (cohort, group, teacher, dates, search) is
 * aggregated live from local_aiqr_resp, so the numbers always match the filters. Figures that
 * need the whole class (discrimination, top/bottom-third answer split) come from the nightly
 * statistics tables and are hidden from viewers restricted to their own groups.
 *
 * Every query goes through report_query, which applies the viewer's course and group scope.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class query {
    /** Smallest group of learners shown anywhere on the page. */
    const MINCELL = 5;

    /** @var report_query */
    protected $q;
    /** @var int period start */
    public $from;
    /** @var int period end */
    public $to;
    /** @var int previous period start */
    public $prevfrom;
    /** @var int previous period end */
    public $prevto;
    /** @var int param counter */
    protected $pc = 0;
    /** @var array cached problem-question rows */
    protected $questioncache = null;
    /** @var array cached funnel results */
    protected $funnelcache = [];

    /**
     * Constructor.
     *
     * @param report_query $q scope must already be resolved
     */
    public function __construct(report_query $q) {
        $this->q = $q;
        $this->q->resp = true;
        $window = builder::window_days() * DAYSECS;
        $tz = \core_date::get_user_timezone_object();
        $to = time();
        if ($q->dateto !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $q->dateto . ' 23:59:59', $tz);
            $to = $dt ? min($to, $dt->getTimestamp()) : $to;
        }
        $from = $to - $window;
        if ($q->datefrom !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $q->datefrom . ' 00:00:00', $tz);
            $from = $dt ? $dt->getTimestamp() : $from;
        }
        $this->from = min($from, $to - DAYSECS);
        $this->to = $to;
        $this->prevto = $this->from - 1;
        $this->prevfrom = $this->from - ($this->to - $this->from);
    }

    /**
     * The report query (filters and scope).
     *
     * @return report_query
     */
    public function filters(): report_query {
        return $this->q;
    }

    /**
     * Period length in whole days.
     *
     * @return int
     */
    public function period_days(): int {
        return max(1, (int) round(($this->to - $this->from) / DAYSECS));
    }

    /**
     * Unique parameter name.
     *
     * @param string $p
     * @return string
     */
    protected function p(string $p): string {
        return 'iq' . $p . (++$this->pc);
    }

    /**
     * WHERE for first-attempt response rows in a period, with every filter and the viewer's scope.
     *
     * @param bool $prev previous period
     * @param array $skip filters to skip
     * @return array [sql, params]
     */
    public function where(bool $prev = false, array $skip = []): array {
        [$sql, $params] = $this->q->where(array_merge($skip, ['date']));
        $f = $this->p('f');
        $t = $this->p('t');
        $params[$f] = $prev ? $this->prevfrom : $this->from;
        $params[$t] = $prev ? $this->prevto : $this->to;
        return ["$sql AND r.isfirst = 1 AND r.timefinished >= :$f AND r.timefinished <= :$t", $params];
    }

    /**
     * Is a course visible to the viewer?
     *
     * @param int $courseid
     * @return bool
     */
    public function can_see_course(int $courseid): bool {
        $scope = $this->q->scope_courses();
        return $scope === null || in_array($courseid, $scope, true);
    }

    /**
     * The one course in view (course page, or exactly one course picked), else 0.
     *
     * @return int
     */
    public function single_course(): int {
        if ($this->q->courseid) {
            return (int) $this->q->courseid;
        }
        if (count($this->q->courses) === 1) {
            return (int) $this->q->courses[0];
        }
        $scope = $this->q->scope_courses();
        return ($scope !== null && count($scope) === 1) ? (int) $scope[0] : 0;
    }

    /**
     * Can the viewer see whole-class figures (discrimination, top/bottom thirds)?
     *
     * Viewers limited to their own groups in a separate-groups course cannot, because those
     * figures are computed over every learner.
     *
     * @param int $courseid 0 = any course in scope
     * @return bool
     */
    public function can_see_class_stats(int $courseid = 0): bool {
        return !$this->q->is_group_restricted($courseid);
    }

    /**
     * Are learner-level filters active (so nightly whole-class figures no longer match)?
     *
     * @return bool
     */
    public function learner_filtered(): bool {
        $q = $this->q;
        return (bool) ($q->cohorts || $q->groups || $q->teachers || $q->students || $q->search !== '');
    }

    // ---------------------------------------------------------------- Headline numbers.

    /**
     * Headline numbers with the previous period for comparison.
     *
     * @return array
     */
    public function kpis(): array {
        global $DB;
        $out = [];
        foreach (['cur' => false, 'prev' => true] as $k => $prev) {
            [$where, $params] = $this->where($prev);
            $row = $DB->get_record_sql("SELECT COUNT(1) AS n, SUM(r.fraction) AS s, COUNT(DISTINCT r.userid) AS learners
                                          " . report_query::resp_from() . " WHERE $where", $params);
            $out[$k]['n'] = (int) $row->n;
            $out[$k]['learners'] = (int) $row->learners;
            $out[$k]['accuracy'] = (int) $row->n ? (float) $row->s / (int) $row->n : null;

            // Median % correct first try across questions with enough learners.
            $rows = $DB->get_records_sql("SELECT " . $this->qkey_sql() . " AS k, COUNT(1) AS n, SUM(r.fraction) AS s
                                            " . report_query::resp_from() . " WHERE $where
                                        GROUP BY r.courseid, r.sourcetype, r.activityid, r.qbeid", $params);
            $facs = [];
            foreach ($rows as $r) {
                if ((int) $r->n >= self::MINCELL) {
                    $facs[] = (float) $r->s / (int) $r->n;
                }
            }
            sort($facs);
            $c = count($facs);
            $out[$k]['median'] = $c ? ($c % 2 ? $facs[intdiv($c, 2)] : ($facs[$c / 2 - 1] + $facs[$c / 2]) / 2) : null;
            $out[$k]['questions'] = $c;

            $f = $this->funnel($prev);
            // Modules at least 7 days old (learners had time to do them); if there are too few, all modules.
            $out[$k]['completionrecent'] = $f['generated7'] < self::MINCELL;
            [$done, $made] = $out[$k]['completionrecent'] ? [$f['completed'], $f['generated']]
                : [$f['completed7'], $f['generated7']];
            $out[$k]['completion'] = $made ? $done / $made : null;
            $out[$k]['completionn'] = $made;
            $out[$k]['recovered'] = $f['metagain'] ? $f['recovered'] / $f['metagain'] : null;
            $out[$k]['recoveredn'] = $f['metagain'];
        }

        // Questions needing attention: live question-level actions, highest severity per question.
        $actions = $this->actions(['live' => true]);
        $perq = [];
        foreach ($actions as $a) {
            if (!(int) $a->qbeid) {
                continue;
            }
            $key = $a->courseid . ':' . $a->sourcetype . ':' . $a->activityid . ':' . $a->qbeid;
            if (!isset($perq[$key]) || rules::SEVERITY[$a->severity] > rules::SEVERITY[$perq[$key]]) {
                $perq[$key] = $a->severity;
            }
        }
        $bysev = array_fill_keys(['critical', 'high', 'medium', 'low', 'info'], 0);
        foreach ($perq as $sev) {
            $bysev[$sev]++;
        }
        $out['attention'] = ['total' => count($perq), 'bysev' => $bysev];

        // Learners affected: learners who got a flagged question wrong first try.
        $flagged = array_keys(array_filter($perq, function ($sev) {
            return rules::SEVERITY[$sev] >= rules::SEVERITY['medium'];
        }));
        foreach (['cur' => false, 'prev' => true] as $k => $prev) {
            $out[$k]['affected'] = 0;
            if (!$flagged) {
                continue;
            }
            [$where, $params] = $this->where($prev);
            [$ksql, $kparams] = $this->qkey_in($flagged);
            $out[$k]['affected'] = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT r.userid) " . report_query::resp_from() . "
                  WHERE $where AND r.iscorrect = 0 AND $ksql", $params + $kparams);
        }
        return $out;
    }

    /**
     * SQL for the question key "courseid:sourcetype:activityid:qbeid".
     *
     * @return string
     */
    protected function qkey_sql(): string {
        global $DB;
        return $DB->sql_concat('r.courseid', "':'", 'r.sourcetype', "':'", 'r.activityid', "':'", 'r.qbeid');
    }

    /**
     * Condition restricting rows to a list of question keys.
     *
     * @param string[] $keys
     * @return array [sql, params]
     */
    protected function qkey_in(array $keys): array {
        $or = [];
        $params = [];
        foreach (array_slice($keys, 0, 500) as $key) {
            [$c, $s, $a, $b] = explode(':', $key);
            $pc = $this->p('kc');
            $ps = $this->p('ks');
            $pa = $this->p('ka');
            $pb = $this->p('kb');
            $params += [$pc => (int) $c, $ps => $s, $pa => (int) $a, $pb => (int) $b];
            $or[] = "(r.courseid = :$pc AND r.sourcetype = :$ps AND r.activityid = :$pa AND r.qbeid = :$pb)";
        }
        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    // ---------------------------------------------------------------- Problem questions.

    /**
     * Every question in view with live figures, ranked by learners wrong × how hard.
     *
     * @return array key => row object
     */
    public function questions(): array {
        global $DB;
        if ($this->questioncache !== null) {
            return $this->questioncache;
        }
        [$where, $params] = $this->where();
        $rows = $DB->get_records_sql(
            "SELECT " . $this->qkey_sql() . " AS k, r.courseid, r.sourcetype, r.activityid, r.qbeid,
                    COUNT(1) AS n, SUM(r.fraction) AS s, SUM(r.iscorrect) AS ncorrect, SUM(r.omitted) AS nomitted,
                    MAX(r.questionid) AS questionid, MAX(r.slot) AS slot, MAX(r.nslots) AS nslots
               " . report_query::resp_from() . "
              WHERE $where
           GROUP BY r.courseid, r.sourcetype, r.activityid, r.qbeid", $params);
        $out = [];
        foreach ($rows as $r) {
            $r->n = (int) $r->n;
            $r->facility = $r->n ? (float) $r->s / $r->n : 0.0;
            $r->wrong = $r->n - (int) $r->ncorrect;
            $r->score = $r->wrong * (1 - $r->facility);
            $r->lowsample = $r->n < 10;
            $out[$r->k] = $r;
        }
        uasort($out, function ($a, $b) {
            return ($b->score <=> $a->score) ?: ($a->facility <=> $b->facility);
        });
        $this->questioncache = $out;
        return $out;
    }

    /**
     * Ranked problem questions, enriched with nightly stats, top wrong answer and open actions.
     *
     * @param int $limit
     * @return array
     */
    public function problem_questions(int $limit = 10): array {
        global $DB;
        $all = array_filter($this->questions(), function ($r) {
            return $r->n >= self::MINCELL && $r->wrong > 0;
        });
        $top = array_slice($all, 0, $limit, true);
        if (!$top) {
            return [];
        }
        // Top wrong answer, live.
        [$where, $params] = $this->where();
        [$ksql, $kparams] = $this->qkey_in(array_keys($top));
        $answers = $DB->get_recordset_sql(
            "SELECT " . $this->qkey_sql() . " AS k, r.answerkey, MAX(r.answerlabel) AS label, COUNT(1) AS n
               " . report_query::resp_from() . "
              WHERE $where AND $ksql AND r.iscorrect = 0 AND r.omitted = 0 AND r.answerkey IS NOT NULL
           GROUP BY r.courseid, r.sourcetype, r.activityid, r.qbeid, r.answerkey", $params + $kparams);
        foreach ($answers as $a) {
            $row = $top[$a->k];
            if (!isset($row->topwrong) || (int) $a->n > $row->topwrong->n) {
                $row->topwrong = (object) ['label' => (string) $a->label, 'n' => (int) $a->n,
                    'rate' => (int) $a->n / $row->n];
            }
        }
        $answers->close();

        $live = $this->actions(['live' => true]);
        foreach ($top as $k => $row) {
            [$c, $s, $a, $b] = explode(':', $k);
            $stats = $DB->get_record('local_aiqr_qstats', ['courseid' => $c, 'sourcetype' => $s, 'activityid' => $a,
                'qbeid' => $b]);
            $row->stats = $stats ?: null;
            $row->spark = $stats ? (json_decode((string) $stats->spark, true) ?: []) : [];
            if ($stats && (int) $stats->slot) {
                $row->slot = (int) $stats->slot;
                $row->questionid = (int) $stats->questionid;
            }
            $row->actions = [];
            foreach ($live as $act) {
                if ((int) $act->courseid === (int) $c && $act->sourcetype === $s && (int) $act->activityid === (int) $a
                        && (int) $act->qbeid === (int) $b) {
                    $row->actions[] = $act;
                }
            }
        }
        return $top;
    }

    // ---------------------------------------------------------------- Heatmap and groups.

    /**
     * Groups of a course the viewer may see, with at least MINCELL learners in view.
     *
     * @param int $courseid
     * @return array groupid => name
     */
    public function visible_groups(int $courseid): array {
        $groups = groups_get_all_groups($courseid);
        $restricted = $this->q->restricted_groups($courseid);
        $out = [];
        foreach ($groups as $g) {
            if ($restricted !== null && !in_array((int) $g->id, $restricted, true)) {
                continue;
            }
            $out[(int) $g->id] = format_string($g->name);
        }
        return $out;
    }

    /**
     * Questions × groups heatmap of % correct first try for one course.
     *
     * @param int $courseid
     * @param int $maxrows
     * @return array ['rows' => [...], 'cols' => [...], 'cells' => [rowkey][groupid] => ['n','value']]
     */
    public function heatmap(int $courseid, int $maxrows = 12): array {
        global $DB;
        $groups = $this->visible_groups($courseid);
        if (!$groups) {
            return ['rows' => [], 'cols' => [], 'cells' => []];
        }
        $questions = array_filter($this->questions(), function ($r) use ($courseid) {
            return (int) $r->courseid === $courseid && $r->n >= self::MINCELL;
        });
        $rows = array_slice($questions, 0, $maxrows, true);
        if (!$rows) {
            return ['rows' => [], 'cols' => [], 'cells' => []];
        }
        [$where, $params] = $this->where();
        [$ksql, $kparams] = $this->qkey_in(array_keys($rows));
        [$gsql, $gparams] = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED, $this->p('hg'));
        $rs = $DB->get_recordset_sql(
            "SELECT " . $this->qkey_sql() . " AS k, gm.groupid, COUNT(1) AS n, SUM(r.fraction) AS s
               " . report_query::resp_from() . "
               JOIN {groups_members} gm ON gm.userid = r.userid AND gm.groupid $gsql
              WHERE $where AND $ksql
           GROUP BY r.courseid, r.sourcetype, r.activityid, r.qbeid, gm.groupid", $params + $kparams + $gparams);
        $cells = [];
        $colsn = [];
        foreach ($rs as $r) {
            $cells[$r->k][(int) $r->groupid] = ['n' => (int) $r->n, 'value' => (int) $r->n ? (float) $r->s / (int) $r->n : null];
            $colsn[(int) $r->groupid] = max($colsn[(int) $r->groupid] ?? 0, (int) $r->n);
        }
        $rs->close();
        $cols = [];
        foreach ($groups as $gid => $name) {
            if (($colsn[$gid] ?? 0) >= self::MINCELL) {
                $cols[$gid] = $name;
            }
        }
        return ['rows' => $rows, 'cols' => $cols, 'cells' => $cells];
    }

    /**
     * % correct first try per group (per-learner mean with a 95% interval) for one course.
     *
     * @param int $courseid
     * @return array groupid => ['name', 'learners', 'value', 'lo', 'hi']; plus key 0 for the whole course
     */
    public function group_comparison(int $courseid): array {
        global $DB;
        $groups = $this->visible_groups($courseid);
        if (!$groups) {
            return [];
        }
        [$where, $params] = $this->where();
        $cp = $this->p('gc');
        $params[$cp] = $courseid;
        [$gsql, $gparams] = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED, $this->p('gg'));
        $rs = $DB->get_recordset_sql(
            "SELECT " . $DB->sql_concat('gm.groupid', "':'", 'r.userid') . " AS k, gm.groupid, r.userid,
                    COUNT(1) AS n, SUM(r.fraction) AS s
               " . report_query::resp_from() . "
               JOIN {groups_members} gm ON gm.userid = r.userid AND gm.groupid $gsql
              WHERE $where AND r.courseid = :$cp
           GROUP BY gm.groupid, r.userid", $params + $gparams);
        $per = [];
        foreach ($rs as $r) {
            $per[(int) $r->groupid][] = (float) $r->s / max(1, (int) $r->n);
        }
        $rs->close();

        // Whole course (every learner in view once).
        [$where2, $params2] = $this->where();
        $cp2 = $this->p('gc');
        $params2[$cp2] = $courseid;
        $all = $DB->get_records_sql(
            "SELECT r.userid, COUNT(1) AS n, SUM(r.fraction) AS s
               " . report_query::resp_from() . "
              WHERE $where2 AND r.courseid = :$cp2
           GROUP BY r.userid", $params2);
        $out = [];
        $overall = array_map(function ($r) {
            return (float) $r->s / max(1, (int) $r->n);
        }, $all);
        if (count($overall) >= self::MINCELL) {
            $label = $this->q->restricted_groups($courseid) !== null ? 'allgroups_mine' : 'allgroups_course';
            $out[0] = ['name' => get_string($label, 'local_aiquizremedial')] + self::mean_ci(array_values($overall));
        }
        foreach ($groups as $gid => $name) {
            if (count($per[$gid] ?? []) >= self::MINCELL) {
                $out[$gid] = ['name' => $name] + self::mean_ci($per[$gid]);
            }
        }
        return $out;
    }

    /**
     * Mean with a 95% confidence interval (t-approximation, clamped to 0..1).
     *
     * @param float[] $v
     * @return array ['learners', 'value', 'lo', 'hi']
     */
    public static function mean_ci(array $v): array {
        $n = count($v);
        $m = $n ? array_sum($v) / $n : 0.0;
        $sd = 0.0;
        if ($n > 1) {
            foreach ($v as $x) {
                $sd += ($x - $m) ** 2;
            }
            $sd = sqrt($sd / ($n - 1));
        }
        $t = $n >= 30 ? 1.96 : [2 => 12.71, 3 => 4.30, 4 => 3.18, 5 => 2.78, 6 => 2.57, 7 => 2.45, 8 => 2.36, 9 => 2.31,
            10 => 2.26, 15 => 2.14, 20 => 2.09, 30 => 2.04][self::nearest_df($n)];
        $half = $n > 1 ? $t * $sd / sqrt($n) : 0.5;
        return ['learners' => $n, 'value' => $m, 'lo' => max(0.0, $m - $half), 'hi' => min(1.0, $m + $half)];
    }

    /**
     * Closest tabulated sample size at or below n.
     *
     * @param int $n
     * @return int
     */
    protected static function nearest_df(int $n): int {
        foreach ([30, 20, 15, 10, 9, 8, 7, 6, 5, 4, 3, 2] as $k) {
            if ($n >= $k) {
                return $k;
            }
        }
        return 2;
    }

    // ---------------------------------------------------------------- Scatter.

    /**
     * Difficulty vs discrimination for quiz questions in view (nightly figures, whole class).
     *
     * @return array of objects with facility, discrimination, n, label and key
     */
    public function scatter(): array {
        global $DB;
        if (!$this->can_see_class_stats()) {
            return [];
        }
        [$where, $params] = $this->q->course_where();
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.courseid, r.sourcetype, r.activityid, r.qbeid, r.questionid, r.slot, r.n, r.ndisc,
                    r.facility, r.discrimination
               FROM {local_aiqr_qstats} r
               JOIN {course} co ON co.id = r.courseid
               JOIN {course_categories} cc ON cc.id = co.category
              WHERE $where AND r.sourcetype = 'quiz' AND r.discrimination IS NOT NULL AND r.ndisc >= 10
           ORDER BY r.n DESC", $params, 0, 400);
        return array_values($rows);
    }

    // ---------------------------------------------------------------- Trend.

    /**
     * Weekly % correct first try over the period (or for one question).
     *
     * @param string|null $qkey restrict to one question "courseid:source:activityid:qbeid"
     * @return array of ['start' => ts, 'n' => int, 'value' => float|null]
     */
    public function trend(?string $qkey = null): array {
        global $DB;
        [$where, $params] = $this->where();
        if ($qkey) {
            [$ksql, $kparams] = $this->qkey_in([$qkey]);
            $where .= " AND $ksql";
            $params += $kparams;
        }
        $weeks = (int) min(26, max(4, ceil(($this->to - $this->from) / WEEKSECS)));
        $start = $this->to - $weeks * WEEKSECS;
        $sp = $this->p('ws');
        $sp2 = $this->p('ws');
        $params[$sp] = $start;
        $params[$sp2] = $start;
        $rows = $DB->get_records_sql(
            "SELECT x.w, COUNT(1) AS n, SUM(x.fraction) AS s
               FROM (SELECT FLOOR((r.timefinished - :$sp) / " . WEEKSECS . ") AS w, r.fraction
                       " . report_query::resp_from() . "
                      WHERE $where AND r.timefinished >= :$sp2) x
           GROUP BY x.w", $params);
        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $out[$i] = ['start' => $start + $i * WEEKSECS, 'n' => 0, 'value' => null];
        }
        foreach ($rows as $r) {
            $i = (int) $r->w;
            if (isset($out[$i])) {
                $out[$i]['n'] = (int) $r->n;
                $out[$i]['value'] = (int) $r->n ? (float) $r->s / (int) $r->n : null;
            }
        }
        return array_values($out);
    }

    // ---------------------------------------------------------------- Revision funnel.

    /**
     * Revision-module funnel for jobs created in the period.
     *
     * Generated → opened → completed → passed the Quick Check first try; then, of the learners
     * who met the same question again in a later attempt, how many got it right.
     *
     * @param bool $prev previous period
     * @param string|null $qkey restrict to one question
     * @return array counts
     */
    public function funnel(bool $prev = false, ?string $qkey = null): array {
        global $DB;
        $ck = ($prev ? 'p' : 'c') . ($qkey ?? '');
        if (isset($this->funnelcache[$ck])) {
            return $this->funnelcache[$ck];
        }
        $jq = clone $this->q;
        $jq->resp = false;
        [$where, $params] = $jq->where(['status', 'date']);
        $f = $this->p('jf');
        $t = $this->p('jt');
        $params[$f] = $prev ? $this->prevfrom : $this->from;
        $params[$t] = $prev ? $this->prevto : $this->to;
        $where .= " AND j.timecreated >= :$f AND j.timecreated <= :$t";
        if ($qkey) {
            [$c, $s, $a, $b] = explode(':', $qkey);
            $pc = $this->p('jc');
            $ps = $this->p('js');
            $pa = $this->p('ja');
            $params += [$pc => (int) $c, $ps => $s, $pa => (int) $a];
            $col = $s === 'quiz' ? 'j.quizid' : 'j.kcid';
            $where .= " AND j.courseid = :$pc AND j.sourcetype = :$ps AND $col = :$pa";
            $qids = $s === 'quiz'
                ? $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = ?', [(int) $b])
                : [(int) $b];
            [$qsql, $qparams] = $DB->get_in_or_equal($qids ?: [-1], SQL_PARAMS_NAMED, $this->p('jq'));
            $where .= " AND j.questionid $qsql";
            $params += $qparams;
        }
        $rs = $DB->get_recordset_sql(
            "SELECT j.id, j.userid, j.sourcetype, j.attemptid, j.questionid, m.id AS moduleid, m.timecreated AS mcreated,
                    c.state, c.attempts_count, c.firstviewed
               " . report_query::from() . "
              WHERE $where AND m.id IS NOT NULL", $params, 0, 20000);
        $out = ['generated' => 0, 'opened' => 0, 'completed' => 0, 'qcfirst' => 0, 'metagain' => 0, 'recovered' => 0,
            'generated7' => 0, 'completed7' => 0];
        $jobs = [];
        $old = time() - 7 * DAYSECS;
        foreach ($rs as $j) {
            $out['generated']++;
            $complete = $j->state === 'complete';
            if ((int) $j->firstviewed > 0 || in_array($j->state, ['inprogress', 'complete'], true)) {
                $out['opened']++;
            }
            if ($complete) {
                $out['completed']++;
                if ((int) $j->attempts_count === 1) {
                    $out['qcfirst']++;
                }
            }
            if ((int) $j->mcreated <= $old) {
                $out['generated7']++;
                $out['completed7'] += (int) $complete;
            }
            $jobs[] = $j;
        }
        $rs->close();
        [$out['metagain'], $out['recovered']] = $this->recovery($jobs);
        $this->funnelcache[$ck] = $out;
        return $out;
    }

    /**
     * For each job, did the learner meet the same question again, and get it right that time?
     *
     * @param array $jobs rows with userid, sourcetype, attemptid, questionid
     * @return int[] [met again, recovered]
     */
    protected function recovery(array $jobs): array {
        global $DB;
        $met = 0;
        $recovered = 0;
        foreach (array_chunk($jobs, 500) as $chunk) {
            $attemptids = array_values(array_unique(array_map(function ($j) {
                return (int) $j->attemptid;
            }, $chunk)));
            [$asql, $aparams] = $DB->get_in_or_equal($attemptids ?: [-1], SQL_PARAMS_NAMED, 'ra');
            $origin = [];
            $rs = $DB->get_recordset_select('local_aiqr_resp', "attemptid $asql", $aparams, '',
                'id, sourcetype, attemptid, questionid, userid, activityid, qbeid, attemptno');
            foreach ($rs as $r) {
                $origin[$r->sourcetype . '|' . $r->attemptid . '|' . $r->questionid] = $r;
            }
            $rs->close();
            $users = [];
            foreach ($origin as $r) {
                $users[(int) $r->userid] = true;
            }
            if (!$users) {
                continue;
            }
            [$usql, $uparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'ru');
            $later = [];
            $rs = $DB->get_recordset_select('local_aiqr_resp', "userid $usql", $uparams, 'attemptno ASC',
                'id, userid, sourcetype, activityid, qbeid, attemptno, iscorrect');
            foreach ($rs as $r) {
                $later[$r->userid . '|' . $r->sourcetype . '|' . $r->activityid . '|' . $r->qbeid][] = $r;
            }
            $rs->close();
            foreach ($chunk as $j) {
                $o = $origin[$j->sourcetype . '|' . $j->attemptid . '|' . $j->questionid] ?? null;
                if (!$o) {
                    continue;
                }
                foreach ($later[$o->userid . '|' . $o->sourcetype . '|' . $o->activityid . '|' . $o->qbeid] ?? [] as $r) {
                    if ((int) $r->attemptno > (int) $o->attemptno) {
                        $met++;
                        $recovered += (int) $r->iscorrect;
                        break;
                    }
                }
            }
        }
        return [$met, $recovered];
    }

    // ---------------------------------------------------------------- Actions.

    /**
     * Suggested actions the viewer may see, newest-severity first.
     *
     * Group-level actions are only shown to members of that group and to people who can see
     * every group in the course.
     *
     * @param array $opts live (bool), statuses (string[]), severities (string[]), rules (string[]), mine (bool), id (int)
     * @return \stdClass[]
     */
    public function actions(array $opts = []): array {
        global $DB, $USER;
        [$where, $params] = $this->q->course_where();
        if (!empty($opts['live'])) {
            $where .= " AND r.status IN ('open', 'acknowledged', 'inprogress')";
        } else if (!empty($opts['statuses'])) {
            [$s, $p] = $DB->get_in_or_equal($opts['statuses'], SQL_PARAMS_NAMED, $this->p('as'));
            $where .= " AND r.status $s";
            $params += $p;
        }
        if (!empty($opts['severities'])) {
            [$s, $p] = $DB->get_in_or_equal($opts['severities'], SQL_PARAMS_NAMED, $this->p('av'));
            $where .= " AND r.severity $s";
            $params += $p;
        }
        if (!empty($opts['rules'])) {
            [$s, $p] = $DB->get_in_or_equal($opts['rules'], SQL_PARAMS_NAMED, $this->p('ar'));
            $where .= " AND r.ruleid $s";
            $params += $p;
        }
        if (!empty($opts['mine'])) {
            $mp = $this->p('am');
            $where .= " AND r.assigneeid = :$mp";
            $params[$mp] = $USER->id;
        }
        if (!empty($opts['id'])) {
            $ip = $this->p('ai');
            $where .= " AND r.id = :$ip";
            $params[$ip] = (int) $opts['id'];
        }
        $rows = $DB->get_records_sql(
            "SELECT r.*
               FROM {local_aiqr_action} r
               JOIN {course} co ON co.id = r.courseid
               JOIN {course_categories} cc ON cc.id = co.category
              WHERE $where
           ORDER BY r.timemodified DESC", $params, 0, 5000);
        $allgroups = [];
        $out = [];
        foreach ($rows as $a) {
            if ((int) $a->groupid) {
                $cid = (int) $a->courseid;
                if (!isset($allgroups[$cid])) {
                    $ctx = \context_course::instance($cid, IGNORE_MISSING);
                    $allgroups[$cid] = $ctx && has_capability('moodle/site:accessallgroups', $ctx);
                }
                if (!$allgroups[$cid] && !groups_is_member((int) $a->groupid)) {
                    continue;
                }
            }
            $out[$a->id] = $a;
        }
        uasort($out, function ($x, $y) {
            $live = ['open' => 0, 'inprogress' => 1, 'acknowledged' => 2, 'snoozed' => 3];
            $lx = $live[$x->status] ?? 9;
            $ly = $live[$y->status] ?? 9;
            if (($lx < 9) !== ($ly < 9)) {
                return $lx <=> $ly;
            }
            return (rules::SEVERITY[$y->severity] <=> rules::SEVERITY[$x->severity])
                ?: ((int) $y->timemodified <=> (int) $x->timemodified);
        });
        return $out;
    }

    // ---------------------------------------------------------------- Question detail.

    /**
     * Everything for the question detail view.
     *
     * @param string $qkey "courseid:source:activityid:qbeid"
     * @return array|null null if not visible
     */
    public function question_detail(string $qkey): ?array {
        global $DB;
        [$c, $s, $a, $b] = explode(':', $qkey);
        $c = (int) $c;
        if (!$this->can_see_course($c)) {
            return null;
        }
        $stats = $DB->get_record('local_aiqr_qstats', ['courseid' => $c, 'sourcetype' => $s, 'activityid' => (int) $a,
            'qbeid' => (int) $b]);
        $live = $this->questions()[$qkey] ?? null;
        if (!$stats && !$live) {
            return null;
        }
        $questionid = $stats ? (int) $stats->questionid : (int) $live->questionid;

        // Answer breakdown (live counts on the latest version; labels and key from the nightly table).
        [$where, $params] = $this->where();
        [$ksql, $kparams] = $this->qkey_in([$qkey]);
        $qp = $this->p('dq');
        $params[$qp] = $questionid;
        $counts = $DB->get_records_sql(
            "SELECT r.answerkey, COUNT(1) AS n, MAX(r.answerlabel) AS label, MAX(r.iscorrect) AS iscorrect
               " . report_query::resp_from() . "
              WHERE $where AND $ksql AND r.questionid = :$qp AND r.answerkey IS NOT NULL
           GROUP BY r.answerkey", $params + $kparams);
        [$where2, $params2] = $this->where();
        [$ksql2, $kparams2] = $this->qkey_in([$qkey]);
        $qp2 = $this->p('dq');
        $params2[$qp2] = $questionid;
        $versionn = (int) $DB->count_records_sql(
            "SELECT COUNT(1) " . report_query::resp_from() . " WHERE $where2 AND $ksql2 AND r.questionid = :$qp2",
            $params2 + $kparams2);
        $options = [];
        if ($stats) {
            foreach ($DB->get_records('local_aiqr_optstats', ['qstatsid' => $stats->id], 'sortorder ASC') as $o) {
                $options[$o->answerkey] = (object) ['label' => $o->label, 'iscorrect' => (int) $o->iscorrect,
                    'n' => 0, 'ratetop' => $o->ratetop, 'ratebottom' => $o->ratebottom];
            }
        }
        foreach ($counts as $k => $row) {
            if (!isset($options[$k])) {
                $options[$k] = (object) ['label' => $row->label, 'iscorrect' => (int) $row->iscorrect, 'n' => 0,
                    'ratetop' => null, 'ratebottom' => null];
            }
            $options[$k]->n = (int) $row->n;
        }
        foreach ($options as $o) {
            $o->rate = $versionn ? $o->n / $versionn : 0.0;
        }
        $answered = array_sum(array_map(function ($o) {
            return $o->n;
        }, $options));
        $blank = max(0, $versionn - $answered);

        // Misconceptions the AI diagnosed for learners who got it wrong (never attributed).
        $misconceptions = [];
        $jobsql = $s === 'quiz' ? 'j.quizid = :a' : 'j.kcid = :a';
        $qids = $s === 'quiz'
            ? $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = ?', [(int) $b])
            : [(int) $b];
        [$qin, $qinp] = $DB->get_in_or_equal($qids ?: [-1], SQL_PARAMS_NAMED, 'mq');
        $lessons = $DB->get_records_sql(
            "SELECT m.id, m.lesson_json
               FROM {local_aiqr_module} m
               JOIN {local_aiqr_job} j ON j.id = m.jobid
              WHERE j.courseid = :c AND j.sourcetype = :s AND $jobsql AND j.questionid $qin AND m.lesson_json IS NOT NULL
           ORDER BY m.id DESC", ['c' => $c, 's' => $s, 'a' => (int) $a] + $qinp, 0, 40);
        foreach ($lessons as $l) {
            $data = \local_aiquizremedial\lesson::normalise(json_decode((string) $l->lesson_json, true));
            $text = $data['misconception_diagnosis'] ?? '';
            if ($text !== '' && !in_array($text, $misconceptions, true)) {
                $misconceptions[] = $text;
            }
            if (count($misconceptions) >= 3) {
                break;
            }
        }

        $actions = array_filter($this->actions(), function ($x) use ($c, $s, $a, $b) {
            return (int) $x->courseid === $c && $x->sourcetype === $s && (int) $x->activityid === (int) $a
                && (int) $x->qbeid === (int) $b;
        });

        return [
            'key' => $qkey, 'courseid' => $c, 'sourcetype' => $s, 'activityid' => (int) $a, 'qbeid' => (int) $b,
            'questionid' => $questionid, 'stats' => $stats ?: null, 'live' => $live,
            'options' => $options, 'versionn' => $versionn, 'blank' => $blank,
            'versions' => $stats ? (json_decode((string) $stats->versions, true) ?: []) : [],
            'trend' => $this->trend($qkey), 'funnel' => $this->funnel(false, $qkey),
            'misconceptions' => $misconceptions, 'actions' => $actions,
        ];
    }
}
