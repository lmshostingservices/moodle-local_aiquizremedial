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

use local_aiquizremedial\helper;

/**
 * Builds the data behind Quiz Insights (v1.5.0).
 *
 * Step 1 (ingest): every finished, non-preview quiz attempt and every completed AI Knowledge
 * Check attempt becomes one local_aiqr_resp row per question — right AND wrong answers. The
 * remedial jobs table only knows about wrong answers, so it cannot give "% correct".
 * Step 2 (stats): per question bank entry and activity, over the insights window, compute
 * % correct first try, discrimination (corrected item-rest point-biserial, as Moodle's Quiz
 * statistics report), answer breakdown for the latest version (with top/bottom-third split),
 * % left blank, revision-module funnel, recovery on the next attempt, a 12-week sparkline and
 * per-version figures.
 *
 * Only learners count: teachers (anyone with local/aiquizremedial:viewall in the course),
 * previews, inactive enrolments, suspended and deleted users are excluded. Knowledge Check
 * survey-mode activities and free-text items are excluded.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class builder {
    /** @var callable */
    protected $log;
    /** @var array cache questionid => [qbeid, version, timecreated, modifiedby] */
    protected $qvcache = [];
    /** @var array cache "courseid:userid" => bool learner */
    protected $learnercache = [];

    /**
     * Constructor.
     *
     * @param callable|null $log
     */
    public function __construct(?callable $log = null) {
        $this->log = $log ?? function (string $line) {
            mtrace($line);
        };
    }

    /**
     * Insights window in days (statistics and rules look back this far).
     *
     * @return int
     */
    public static function window_days(): int {
        $v = (int) get_config('local_aiquizremedial', 'insights_window');
        return $v > 0 ? $v : 90;
    }

    /**
     * How far back the first ingest reaches (days).
     *
     * @return int
     */
    public static function history_days(): int {
        $v = (int) get_config('local_aiquizremedial', 'insights_history');
        return $v > 0 ? $v : 365;
    }

    /**
     * Full nightly run.
     *
     * @param int $budget seconds for ingest
     * @param int $courseid 0 = all courses
     */
    public function run(int $budget = 900, int $courseid = 0): void {
        $this->ingest_quiz($budget, $courseid);
        $this->ingest_kc($courseid);
        $this->compute_stats($courseid);
    }

    // ------------------------------------------------------------------ Ingest.

    /**
     * Is this user a learner (counted in stats) in this course?
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    protected function is_learner(int $courseid, int $userid): bool {
        global $DB;
        $key = $courseid . ':' . $userid;
        if (!isset($this->learnercache[$key])) {
            $ok = false;
            $user = $DB->get_record('user', ['id' => $userid], 'id, deleted, suspended');
            if ($user && !$user->deleted && !$user->suspended) {
                $ctx = \context_course::instance($courseid, IGNORE_MISSING);
                $ok = $ctx && is_enrolled($ctx, $userid, '', true)
                    && !has_capability('local/aiquizremedial:viewall', $ctx, $userid);
            }
            if (count($this->learnercache) > 5000) {
                $this->learnercache = [];
            }
            $this->learnercache[$key] = $ok;
        }
        return $this->learnercache[$key];
    }

    /**
     * Question bank entry, version number, creation time and editor for a question id.
     *
     * @param int $questionid
     * @return array [qbeid, version, timecreated, modifiedby]
     */
    public function question_version(int $questionid): array {
        global $DB;
        if (!isset($this->qvcache[$questionid])) {
            $row = $DB->get_record_sql(
                "SELECT qv.questionbankentryid, qv.version, q.timecreated, q.modifiedby
                   FROM {question} q
              LEFT JOIN {question_versions} qv ON qv.questionid = q.id
                  WHERE q.id = :id",
                ['id' => $questionid]
            );
            $this->qvcache[$questionid] = $row
                ? [(int) ($row->questionbankentryid ?: $questionid), (int) ($row->version ?: 1), (int) $row->timecreated,
                    (int) $row->modifiedby]
                : [$questionid, 1, 0, 0];
        }
        return $this->qvcache[$questionid];
    }

    /**
     * Ingest finished quiz attempts modified since the last run (regrades included).
     *
     * @param int $budget seconds
     * @param int $courseid
     * @return int attempts processed
     */
    public function ingest_quiz(int $budget, int $courseid = 0): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $since = (int) get_config('local_aiquizremedial', 'insights_quiz_since');
        if (!$since) {
            $since = time() - self::history_days() * DAYSECS;
        }
        $params = ['since' => $since];
        $coursesql = '';
        if ($courseid) {
            $coursesql = ' AND qz.course = :courseid';
            $params['courseid'] = $courseid;
        }
        $rs = $DB->get_recordset_sql(
            "SELECT qa.id, qa.quiz, qa.userid, qa.attempt, qa.uniqueid, qa.timefinish, qa.timemodified, qz.course
               FROM {quiz_attempts} qa
               JOIN {quiz} qz ON qz.id = qa.quiz
              WHERE qa.state = 'finished' AND qa.preview = 0 AND qa.timemodified >= :since {$coursesql}
           ORDER BY qa.timemodified ASC, qa.id ASC",
            $params
        );
        $start = time();
        $count = 0;
        $maxtime = $since;
        foreach ($rs as $attempt) {
            if (time() - $start > $budget) {
                break;
            }
            try {
                $this->ingest_quiz_attempt($attempt);
            } catch (\Throwable $e) {
                ($this->log)('  [AIQR-INSIGHTS] Attempt ' . $attempt->id . ' skipped: ' . $e->getMessage());
            }
            $maxtime = max($maxtime, (int) $attempt->timemodified);
            $count++;
        }
        $rs->close();
        if (!$courseid) {
            // Only advance the high-water mark on full runs. The same second may hold more
            // attempts than one run processed, so step back one second (re-ingest is idempotent).
            set_config('insights_quiz_since', max($since, $maxtime - 1), 'local_aiquizremedial');
        }
        if ($count) {
            ($this->log)("  [AIQR-INSIGHTS] Ingested {$count} quiz attempt(s).");
        }
        return $count;
    }

    /**
     * Replace the response rows for one quiz attempt.
     *
     * @param \stdClass $attempt quiz_attempts row + course
     */
    public function ingest_quiz_attempt(\stdClass $attempt): void {
        global $DB;
        $DB->delete_records('local_aiqr_resp', ['sourcetype' => 'quiz', 'attemptid' => $attempt->id]);
        if (!$this->is_learner((int) $attempt->course, (int) $attempt->userid)) {
            return;
        }
        $firstno = (int) $DB->get_field_sql(
            "SELECT MIN(attempt) FROM {quiz_attempts}
              WHERE quiz = :quiz AND userid = :userid AND state = 'finished' AND preview = 0",
            ['quiz' => $attempt->quiz, 'userid' => $attempt->userid]
        );
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();
        $rows = [];
        $nslots = 0;
        foreach ($slots as $slot) {
            $qa = $quba->get_question_attempt($slot);
            if ((float) $qa->get_max_mark() <= 0) {
                continue;
            }
            $nslots++;
        }
        $pos = 0;
        foreach ($slots as $slot) {
            $qa = $quba->get_question_attempt($slot);
            if ((float) $qa->get_max_mark() <= 0) {
                continue;
            }
            $pos++;
            $state = $qa->get_state();
            $fraction = $qa->get_fraction();
            $omitted = $fraction === null && $state->is_gave_up();
            if ($fraction === null && !$omitted) {
                continue; // Needs manual grading or not finished.
            }
            $question = $qa->get_question();
            [$qbeid, $version] = $this->question_version((int) $question->id);
            [$key, $label] = $omitted ? [null, null] : self::answer_key($qa, $question);
            $rows[] = (object) [
                'userid'       => (int) $attempt->userid,
                'courseid'     => (int) $attempt->course,
                'sourcetype'   => 'quiz',
                'activityid'   => (int) $attempt->quiz,
                'attemptid'    => (int) $attempt->id,
                'attemptno'    => (int) $attempt->attempt,
                'isfirst'      => (int) ((int) $attempt->attempt === $firstno),
                'slot'         => $pos,
                'nslots'       => $nslots,
                'qbeid'        => $qbeid,
                'questionid'   => (int) $question->id,
                'version'      => $version,
                'fraction'     => $omitted ? 0 : (float) $fraction,
                'maxmark'      => (float) $qa->get_max_mark(),
                'iscorrect'    => (!$omitted && (float) $fraction >= 0.99999) ? 1 : 0,
                'omitted'      => $omitted ? 1 : 0,
                'answerkey'    => $key,
                'answerlabel'  => $label,
                'timefinished' => (int) ($attempt->timefinish ?: $attempt->timemodified),
            ];
        }
        if ($rows) {
            $DB->insert_records('local_aiqr_resp', $rows);
        }
        // A later first attempt can change which attempt is "first" (e.g. after a deletion).
        $DB->execute(
            "UPDATE {local_aiqr_resp} SET isfirst = CASE WHEN attemptno = :first THEN 1 ELSE 0 END
              WHERE sourcetype = 'quiz' AND activityid = :quiz AND userid = :userid",
            ['first' => $firstno, 'quiz' => $attempt->quiz, 'userid' => $attempt->userid]
        );
    }

    /**
     * Stable key + human label for the learner's answer.
     *
     * Multiple choice (single) and true/false map to the question_answers id, so shuffled option
     * order never mixes options up. Other types group identical response summaries.
     *
     * @param \question_attempt $qa
     * @param \question_definition $question
     * @return array [key|null, label|null]
     */
    public static function answer_key(\question_attempt $qa, $question): array {
        try {
            if ($question instanceof \qtype_multichoice_single_question) {
                $choice = $qa->get_last_qt_var('answer');
                $order = $question->get_order($qa);
                if ($choice !== null && $choice !== '' && isset($order[(int) $choice])) {
                    $answerid = (int) $order[(int) $choice];
                    $text = $question->answers[$answerid]->answer ?? '';
                    return ['a:' . $answerid, self::short(html_to_text((string) $text, 0, false))];
                }
            } else if ($question instanceof \qtype_truefalse_question) {
                $choice = $qa->get_last_qt_var('answer');
                if ($choice !== null && $choice !== '') {
                    $answerid = (int) $choice ? (int) $question->trueanswerid : (int) $question->falseanswerid;
                    return ['a:' . $answerid, (int) $choice ? get_string('true', 'qtype_truefalse')
                        : get_string('false', 'qtype_truefalse')];
                }
            }
            $summary = trim((string) $qa->get_response_summary());
            if ($summary === '') {
                return [null, null];
            }
            return ['r:' . substr(sha1(\core_text::strtolower($summary)), 0, 24), self::short($summary)];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    /**
     * Trim a label for storage.
     *
     * @param string $s
     * @return string
     */
    protected static function short(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return \core_text::strlen($s) > 250 ? \core_text::substr($s, 0, 249) . '…' : $s;
    }

    /**
     * Ingest completed AI Knowledge Check attempts (if that plugin is installed).
     *
     * @param int $courseid
     * @return int attempts processed
     */
    public function ingest_kc(int $courseid = 0): int {
        global $DB;
        if (!helper::kc_installed()) {
            return 0;
        }
        $dbman = $DB->get_manager();
        $attemptcols = $DB->get_columns('aiknowledgecheck_attempts');
        $kccol = isset($attemptcols['aiknowledgecheckid']) ? 'aiknowledgecheckid' : (isset($attemptcols['kcid']) ? 'kcid' : null);
        if (!$kccol) {
            return 0;
        }
        $hasstatus = isset($attemptcols['status']);
        $timecol = isset($attemptcols['timemodified']) ? 'timemodified' : null;
        $qcols = $DB->get_columns('aiknowledgecheck_questions');
        $qkccol = isset($qcols['aiknowledgecheckid']) ? 'aiknowledgecheckid' : 'kcid';
        $kccols = $DB->get_columns('aiknowledgecheck');

        $since = (int) get_config('local_aiquizremedial', 'insights_kc_since');
        $where = [];
        $params = [];
        if ($hasstatus) {
            $where[] = 'a.status = 1';
        }
        if ($timecol && $since) {
            $where[] = "a.{$timecol} >= :since";
            $params['since'] = $since;
        } else if ($since) {
            $where[] = 'a.id > :sinceid';
            $params['sinceid'] = (int) get_config('local_aiquizremedial', 'insights_kc_sinceid');
        }
        if ($courseid) {
            $where[] = 'k.course = :courseid';
            $params['courseid'] = $courseid;
        }
        $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $timeselect = $timecol ? "a.{$timecol} AS tmod" : '0 AS tmod';
        $attempts = $DB->get_records_sql(
            "SELECT a.id, a.userid, a.answers, a.{$kccol} AS kcid, {$timeselect}, k.course
               FROM {aiknowledgecheck_attempts} a
               JOIN {aiknowledgecheck} k ON k.id = a.{$kccol}
               {$wheresql}
           ORDER BY a.id ASC",
            $params
        );
        $count = 0;
        $maxtime = $since;
        $maxid = (int) get_config('local_aiquizremedial', 'insights_kc_sinceid');
        $kcs = [];
        foreach ($attempts as $attempt) {
            $maxtime = max($maxtime, (int) $attempt->tmod);
            $maxid = max($maxid, (int) $attempt->id);
            if (!isset($kcs[$attempt->kcid])) {
                $kc = $DB->get_record('aiknowledgecheck', ['id' => $attempt->kcid]);
                $qs = $DB->get_records('aiknowledgecheck_questions', [$qkccol => $attempt->kcid], 'questionnumber ASC, id ASC');
                $qs = array_filter($qs, function ($q) {
                    return ($q->questiontype ?? 'scale') !== 'freetext';
                });
                $kcs[$attempt->kcid] = [$kc, array_values($qs)];
            }
            [$kc, $questions] = $kcs[$attempt->kcid];
            if (!$kc || (isset($kccols['surveymode']) && !empty($kc->surveymode))) {
                continue;
            }
            $this->ingest_kc_attempt($attempt, $questions, $kccol, $hasstatus);
            $count++;
        }
        if (!$courseid) {
            set_config('insights_kc_since', $timecol ? max($since, $maxtime - 1) : time(), 'local_aiquizremedial');
            set_config('insights_kc_sinceid', $maxid, 'local_aiquizremedial');
        }
        if ($count) {
            ($this->log)("  [AIQR-INSIGHTS] Ingested {$count} Knowledge Check attempt(s).");
        }
        return $count;
    }

    /**
     * Replace the response rows for one Knowledge Check attempt.
     *
     * @param \stdClass $attempt
     * @param array $questions scale questions in order
     * @param string $kccol attempt column holding the KC id
     * @param bool $hasstatus
     */
    protected function ingest_kc_attempt(\stdClass $attempt, array $questions, string $kccol, bool $hasstatus): void {
        global $DB;
        $DB->delete_records('local_aiqr_resp', ['sourcetype' => 'knowledgecheck', 'attemptid' => $attempt->id]);
        if (!$questions || !$this->is_learner((int) $attempt->course, (int) $attempt->userid)) {
            return;
        }
        $statussql = $hasstatus ? ' AND status = 1' : '';
        $earlier = $DB->get_fieldset_sql(
            "SELECT id FROM {aiknowledgecheck_attempts}
              WHERE {$kccol} = :kc AND userid = :userid {$statussql} ORDER BY id ASC",
            ['kc' => $attempt->kcid, 'userid' => $attempt->userid]
        );
        $attemptno = array_search($attempt->id, array_map('intval', $earlier));
        $attemptno = $attemptno === false ? 1 : $attemptno + 1;
        $isfirst = $attemptno === 1;
        $answers = json_decode((string) $attempt->answers, true) ?: [];
        $finished = (int) ($attempt->tmod ?: time());
        $rows = [];
        foreach ($questions as $i => $q) {
            $ans = $answers[(string) $q->id] ?? $answers[(int) $q->id] ?? null;
            if ($ans === null && !$isfirst) {
                continue; // "Retry wrong answers" attempts only contain some questions.
            }
            $omitted = $ans === null || !isset($ans['answer']) || (int) $ans['answer'] < 0;
            $index = $omitted ? null : (int) $ans['answer'];
            $iscorrect = !$omitted && !empty($ans['iscorrect']);
            $label = null;
            if ($index !== null) {
                $label = (string) ($q->{'answer' . ($index + 1)} ?? '');
            }
            $rows[] = (object) [
                'userid'       => (int) $attempt->userid,
                'courseid'     => (int) $attempt->course,
                'sourcetype'   => 'knowledgecheck',
                'activityid'   => (int) $attempt->kcid,
                'attemptid'    => (int) $attempt->id,
                'attemptno'    => $attemptno,
                'isfirst'      => $isfirst ? 1 : 0,
                'slot'         => $i + 1,
                'nslots'       => count($questions),
                'qbeid'        => (int) $q->id,
                'questionid'   => (int) $q->id,
                'version'      => 1,
                'fraction'     => $iscorrect ? 1 : 0,
                'maxmark'      => 1,
                'iscorrect'    => $iscorrect ? 1 : 0,
                'omitted'      => $omitted ? 1 : 0,
                'answerkey'    => $index === null ? null : 'o:' . $index,
                'answerlabel'  => $label === null ? null : self::short(html_to_text($label, 0, false)),
                'timefinished' => $finished,
            ];
        }
        if ($rows) {
            $DB->insert_records('local_aiqr_resp', $rows);
        }
    }

    // ------------------------------------------------------------------ Statistics.

    /**
     * Recompute statistics for every activity with responses in the window.
     *
     * @param int $courseid 0 = all
     */
    public function compute_stats(int $courseid = 0): void {
        global $DB;
        $from = time() - self::window_days() * DAYSECS;
        $params = ['from' => $from];
        $coursesql = '';
        if ($courseid) {
            $coursesql = ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $activities = $DB->get_records_sql(
            "SELECT DISTINCT " . $DB->sql_concat('courseid', "'-'", 'sourcetype', "'-'", 'activityid') . " AS k,
                    courseid, sourcetype, activityid
               FROM {local_aiqr_resp}
              WHERE isfirst = 1 AND timefinished >= :from {$coursesql}",
            $params
        );
        $seen = [];
        foreach ($activities as $a) {
            $ids = $this->compute_activity((int) $a->courseid, $a->sourcetype, (int) $a->activityid, $from);
            $seen = array_merge($seen, $ids);
        }
        // Remove statistics for questions no longer in the window.
        $select = 'timecomputed < :cutoff';
        $delparams = ['cutoff' => time() - 60];
        if ($courseid) {
            $select .= ' AND courseid = :courseid';
            $delparams['courseid'] = $courseid;
        }
        $stale = $DB->get_fieldset_select('local_aiqr_qstats', 'id', $select, $delparams);
        $stale = array_diff(array_map('intval', $stale), $seen);
        foreach (array_chunk($stale, 500) as $chunk) {
            [$in, $inparams] = $DB->get_in_or_equal($chunk);
            $DB->delete_records_select('local_aiqr_optstats', "qstatsid $in", $inparams);
            $DB->delete_records_select('local_aiqr_qstats', "id $in", $inparams);
        }
        ($this->log)('  [AIQR-INSIGHTS] Statistics computed for ' . count($activities) . ' activit(ies).');
    }

    /**
     * Pearson correlation.
     *
     * @param float[] $x
     * @param float[] $y
     * @return float|null
     */
    public static function correlation(array $x, array $y): ?float {
        $n = count($x);
        if ($n < 3) {
            return null;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mx;
            $dy = $y[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 0 || $syy <= 0) {
            return null;
        }
        return $sxy / sqrt($sxx * $syy);
    }

    /**
     * Compute and store statistics for one activity.
     *
     * @param int $courseid
     * @param string $source
     * @param int $activityid
     * @param int $from window start
     * @return int[] qstats ids written
     */
    public function compute_activity(int $courseid, string $source, int $activityid, int $from): array {
        global $DB;
        $now = time();
        $rows = $DB->get_records_select('local_aiqr_resp',
            'courseid = :c AND sourcetype = :s AND activityid = :a AND isfirst = 1 AND timefinished >= :from',
            ['c' => $courseid, 's' => $source, 'a' => $activityid, 'from' => $from], 'id ASC');

        // Per attempt totals (for discrimination and top/bottom thirds).
        $attempts = [];
        foreach ($rows as $r) {
            $attempts[$r->attemptid]['items'][$r->qbeid] = (float) $r->fraction;
            $attempts[$r->attemptid]['marks'][$r->qbeid] = (float) $r->fraction * (float) $r->maxmark;
            $attempts[$r->attemptid]['max'][$r->qbeid] = (float) $r->maxmark;
        }
        $totals = [];
        foreach ($attempts as $aid => $a) {
            // Marks, not fractions: questions can carry different weights (as Moodle's report).
            $totals[$aid] = array_sum($a['marks']);
        }
        // Thirds by total score.
        $sorted = $totals;
        asort($sorted);
        $third = (int) floor(count($sorted) / 3);
        $bottom = $third ? array_slice(array_keys($sorted), 0, $third, true) : [];
        $top = $third ? array_slice(array_keys($sorted), -$third, $third, true) : [];
        $bottom = array_flip($bottom);
        $top = array_flip($top);

        $byq = [];
        foreach ($rows as $r) {
            $byq[$r->qbeid][] = $r;
        }

        // Activity-level: median % and Cronbach's alpha (fixed item sets only).
        $pcts = [];
        foreach ($attempts as $aid => $a) {
            $max = array_sum($a['max']);
            $pcts[] = $max > 0 ? array_sum($a['marks']) / $max : 0;
        }
        sort($pcts);
        $median = $pcts ? $pcts[(int) floor((count($pcts) - 1) / 2)] : null;
        $alpha = $this->alpha($attempts);
        $act = $DB->get_record('local_aiqr_actstats', ['courseid' => $courseid, 'sourcetype' => $source,
            'activityid' => $activityid]);
        $actrec = (object) ['courseid' => $courseid, 'sourcetype' => $source, 'activityid' => $activityid,
            'n' => count($attempts), 'medianpct' => $median, 'alpha' => $alpha, 'timecomputed' => $now];
        if ($act) {
            $actrec->id = $act->id;
            $DB->update_record('local_aiqr_actstats', $actrec);
        } else {
            $DB->insert_record('local_aiqr_actstats', $actrec);
        }

        $written = [];
        foreach ($byq as $qbeid => $qrows) {
            $n = count($qrows);
            $ncorrect = 0;
            $nomitted = 0;
            $sum = 0.0;
            $latestversion = 0;
            $latestq = 0;
            $slot = 0;
            $nslots = 0;
            $item = [];
            $rest = [];
            $versions = [];
            foreach ($qrows as $r) {
                $ncorrect += (int) $r->iscorrect;
                $nomitted += (int) $r->omitted;
                $sum += (float) $r->fraction;
                if ((int) $r->version >= $latestversion) {
                    $latestversion = (int) $r->version;
                    $latestq = (int) $r->questionid;
                    $slot = (int) $r->slot;
                    $nslots = (int) $r->nslots;
                }
                $mark = (float) $r->fraction * (float) $r->maxmark;
                $item[] = $mark;
                $rest[] = $totals[$r->attemptid] - $mark;
                $v = (int) $r->version;
                if (!isset($versions[$v])) {
                    $versions[$v] = ['version' => $v, 'questionid' => (int) $r->questionid, 'n' => 0, 'sum' => 0.0,
                        'first' => (int) $r->timefinished];
                }
                $versions[$v]['n']++;
                $versions[$v]['sum'] += (float) $r->fraction;
                $versions[$v]['first'] = min($versions[$v]['first'], (int) $r->timefinished);
            }
            $facility = $n ? $sum / $n : null;
            $disc = $source === 'quiz' ? self::correlation($item, $rest) : null;

            // Versions: % correct per version + when the version was created and by whom.
            ksort($versions);
            $vout = [];
            foreach ($versions as $v) {
                $meta = $source === 'quiz' ? $this->question_version($v['questionid']) : [0, 1, 0, 0];
                $vout[] = ['version' => $v['version'], 'questionid' => $v['questionid'], 'n' => $v['n'],
                    'facility' => $v['n'] ? round($v['sum'] / $v['n'], 5) : null,
                    'created' => $meta[2] ?: $v['first'], 'editor' => $meta[3]];
            }

            // Weekly sparkline: 12 weeks, % correct first try.
            $weeks = array_fill(0, 12, ['n' => 0, 'sum' => 0.0]);
            $weekstart = $now - 12 * WEEKSECS;
            foreach ($qrows as $r) {
                if ((int) $r->timefinished < $weekstart) {
                    continue;
                }
                $w = min(11, (int) floor(((int) $r->timefinished - $weekstart) / WEEKSECS));
                $weeks[$w]['n']++;
                $weeks[$w]['sum'] += (float) $r->fraction;
            }
            $spark = array_map(function ($w) {
                return $w['n'] >= 2 ? round($w['sum'] / $w['n'], 4) : null;
            }, $weeks);

            // Remediation funnel + recovery for this question.
            $rem = $this->remediation_stats($courseid, $source, $activityid, (int) $qbeid, $from);

            $rec = (object) [
                'courseid' => $courseid, 'sourcetype' => $source, 'activityid' => $activityid, 'qbeid' => (int) $qbeid,
                'questionid' => $latestq, 'slot' => $slot, 'nslots' => $nslots, 'n' => $n, 'ncorrect' => $ncorrect,
                'nomitted' => $nomitted, 'facility' => $facility, 'discrimination' => $disc,
                'ndisc' => $disc === null ? 0 : $n,
                'nmodules' => $rem['nmodules'], 'ncompleted' => $rem['ncompleted'], 'nqcfirst' => $rem['nqcfirst'],
                'nreenc' => $rem['nreenc'], 'nrecovered' => $rem['nrecovered'], 'credits' => $rem['credits'],
                'spark' => json_encode($spark), 'versions' => json_encode($vout), 'timecomputed' => $now,
            ];
            $existing = $DB->get_record('local_aiqr_qstats', ['courseid' => $courseid, 'sourcetype' => $source,
                'activityid' => $activityid, 'qbeid' => $qbeid], 'id');
            if ($existing) {
                $rec->id = $existing->id;
                $DB->update_record('local_aiqr_qstats', $rec);
            } else {
                $rec->id = $DB->insert_record('local_aiqr_qstats', $rec);
            }
            $written[] = (int) $rec->id;

            // Answer breakdown for the latest version only (answer ids differ between versions).
            $DB->delete_records('local_aiqr_optstats', ['qstatsid' => $rec->id]);
            $latestrows = array_filter($qrows, function ($r) use ($latestq) {
                return (int) $r->questionid === $latestq;
            });
            $this->write_options((int) $rec->id, $source, $latestq, $latestrows, $top, $bottom);
        }
        return $written;
    }

    /**
     * Cronbach's alpha for attempts sharing the same item set (null if not enough data).
     *
     * @param array $attempts
     * @return float|null
     */
    protected function alpha(array $attempts): ?float {
        $sets = [];
        foreach ($attempts as $aid => $a) {
            $keys = array_keys($a['items']);
            sort($keys);
            $sets[implode(',', $keys)][] = $a['items'];
        }
        if (!$sets) {
            return null;
        }
        uasort($sets, function ($x, $y) {
            return count($y) <=> count($x);
        });
        $group = reset($sets);
        $k = count(reset($group));
        if (count($group) < 30 || $k < 2) {
            return null;
        }
        $var = function (array $v) {
            $n = count($v);
            $m = array_sum($v) / $n;
            $s = 0.0;
            foreach ($v as $x) {
                $s += ($x - $m) ** 2;
            }
            return $s / ($n - 1);
        };
        $itemvars = 0.0;
        foreach (array_keys(reset($group)) as $q) {
            $itemvars += $var(array_column($group, $q));
        }
        $tot = $var(array_map('array_sum', $group));
        if ($tot <= 0) {
            return null;
        }
        return ($k / ($k - 1)) * (1 - $itemvars / $tot);
    }

    /**
     * Write option statistics for the latest version.
     *
     * @param int $qstatsid
     * @param string $source
     * @param int $questionid
     * @param array $rows first-attempt rows for that version
     * @param array $top attempt ids in the top third
     * @param array $bottom attempt ids in the bottom third
     */
    protected function write_options(int $qstatsid, string $source, int $questionid, array $rows, array $top, array $bottom): void {
        global $DB;
        $n = count($rows);
        if (!$n) {
            return;
        }
        $options = [];
        $order = 0;
        if ($source === 'quiz') {
            $qtype = $DB->get_field('question', 'qtype', ['id' => $questionid]);
            if (in_array($qtype, ['multichoice', 'truefalse'], true)) {
                foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id ASC') as $ans) {
                    $options['a:' . $ans->id] = ['label' => self::short(html_to_text((string) $ans->answer, 0, false)),
                        'iscorrect' => (float) $ans->fraction >= 0.99999 ? 1 : 0, 'order' => $order++];
                }
            }
        } else if (helper::kc_installed()) {
            $q = $DB->get_record('aiknowledgecheck_questions', ['id' => $questionid]);
            if ($q) {
                for ($i = 0; $i < 5; $i++) {
                    $text = (string) ($q->{'answer' . ($i + 1)} ?? '');
                    if (trim($text) === '') {
                        continue;
                    }
                    $options['o:' . $i] = ['label' => self::short(html_to_text($text, 0, false)),
                        'iscorrect' => (int) ((int) $q->correctanswer === $i), 'order' => $order++];
                }
            }
        }
        $counts = [];
        $ntop = $nbottom = 0;
        foreach ($rows as $r) {
            $intop = isset($top[$r->attemptid]);
            $inbottom = isset($bottom[$r->attemptid]);
            $ntop += (int) $intop;
            $nbottom += (int) $inbottom;
            if ($r->answerkey === null) {
                continue;
            }
            $k = $r->answerkey;
            if (!isset($counts[$k])) {
                $counts[$k] = ['n' => 0, 'top' => 0, 'bottom' => 0];
            }
            $counts[$k]['n']++;
            $counts[$k]['top'] += (int) $intop;
            $counts[$k]['bottom'] += (int) $inbottom;
            if (!isset($options[$k])) {
                $options[$k] = ['label' => (string) $r->answerlabel, 'iscorrect' => (int) $r->iscorrect,
                    'order' => 1000 + $order++];
            } else if ((int) $r->iscorrect) {
                $options[$k]['iscorrect'] = 1;
            }
        }
        // Free-response types: keep the 8 most common answers.
        if ($options && count($options) > 8) {
            uksort($options, function ($a, $b) use ($counts, $options) {
                return (($counts[$b]['n'] ?? 0) <=> ($counts[$a]['n'] ?? 0)) ?: ($options[$a]['order'] <=> $options[$b]['order']);
            });
            $options = array_slice($options, 0, 8, true);
        }
        $records = [];
        foreach ($options as $key => $o) {
            $c = $counts[$key] ?? ['n' => 0, 'top' => 0, 'bottom' => 0];
            $records[] = (object) [
                'qstatsid' => $qstatsid, 'answerkey' => substr($key, 0, 64), 'label' => $o['label'],
                'iscorrect' => $o['iscorrect'], 'n' => $c['n'], 'rate' => $c['n'] / $n,
                'ratetop' => $ntop ? $c['top'] / $ntop : null, 'ratebottom' => $nbottom ? $c['bottom'] / $nbottom : null,
                'sortorder' => $o['order'],
            ];
        }
        if ($records) {
            $DB->insert_records('local_aiqr_optstats', $records);
        }
    }

    /**
     * Revision-module funnel and "recovered on the next attempt" for one question.
     *
     * @param int $courseid
     * @param string $source
     * @param int $activityid
     * @param int $qbeid
     * @param int $from
     * @return array
     */
    protected function remediation_stats(int $courseid, string $source, int $activityid, int $qbeid, int $from): array {
        global $DB;
        $out = ['nmodules' => 0, 'ncompleted' => 0, 'nqcfirst' => 0, 'nreenc' => 0, 'nrecovered' => 0, 'credits' => 0];
        if ($source === 'quiz') {
            $questionids = $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = :q',
                ['q' => $qbeid]);
            if (!$questionids) {
                $questionids = [$qbeid];
            }
            [$in, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'qid');
            $params += ['a' => $activityid, 'from' => $from, 'c' => $courseid];
            $activitysql = "j.sourcetype = 'quiz' AND j.quizid = :a";
        } else {
            $in = '= :qid';
            $params = ['qid' => $qbeid, 'a' => $activityid, 'from' => $from, 'c' => $courseid];
            $activitysql = "j.sourcetype = 'knowledgecheck' AND j.kcid = :a";
        }
        $jobs = $DB->get_records_sql(
            "SELECT j.id, j.userid, j.attemptid, m.id AS moduleid, m.credits_used, c.state, c.attempts_count
               FROM {local_aiqr_job} j
               JOIN {local_aiqr_module} m ON m.jobid = j.id
          LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = j.userid
              WHERE {$activitysql} AND j.courseid = :c AND j.questionid {$in} AND j.timecreated >= :from",
            $params
        );
        foreach ($jobs as $j) {
            $out['nmodules']++;
            $out['credits'] += (int) $j->credits_used;
            if ($j->state === 'complete') {
                $out['ncompleted']++;
                if ((int) $j->attempts_count === 1) {
                    $out['nqcfirst']++;
                }
            }
            // Next attempt at the same question by the same learner.
            $thisno = $DB->get_field('local_aiqr_resp', 'attemptno', ['sourcetype' => $source, 'attemptid' => $j->attemptid,
                'qbeid' => $qbeid], IGNORE_MULTIPLE);
            if ($thisno === false) {
                continue;
            }
            $next = $DB->get_records_select('local_aiqr_resp',
                'sourcetype = :s AND activityid = :a AND userid = :u AND qbeid = :q AND attemptno > :no',
                ['s' => $source, 'a' => $activityid, 'u' => $j->userid, 'q' => $qbeid, 'no' => $thisno],
                'attemptno ASC', 'id, iscorrect', 0, 1);
            if ($next) {
                $out['nreenc']++;
                $out['nrecovered'] += (int) reset($next)->iscorrect;
            }
        }
        return $out;
    }
}
