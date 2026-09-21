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
 * Explanation-image support (v1.4.1): question snapshots, image states and backfill.
 *
 * The image service (POST /api/image/generate, action remediation_image) uses GPT Image 2
 * (medium quality, 864×1536 portrait, 75 s provider deadline, no fallback, no automatic
 * retries). It is text-conditioned: it never sees the question's own diagrams or photos,
 * so images inside the question are sent as their alt-text descriptions and questions whose
 * images have no description are flagged rather than guessed at.
 *
 * Image states stored on local_aiqr_module.image_status:
 *   ready     image stored in explain_image_url
 *   retry     a retryable failure (timeout, 5xx, 429, malformed response) — backfill retries
 *             with back-off up to MAX_ATTEMPTS
 *   failed    retryable failures exhausted
 *   rejected  not retryable unchanged (400 input limits, 401/403 auth, 404/413/422) — a
 *             teacher/admin can reset it from the report after fixing the cause
 *   disabled  images were switched off when the module was generated
 *   NULL      module created before v1.4.1 — treated like 'retry' by the backfill
 *
 * Charging: the module's credit charge already covers optional media. Image generation and
 * backfill never charge again, and a failed image is not refunded automatically.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class media {
    /** Total image attempts per module (initial generation counts as one). */
    const MAX_ATTEMPTS = 3;

    /** Base back-off in seconds (doubles each attempt). */
    const BACKOFF = 900;

    /** Backfill cap per cron run (unchanged from v1.2.51). */
    const BACKFILL_PER_RUN = 3;

    /**
     * Replace embedded images with their accessible descriptions so a text-only service can
     * use them. Images without a description are counted, never invented.
     *
     * @param string $html
     * @return array [string html, int undescribed image count]
     */
    public static function describe_media(string $html): array {
        $undescribed = 0;
        // Scripts and styles are never question content.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use (&$undescribed) {
            $alt = '';
            if (preg_match('/\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[0], $a)) {
                $alt = trim(html_entity_decode($a[2] !== '' ? $a[2] : ($a[3] ?? ''), ENT_QUOTES, 'UTF-8'));
            }
            if ($alt === '' || preg_match('/^(image|picture|photo|img|\d+|[\w-]+\.(png|jpe?g|gif|svg|webp))$/i', $alt)) {
                $undescribed++;
                return ' [Image with no description] ';
            }
            return ' [Image: ' . s($alt) . '] ';
        }, $html);
        return [$html, $undescribed];
    }

    /**
     * Minimal, sanitised snapshot of the ORIGINAL question as the learner saw it, stored with
     * the module so initial generation and later backfill send the same question.
     * Contains no learner name, email, grade or attempt metadata.
     *
     * @param array $question payload['question'] from question_payload / kc_question_payload
     * @param string $source 'generation' or 'reconstructed'
     * @return array
     */
    public static function snapshot(array $question, string $source = 'generation'): array {
        [$html, $undescribed] = self::describe_media((string) ($question['text_html'] ?? ''));
        return [
            'id'          => (int) ($question['id'] ?? 0),
            'qtype'       => (string) ($question['qtype'] ?? ''),
            'name'        => (string) ($question['name'] ?? ''),
            'text_html'   => $html,
            'undescribed_images' => $undescribed,
            'source'      => $source,
        ];
    }

    /**
     * The `question` object for the image request (only the four contract fields).
     *
     * @param array|null $snapshot
     * @return \stdClass|null null = omit (context-only generation)
     */
    public static function request_question(?array $snapshot): ?\stdClass {
        if (!$snapshot || trim(strip_tags((string) ($snapshot['text_html'] ?? ''))) === '') {
            return null;
        }
        return (object) [
            'id'        => (int) $snapshot['id'],
            'qtype'     => (string) $snapshot['qtype'],
            'name'      => (string) $snapshot['name'],
            'text_html' => (string) $snapshot['text_html'],
        ];
    }

    /**
     * Rebuild the original question for a module created before snapshots existed.
     *
     * Moodle quiz: the attempt's question usage references the exact question version the
     * learner saw (Moodle 4.0+ creates a new question id for every edit), so it is safe.
     * AI Knowledge Check questions are edited in place, so their current text may not be what
     * the learner saw — they are NOT reconstructed (context-only instead).
     *
     * @param \stdClass $job
     * @return array|null
     */
    public static function reconstruct_snapshot(\stdClass $job): ?array {
        global $DB, $CFG;
        if (($job->sourcetype ?? 'quiz') !== 'quiz' || empty($job->attemptid) || empty($job->questionid)) {
            return null;
        }
        try {
            require_once($CFG->libdir . '/questionlib.php');
            require_once($CFG->dirroot . '/question/engine/lib.php');
            $attempt = $DB->get_record('quiz_attempts', ['id' => $job->attemptid]);
            if (!$attempt) {
                return null;
            }
            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            foreach ($quba->get_slots() as $slot) {
                $question = $quba->get_question_attempt($slot)->get_question();
                if ((int) $question->id === (int) $job->questionid) {
                    return self::snapshot([
                        'id' => (int) $question->id,
                        'qtype' => $question->get_type_name(),
                        'name' => (string) ($question->name ?? ''),
                        'text_html' => (string) ($question->questiontext ?? ''),
                    ], 'reconstructed');
                }
            }
        } catch (\Throwable $e) {
            debugging('local_aiquizremedial: question reconstruction failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return null;
    }

    /**
     * Module fields to store after an image attempt. Never clears an existing image.
     *
     * @param array $result from credits_client::generate_image()
     * @param int $attempts attempts made so far including this one
     * @return array field => value
     */
    public static function fields_from_result(array $result, int $attempts): array {
        $now = time();
        $fields = ['image_attempts' => $attempts, 'timemodified' => $now];
        if (!empty($result['ok'])) {
            $fields += [
                'explain_image_url' => $result['image_url'],
                'image_status'      => 'ready',
                'image_error'       => null,
                'image_nextattempt' => 0,
                'image_meta'        => json_encode(
                    ['provenance' => $result['provenance'] ?? null,
                    'time' => $now], JSON_UNESCAPED_SLASHES),
            ];
            return $fields;
        }
        if (empty($result['retryable'])) {
            $status = 'rejected';
        } else {
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
        }
        $fields += [
            'image_status'      => $status,
            'image_error'       => substr((string) ($result['error'] ?? 'Unknown error'), 0, 500),
            'image_nextattempt' => $status === 'retry' ? $now + self::BACKOFF * (2 ** max(0, $attempts - 1)) : 0,
        ];
        return $fields;
    }

    /**
     * Cron backfill: generate missing images, at most BACKFILL_PER_RUN per run.
     *
     * @param callable $log
     * @return int images stored
     */
    public static function backfill(callable $log): int {
        global $DB;
        if (!credit_calculator::is_images_enabled()) {
            return 0;
        }
        $sql = "SELECT m.id, m.explain_text, m.question_json, m.image_attempts, m.image_status,
                       j.id AS jobid, j.sourcetype, j.attemptid, j.questionid
                  FROM {local_aiqr_module} m
                  JOIN {local_aiqr_job} j ON j.id = m.jobid
                 WHERE m.explain_image_url IS NULL
                   AND m.explain_text IS NOT NULL
                   AND j.status = 'ready'
                   AND (m.image_status IS NULL OR m.image_status = 'retry')
                   AND m.image_attempts < :max
                   AND m.image_nextattempt <= :now
              ORDER BY m.timecreated DESC";
        $modules = $DB->get_records_sql($sql, ['max' => self::MAX_ATTEMPTS, 'now' => time()], 0, self::BACKFILL_PER_RUN);

        $stored = 0;
        foreach ($modules as $mod) {
            if (trim((string) $mod->explain_text) === '') {
                continue;
            }
            $snapshot = !empty($mod->question_json) ? json_decode($mod->question_json, true) : null;
            if (!$snapshot) {
                $snapshot = self::reconstruct_snapshot((object) [
                    'sourcetype' => $mod->sourcetype, 'attemptid' => $mod->attemptid, 'questionid' => $mod->questionid,
                ]);
                if ($snapshot) {
                    $DB->set_field(
                        'local_aiqr_module', 'question_json', json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                        ['id' => $mod->id]);
                }
            }
            $ai = new ai\credits_client();
            $result = $ai->generate_image((string) $mod->explain_text, $snapshot);
            $fields = self::fields_from_result($result, (int) $mod->image_attempts + 1);
            $DB->update_record('local_aiqr_module', (object) (['id' => (int) $mod->id] + $fields));

            $mode = $result['provenance']['promptMode'] ?? ($snapshot ? 'question_with_context' : 'context_only');
            if (!empty($result['ok'])) {
                $stored++;
                $log("  [AIQR-IMAGE] Module {$mod->id}: image stored ({$mode}).");
            } else {
                $log("  [AIQR-IMAGE] Module {$mod->id}: {$fields['image_status']} — {$fields['image_error']}");
            }
        }
        return $stored;
    }
}
