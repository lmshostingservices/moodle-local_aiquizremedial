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
 * Shared helpers used by pages, the footer hook and the scheduled task.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class helper {
    /** Failed question jobs are retried automatically this many times. */
    const MAX_RETRIES = 3;

    /** @var bool|null cached result of kc_installed(). */
    protected static $kcinstalled = null;

    /**
     * Is the optional AI Knowledge Check activity (mod_aiknowledgecheck) installed?
     *
     * Every SQL join against the aiknowledgecheck tables MUST be guarded by this. On sites
     * without that plugin the tables do not exist and an unguarded join throws
     * dml_read_exception ("Error reading from database") — this was the reason students
     * could not open their revision modules on sites without Knowledge Check (v1.3.0).
     *
     * @return bool
     */
    public static function kc_installed(): bool {
        global $DB;
        if (self::$kcinstalled === null) {
            try {
                $dbman = $DB->get_manager();
                self::$kcinstalled = $dbman->table_exists('aiknowledgecheck')
                    && $dbman->table_exists('aiknowledgecheck_questions');
            } catch (\Throwable $e) {
                self::$kcinstalled = false;
            }
        }
        return self::$kcinstalled;
    }

    /**
     * Is remediation switched on for a source type? Quiz and AI Knowledge Check are
     * independent: either can be used without the other.
     *
     * @param string $sourcetype 'quiz' or 'knowledgecheck'
     * @return bool
     */
    public static function source_enabled(string $sourcetype): bool {
        if (!get_config('local_aiquizremedial', 'enabled')) {
            return false;
        }
        return in_array($sourcetype === 'knowledgecheck' ? 'knowledgecheck' : 'quiz', self::enabled_sources(), true);
    }

    /**
     * Activity types remedial learning is applied to ("Apply remedial learning to" setting).
     * Never saved = both; nothing ticked = none.
     *
     * @return string[] subset of ['quiz', 'knowledgecheck']
     */
    public static function enabled_sources(): array {
        $raw = get_config('local_aiquizremedial', 'sources');
        if ($raw === false || $raw === null) {
            return ['quiz', 'knowledgecheck'];
        }
        return array_values(array_intersect(array_map('trim', explode(',', (string) $raw)), ['quiz', 'knowledgecheck']));
    }

    /**
     * SQL CASE expression returning the learner-facing status of a question-level job row.
     *
     * Values: complete, inprogress, notstarted, generating, failed.
     * A failed job that still has automatic retries left is reported as 'generating'.
     *
     * @param string $j alias of local_aiqr_job
     * @param string $c alias of local_aiqr_completion
     * @return string
     */
    public static function status_sql(string $j = 'j', string $c = 'c'): string {
        $max = (int) self::MAX_RETRIES;
        return "CASE
                    WHEN {$j}.status = 'ready' AND {$c}.state = 'complete' THEN 'complete'
                    WHEN {$j}.status = 'ready' AND {$c}.state = 'inprogress' THEN 'inprogress'
                    WHEN {$j}.status = 'ready' THEN 'notstarted'
                    WHEN {$j}.status = 'failed' AND {$j}.retries >= {$max} THEN 'failed'
                    ELSE 'generating'
                END";
    }

    /**
     * Summary of a learner's revision modules, optionally limited to a course / quiz / attempt.
     *
     * @param int $userid
     * @param int $courseid 0 = any
     * @param int $quizid 0 = any
     * @param int $attemptid 0 = any
     * @param int $kcid 0 = any (AI Knowledge Check instance)
     * @return \stdClass {total, ready, outstanding, complete, generating, failed, pendingumbrellas}
     */
    public static function learner_summary(int $userid, int $courseid = 0, int $quizid = 0, int $attemptid = 0,
            int $kcid = 0): \stdClass {
        global $DB;

        $where = ['j.userid = :userid'];
        $params = ['userid' => $userid, 'userid2' => $userid];
        if ($courseid > 0) {
            $where[] = 'j.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($quizid > 0) {
            $where[] = 'j.quizid = :quizid';
            $params['quizid'] = $quizid;
        }
        if ($kcid > 0) {
            $where[] = "j.kcid = :kcid AND j.sourcetype = 'knowledgecheck'";
            $params['kcid'] = $kcid;
        }
        if ($attemptid > 0) {
            $where[] = 'j.attemptid = :attemptid';
            $params['attemptid'] = $attemptid;
            // Quiz attempt ids and KC attempt ids come from different sequences.
            if ($quizid > 0) {
                $where[] = "j.sourcetype = 'quiz'";
            }
        }
        $wheresql = implode(' AND ', $where);
        $status = self::status_sql();

        $sql = "SELECT s.status, COUNT(1) AS n
                  FROM (SELECT {$status} AS status
                          FROM {local_aiqr_job} j
                     LEFT JOIN {local_aiqr_module} m ON m.jobid = j.id
                     LEFT JOIN {local_aiqr_completion} c ON c.moduleid = m.id AND c.userid = :userid2
                         WHERE {$wheresql} AND j.questionid IS NOT NULL) s
              GROUP BY s.status";
        $counts = $DB->get_records_sql_menu($sql, $params);

        $out = new \stdClass();
        $out->complete   = (int) ($counts['complete'] ?? 0);
        $out->inprogress = (int) ($counts['inprogress'] ?? 0);
        $out->notstarted = (int) ($counts['notstarted'] ?? 0);
        $out->generating = (int) ($counts['generating'] ?? 0);
        $out->failed     = (int) ($counts['failed'] ?? 0);
        $out->ready       = $out->complete + $out->inprogress + $out->notstarted;
        $out->outstanding = $out->inprogress + $out->notstarted;
        $out->total       = $out->ready + $out->generating + $out->failed;

        // Umbrella jobs that cron has not expanded yet: we do not know how many questions
        // are wrong yet, but the learner should be told that modules are on their way.
        // Quiz attempts with full marks are excluded so a perfect score never shows
        // "preparing" (sumgrades is NULL while an attempt is still being graded).
        $uparams = $params;
        unset($uparams['userid2']);
        $out->pendingumbrellas = (int) $DB->count_records_sql(
            "SELECT COUNT(1) FROM {local_aiqr_job} j
              WHERE {$wheresql} AND j.questionid IS NULL AND j.status IN ('pending', 'processing')
                AND (j.sourcetype <> 'quiz' OR EXISTS (
                        SELECT 1 FROM {quiz_attempts} qa
                          JOIN {quiz} q ON q.id = qa.quiz
                         WHERE qa.id = j.attemptid
                           AND (qa.sumgrades IS NULL OR qa.sumgrades < q.sumgrades - 0.00001)))",
            $uparams
        );
        return $out;
    }

    /**
     * Enabled extra languages, parsed from the multicheckbox setting.
     *
     * The admin multicheckbox setting is stored as a comma-separated list ("fr,es"). Versions
     * before 1.3.0 tried to decode it as a PHP-serialised array, which always failed, so extra
     * languages were never generated.
     *
     * @param mixed $raw
     * @param array $supported language codes allowed
     * @return string[]
     */
    public static function parse_languages($raw, array $supported): array {
        if (empty($raw) || !is_string($raw)) {
            return [];
        }
        $codes = array_filter(array_map('trim', explode(',', $raw)), function ($c) {
            return $c !== '' && $c !== '0';
        });
        return array_values(array_intersect(array_unique($codes), $supported));
    }
}
