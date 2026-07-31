<?php
namespace local_aiquizremedial\ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

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
        $gen = $this->generate_text_remediation($userid, $courseid, $payload);

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
            $voicetext = strip_tags(html_entity_decode((string) ($gen['explain_text'] ?? ''), ENT_QUOTES, 'UTF-8'));
            $tts = $this->tts($voicetext);
            $explainAudioUrl = $tts['audio_url'] ?? null;
        }

        $explainImageUrl = null;
        if (credit_calculator::is_images_enabled()) {
            $img = $this->generate_image($gen['explain_text'], $payload);
            $explainImageUrl = $img['image_url'] ?? null;
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
            'explain_image_url' => $explainImageUrl,
            'check_question'    => $gen['check_question'],
            'feedback'          => $feedback,
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
        ]);

        $response = $curl->post($this->baseurl . '/api/quiz-remediation/generate', $body);
        $data = json_decode($response, true);

        if (!empty($data['explain_text'])) {
            return $data;
        }

        // BUG-REM-CREDITS-FAILED (v1.2.35): throw instead of returning the placeholder string.
        // The caller (generate_remediation) now charges credits AFTER this call succeeds, so
        // throwing here means no credits are consumed when the AI API fails.
        // process_jobs.php catch block will set the job status to 'failed'.
        throw new \moodle_exception('remediationfailed', 'local_aiquizremedial', '', null,
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
     * Public wrapper for generate_image() used by the cron backfill task (FIX-IMG-BACKFILL,
     * v1.2.51). Modules created during the v1.2.49 window had explain_image_url = null because
     * the negativePrompt bug silently rejected Imagen requests. This method lets the scheduled
     * task regenerate a missing image without re-running the full remediation flow or
     * re-charging credits (the credit cost was already included in the original module charge).
     *
     * @param string $explainText  The module's explain_text used as the image context.
     * @return array{image_url: string|null}
     */
    public function backfill_image(string $explainText): array {
        return $this->generate_image($explainText, []);
    }

    protected function generate_image(string $context, array $payload): array {
        $siteid = local_aiquizremedial_get_siteid();
        $apikey = local_aiquizremedial_get_apikey();

        $curl = new \curl();
        // FIX-IMG-TIMEOUT (v1.2.43): Imagen 4 Ultra retry chain (up to 2 retries with
        // 15s+30s back-off) followed by OpenAI gpt-image-1 fallback can exceed 90s when
        // Imagen content-policy filters reject the prompt. The previous 60s timeout caused
        // PHP to abort before the fallback returned, leaving explain_image_url null and
        // no image rendered for the student. Raised to 180s to accommodate the full chain.
        $curl->setopt(CURLOPT_TIMEOUT, 180);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey,
        ]);

        // FIX-QUESTION-ARRAY (v1.2.54): PHP's json_encode([]) produces a JSON array "[]",
        // but the server's Zod schema expects "question" to be a JSON object (or omitted).
        // Cast to (object) so an empty/missing question always serialises as "{}" not "[]".
        $body = json_encode([
            'siteId'   => $siteid,
            'context'  => $context,
            'action'   => 'remediation_image',
            'question' => (object)($payload['question'] ?? []),
        ]);

        $response = $curl->post($this->baseurl . '/api/image/generate', $body);
        $data = json_decode($response, true);

        return ['image_url' => $data['image_url'] ?? null];
    }
}
