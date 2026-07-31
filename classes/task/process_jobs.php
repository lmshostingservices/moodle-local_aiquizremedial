<?php
namespace local_aiquizremedial\task;

use core\task\scheduled_task;
use local_aiquizremedial\credit_calculator;

defined('MOODLE_INTERNAL') || die();

class process_jobs extends scheduled_task {

    public function get_name(): string {
        return get_string('task_process_jobs', 'local_aiquizremedial');
    }

    public function execute(): void {
        global $DB;

        $batchsize = credit_calculator::get_batch_size();
        $jobs = $DB->get_records('local_aiqr_job', ['status' => 'pending'], 'timecreated ASC', '*', 0, $batchsize);

        foreach ($jobs as $job) {
            try {
                $DB->update_record('local_aiqr_job', (object) [
                    'id'           => $job->id,
                    'status'       => 'processing',
                    'timemodified' => time(),
                ]);

                $this->expand_and_generate((object) $job);

                $DB->update_record('local_aiqr_job', (object) [
                    'id'           => $job->id,
                    'status'       => 'ready',
                    'timemodified' => time(),
                ]);

            } catch (\Throwable $e) {
                $DB->update_record('local_aiqr_job', (object) [
                    'id'           => $job->id,
                    'status'       => 'failed',
                    'errormsg'     => substr($e->getMessage(), 0, 1000),
                    'timemodified' => time(),
                ]);
                mtrace('  [AIQR] Job ' . $job->id . ' failed: ' . $e->getMessage());
            }
        }

        // FIX-IMG-BACKFILL (v1.2.51): Repair modules that were created during the v1.2.49
        // window and therefore have explain_image_url = null even though images are enabled.
        // Process up to 3 per cron run to avoid overly long task execution.
        $this->backfill_missing_images();
    }

    /**
     * FIX-IMG-BACKFILL (v1.2.51): Find up to 3 existing ready modules whose explain_image_url
     * is null (image generation silently failed during v1.2.49) and generate the missing image
     * now. No credits are charged — the image cost was already included in the original module
     * charge. Runs only when the 'Enable explanatory images' setting is on.
     */
    private function backfill_missing_images(): void {
        global $DB;

        if (!credit_calculator::is_images_enabled()) {
            return;
        }

        $sql = "SELECT m.id, m.explain_text
                  FROM {local_aiqr_module} m
                  JOIN {local_aiqr_job} j ON j.id = m.jobid
                 WHERE m.explain_image_url IS NULL
                   AND m.explain_text IS NOT NULL
                   AND m.explain_text <> ''
                   AND j.status = 'ready'
                 ORDER BY m.timecreated DESC";

        $modules = $DB->get_records_sql($sql, [], 0, 3);

        foreach ($modules as $mod) {
            try {
                $ai  = new \local_aiquizremedial\ai\credits_client();
                $img = $ai->backfill_image((string) $mod->explain_text);
                if (!empty($img['image_url'])) {
                    $DB->update_record('local_aiqr_module', (object) [
                        'id'                => (int) $mod->id,
                        'explain_image_url' => $img['image_url'],
                        'timemodified'      => time(),
                    ]);
                    mtrace('  [AIQR-BACKFILL] Module ' . $mod->id . ': image backfilled successfully.');
                } else {
                    mtrace('  [AIQR-BACKFILL] Module ' . $mod->id . ': image generation returned no URL — will retry next run.');
                }
            } catch (\Throwable $e) {
                mtrace('  [AIQR-BACKFILL] Module ' . $mod->id . ' failed: ' . $e->getMessage());
            }
        }
    }

    private function expand_and_generate(object $umbrellaJob): void {
        $sourcetype = $umbrellaJob->sourcetype ?? 'quiz';
        if ($sourcetype === 'knowledgecheck') {
            $this->expand_and_generate_kc($umbrellaJob);
            return;
        }

        $this->expand_and_generate_quiz($umbrellaJob);
    }

    private function expand_and_generate_quiz(object $umbrellaJob): void {
        global $DB, $CFG;

        // question_engine is not autoloaded in scheduled task/CLI contexts.
        // Include both entry points: questionlib.php (Moodle API wrapper) and
        // question/engine/lib.php (direct class definition). Either alone can
        // fail on some Moodle installations depending on bootstrap order.
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $attempt = $DB->get_record('quiz_attempts', ['id' => $umbrellaJob->attemptid], '*', MUST_EXIST);
        $quiz    = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();

        $costperquestion = credit_calculator::get_cost_per_question();

        foreach ($slots as $slot) {
            $qa = $quba->get_question_attempt($slot);

            $fraction = $qa->get_fraction();
            if ($fraction === null) {
                continue;
            }
            if ((float) $fraction >= 1.0) {
                continue;
            }

            $question   = $qa->get_question();
            $questionid = (int) $question->id;

            $existing = $DB->get_record('local_aiqr_job', [
                'attemptid'  => $umbrellaJob->attemptid,
                'questionid' => $questionid,
                'status'     => 'ready',
            ]);
            if ($existing) {
                continue;
            }

            $qjob = (object) [
                'userid'       => $umbrellaJob->userid,
                'courseid'     => $umbrellaJob->courseid,
                'quizid'       => $quiz->id,
                'kcid'         => null,
                'attemptid'    => $umbrellaJob->attemptid,
                'questionid'   => $questionid,
                'sourcetype'   => 'quiz',
                'status'       => 'processing',
                'timecreated'  => time(),
                'timemodified' => time(),
            ];
            $qjobid = $DB->insert_record('local_aiqr_job', $qjob);

            try {
                $payload = \local_aiquizremedial\question_payload::build($quiz, $attempt, $qa);

                $ai     = new \local_aiquizremedial\ai\credits_client();
                $result = $ai->generate_remediation(
                    $umbrellaJob->userid,
                    $umbrellaJob->courseid,
                    $payload
                );

                $extraLanguages  = credit_calculator::get_enabled_languages();
                $translationsJson = null;
                if (!empty($extraLanguages)) {
                    $plainText   = strip_tags(html_entity_decode((string) $result['explain_text'], ENT_QUOTES, 'UTF-8'));
                    $translations = $ai->generate_translations($plainText, $extraLanguages);
                    if (!empty($translations)) {
                        $translationsJson = json_encode($translations, JSON_UNESCAPED_UNICODE);
                    }
                }

                $module = (object) [
                    'jobid'               => $qjobid,
                    'explain_text'        => $result['explain_text'],
                    'explain_audio_url'   => $result['explain_audio_url'] ?? null,
                    'explain_image_url'   => $result['explain_image_url'] ?? null,
                    'check_question_json' => json_encode($result['check_question'], JSON_UNESCAPED_UNICODE),
                    'feedback_json'       => json_encode($result['feedback'], JSON_UNESCAPED_UNICODE),
                    'translations_json'   => $translationsJson,
                    'credits_used'        => (int) ($result['credits_used'] ?? $costperquestion),
                    'timecreated'         => time(),
                    'timemodified'        => time(),
                ];
                $moduleid = $DB->insert_record('local_aiqr_module', $module);

                $DB->insert_record('local_aiqr_completion', (object) [
                    'moduleid'       => $moduleid,
                    'userid'         => $umbrellaJob->userid,
                    'state'          => 'notstarted',
                    'attempts_count' => 0,
                    'completed_at'   => null,
                    'timecreated'    => time(),
                    'timemodified'   => time(),
                ]);

                $DB->update_record('local_aiqr_job', (object) [
                    'id'           => $qjobid,
                    'status'       => 'ready',
                    'timemodified' => time(),
                ]);

                $langCount = count($extraLanguages);
                mtrace('  [AIQR] Module ' . $moduleid . ' created for question ' . $questionid . ' (' . $costperquestion . ' credits, ' . $langCount . ' extra language(s))');

            } catch (\Throwable $e) {
                $DB->update_record('local_aiqr_job', (object) [
                    'id'           => $qjobid,
                    'status'       => 'failed',
                    'errormsg'     => substr($e->getMessage(), 0, 1000),
                    'timemodified' => time(),
                ]);
                mtrace('  [AIQR] Question job ' . $qjobid . ' failed: ' . $e->getMessage());
            }
        }
    }

    private function expand_and_generate_kc(object $umbrellaJob): void {
        global $DB;

        $kcid = (int) ($umbrellaJob->kcid ?? 0);
        if ($kcid <= 0) {
            throw new \moodle_exception('invalidkcid', 'local_aiquizremedial');
        }

        $kc      = $DB->get_record('aiknowledgecheck', ['id' => $kcid], '*', MUST_EXIST);
        $attempt = $DB->get_record('aiknowledgecheck_attempts', ['id' => $umbrellaJob->attemptid], '*', MUST_EXIST);

        $answers = json_decode($attempt->answers, true) ?: [];
        if (empty($answers)) {
            mtrace('  [AIQR-KC] No answers in attempt ' . $umbrellaJob->attemptid . ', skipping.');
            return;
        }

        $costperquestion = credit_calculator::get_cost_per_question();

        foreach ($answers as $qid => $ans) {
            if (!empty($ans['iscorrect'])) {
                continue;
            }

            $questionid = (int) $qid;

            $existing = $DB->get_record('local_aiqr_job', [
                'attemptid'  => $umbrellaJob->attemptid,
                'questionid' => $questionid,
                'sourcetype' => 'knowledgecheck',
                'status'     => 'ready',
            ]);
            if ($existing) {
                continue;
            }

            $question = $DB->get_record('aiknowledgecheck_questions', ['id' => $questionid]);
            if (!$question) {
                mtrace('  [AIQR-KC] KC question ' . $questionid . ' not found, skipping.');
                continue;
            }

            $qjob = (object) [
                'userid'       => $umbrellaJob->userid,
                'courseid'     => $umbrellaJob->courseid,
                'quizid'       => null,
                'kcid'         => $kcid,
                'attemptid'    => $umbrellaJob->attemptid,
                'questionid'   => $questionid,
                'sourcetype'   => 'knowledgecheck',
                'status'       => 'processing',
                'timecreated'  => time(),
                'timemodified' => time(),
            ];
            $qjobid = $DB->insert_record('local_aiqr_job', $qjob);

            try {
                $payload = \local_aiquizremedial\kc_question_payload::build($kc, $attempt, $question, $ans);

                $ai     = new \local_aiquizremedial\ai\credits_client();
                $result = $ai->generate_remediation(
                    $umbrellaJob->userid,
                    $umbrellaJob->courseid,
                    $payload
                );

                $extraLanguages  = credit_calculator::get_enabled_languages();
                $translationsJson = null;
                if (!empty($extraLanguages)) {
                    $plainText   = strip_tags(html_entity_decode((string) $result['explain_text'], ENT_QUOTES, 'UTF-8'));
                    $translations = $ai->generate_translations($plainText, $extraLanguages);
                    if (!empty($translations)) {
                        $translationsJson = json_encode($translations, JSON_UNESCAPED_UNICODE);
                    }
                }

                $module = (object) [
                    'jobid'               => $qjobid,
                    'explain_text'        => $result['explain_text'],
                    'explain_audio_url'   => $result['explain_audio_url'] ?? null,
                    'explain_image_url'   => $result['explain_image_url'] ?? null,
                    'check_question_json' => json_encode($result['check_question'], JSON_UNESCAPED_UNICODE),
                    'feedback_json'       => json_encode($result['feedback'], JSON_UNESCAPED_UNICODE),
                    'translations_json'   => $translationsJson,
                    'credits_used'        => (int) ($result['credits_used'] ?? $costperquestion),
                    'timecreated'         => time(),
                    'timemodified'        => time(),
                ];
                $moduleid = $DB->insert_record('local_aiqr_module', $module);

                $DB->insert_record('local_aiqr_completion', (object) [
                    'moduleid'       => $moduleid,
                    'userid'         => $umbrellaJob->userid,
                    'state'          => 'notstarted',
                    'attempts_count' => 0,
                    'completed_at'   => null,
                    'timecreated'    => time(),
                    'timemodified'   => time(),
                ]);

                $DB->update_record('local_aiqr_job', (object) [
                    'id'           => $qjobid,
                    'status'       => 'ready',
                    'timemodified' => time(),
                ]);

                $langCount = count($extraLanguages);
                mtrace('  [AIQR-KC] Module ' . $moduleid . ' created for KC question ' . $questionid . ' (' . $costperquestion . ' credits, ' . $langCount . ' extra language(s))');

            } catch (\Throwable $e) {
                $DB->update_record('local_aiqr_job', (object) [
                    'id'           => $qjobid,
                    'status'       => 'failed',
                    'errormsg'     => substr($e->getMessage(), 0, 1000),
                    'timemodified' => time(),
                ]);
                mtrace('  [AIQR-KC] Question job ' . $qjobid . ' failed: ' . $e->getMessage());
            }
        }
    }
}
