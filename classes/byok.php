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

use local_aiquizremedial\local\byok\engine;

/**
 * Bring your own AI: revision modules made on the site's own AI instead of LMS Labs credits.
 *
 * Each part follows its own service in AI Grader Central Config: the lesson and its translations follow Text, the
 * narration follows Voice and the picture follows Images. A part set to the site's own AI runs here, from this Moodle
 * site straight to the AI provider, and costs no credits; a part left on LMS Labs credits runs on LMS Labs as before
 * and is charged as before.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** @var string This plugin, as AI Grader Central Config knows it. */
    const COMPONENT = 'local_aiquizremedial';

    /** @var float The service's speaking rate (generateVoiceover()'s default). */
    const SPEAKING_RATE = 0.92;

    /** @var string The file area that keeps pictures made on the site's own AI. */
    const FILEAREA = 'byokimage';

    /**
     * Whether a service runs on the site's own AI now.
     *
     * @param string $service text, voice or image
     * @return bool
     */
    public static function uses(string $service): bool {
        return class_exists('\local_aiconfig\byok\ai') && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, $service);
    }

    /**
     * Whether translations run here: they need the site's own Text and Voice, as the service makes both together.
     *
     * @return bool
     */
    public static function uses_translation(): bool {
        return self::uses('text') && self::uses('voice');
    }

    /**
     * Whether any part of a revision module runs on the site's own AI.
     *
     * @return bool
     */
    public static function any(): bool {
        return self::uses('text') || self::uses('voice') || self::uses('image');
    }

    /**
     * Credits for one revision module: only the parts still made by LMS Labs.
     *
     * @return int
     */
    public static function units(): int {
        $units = self::uses('text') ? 0 : credit_calculator::COST_TEXT;
        if (credit_calculator::is_voiceover_enabled() && !self::uses('voice')) {
            $units += credit_calculator::COST_VOICEOVER;
        }
        if (credit_calculator::is_images_enabled() && !self::uses('image')) {
            $units += credit_calculator::COST_IMAGE;
        }
        if (!self::uses_translation()) {
            $units += count(credit_calculator::get_enabled_languages()) * credit_calculator::COST_PER_LANGUAGE;
        }
        return $units;
    }

    /**
     * Point the service code at the site's own AI.
     */
    protected static function hooks(): void {
        engine::$text = function (string $system, string $user, int $maxtokens, float $temperature, ?array $schema): string {
            \core_php_time_limit::raise(300);
            $options = ['maxtokens' => $maxtokens, 'temperature' => $temperature, 'json' => true, 'timeout' => 150];
            if ($schema !== null) {
                $options['schema'] = $schema;
            }
            return \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, $options);
        };
        engine::$tts = function (string $text, string $voiceid, string $language, string $format): string {
            return self::speech($text, $voiceid, $language, $format);
        };
    }

    /**
     * Generate a revision module's text (POST /api/quiz-remediation/generate) on the site's own AI.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $payload The question payload the plugin sends to LMS Labs.
     * @return array The service's answer.
     */
    public static function generate(int $userid, int $courseid, array $payload): array {
        self::hooks();
        [$status, $data] = engine::generate([
            'siteId' => 'byok',
            'action' => 'generate_remediation',
            'userId' => $userid,
            'courseId' => $courseid,
            'payload' => $payload,
            'responseFormat' => lesson::FORMAT,
            'locale' => 'en-AU',
        ]);
        if (
            $status === 200 && (!empty($data['explain_text']) || !empty($data['tts_script'])
                || lesson::normalise($data['lesson'] ?? null))
        ) {
            return $data;
        }
        throw new \moodle_exception(
            'remediationfailed',
            'local_aiquizremedial',
            '',
            null,
            'Your AI did not return a usable revision module: ' . substr((string) ($data['error'] ?? ''), 0, 300)
        );
    }

    /**
     * Narrate text as the service's TTS route does, returning the same data: URL.
     *
     * @param string $text
     * @return string|null
     */
    public static function tts_url(string $text): ?string {
        self::hooks();
        [$status, $data] = engine::tts($text);
        return $status === 200 ? $data['audio_url'] : null;
    }

    /**
     * Translate and narrate a module's text as the service's translate route does.
     *
     * @param string $text
     * @param string $language
     * @return array translated_text and audio_url (null when it failed)
     */
    public static function translate(string $text, string $language): array {
        self::hooks();
        [$status, $data] = engine::translate($text, $language);
        if ($status !== 200) {
            throw new \RuntimeException((string) ($data['error'] ?? 'Translation failed'));
        }
        return ['translated_text' => $data['translated_text'], 'audio_url' => $data['audio_url']];
    }

    /**
     * Make the module's picture on the site's own AI, with the service's prompt, and keep it in Moodle.
     *
     * @param string $context The corrective explanation.
     * @param array|null $snapshot The original question snapshot (media::snapshot()).
     * @return array As credits_client::generate_image().
     */
    public static function image(string $context, ?array $snapshot): array {
        $question = media::request_question($snapshot);
        try {
            [$prompt, $mode] = engine::image_prompt($context, $question ? (array) $question : null);
        } catch (\LengthException $e) {
            return ['ok' => false, 'retryable' => false, 'http' => 400, 'error' => $e->getMessage()];
        }
        try {
            \core_php_time_limit::raise(300);
            $bytes = \local_aiconfig\byok\ai::image(self::COMPONENT, $prompt, ['size' => '1024x1536', 'quality' => 'medium']);
            $info = @getimagesizefromstring($bytes);
            if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                return ['ok' => false, 'retryable' => true, 'http' => 200, 'error' => 'Your AI returned no usable picture.'];
            }
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime']];
            $filename = random_string(32) . '.' . $ext;
            $context = \context_system::instance();
            get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => self::COMPONENT,
                'filearea' => self::FILEAREA, 'itemid' => 0, 'filepath' => '/', 'filename' => $filename], $bytes);
            $url = \moodle_url::make_pluginfile_url($context->id, self::COMPONENT, self::FILEAREA, 0, '/', $filename);
            $own = \local_aiconfig\byok\router::source(self::COMPONENT, 'image') === \local_aiconfig\byok\router::OWNKEY;
            $vendor = $own ? \local_aiconfig\byok\router::vendor(\local_aiconfig\byok\router::IMAGE) : null;
            $model = $vendor ? \local_aiconfig\byok\ai::model($vendor, 'image') : 'moodle';
            return ['ok' => true, 'image_url' => $url->out(false), 'http' => 200, 'provenance' => [
                'provider' => $vendor ?: 'moodle', 'model' => $model, 'requestedModel' => $model,
                'modelIdentitySource' => 'requested', 'fallback' => false, 'promptMode' => $mode]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'retryable' => true, 'http' => 0,
                'error' => \core_text::substr('Your AI could not make the picture: ' . $e->getMessage(), 0, 300)];
        }
    }

    /**
     * One narration request on the site's voice.
     *
     * The service narrates with Google voices at 0.92 speed. A Google key uses the same voice; an OpenAI key gets
     * shimmer, the closest OpenAI voice to the service's female voices; ElevenLabs uses the voice the administrator chose.
     *
     * @param string $text
     * @param string $voiceid
     * @param string $language
     * @param string $format ogg or mp3
     * @return string
     */
    protected static function speech(string $text, string $voiceid, string $language, string $format): string {
        $vendor = \local_aiconfig\byok\router::vendor(\local_aiconfig\byok\router::VOICE);
        $name = $vendor === 'googletts' ? $voiceid : ($vendor === 'openai' ? 'shimmer' : '');
        return \local_aiconfig\byok\ai::speech(
            self::COMPONENT,
            $text,
            ['language' => $language, 'voice' => $name, 'format' => $format, 'rate' => self::SPEAKING_RATE]
        );
    }
}
