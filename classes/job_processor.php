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

namespace local_aiquizremedial;

/**
 * Two-stage job pipeline for remedial module generation (v1.3.0).
 *
 * Stage 1 — expand: each umbrella job (one per quiz / KC attempt, questionid NULL) is
 *   turned into one 'queued' question job per wrong or unanswered question. This is fast
 *   (no AI calls) so learners can be told straight away how many modules are coming.
 * Stage 2 — generate: queued question jobs are sent to the AI service one by one, within a
 *   time budget, so a large backlog is spread across cron runs instead of one run holding
 *   the task lock for an hour.
 *
 * Reliability rules that fix "students got questions wrong but never saw a module":
 *  - Attempts that are not graded yet (Moodle 5.0 'submitted', or still in progress) are
 *    left pending and retried, instead of being closed as 'ready' with zero modules.
 *  - Unanswered questions (gave up, fraction NULL) are treated as wrong (setting).
 *  - Failed question jobs are retried automatically up to helper::MAX_RETRIES times.
 *  - Jobs left in 'processing' by a crashed cron run are recovered.
 *  - De-duplication checks any existing question job, not only 'ready' ones, so a retry
 *    never creates a second job for the same question.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class job_processor {
    /** Seconds after which a job still in 'processing' is considered dead. */
    const STALE_AFTER = 1800;

    /** Seconds of AI generation per cron run before yielding to the next run. */
    const TIME_BUDGET = 480;

    /** Umbrella jobs whose attempt is still ungraded after this long are closed. */
    const UMBRELLA_MAX_AGE = 14 * DAYSECS;

    /** @var array cache of loaded question usages keyed by quiz attempt id. */
    protected $qubacache = [];

    /** @var callable output function */
    protected $log;

    /**
     * Constructor.
     *
     * @param callable|null $log receives one line of text; defaults to mtrace().
     */
    public function __construct(?callable $log = null) {
        $this->log = $log ?? function (string $line) {
            mtrace($line);
        };
    }

    /**
     * Write a log line.
     *
     * @param string $line
     */
    protected function log(string $line): void {
        ($this->log)($line);
    }

    /**
     * Run the full pipeline once (called by the scheduled task).
     */
    public function run(): void {
        $this->recover_stale();
        $this->requeue_failed();
        $this->expand_pending(50);
        $this->generate_queued(self::TIME_BUDGET);
    }

    /**
     * Recover jobs left in 'processing' by a cron run that died mid-way.
     */
    public function recover_stale(): void {
        global $DB;
        $cutoff = time() - self::STALE_AFTER;

        $n = $DB->count_records_select(
            'local_aiqr_job',
            "status = 'processing' AND questionid IS NULL AND timemodified < :cutoff", ['cutoff' => $cutoff]);
        if ($n) {
            $DB->execute("UPDATE {local_aiqr_job} SET status = 'pending', timemodified = :now
                           WHERE status = 'processing' AND questionid IS NULL AND timemodified < :cutoff",
                ['now' => time(), 'cutoff' => $cutoff]);
            $this->log("  [AIQR] Recovered {$n} stalled attempt job(s).");
        }

        $stuck = $DB->get_records_select(
            'local_aiqr_job',
            "status = 'processing' AND questionid IS NOT NULL AND timemodified < :cutoff", ['cutoff' => $cutoff],
            '', 'id, retries');
        foreach ($stuck as $job) {
            $retries = (int) $job->retries + 1;
            $DB->update_record('local_aiqr_job', (object) [
                'id'           => $job->id,
                'status'       => $retries >= helper::MAX_RETRIES ? 'failed' : 'queued',
                'retries'      => $retries,
                'errormsg'     => 'Generation did not finish (cron run stopped).',
                'timemodified' => time(),
            ]);
        }
        if ($stuck) {
            $this->log('  [AIQR] Recovered ' . count($stuck) . ' stalled question job(s).');
        }
    }

    /**
     * Put failed question jobs back in the queue while they still have retries left.
     * Back-off: 10 minutes × number of failures so far.
     */
    public function requeue_failed(): void {
        global $DB;
        $jobs = $DB->get_records_select(
            'local_aiqr_job',
            "status = 'failed' AND questionid IS NOT NULL AND retries < :max",
            ['max' => helper::MAX_RETRIES], 'timemodified ASC', 'id, retries, timemodified', 0, 200);
        $n = 0;
        foreach ($jobs as $job) {
            $wait = 600 * max(1, (int) $job->retries);
            if ((int) $job->timemodified > time() - $wait) {
                continue;
            }
            $DB->set_field('local_aiqr_job', 'status', 'queued', ['id' => $job->id]);
            $n++;
        }
        if ($n) {
            $this->log("  [AIQR] Re-queued {$n} failed question job(s) for automatic retry.");
        }
    }

    /**
     * Stage 1: expand pending umbrella jobs into queued question jobs.
     *
     * @param int $limit
     * @return int number of question jobs queued
     */
    public function expand_pending(int $limit = 50): int {
        global $DB;
        $umbrellas = $DB->get_records_select(
            'local_aiqr_job',
            "status = 'pending' AND questionid IS NULL", [], 'timecreated ASC', '*', 0, $limit);

        $queued = 0;
        foreach ($umbrellas as $job) {
            try {
                $DB->set_field('local_aiqr_job', 'status', 'processing', ['id' => $job->id]);
                $DB->set_field('local_aiqr_job', 'timemodified', time(), ['id' => $job->id]);

                $source = ($job->sourcetype ?? 'quiz') === 'knowledgecheck' ? 'knowledgecheck' : 'quiz';
                if (!helper::source_enabled($source)) {
                    // Remediation switched off for this source: close without generating.
                    $this->close($job, 'ready', 'Skipped: remediation disabled for ' . $source . '.');
                    continue;
                }
                if ($source === 'knowledgecheck') {
                    $result = $this->expand_kc($job);
                } else {
                    $result = $this->expand_quiz($job);
                }

                if ($result === null) {
                    // Attempt not graded yet — try again on the next run (or close if too old).
                    if ((int) $job->timecreated < time() - self::UMBRELLA_MAX_AGE) {
                        $this->close($job, 'failed', 'Attempt was never graded.');
                    } else {
                        $DB->update_record('local_aiqr_job', (object) [
                            'id' => $job->id, 'status' => 'pending', 'timemodified' => time(),
                        ]);
                    }
                    continue;
                }
                $queued += $result;
                $this->close($job, 'ready', null);
                $this->log("  [AIQR] Attempt job {$job->id}: {$result} question(s) queued for generation.");
            } catch (\Throwable $e) {
                $this->close($job, 'failed', $e->getMessage());
                $this->log('  [AIQR] Attempt job ' . $job->id . ' failed: ' . $e->getMessage());
            }
        }
        return $queued;
    }

    /**
     * Mark an umbrella job finished.
     *
     * @param \stdClass $job
     * @param string $status
     * @param string|null $error
     */
    protected function close(\stdClass $job, string $status, ?string $error): void {
        global $DB;
        $DB->update_record('local_aiqr_job', (object) [
            'id'           => $job->id,
            'status'       => $status,
            'errormsg'     => $error === null ? null : substr($error, 0, 1000),
            'timemodified' => time(),
        ]);
    }

    /**
     * Should unanswered questions produce a revision module?
     *
     * @return bool
     */
    protected static function include_unanswered(): bool {
        $v = get_config('local_aiquizremedial', 'includeunanswered');
        return $v === false ? true : (bool) $v; // Default on when never saved.
    }

    /**
     * Load (and cache) the question usage for a quiz attempt.
     *
     * @param \stdClass $attempt
     * @return \question_usage_by_activity
     */
    protected function load_quba(\stdClass $attempt): \question_usage_by_activity {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');
        if (!isset($this->qubacache[$attempt->id])) {
            if (count($this->qubacache) > 20) {
                $this->qubacache = [];
            }
            $this->qubacache[$attempt->id] = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        }
        return $this->qubacache[$attempt->id];
    }

    /**
     * Is this question attempt wrong (or unanswered) and therefore worth a module?
     *
     * @param \question_attempt $qa
     * @return bool
     */
    public static function is_wrong(\question_attempt $qa): bool {
        $state = $qa->get_state();
        if (!$state->is_finished()) {
            return false;
        }
        if ((float) $qa->get_max_mark() <= 0) {
            return false; // Description / zero-mark items.
        }
        $fraction = $qa->get_fraction();
        if ($fraction === null) {
            // Blank answer → gave up. Needs-grading (essay) states are also NULL but are not
            // "gave up", so they are correctly skipped until a teacher grades them.
            return $state->is_gave_up() && self::include_unanswered();
        }
        return (float) $fraction < 0.99999;
    }

    /**
     * Expand a quiz umbrella job.
     *
     * @param \stdClass $job
     * @return int|null number of question jobs queued, or null if the attempt is not graded yet
     */
    protected function expand_quiz(\stdClass $job): ?int {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $job->attemptid]);
        if (!$attempt) {
            throw new \moodle_exception('error', 'moodle', '', null, 'Quiz attempt ' . $job->attemptid . ' no longer exists.');
        }
        if ($attempt->state === 'abandoned') {
            return 0;
        }
        if ($attempt->state !== 'finished') {
            return null; // In progress, overdue or (Moodle 5.0+) submitted-but-not-graded.
        }

        $quba = $this->load_quba($attempt);
        $queued = 0;
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            if (!self::is_wrong($qa)) {
                continue;
            }
            $questionid = (int) $qa->get_question()->id;
            if ($this->queue_question($job, $questionid, (int) $attempt->quiz, null, 'quiz')) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Expand an AI Knowledge Check umbrella job.
     *
     * @param \stdClass $job
     * @return int|null
     */
    protected function expand_kc(\stdClass $job): ?int {
        global $DB;
        if (!helper::kc_installed()) {
            throw new \moodle_exception('error', 'moodle', '', null, 'AI Knowledge Check is not installed.');
        }
        $kcid = (int) ($job->kcid ?? 0);
        if ($kcid <= 0) {
            throw new \moodle_exception('invalidkcid', 'local_aiquizremedial');
        }
        $attempt = $DB->get_record('aiknowledgecheck_attempts', ['id' => $job->attemptid], '*', MUST_EXIST);
        $kc = $DB->get_record('aiknowledgecheck', ['id' => $kcid], '*', MUST_EXIST);

        // Survey-mode activities have no right or wrong answers: their responses are saved
        // without an 'iscorrect' flag, which the old code read as "wrong" — so every survey
        // response would have generated (and charged for) a revision module.
        if (!empty($kc->surveymode)) {
            return 0;
        }

        $answers = json_decode((string) $attempt->answers, true) ?: [];
        $questions = $DB->get_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $kcid]);

        $queued = 0;
        foreach ($answers as $qid => $ans) {
            $question = $questions[(int) $qid] ?? null;
            if (!$question || !is_array($ans)) {
                continue;
            }
            // Free-text (comment) questions are not scored — answer is stored as -1.
            if (($question->questiontype ?? 'scale') === 'freetext' || (int) ($ans['answer'] ?? -1) < 0) {
                continue;
            }
            if (!empty($ans['iscorrect'])) {
                continue;
            }
            // Skipped questions are deliberately NOT inferred from the question list:
            // "Retry wrong answers" attempts only contain the previously wrong questions,
            // so a missing answer does not mean the learner skipped it.
            if ($this->queue_question($job, (int) $qid, null, $kcid, 'knowledgecheck')) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Insert a queued question job unless one already exists for that attempt + question.
     *
     * @param \stdClass $umbrella
     * @param int $questionid
     * @param int|null $quizid
     * @param int|null $kcid
     * @param string $sourcetype
     * @return bool true if a new job was queued
     */
    protected function queue_question(\stdClass $umbrella, int $questionid, ?int $quizid, ?int $kcid, string $sourcetype): bool {
        global $DB;
        if ($DB->record_exists('local_aiqr_job', [
            'attemptid'  => $umbrella->attemptid,
            'questionid' => $questionid,
            'sourcetype' => $sourcetype,
        ])) {
            return false;
        }
        // Don't generate (and charge for) a second module while the learner still has an
        // unfinished one for the same question — e.g. a quiz re-attempt or a Knowledge
        // Check "Retry wrong answers" attempt. Once they complete it, a new mistake on that
        // question gets a fresh module.
        $outstanding = $DB->record_exists_sql(
            "SELECT 1
               FROM {local_aiqr_job} j
          LEFT JOIN {local_aiqr_module} m ON m.jobid = j.id
          LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = j.userid
              WHERE j.userid = :userid AND j.questionid = :questionid AND j.sourcetype = :sourcetype
                AND (j.status IN ('queued', 'processing')
                     OR (j.status = 'failed' AND j.retries < :maxretries)
                     OR (j.status = 'ready' AND (c.state IS NULL OR c.state <> 'complete')))",
            ['userid' => $umbrella->userid, 'questionid' => $questionid, 'sourcetype' => $sourcetype,
                'maxretries' => helper::MAX_RETRIES]);
        if ($outstanding) {
            return false;
        }
        $DB->insert_record('local_aiqr_job', (object) [
            'userid'       => $umbrella->userid,
            'courseid'     => $umbrella->courseid,
            'quizid'       => $quizid,
            'kcid'         => $kcid,
            'attemptid'    => $umbrella->attemptid,
            'questionid'   => $questionid,
            'sourcetype'   => $sourcetype,
            'status'       => 'queued',
            'errormsg'     => null,
            'retries'      => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        return true;
    }

    /**
     * Stage 2: generate AI content for queued question jobs.
     *
     * @param int $budget seconds
     * @param int $limit max jobs this run
     * @return int modules created
     */
    public function generate_queued(int $budget = self::TIME_BUDGET, int $limit = 200): int {
        global $DB;
        $start = time();
        $jobs = $DB->get_records_select(
            'local_aiqr_job',
            "status = 'queued' AND questionid IS NOT NULL", [], 'timecreated ASC, id ASC', '*', 0, $limit);

        $created = 0;
        foreach ($jobs as $job) {
            if (time() - $start > $budget) {
                $this->log('  [AIQR] Time budget reached; remaining jobs continue next run.');
                break;
            }
            if ($this->generate_one($job)) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * Generate one question job's module.
     *
     * @param \stdClass $job
     * @return bool success
     */
    public function generate_one(\stdClass $job): bool {
        global $DB;

        $DB->update_record('local_aiqr_job', (object) [
            'id' => $job->id, 'status' => 'processing', 'timemodified' => time(),
        ]);

        try {
            // A module may already exist (e.g. a crash after insert) — just mark ready.
            if ($DB->record_exists('local_aiqr_module', ['jobid' => $job->id])) {
                $DB->update_record('local_aiqr_job', (object) [
                    'id' => $job->id, 'status' => 'ready', 'errormsg' => null, 'timemodified' => time(),
                ]);
                return true;
            }

            $payload = ($job->sourcetype ?? 'quiz') === 'knowledgecheck'
                ? $this->build_kc_payload($job)
                : $this->build_quiz_payload($job);

            $ai = new ai\credits_client();
            $result = $ai->generate_remediation((int) $job->userid, (int) $job->courseid, $payload);

            $lessondata = $result['lesson'] ?? null;
            $extralanguages = credit_calculator::get_enabled_languages();
            $translationsjson = null;
            if (!empty($extralanguages)) {
                // Version 1.4.0: with a lesson, translate the card text (block-structured so it can be
                // split back into cards); otherwise translate the plain explanation as before.
                $plain = $lessondata ? lesson::to_translation_text($lessondata)
                    : strip_tags(html_entity_decode((string) $result['explain_text'], ENT_QUOTES, 'UTF-8'));
                $translations = $ai->generate_translations($plain, $extralanguages);
                if ($lessondata) {
                    foreach ($translations as $code => $t) {
                        $translations[$code]['lesson'] = lesson::from_translation_text(
                            (string) $t['explain_text'],
                            $lessondata);
                    }
                }
                if (!empty($translations)) {
                    $translationsjson = json_encode($translations, JSON_UNESCAPED_UNICODE);
                }
            }

            $now = time();
            $moduleid = $DB->insert_record('local_aiqr_module', (object) [
                'jobid'               => $job->id,
                'explain_text'        => $result['explain_text'],
                'explain_audio_url'   => $result['explain_audio_url'] ?? null,
                'explain_image_url'   => $result['explain_image_url'] ?? null,
                'check_question_json' => json_encode($result['check_question'], JSON_UNESCAPED_UNICODE),
                'feedback_json'       => json_encode($result['feedback'], JSON_UNESCAPED_UNICODE),
                'translations_json'   => $translationsjson,
                'lesson_json'         => $lessondata ? json_encode($lessondata, JSON_UNESCAPED_UNICODE) : null,
                'question_json'       => !empty($result['question_snapshot'])
                    ? json_encode($result['question_snapshot'], JSON_UNESCAPED_UNICODE) : null,
                'credits_used'        => (int) ($result['credits_used'] ?? credit_calculator::get_cost_per_question()),
                'timecreated'         => $now,
                'timemodified'        => $now,
            ]);

            // Version 1.4.1: record the image state (ready / retry / failed / rejected / disabled).
            $imagefields = !empty($result['image'])
                ? media::fields_from_result($result['image'], 1)
                : ['image_status' => 'disabled', 'image_attempts' => 0];
            $DB->update_record('local_aiqr_module', (object) (['id' => $moduleid] + $imagefields));
            if (!empty($result['image']) && empty($result['image']['ok'])) {
                $this->log('  [AIQR-IMAGE] Module ' . $moduleid . ': image ' . $imagefields['image_status'] . ' — '
                    . $imagefields['image_error'] . ' (text kept).');
            }

            if (!$DB->record_exists('local_aiqr_completion', ['moduleid' => $moduleid, 'userid' => $job->userid])) {
                $DB->insert_record('local_aiqr_completion', (object) [
                    'moduleid'       => $moduleid,
                    'userid'         => $job->userid,
                    'state'          => 'notstarted',
                    'attempts_count' => 0,
                    'completed_at'   => null,
                    'timecreated'    => $now,
                    'timemodified'   => $now,
                ]);
            }

            $DB->update_record('local_aiqr_job', (object) [
                'id' => $job->id, 'status' => 'ready', 'errormsg' => null, 'timemodified' => time(),
            ]);
            $this->log('  [AIQR] Module ' . $moduleid . ' created for job ' . $job->id . ' (question ' . $job->questionid
                . ', ' . count($extralanguages) . ' extra language(s)).');
            return true;

        } catch (\Throwable $e) {
            $retries = (int) ($job->retries ?? 0) + 1;
            $DB->update_record('local_aiqr_job', (object) [
                'id'           => $job->id,
                'status'       => 'failed',
                'retries'      => $retries,
                'errormsg'     => substr($e->getMessage() . (isset($e->debuginfo) ? ' — ' . $e->debuginfo : ''), 0, 1000),
                'timemodified' => time(),
            ]);
            $this->log('  [AIQR] Question job ' . $job->id . ' failed (try ' . $retries . '/' . helper::MAX_RETRIES . '): '
                . $e->getMessage());
            return false;
        }
    }

    /**
     * Build the AI payload for a quiz question job.
     *
     * @param \stdClass $job
     * @return array
     */
    protected function build_quiz_payload(\stdClass $job): array {
        global $DB;
        $attempt = $DB->get_record('quiz_attempts', ['id' => $job->attemptid], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
        $quba = $this->load_quba($attempt);
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            if ((int) $qa->get_question()->id === (int) $job->questionid) {
                return question_payload::build($quiz, $attempt, $qa);
            }
        }
        throw new \moodle_exception(
            'error', 'moodle', '', null,
            'Question ' . $job->questionid . ' not found in attempt ' . $job->attemptid);
    }

    /**
     * Build the AI payload for a Knowledge Check question job.
     *
     * @param \stdClass $job
     * @return array
     */
    protected function build_kc_payload(\stdClass $job): array {
        global $DB;
        if (!helper::kc_installed()) {
            throw new \moodle_exception('error', 'moodle', '', null, 'AI Knowledge Check is not installed.');
        }
        $kc = $DB->get_record('aiknowledgecheck', ['id' => $job->kcid], '*', MUST_EXIST);
        $attempt = $DB->get_record('aiknowledgecheck_attempts', ['id' => $job->attemptid], '*', MUST_EXIST);
        $question = $DB->get_record('aiknowledgecheck_questions', ['id' => $job->questionid], '*', MUST_EXIST);
        $answers = json_decode($attempt->answers, true) ?: [];
        $ans = $answers[(string) $job->questionid] ?? $answers[(int) $job->questionid] ?? [];
        return kc_question_payload::build($kc, $attempt, $question, $ans);
    }

    /**
     * Re-scan finished quiz attempts that never produced modules (used by the CLI script).
     *
     * @param int $since timestamp; only attempts finished after this
     * @param int $courseid 0 = all courses
     * @param bool $dryrun
     * @return array [attempts checked, umbrella jobs re-opened, wrong questions found]
     */
    public function rescan(int $since, int $courseid = 0, bool $dryrun = true): array {
        global $DB;
        $params = ['since' => $since];
        $coursesql = '';
        if ($courseid) {
            $coursesql = ' AND q.course = :courseid';
            $params['courseid'] = $courseid;
        }
        $attempts = $DB->get_records_sql(
            "SELECT qa.*, q.course AS courseid
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE qa.state = 'finished' AND qa.preview = 0 AND qa.timefinish >= :since {$coursesql}
           ORDER BY qa.timefinish ASC", $params);

        $checked = 0;
        $reopened = 0;
        $wrongtotal = 0;
        foreach ($attempts as $attempt) {
            $checked++;
            $quba = $this->load_quba($attempt);
            $wrong = [];
            foreach ($quba->get_slots() as $slot) {
                $qa = $quba->get_question_attempt($slot);
                if (self::is_wrong($qa)) {
                    $wrong[] = (int) $qa->get_question()->id;
                }
            }
            $missing = 0;
            foreach ($wrong as $qid) {
                if (!$DB->record_exists(
                    'local_aiqr_job',
                        ['attemptid' => $attempt->id, 'questionid' => $qid, 'sourcetype' => 'quiz'])) {
                    $missing++;
                }
            }
            if (!$missing) {
                continue;
            }
            $wrongtotal += $missing;
            $reopened++;
            $this->log("  attempt {$attempt->id} (user {$attempt->userid}, quiz {$attempt->quiz}): {$missing} question(s) without a module");
            if ($dryrun) {
                continue;
            }
            $umbrella = $DB->get_record(
                'local_aiqr_job',
                ['attemptid' => $attempt->id, 'questionid' => null, 'sourcetype' => 'quiz']);
            if ($umbrella) {
                $DB->update_record('local_aiqr_job', (object) [
                    'id' => $umbrella->id, 'status' => 'pending', 'timemodified' => time(),
                ]);
            } else {
                $DB->insert_record('local_aiqr_job', (object) [
                    'userid' => $attempt->userid, 'courseid' => $attempt->courseid, 'quizid' => $attempt->quiz,
                    'kcid' => null, 'attemptid' => $attempt->id, 'questionid' => null, 'sourcetype' => 'quiz',
                    'status' => 'pending', 'errormsg' => null, 'retries' => 0,
                    'timecreated' => time(), 'timemodified' => time(),
                ]);
            }
        }
        return [$checked, $reopened, $wrongtotal];
    }
}
