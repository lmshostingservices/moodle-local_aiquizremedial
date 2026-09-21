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
 * Part of the local_aiquizremedial plugin.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_aiquizremedial\ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');
global $CFG;
require_once($CFG->libdir . '/filelib.php'); // Moodle's \curl class is not autoloaded (cron context).

use local_aiquizremedial\credit_calculator;

class credits_client {
    private string $baseurl = 'https://lms-labs.com';

    public function generate_remediation(int $userid, int $courseid, array $payload): array {
        $costperquestion = credit_calculator::get_cost_per_question();

        // BUG-REM-CREDITS-FAILED (v1.2.35): generate content FIRST, charge credits AFTER
        // success only.  Previously credits were charged before generation; when the AI API
        // returned no explain_text the fallback placeholder was stored and credits were
        // consumed for nothing.  generate_text_remediation() now throws \moodle_exception
        // on failure so we only reach the charge call on genuine success.
        // v1.4.1: the services are text-only, so images inside the question are sent as their
        // alt-text descriptions (never invented). The same sanitised question snapshot is kept
        // with the module so later image backfill sends exactly what the learner saw.
        $snapshot = !empty($payload['question']) && is_array($payload['question'])
            ? \local_aiquizremedial\media::snapshot($payload['question']) : null;
        if ($snapshot) {
            $payload['question']['text_html'] = $snapshot['text_html'];
        }

        $gen = $this->generate_text_remediation($userid, $courseid, $payload);

        // Version 1.4.0: structured 4-card tutor lesson (null when the service returns the old format).
        $lesson = \local_aiquizremedial\lesson::normalise($gen['lesson'] ?? null);
        $ttsscript = trim((string) ($gen['tts_script'] ?? ($gen['lesson']['tts_script'] ?? '')));
        if (empty($gen['explain_text']) && $ttsscript !== '') {
            $gen['explain_text'] = $ttsscript;
        }

        $charge = $this->charge_credits($userid, $courseid, $costperquestion);

        if (empty($charge['ok'])) {
            throw new \moodle_exception('insufficientcredits', 'local_aiquizremedial');
        }

        $explainAudioUrl = null;
        if (credit_calculator::is_voiceover_enabled()) {
            // BUG-TTS-MISMATCH (v1.1.7): the AI server returns a separate
            // voiceover_script that can differ from explain_text (the text
            // actually displayed to the student), so the audio read words not
            // present on screen.  Fix: always derive TTS text from explain_text,
            // stripped of HTML and entity-decoded, so audio exactly matches what
            // the student reads.  voiceover_script is intentionally ignored.
            // v1.4.0: prefer the service's purpose-written narration script for the lesson.
            $voicetext = $ttsscript !== '' ? $ttsscript
                : strip_tags(html_entity_decode((string) ($gen['explain_text'] ?? ''), ENT_QUOTES, 'UTF-8'));
            $tts = $this->tts($voicetext);
            $explainAudioUrl = $tts['audio_url'] ?? null;
        }

        // Optional image. A failure never loses the generated text: the module is saved with
        // an image state and the cron backfill retries retryable failures. No second charge.
        $image = null;
        if (credit_calculator::is_images_enabled()) {
            $image = $this->generate_image((string) $gen['explain_text'], $snapshot);
        }

        $feedback = $gen['feedback'] ?? [];
        if (credit_calculator::is_voiceover_enabled()) {
            foreach ($feedback as $i => $fb) {
                if (!empty($fb['explain'])) {
                    // BUG-TTS-MISMATCH: strip HTML from feedback explain text too.
                    $fbtext = strip_tags(html_entity_decode((string) $fb['explain'], ENT_QUOTES, 'UTF-8'));
                    $fbtts = $this->tts($fbtext);
                    $feedback[$i]['audio_url'] = $fbtts['audio_url'] ?? null;
                }
            }
        }

        return [
            'credits_used'      => (int) ($charge['units'] ?? $costperquestion),
            'explain_text'      => (string) $gen['explain_text'],
            'explain_audio_url' => $explainAudioUrl,
            'explain_image_url' => ($image && !empty($image['ok'])) ? $image['image_url'] : null,
            'image'             => $image,
            'question_snapshot' => $snapshot,
            'check_question'    => $gen['check_question'],
            'feedback'          => $feedback,
            'lesson'            => $lesson,
        ];
    }

    /**
     * Generate translated explain_text and audio for each requested language.
     * Credits are already included in the upfront charge from generate_remediation().
     *
     * @param string $explainText  Plain-text explain_text (no HTML) for translation.
     * @param array  $languages    Language codes, e.g. ['fr', 'es'].
     * @return array<string, array{explain_text: string, audio_url: string|null}>
     */
    public function generate_translations(string $explainText, array $languages): array {
        $results = [];
        foreach ($languages as $lang) {
            try {
                $result = $this->translate_and_tts($explainText, $lang);
                $results[$lang] = [
                    'explain_text' => $result['translated_text'] ?? $explainText,
                    'audio_url'    => $result['audio_url'] ?? null,
                ];
            } catch (\Throwable $e) {
                mtrace('  [AIQR-TRANSLATE] Language ' . $lang . ' failed: ' . $e->getMessage());
            }
        }
        return $results;
    }

    protected function translate_and_tts(string $text, string $targetLanguage): array {
        $siteid = local_aiquizremedial_get_siteid();
        $apikey = local_aiquizremedial_get_apikey();

        $curl = new \curl();
        $curl->setopt(CURLOPT_TIMEOUT, 90);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey,
        ]);

        $body = json_encode([
            'siteId'         => $siteid,
            'text'           => $text,
            'targetLanguage' => $targetLanguage,
            'action'         => 'remediation_translate',
        ]);

        $response = $curl->post($this->baseurl . '/api/quiz-remediation/translate', $body);
        $data = json_decode($response, true);

        return [
            'translated_text' => $data['translated_text'] ?? null,
            'audio_url'       => $data['audio_url'] ?? null,
        ];
    }

    protected function charge_credits(int $userid, int $courseid, int $units): array {
        $siteid = local_aiquizremedial_get_siteid();
        $apikey = local_aiquizremedial_get_apikey();

        if (empty($siteid) || empty($apikey)) {
            throw new \moodle_exception('missingconfig', 'local_aiquizremedial');
        }

        $curl = new \curl();
        $curl->setopt(CURLOPT_TIMEOUT, 30);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey,
        ]);

        $body = json_encode([
            'siteId'   => $siteid,
            'units'    => $units,
            'action'   => 'quiz_remediation',
            'userId'   => $userid,
            'courseId'  => $courseid,
        ]);

        $response = $curl->post($this->baseurl . '/api/credits/consume', $body);
        $data = json_decode($response, true);

        if (!empty($data['success'])) {
            return ['ok' => true, 'units' => $units, 'remaining' => $data['remaining'] ?? null];
        }

        return ['ok' => false, 'reason' => $data['error'] ?? 'Unknown error'];
    }

    protected function generate_text_remediation(int $userid, int $courseid, array $payload): array {
        $siteid = local_aiquizremedial_get_siteid();
        $apikey = local_aiquizremedial_get_apikey();

        $curl = new \curl();
        $curl->setopt(CURLOPT_TIMEOUT, 60);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey,
        ]);

        $body = json_encode([
            'siteId'   => $siteid,
            'action'   => 'generate_remediation',
            'userId'   => $userid,
            'courseId'  => $courseid,
            'payload'  => $payload,
            // Version 1.4.0: ask for the structured 4-card tutor lesson. Services that don't know this
            // field ignore it and return the old explain_text format, which still renders.
            'responseFormat' => \local_aiquizremedial\lesson::FORMAT,
            'locale'   => 'en-AU',
        ]);

        $response = $curl->post($this->baseurl . '/api/quiz-remediation/generate', $body);
        $data = json_decode($response, true);

        if (!empty($data['explain_text']) || !empty($data['tts_script'])
                || \local_aiquizremedial\lesson::normalise($data['lesson'] ?? null)) {
            return $data;
        }

        // BUG-REM-CREDITS-FAILED (v1.2.35): throw instead of returning the placeholder string.
        // The caller (generate_remediation) now charges credits AFTER this call succeeds, so
        // throwing here means no credits are consumed when the AI API fails.
        // process_jobs.php catch block will set the job status to 'failed'.
        throw new \moodle_exception(
            'remediationfailed', 'local_aiquizremedial', '', null,
            'AI did not return explain_text. Response snippet: ' . substr((string) ($response ?? ''), 0, 300));
    }

    protected function tts(string $text): array {
        $siteid = local_aiquizremedial_get_siteid();
        $apikey = local_aiquizremedial_get_apikey();

        $curl = new \curl();
        $curl->setopt(CURLOPT_TIMEOUT, 60);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey,
        ]);

        $body = json_encode([
            'siteId' => $siteid,
            'text'   => $text,
            'action' => 'remediation_tts',
        ]);

        $response = $curl->post($this->baseurl . '/api/tts/generate', $body);
        $data = json_decode($response, true);

        return ['audio_url' => $data['audio_url'] ?? null];
    }

    /**
     * Generate the explanation image (POST /api/image/generate, action remediation_image).
     *
     * The service (v1.4.1 contract) uses GPT Image 2 — medium quality, 864×1536 portrait, a
     * 75-second provider deadline, no model fallback and no automatic retries — stores the
     * image and returns an HTTPS image_url plus provenance. It never returns base64 to Moodle.
     * The 180-second HTTP timeout here covers the provider deadline plus storage overhead.
     *
     * Every failure is classified instead of collapsing to null, so the caller can keep the
     * generated text, keep any earlier image, record a useful state and retry only what is
     * worth retrying. The API key is never logged or included in the error text.
     *
     * @param string $context the full corrective explanation (not truncated here)
     * @param array|null $snapshot original-question snapshot (media::snapshot); null = context-only
     * @return array {ok: bool, image_url?: string, provenance?: array, error?: string, retryable?: bool, http?: int}
     */
    public function generate_image(string $context, ?array $snapshot): array {
        $siteid = local_aiquizremedial_get_siteid();
        $apikey = local_aiquizremedial_get_apikey();
        if ($siteid === '' || $apikey === '') {
            return ['ok' => false, 'retryable' => false, 'error' => 'Site ID or API key not configured.'];
        }

        $request = [
            'siteId'  => $siteid,
            'action'  => 'remediation_image',
            'context' => $context,
        ];
        // Send an object or omit the field — never an empty JSON array (FIX-QUESTION-ARRAY).
        $question = \local_aiquizremedial\media::request_question($snapshot);
        if ($question) {
            $request['question'] = $question;
        }

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 180, 'CURLOPT_CONNECTTIMEOUT' => 20]);
        $curl->setHeader(['Content-Type: application/json', 'X-API-Key: ' . $apikey]);
        $response = $curl->post($this->baseurl . '/api/image/generate', json_encode($request));

        $errno = (int) $curl->get_errno();
        $info = $curl->get_info();
        $http = (int) ($info['http_code'] ?? 0);
        $scrub = function (string $msg) use ($apikey): string {
            $msg = trim(preg_replace('/\s+/', ' ', strip_tags($msg)));
            if (strlen($apikey) >= 8) {
                $msg = str_replace($apikey, '[redacted]', $msg);
            }
            return \core_text::substr($msg, 0, 300);
        };

        if ($errno) {
            return ['ok' => false, 'retryable' => true, 'http' => 0,
                'error' => $scrub('Connection failed or timed out (cURL ' . $errno . ': ' . $curl->error . ').')];
        }
        $data = json_decode((string) $response, true);
        $servicemsg = is_array($data) ? (string) ($data['error'] ?? ($data['message'] ?? '')) : '';

        if ($http < 200 || $http >= 300) {
            // Invalid input (e.g. over the documented bounds) and auth failures will fail the
            // same way again, so they are not retried unchanged.
            $retryable = $http === 0 || $http === 408 || $http === 429 || $http >= 500;
            return ['ok' => false, 'retryable' => $retryable, 'http' => $http,
                'error' => $scrub('HTTP ' . $http . ($servicemsg !== '' ? ': ' . $servicemsg : '') . '.')];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'retryable' => true, 'http' => $http, 'error' => 'Malformed response from image service.'];
        }
        if (array_key_exists('success', $data) && $data['success'] !== true) {
            return ['ok' => false, 'retryable' => true, 'http' => $http,
                'error' => $scrub('Image service reported failure' . ($servicemsg !== '' ? ': ' . $servicemsg : '') . '.')];
        }
        $url = (string) ($data['image_url'] ?? '');
        if (!$this->is_expected_image_url($url)) {
            return ['ok' => false, 'retryable' => true, 'http' => $http,
                'error' => $url === '' ? 'Image service returned no image URL.'
                    : 'Image service returned an unexpected image URL (not an HTTPS link on the service host).'];
        }
        $provenance = is_array($data['provenance'] ?? null) ? array_intersect_key(
            $data['provenance'],
            array_flip(['provider', 'model', 'requestedModel', 'modelIdentitySource', 'fallback', 'promptMode'])) : null;
        return ['ok' => true, 'image_url' => $url, 'provenance' => $provenance, 'http' => $http];
    }

    /**
     * Only accept a hosted image on the service's own host, over HTTPS (never base64 / data:).
     *
     * @param string $url
     * @return bool
     */
    protected function is_expected_image_url(string $url): bool {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        $base = parse_url($this->baseurl);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $basehost = strtolower($base['host'] ?? '');
        $hostok = $host === $basehost || substr($host, -strlen('.' . $basehost)) === '.' . $basehost;
        // HTTPS is required; plain HTTP is only accepted when the service itself is configured
        // on HTTP (local development).
        $schemeok = $scheme === 'https' || ($scheme === 'http' && ($base['scheme'] ?? '') === 'http');
        return $hostok && $schemeok;
    }
}
