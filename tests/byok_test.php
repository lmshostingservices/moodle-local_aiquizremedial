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
 * Tests for revision modules made on the site's own AI.
 *
 * local\byok\engine was compared with the LMS Labs service's code on 21,000 generated requests and AI answers
 * (lessons, repairs, the older single explanation, translations, input cleaning and picture prompts), with identical
 * results; these tests cover the wiring into the plugin.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aiquizremedial\byok
 * @covers     \local_aiquizremedial\local\byok\engine
 * @covers     \local_aiquizremedial\ai\credits_client
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ai\credits_client::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked providers received: [url, body]. */
    protected $requests = [];
    /** @var array Answers the mocked Anthropic gives, in order: a string is text, an array is a tool answer. */
    protected $answers = [];

    /** @var string A 4x6 PNG. */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAGCAIAAABrW6giAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgoCYAAABOAAGD'
        . 'ehqBAAAAAElFTkSuQmCC';

    /**
     * Licence the site for Bring your own AI on a test key: Text on Anthropic, Voice and Images on OpenAI, no LMS Labs
     * credentials; narration, pictures and French switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config with Bring your own AI is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        foreach (['text' => 'anthropic', 'voice' => 'openai', 'image' => 'openai'] as $service => $vendor) {
            set_config('src_' . $service, 'ownkey', 'local_aiconfig');
            set_config('vendor_' . $service, $vendor, 'local_aiconfig');
        }
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('key_openai', 'sk-test', 'local_aiconfig');
        foreach (['local_aiconfig', 'local_aiquizremedial'] as $plugin) {
            set_config('siteid', '', $plugin);
            set_config('apikey', '', $plugin);
        }
        set_config('enablevoiceover', 1, 'local_aiquizremedial');
        set_config('enableimages', 1, 'local_aiquizremedial');
        set_config('extra_languages', 'fr', 'local_aiquizremedial');
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = [$url, $body];
            if (strpos($url, '/audio/speech') !== false) {
                return 'OGGAUDIO';
            }
            if (strpos($url, '/images/') !== false) {
                return ['data' => [['b64_json' => self::PNG]]];
            }
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            if (is_array($answer)) {
                return json_encode(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => $body['tools'][0]['name'],
                    'input' => $answer]], 'stop_reason' => 'tool_use']);
            }
            return ['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn'];
        };
        engine::$sleep = fn(int $ms) => null;
    }

    /**
     * Clean up the mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        engine::$text = null;
        engine::$tts = null;
        engine::$sleep = null;
        parent::tearDown();
    }

    /**
     * A question payload as the plugin builds it.
     *
     * @return array
     */
    protected function payload(): array {
        return ['quiz' => ['id' => 3, 'name' => 'Conveyor isolation'], 'attempt' => ['id' => 9, 'attempt' => 1],
            'question' => ['id' => 12, 'qtype' => 'multichoice', 'name' => 'Q1',
                'text_html' => '<p>Priya must clear a jam on conveyor CV-03. What must she do first?</p>'],
            'student_response' => ['answer' => 0, 'answertext' => 'Press the stop button'],
            'correct_answer' => [['answer' => 'Isolate and lock the conveyor', 'feedback' => null]], 'fraction' => 0.0];
    }

    /**
     * The lesson from the fixture, as the AI gives it.
     *
     * @return array
     */
    protected function lesson(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/byok_lesson.json'), true);
    }

    /**
     * Only the parts still made by LMS Labs are charged.
     */
    public function test_units(): void {
        $this->assertTrue(byok::any());
        $this->assertSame(0, byok::units());
        set_config('src_voice', 'credits', 'local_aiconfig');
        // Narration (2) and French, which needs the site's own voice too (5).
        $this->assertFalse(byok::uses_translation());
        $this->assertSame(credit_calculator::COST_VOICEOVER + credit_calculator::COST_PER_LANGUAGE, byok::units());
        set_config('licencekey', '', 'local_aiconfig');
        $this->assertFalse(byok::any());
        $this->assertSame(credit_calculator::get_cost_per_question(), byok::units());
    }

    /**
     * A whole module on the site's own AI: the tutor lesson, narration and picture, with no credits and no LMS Labs.
     */
    public function test_module(): void {
        $this->answers = [$this->lesson()];
        // AI Grader Central Config's lib.php adds a debugging error handler the first time it loads; take it off again.
        $loaded = function_exists('local_aiconfig_get_siteid');
        $result = (new ai\credits_client())->generate_remediation(2, 3, $this->payload());
        if (!$loaded && function_exists('local_aiconfig_get_siteid') && !empty($GLOBALS['CFG']->debug)) {
            restore_error_handler();
        }

        $this->assertSame(0, $result['credits_used']);
        $this->assertSame($this->lesson()['tts_script'], $result['explain_text']);
        $this->assertSame('Why it seemed right', $result['lesson']['cards'][0]['heading']);
        $this->assertSame(1, $result['check_question']['correct_index']);
        $this->assertSame(['Incorrect', 'Correct', 'Incorrect'], array_column($result['feedback'], 'label'));
        $this->assertSame('data:audio/ogg;base64,' . base64_encode('OGGAUDIO'), $result['explain_audio_url']);
        $this->assertSame('data:audio/ogg;base64,' . base64_encode('OGGAUDIO'), $result['feedback'][0]['audio_url']);

        // The lesson request is the service's.
        [$url, $sent] = $this->requests[0];
        $this->assertStringEndsWith('/v1/messages', $url);
        $this->assertSame(2500, $sent['max_tokens']);
        $this->assertSame(0.4, $sent['temperature']);
        $this->assertStringContainsString("You are the world's best one-on-one tutor", $sent['system']);
        $this->assertStringContainsString('"student_answer":"Press the stop button"', $sent['messages'][0]['content'][0]['text']);
        $this->assertSame('remedial_lesson_v1', $sent['tools'][0]['name']);

        // Narration on the site's voice at the service's speed, and the picture with the service's prompt.
        $speech = array_values(array_filter($this->requests, fn($r) => strpos($r[0], '/audio/speech') !== false));
        $this->assertSame(['shimmer', 'opus', 0.92], [$speech[0][1]['voice'], $speech[0][1]['response_format'],
            $speech[0][1]['speed']]);
        $image = array_values(array_filter($this->requests, fn($r) => strpos($r[0], '/images/') !== false))[0][1];
        $this->assertSame('1024x1536', $image['size']);
        $this->assertStringContainsString('ORIGINAL QUESTION (primary source', $image['prompt']);
        $this->assertStringContainsString('Priya must clear a jam', $image['prompt']);
        $this->assertTrue($result['image']['ok']);
        $this->assertSame('question_with_context', $result['image']['provenance']['promptMode']);
        $this->assertStringContainsString('/pluginfile.php/' . \context_system::instance()->id .
            '/local_aiquizremedial/byokimage/0/', $result['explain_image_url']);
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_aiquizremedial',
            'byokimage',
            0,
            'id',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame(base64_decode(self::PNG), reset($files)->get_content());
    }

    /**
     * A lesson that fails the service's checks is repaired once, then the service's older explanation is used.
     */
    public function test_repair_then_older_format(): void {
        set_config('enablevoiceover', 0, 'local_aiquizremedial');
        set_config('enableimages', 0, 'local_aiquizremedial');
        $bad = $this->lesson();
        $bad['lesson']['cards'][1]['heading'] = 'Key idea';
        $this->answers = [$bad, $bad, json_encode(['explain_text' => 'Listen & Learn: The correct answer is Isolate and ' .
            'lock the conveyor, which is a crucial step.', 'voiceover_script' => "Isolate first.\nThen lock.",
            'check_question' => ['prompt' => 'What comes before clearing a jam?', 'options' => ['Isolate', 'Stop'],
                'correct_index' => 0], 'feedback' => [['explain' => "That's correct. Isolation comes first."]]])];
        $result = (new ai\credits_client())->generate_remediation(2, 3, $this->payload());

        $this->assertCount(3, $this->requests);
        $repair = json_encode($this->requests[1][1]['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Your previous JSON draft', $repair);
        $this->assertStringContainsString('lesson.cards[1].heading must be \"The key idea\"', $repair);
        $this->assertSame([1500, 0.2], [$this->requests[2][1]['max_tokens'], $this->requests[2][1]['temperature']]);
        // The service's clean-up: heading removed, banned word replaced, options padded, feedback filled.
        $this->assertSame(
            'The correct answer is Isolate and lock the conveyor, which is a important step.',
            $result['explain_text']
        );
        $this->assertSame(['Isolate', 'Stop', 'Option 3', 'Option 4'], $result['check_question']['options']);
        $this->assertSame(['Correct', 'Incorrect', 'Incorrect', 'Incorrect'], array_column($result['feedback'], 'label'));
        $this->assertNull($result['lesson']);
        $this->assertNull($result['explain_audio_url']);
    }

    /**
     * Translations keep every line break and get narration in that language.
     */
    public function test_translation(): void {
        $this->answers = [json_encode(['lines' => [['index' => 0, 'text' => 'Isolez d\'abord.'],
            ['index' => 2, 'text' => 'Puis verrouillez.']]])];
        $out = (new ai\credits_client())->generate_translations("Isolate first.\n\nThen lock.", ['fr']);
        $this->assertSame("Isolez d'abord.\n\nPuis verrouillez.", $out['fr']['explain_text']);
        $this->assertStringStartsWith('data:audio/ogg;base64,', $out['fr']['audio_url']);
        $this->assertStringContainsString(
            'Translate the following educational remediation text to French.',
            json_encode($this->requests[0][1]['messages'], JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame("Isolez d'abord.\n\nPuis verrouillez.", end($this->requests)[1]['input']);

        // A translation that does not fit the lines is dropped, as on the service.
        $this->expectOutputRegex('/Language fr failed: Invalid translation response: expected 2 translated lines/');
        $this->requests = [];
        $this->answers = [json_encode(['lines' => [['index' => 0, 'text' => 'Seulement une']]])];
        $this->assertSame([], (new ai\credits_client())->generate_translations("One.\nTwo.", ['fr']));
    }

    /**
     * When the site's AI fails, the module fails without charging anything.
     */
    public function test_failure(): void {
        $this->answers = [null, null, null];
        try {
            (new ai\credits_client())->generate_remediation(2, 3, $this->payload());
            $this->fail('Expected a failure');
        } catch (\moodle_exception $e) {
            $this->assertSame('remediationfailed', $e->errorcode);
            $this->assertStringContainsString('Overloaded', $e->debuginfo);
        }
        $this->assertCount(3, $this->requests);
        $this->assertNull(local_aiquizremedial_tts_generate(''));
    }
}
