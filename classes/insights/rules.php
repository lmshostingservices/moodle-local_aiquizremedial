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

/**
 * Suggested-action rules (v1.5.0).
 *
 * Every rule uses a rate AND a minimum count, so small classes don't raise false alarms and
 * large classes aren't flagged for normal variation. Defaults are the values agreed in the
 * v1.5 plan; each can be changed (or the rule switched off) in the plugin settings.
 *
 * Severity: critical > high > medium > low > info.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class rules {
    /** Severity order. */
    const SEVERITY = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /**
     * Rule catalogue: id => [scope, sources, params (name => default)].
     *
     * Scopes: question (per question per activity), course (per course), group (per group).
     *
     * @return array
     */
    public static function catalogue(): array {
        return [
            'R1'  => ['scope' => 'question', 'sources' => ['quiz'],
                'params' => ['minn' => 10, 'maxcorrect' => 30, 'minwrong' => 5, 'critical' => 20]],
            'R2'  => ['scope' => 'question', 'sources' => ['quiz'], 'params' => ['minn' => 30]],
            'R3'  => ['scope' => 'question', 'sources' => ['quiz', 'knowledgecheck'],
                'params' => ['minn' => 10, 'margin' => 5, 'minlearners' => 4]],
            'R4'  => ['scope' => 'question', 'sources' => ['quiz'],
                'params' => ['minn' => 30, 'maxdisc' => 15, 'mincorrect' => 30, 'maxcorrect' => 90]],
            'R5'  => ['scope' => 'question', 'sources' => ['quiz', 'knowledgecheck'],
                'params' => ['minn' => 20, 'easy' => 95, 'deadminn' => 30, 'deadrate' => 3]],
            'R6'  => ['scope' => 'question', 'sources' => ['quiz', 'knowledgecheck'],
                'params' => ['minn' => 10, 'blank' => 15, 'minlearners' => 3]],
            'R8'  => ['scope' => 'course', 'sources' => [],
                'params' => ['minmodules' => 10, 'agedays' => 14, 'mincompletion' => 40]],
            'R9'  => ['scope' => 'question', 'sources' => ['quiz', 'knowledgecheck'],
                'params' => ['mincompleted' => 8, 'minpass' => 50]],
            'R10' => ['scope' => 'question', 'sources' => ['quiz', 'knowledgecheck'],
                'params' => ['minreenc' => 8, 'minrecovered' => 50]],
            'R11' => ['scope' => 'group', 'sources' => ['quiz', 'knowledgecheck'],
                'params' => ['mingroup' => 8, 'gap' => 20]],
            'R12' => ['scope' => 'question', 'sources' => ['quiz'], 'params' => ['minn' => 10, 'change' => 15]],
            'R13' => ['scope' => 'question', 'sources' => ['knowledgecheck'],
                'params' => ['minn' => 8, 'maxcorrect' => 40]],
            'R14' => ['scope' => 'question', 'sources' => ['quiz', 'knowledgecheck'],
                'params' => ['share' => 15, 'minmodules' => 20]],
        ];
    }

    /**
     * Is a rule switched on?
     *
     * @param string $ruleid
     * @return bool
     */
    public static function enabled(string $ruleid): bool {
        $v = get_config('local_aiquizremedial', 'rule_' . $ruleid . '_enabled');
        return $v === false ? true : (bool) $v;
    }

    /**
     * Effective parameter value (setting or default).
     *
     * @param string $ruleid
     * @param string $param
     * @return float
     */
    public static function param(string $ruleid, string $param): float {
        $v = get_config('local_aiquizremedial', 'rule_' . $ruleid . '_' . $param);
        if ($v === false || $v === '' || !is_numeric($v)) {
            return (float) self::catalogue()[$ruleid]['params'][$param];
        }
        return (float) $v;
    }

    /**
     * Evaluate all rules. Returns candidate actions (not yet stored).
     *
     * @param int $courseid 0 = all courses
     * @return array[] each: ruleid, severity, courseid, sourcetype, activityid, qbeid, groupid, assigneeid, evidence
     */
    public static function evaluate(int $courseid = 0): array {
        global $DB;
        $out = [];
        $params = [];
        $coursesql = '';
        if ($courseid) {
            $coursesql = 'WHERE courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $stats = $DB->get_records_sql("SELECT * FROM {local_aiqr_qstats} {$coursesql}", $params);
        $courseids = [];
        foreach ($stats as $s) {
            $courseids[(int) $s->courseid] = true;
            $opts = array_values($DB->get_records('local_aiqr_optstats', ['qstatsid' => $s->id], 'sortorder ASC'));
            foreach (self::evaluate_question($s, $opts) as $c) {
                $out[] = $c;
            }
        }
        // Course-level rules also run for courses with modules but no quiz statistics.
        $jobcourses = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {local_aiqr_job} WHERE questionid IS NOT NULL" .
            ($courseid ? ' AND courseid = :courseid' : ''), $params);
        foreach ($jobcourses as $cid) {
            $courseids[(int) $cid] = true;
        }
        foreach (array_keys($courseids) as $cid) {
            if (self::enabled('R8') && ($c = self::evaluate_r8($cid))) {
                $out[] = $c;
            }
            if (self::enabled('R11')) {
                foreach (self::evaluate_r11($cid) as $c) {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    /**
     * Candidate for a question-level rule.
     *
     * @param string $ruleid
     * @param string $severity
     * @param \stdClass $s qstats row
     * @param array $evidence
     * @param int $assignee
     * @return array
     */
    protected static function candidate(string $ruleid, string $severity, \stdClass $s, array $evidence,
        int $assignee = -1): array {
        return [
            'ruleid' => $ruleid, 'severity' => $severity, 'courseid' => (int) $s->courseid,
            'sourcetype' => $s->sourcetype, 'activityid' => (int) $s->activityid, 'qbeid' => (int) $s->qbeid,
            'groupid' => 0, 'assigneeid' => $assignee < 0 ? self::question_editor($s) : $assignee,
            'evidence' => $evidence + [
                'n' => (int) $s->n, 'facility' => $s->facility === null ? null : round((float) $s->facility, 4),
                'slot' => (int) $s->slot, 'nslots' => (int) $s->nslots, 'questionid' => (int) $s->questionid,
                'version' => self::latest_version($s),
            ],
        ];
    }

    /**
     * Latest version number in the stats row.
     *
     * @param \stdClass $s
     * @return int
     */
    protected static function latest_version(\stdClass $s): int {
        $v = json_decode((string) $s->versions, true) ?: [];
        return $v ? (int) end($v)['version'] : 1;
    }

    /**
     * The teacher who last edited the question (the default owner of its actions), if they can
     * still manage actions in the course.
     *
     * @param \stdClass $s
     * @return int
     */
    protected static function question_editor(\stdClass $s): int {
        global $DB;
        if ($s->sourcetype !== 'quiz') {
            return 0;
        }
        $q = $DB->get_record('question', ['id' => $s->questionid], 'id, modifiedby, createdby');
        $uid = $q ? (int) ($q->modifiedby ?: $q->createdby) : 0;
        if (!$uid) {
            return 0;
        }
        $ctx = \context_course::instance((int) $s->courseid, IGNORE_MISSING);
        return ($ctx && has_capability('local/aiquizremedial:manageactions', $ctx, $uid)) ? $uid : 0;
    }

    /**
     * Question-level rules for one stats row.
     *
     * @param \stdClass $s
     * @param array $opts optstats rows
     * @return array[]
     */
    public static function evaluate_question(\stdClass $s, array $opts): array {
        $out = [];
        $cat = self::catalogue();
        $n = (int) $s->n;
        $fac = $s->facility === null ? null : (float) $s->facility * 100;
        $wrong = $n - (int) $s->ncorrect;
        $applies = function (string $r) use ($cat, $s) {
            return self::enabled($r) && in_array($s->sourcetype, $cat[$r]['sources'], true);
        };

        // Key and top wrong option.
        $keyrate = 0.0;
        $keylabel = '';
        $top = null;
        foreach ($opts as $o) {
            if ((int) $o->iscorrect) {
                $keyrate += (float) $o->rate;
                $keylabel = $keylabel === '' ? (string) $o->label : $keylabel;
            } else if ((int) $o->n > 0 && (!$top || (float) $o->rate > (float) $top->rate)) {
                $top = $o;
            }
        }
        $topev = $top ? ['toplabel' => (string) $top->label, 'toprate' => round((float) $top->rate, 4),
            'topn' => (int) $top->n] : [];

        if ($applies('R1') && $fac !== null && $n >= self::param('R1', 'minn') && $fac < self::param('R1', 'maxcorrect')
                && $wrong >= self::param('R1', 'minwrong')) {
            $sev = $fac < self::param('R1', 'critical') ? 'critical' : 'high';
            $out[] = self::candidate('R1', $sev, $s, $topev + ['wrong' => $wrong]);
        }
        if ($applies('R2') && $s->discrimination !== null && (int) $s->ndisc >= self::param('R2', 'minn')
                && (float) $s->discrimination < 0) {
            $out[] = self::candidate('R2', 'critical', $s, ['disc' => round((float) $s->discrimination * 100, 1)] + $topev);
        }
        if ($applies('R3') && $top && $n >= self::param('R3', 'minn') && (int) $top->n >= self::param('R3', 'minlearners')
                && ((float) $top->rate - $keyrate) * 100 >= self::param('R3', 'margin')) {
            $out[] = self::candidate('R3', 'critical', $s, $topev + ['keylabel' => $keylabel,
                'keyrate' => round($keyrate, 4)]);
        }
        if ($applies('R4') && $s->discrimination !== null && (int) $s->ndisc >= self::param('R4', 'minn')
                && (float) $s->discrimination >= 0 && (float) $s->discrimination * 100 < self::param('R4', 'maxdisc')
                && $fac !== null && $fac >= self::param('R4', 'mincorrect') && $fac <= self::param('R4', 'maxcorrect')) {
            $out[] = self::candidate('R4', 'medium', $s, ['disc' => round((float) $s->discrimination * 100, 1)]);
        }
        if ($applies('R5')) {
            $dead = [];
            if ($n >= self::param('R5', 'deadminn')) {
                foreach ($opts as $o) {
                    if (!(int) $o->iscorrect && (float) $o->rate * 100 < self::param('R5', 'deadrate')
                            && strpos((string) $o->answerkey, 'r:') !== 0) {
                        $dead[] = (string) $o->label;
                    }
                }
            }
            $easy = $fac !== null && $n >= self::param('R5', 'minn') && $fac > self::param('R5', 'easy');
            if ($easy || $dead) {
                $out[] = self::candidate('R5', 'info', $s, ['easy' => $easy ? 1 : 0, 'dead' => $dead]);
            }
        }
        if ($applies('R6') && $n >= self::param('R6', 'minn') && (int) $s->nomitted >= self::param('R6', 'minlearners')
                && (int) $s->nomitted / max(1, $n) * 100 >= self::param('R6', 'blank')) {
            $late = (int) $s->nslots > 0 && (int) $s->slot > 0.8 * (int) $s->nslots;
            $out[] = self::candidate('R6', $late ? 'high' : 'medium', $s, ['blank' => (int) $s->nomitted,
                'blankrate' => round((int) $s->nomitted / max(1, $n), 4), 'late' => $late ? 1 : 0]);
        }
        if ($applies('R9') && (int) $s->ncompleted >= self::param('R9', 'mincompleted')
                && (int) $s->nqcfirst / max(1, (int) $s->ncompleted) * 100 < self::param('R9', 'minpass')) {
            $out[] = self::candidate('R9', 'high', $s, ['completed' => (int) $s->ncompleted, 'qcfirst' => (int) $s->nqcfirst], 0);
        }
        if ($applies('R10') && (int) $s->nreenc >= self::param('R10', 'minreenc')
                && (int) $s->nrecovered / max(1, (int) $s->nreenc) * 100 < self::param('R10', 'minrecovered')) {
            $out[] = self::candidate('R10', 'high', $s, ['reenc' => (int) $s->nreenc, 'recovered' => (int) $s->nrecovered], 0);
        }
        if ($applies('R12')) {
            $versions = json_decode((string) $s->versions, true) ?: [];
            if (count($versions) >= 2) {
                $new = end($versions);
                $old = prev($versions);
                if ($old && $new['n'] >= self::param('R12', 'minn') && $old['n'] >= self::param('R12', 'minn')) {
                    $delta = ((float) $new['facility'] - (float) $old['facility']) * 100;
                    if (abs($delta) >= self::param('R12', 'change')) {
                        $editor = (int) ($new['editor'] ?? 0);
                        $ctx = \context_course::instance((int) $s->courseid, IGNORE_MISSING);
                        if ($editor && !($ctx && has_capability('local/aiquizremedial:manageactions', $ctx, $editor))) {
                            $editor = 0;
                        }
                        $out[] = self::candidate('R12', $delta < 0 ? 'high' : 'info', $s, [
                            'before' => round((float) $old['facility'], 4), 'after' => round((float) $new['facility'], 4),
                            'beforen' => (int) $old['n'], 'aftern' => (int) $new['n'], 'edited' => (int) $new['created'],
                            'direction' => $delta < 0 ? 'down' : 'up',
                        ], $editor);
                    }
                }
            }
        }
        if ($applies('R13') && $fac !== null && $n >= self::param('R13', 'minn') && $fac < self::param('R13', 'maxcorrect')) {
            $out[] = self::candidate('R13', 'medium', $s, $topev);
        }
        if ($applies('R14') && (int) $s->nmodules >= self::param('R14', 'minmodules')) {
            $coursecredits = self::course_credits((int) $s->courseid);
            if ($coursecredits > 0 && (int) $s->credits / $coursecredits * 100 >= self::param('R14', 'share')) {
                $out[] = self::candidate('R14', 'low', $s, ['modules' => (int) $s->nmodules, 'credits' => (int) $s->credits,
                    'share' => round((int) $s->credits / $coursecredits, 4)]);
            }
        }
        return $out;
    }

    /**
     * Credits used by revision modules in a course within the insights window.
     *
     * @param int $courseid
     * @return int
     */
    protected static function course_credits(int $courseid): int {
        global $DB;
        static $cache = [];
        if (!isset($cache[$courseid])) {
            $cache[$courseid] = (int) $DB->get_field_sql(
                "SELECT COALESCE(SUM(m.credits_used), 0)
                   FROM {local_aiqr_module} m
                   JOIN {local_aiqr_job} j ON j.id = m.jobid
                  WHERE j.courseid = :c AND j.timecreated >= :from",
                ['c' => $courseid, 'from' => time() - builder::window_days() * DAYSECS]
            );
        }
        return $cache[$courseid];
    }

    /**
     * R8: revision modules not being completed in a course.
     *
     * @param int $courseid
     * @return array|null
     */
    public static function evaluate_r8(int $courseid): ?array {
        global $DB;
        $to = time() - (int) self::param('R8', 'agedays') * DAYSECS;
        $from = time() - builder::window_days() * DAYSECS;
        $row = $DB->get_record_sql(
            "SELECT COUNT(m.id) AS total,
                    SUM(CASE WHEN c.state = 'complete' THEN 1 ELSE 0 END) AS completed
               FROM {local_aiqr_module} m
               JOIN {local_aiqr_job} j ON j.id = m.jobid
          LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = j.userid
              WHERE j.courseid = :c AND m.timecreated <= :to AND m.timecreated >= :from",
            ['c' => $courseid, 'to' => $to, 'from' => $from]
        );
        $total = (int) ($row->total ?? 0);
        $completed = (int) ($row->completed ?? 0);
        if ($total < self::param('R8', 'minmodules') || $completed / max(1, $total) * 100 >= self::param('R8', 'mincompletion')) {
            return null;
        }
        return [
            'ruleid' => 'R8', 'severity' => 'medium', 'courseid' => $courseid, 'sourcetype' => null, 'activityid' => 0,
            'qbeid' => 0, 'groupid' => 0, 'assigneeid' => 0,
            'evidence' => ['modules' => $total, 'completed' => $completed, 'rate' => round($completed / $total, 4)],
        ];
    }

    /**
     * R11: a group finds a question much harder than the rest of the course.
     *
     * Two-proportion z-test on "correct first try" (|z| >= 1.96) plus a minimum gap and group
     * sizes, so small-group noise doesn't raise alarms.
     *
     * @param int $courseid
     * @return array[]
     */
    public static function evaluate_r11(int $courseid): array {
        global $DB;
        $min = (int) self::param('R11', 'mingroup');
        $gap = self::param('R11', 'gap') / 100;
        $from = time() - builder::window_days() * DAYSECS;
        $rows = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('r.sourcetype', "'-'", 'r.activityid', "'-'", 'r.qbeid', "'-'", 'gm.groupid') . " AS k,
                    r.sourcetype, r.activityid, r.qbeid, gm.groupid, COUNT(1) AS n, SUM(r.iscorrect) AS ncorrect
               FROM {local_aiqr_resp} r
               JOIN {groups_members} gm ON gm.userid = r.userid
               JOIN {groups} g ON g.id = gm.groupid AND g.courseid = r.courseid
              WHERE r.courseid = :c AND r.isfirst = 1 AND r.timefinished >= :from
           GROUP BY r.sourcetype, r.activityid, r.qbeid, gm.groupid",
            ['c' => $courseid, 'from' => $from]
        );
        if (!$rows) {
            return [];
        }
        $totals = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('sourcetype', "'-'", 'activityid', "'-'", 'qbeid') . " AS k,
                    COUNT(1) AS n, SUM(iscorrect) AS ncorrect
               FROM {local_aiqr_resp}
              WHERE courseid = :c AND isfirst = 1 AND timefinished >= :from
           GROUP BY sourcetype, activityid, qbeid",
            ['c' => $courseid, 'from' => $from]
        );
        $out = [];
        foreach ($rows as $g) {
            $tk = $g->sourcetype . '-' . $g->activityid . '-' . $g->qbeid;
            if (!isset($totals[$tk]) || (int) $g->n < $min) {
                continue;
            }
            $restn = (int) $totals[$tk]->n - (int) $g->n;
            $restc = (int) $totals[$tk]->ncorrect - (int) $g->ncorrect;
            if ($restn < $min) {
                continue;
            }
            $p1 = (int) $g->ncorrect / (int) $g->n;
            $p2 = $restc / $restn;
            if ($p2 - $p1 < $gap) {
                continue;
            }
            $p = ((int) $g->ncorrect + $restc) / ((int) $g->n + $restn);
            $se = sqrt(max(1e-9, $p * (1 - $p) * (1 / (int) $g->n + 1 / $restn)));
            if (($p2 - $p1) / $se < 1.96) {
                continue;
            }
            $s = $DB->get_record('local_aiqr_qstats', ['courseid' => $courseid, 'sourcetype' => $g->sourcetype,
                'activityid' => $g->activityid, 'qbeid' => $g->qbeid]);
            if (!$s || !in_array($g->sourcetype, self::catalogue()['R11']['sources'], true)) {
                continue;
            }
            $c = self::candidate('R11', 'medium', $s, ['groupn' => (int) $g->n, 'grouprate' => round($p1, 4),
                'restn' => $restn, 'restrate' => round($p2, 4)], 0);
            $c['groupid'] = (int) $g->groupid;
            $out[] = $c;
        }
        return $out;
    }
}
