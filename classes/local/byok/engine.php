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

namespace local_aiquizremedial\local\byok;

/**
 * The LMS Labs service's revision-module routes, ported to run on the site's own AI.
 *
 * POST /api/quiz-remediation/generate (the lesson_v1 tutor lesson, falling back to the older single explanation),
 * /api/quiz-remediation/translate, /api/tts/generate and the remediation_image action of /api/image/generate. The
 * prompts, limits, checks and repairs are the service's; only the calls to the AI go to the site's own AI through
 * the hooks below. The behaviour was compared with the service's code on generated requests and AI answers.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var callable|null fn(string $system, string $user, int $maxtokens, float $temperature, ?array $schema): string */
    public static $text = null;
    /** @var callable|null fn(string $text, string $voiceid, string $language, string $format): string (audio bytes) */
    public static $tts = null;
    /** @var callable|null fn(int $ms): void, for the voice retries (tests make it instant). */
    public static $sleep = null;

    /** @var int REMEDIAL_QUESTION_MAX_CHARS. */
    const QUESTION_MAX = 16000;
    /** @var int REMEDIAL_ANSWER_MAX_CHARS. */
    const ANSWER_MAX = 6000;
    /** @var int LESSON_V1_MAX_TOKENS. */
    const LESSON_MAX_TOKENS = 2500;
    /** @var int The voice's request limit in UTF-8 bytes (splitTextIntoChunks). */
    const TTS_MAX_BYTES = 4800;
    /** @var string The service's voice for revision modules. */
    const VOICE = 'en-AU-Chirp3-HD-Kore';

    /** @var string[] Elements stripHtml() treats as markup. */
    const HTML_ELEMENTS = ['a', 'abbr', 'address', 'article', 'aside', 'audio', 'b', 'blockquote', 'body', 'br', 'button',
        'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hr',
        'html', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label', 'legend', 'li', 'link', 'main', 'mark', 'menu', 'meta',
        'nav', 'noscript', 'ol', 'optgroup', 'option', 'p', 'picture', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'script',
        'section', 'select', 'small', 'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td',
        'template', 'textarea', 'tfoot', 'th', 'thead', 'time', 'title', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr'];

    /** @var string[] Named entities the input cleaner decodes. */
    const ENTITIES = ['amp' => '&', 'apos' => "'", 'bull' => '•', 'copy' => '©', 'divide' => '÷', 'hellip' => '…',
        'gt' => '>', 'ldquo' => '“', 'lsquo' => '‘', 'lt' => '<', 'mdash' => '—', 'ndash' => '–', 'nbsp' => "\u{a0}",
        'quot' => '"', 'rdquo' => '”', 'reg' => '®', 'rsquo' => '’', 'times' => '×', 'trade' => '™'];

    /** @var string[] The legacy route's banned words (bannedHigh). */
    const BANNED = ['crucial', 'delve', 'dive', 'unpack', 'holistic', 'robust', 'synergy', 'paradigm', 'tapestry',
        'multifaceted', 'nuanced', 'pivotal', 'empower', 'leverage', 'realm', 'journey', 'landscape', 'aforementioned',
        'endeavour', 'pertaining', 'henceforth', 'whereby', 'thereof', 'therein', 'notwithstanding'];

    /** @var string[] The legacy route's replacements, in its order. */
    const REPLACEMENTS = ['crucial' => 'important', 'delve' => 'look', 'dive' => 'go', 'unpack' => 'break down',
        'holistic' => 'complete', 'robust' => 'strong', 'utilize' => 'use', 'utilise' => 'use', 'leverage' => 'use',
        'empower' => 'help', 'navigate' => 'handle', 'pivotal' => 'key', 'nuanced' => 'detailed', 'multifaceted' => 'varied',
        'endeavour' => 'effort', 'paramount' => 'top priority', 'comprehensive' => 'thorough', 'facilitate' => 'help with',
        'journey' => 'process', 'landscape' => 'field', 'realm' => 'area', 'foster' => 'support', 'synergy' => 'teamwork',
        'paradigm' => 'model', 'tapestry' => 'mix', 'explore' => 'look at'];

    /** @var string[] Lesson card ids, in order. */
    const IDS = ['tempting', 'principle', 'example', 'hook'];
    /** @var string[] Lesson card headings, in order. */
    const HEADINGS = ['Why it seemed right', 'The key idea', 'See it at work', 'Lock it in'];
    /** @var int[][] Lesson card word bands, in order. */
    const BODY_LIMITS = [[30, 45], [30, 45], [35, 50], [15, 30]];
    /** @var string[] Allowed hook types. */
    const HOOK_TYPES = ['rule', 'contrast', 'image', 'analogy', 'mnemonic'];

    /** @var array[] The translate route's languages: name, voice, language code. */
    const LANGUAGES = [
        'fr' => ['French', 'fr-FR-Wavenet-C', 'fr-FR'],
        'es' => ['Spanish', 'es-ES-Wavenet-C', 'es-ES'],
        'zh' => ['Mandarin Chinese', 'cmn-CN-Wavenet-A', 'cmn-CN'],
        'ar' => ['Arabic', 'ar-XA-Wavenet-A', 'ar-XA'],
        'pt' => ['Portuguese', 'pt-BR-Wavenet-A', 'pt-BR'],
        'de' => ['German', 'de-DE-Wavenet-C', 'de-DE'],
        'ja' => ['Japanese', 'ja-JP-Wavenet-A', 'ja-JP'],
        'ko' => ['Korean', 'ko-KR-Wavenet-A', 'ko-KR'],
        'vi' => ['Vietnamese', 'vi-VN-Wavenet-A', 'vi-VN'],
        'hi' => ['Hindi', 'hi-IN-Wavenet-A', 'hi-IN'],
        'id' => ['Indonesian', 'id-ID-Wavenet-A', 'id-ID'],
        'it' => ['Italian', 'it-IT-Wavenet-A', 'it-IT'],
    ];

    // POST /api/quiz-remediation/generate.

    /**
     * Generate a revision module, as the service's generate route does.
     *
     * @param array $request The body the plugin would send (siteId, payload, responseFormat, locale...).
     * @return array [HTTP status, the service's JSON answer decoded as the plugin decodes it]
     */
    public static function generate(array $request): array {
        $body = json_decode(json_encode($request, JSON_PRESERVE_ZERO_FRACTION));
        $payload = js::get($body, 'payload');
        $question = js::get($payload, 'question');
        $locale = js::get($body, 'locale') ?? 'en-AU';
        if (!self::valid_request($body, $payload, $question, $locale)) {
            return [400, ['success' => false, 'error' => 'Invalid parameters']];
        }
        try {
            $questiontext = self::bounded((string) $question->text_html, 'question');
            $student = '';
            $response = js::get($payload, 'student_response');
            if ($response !== null) {
                if ($response instanceof \stdClass) {
                    $answertext = self::answer(js::get($response, 'answertext'));
                    if (js::len(js::trim($answertext)) > 0) {
                        $student = $answertext;
                    } else {
                        $values = [];
                        foreach (js::keys($response) as $key) {
                            $value = self::answer($response->$key);
                            if ($value !== '') {
                                $values[] = $value;
                            }
                        }
                        $student = implode('; ', $values);
                    }
                } else {
                    $student = self::answer($response);
                }
            }
            $student = self::bounded($student, 'answer');
            $correct = '';
            $right = js::get($payload, 'correct_answer');
            if ($right !== null) {
                if (is_array($right)) {
                    $values = [];
                    foreach ($right as $answer) {
                        $value = $answer instanceof \stdClass && property_exists($answer, 'answer') && $answer->answer !== null
                            ? self::answer($answer->answer) : self::answer($answer);
                        if ($value !== '') {
                            $values[] = $value;
                        }
                    }
                    $correct = implode('; ', $values);
                } else {
                    $correct = self::answer($right);
                }
            }
            $correct = self::bounded($correct, 'answer');
        } catch (\LengthException $e) {
            return [400, ['success' => false, 'error' => $e->getMessage()]];
        }

        try {
            if (js::get($body, 'responseFormat') === 'lesson_v1') {
                $teacher = null;
                foreach ([$payload, $question] as $record) {
                    foreach (['teacher_feedback', 'teacherFeedback', 'generalfeedback', 'correctfeedback', 'feedback'] as $k) {
                        if ($teacher === null) {
                            $teacher = js::get($record, $k);
                        }
                    }
                }
                $source = ['locale' => $locale, 'quiz' => (string) $payload->quiz->name, 'question' => $questiontext,
                    'questionOptions' => js::get($question, 'options'), 'studentAnswer' => $student,
                    'correctAnswer' => $correct, 'teacherFeedback' => $teacher];
                $lesson = self::lesson($source);
                if ($lesson !== null) {
                    $answer = new \stdClass();
                    $answer->success = true;
                    foreach (get_object_vars($lesson) as $k => $v) {
                        $answer->$k = $v;
                    }
                    return [200, json_decode(js::stringify($answer), true)];
                }
            }
            return [200, json_decode(js::stringify(self::legacy(
                (string) $question->qtype,
                (string) $payload->quiz->name,
                $questiontext,
                $student,
                $correct
            )), true)];
        } catch (\Throwable $e) {
            return [500, ['success' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Generation failed']];
        }
    }

    /**
     * The route's request schema, for the fields it reads.
     *
     * @param mixed $body
     * @param mixed $payload
     * @param mixed $question
     * @param mixed $locale
     * @return bool
     */
    protected static function valid_request($body, $payload, $question, $locale): bool {
        $number = fn($v) => is_int($v) || is_float($v);
        $quiz = js::get($payload, 'quiz');
        $attempt = js::get($payload, 'attempt');
        return is_string(js::get($body, 'siteId')) && is_string($locale) && js::len($locale) >= 1 && js::len($locale) <= 35
            && $payload instanceof \stdClass && $quiz instanceof \stdClass && $number(js::get($quiz, 'id'))
            && is_string(js::get($quiz, 'name')) && $attempt instanceof \stdClass && $number(js::get($attempt, 'id'))
            && $number(js::get($attempt, 'attempt')) && $question instanceof \stdClass && $number(js::get($question, 'id'))
            && is_string(js::get($question, 'qtype')) && is_string(js::get($question, 'text_html'))
            && (js::get($question, 'name') === null || is_string($question->name))
            && property_exists($payload, 'fraction') && ($payload->fraction === null || $number($payload->fraction))
            && (js::get($body, 'responseFormat') === null || is_string($body->responseFormat));
    }

    /**
     * The older single-explanation module (the route's legacy pipeline).
     *
     * @param string $qtype
     * @param string $quiz
     * @param string $question
     * @param string $student
     * @param string $correct
     * @return \stdClass The route's answer.
     */
    public static function legacy(string $qtype, string $quiz, string $question, string $student, string $correct): \stdClass {
        $tf = $qtype === 'truefalse';
        $matching = $qtype === 'matching';
        $n = $tf ? 2 : 4;
        $check = $tf ? prompts::CHECK_TF : strtr(
            prompts::CHECK_MC,
            ['%%prompt%%' => $matching ? prompts::CHECK_MATCHING : prompts::CHECK_REWORD]
        );
        $system = strtr(prompts::LEGACY_SYSTEM, [
            '%%correct%%' => $correct !== '' ? $correct : 'the correct option',
            '%%student%%' => $student !== '' ? $student : 'Your answer',
            '%%studentlower%%' => $student !== '' ? $student : 'your answer',
            '%%check%%' => $check,
            '%%feedback%%' => $tf ? prompts::FEEDBACK_TF : prompts::FEEDBACK_MC,
            '%%n%%' => (string) $n,
            '%%rules%%' => $tf ? prompts::RULES_TF : ($matching ? prompts::RULES_MATCHING : ''),
        ]);
        $user = strtr(prompts::LEGACY_USER, ['%%json%%' => js::stringify((object) ['quiz' => $quiz,
            'question' => $question, 'student_answer' => $student !== '' ? $student : '(no answer)',
            'correct_answer' => $correct !== '' ? $correct : '(not available)'])]);
        $reply = (self::$text)($system, $user, 1500, 0.2, null);
        if ($reply === '') {
            throw new \RuntimeException('Empty AI response');
        }
        try {
            $ai = js::parse($reply);
        } catch (\RuntimeException $e) {
            if (!preg_match('/\{.*\}/s', $reply, $m)) {
                throw new \RuntimeException('Failed to parse AI response as JSON');
            }
            $ai = js::parse($m[0]);
        }
        if ($ai === null) {
            throw new \RuntimeException("Cannot read properties of null (reading 'explain_text')");
        }
        $explain = js::get($ai, 'explain_text');
        if (!js::truthy($explain) || js::len(js::str($explain)) < 25) {
            throw new \RuntimeException('Explanation not generated or too short');
        }
        $cq = js::get($ai, 'check_question');
        if (!js::truthy($cq)) {
            throw new \RuntimeException('AI response missing check_question');
        }
        $prompt = js::get($cq, 'prompt');
        if (!js::truthy($prompt) || js::len(js::trim(js::str($prompt))) < 10) {
            throw new \RuntimeException('check_question prompt is missing or too short');
        }
        // A check question with a prompt is an object from here on.
        $options = $cq->options ?? null;
        if (!is_array($options) || count($options) !== $n) {
            if ($tf && is_array($options) && count($options) >= 2) {
                $cq->options = ['True', 'False'];
            } else if (!$tf && is_array($options) && count($options) === 2) {
                while (count($cq->options) < 4) {
                    $cq->options[] = 'Option ' . (count($cq->options) + 1);
                }
            } else {
                throw new \RuntimeException("check_question must have exactly {$n} options");
            }
        }
        if ($tf) {
            $cq->options = ['True', 'False'];
        }
        $index = $cq->correct_index ?? null;
        if (!(is_int($index) || is_float($index)) || $index > $n - 1 || $index < 0) {
            $cq->correct_index = 0;
        }
        $feedback = js::get($ai, 'feedback');
        if (!is_array($feedback)) {
            $feedback = [];
        }
        while (count($feedback) < $n) {
            $feedback[] = (object) ['label' => 'Incorrect', 'explain' => 'Review the explanation above for more detail.'];
        }
        $feedback = array_slice($feedback, 0, $n);
        $index = $cq->correct_index;
        foreach ($feedback as $i => $item) {
            if ($item instanceof \stdClass) {
                $item->label = self::same_number($i, $index) ? 'Correct' : 'Incorrect';
            } else if ($item === null) {
                throw new \RuntimeException("Cannot set properties of null (setting 'label')");
            } else if (!is_array($item)) {
                $type = is_string($item) ? 'string' : (is_bool($item) ? 'boolean' : 'number');
                throw new \RuntimeException("Cannot create property 'label' on {$type} '" . js::str($item) . "'");
            }
        }
        $voice = js::get($ai, 'voiceover_script');
        if (js::truthy($voice)) {
            if (!is_string($voice)) {
                throw new \RuntimeException('parsed_ai.voiceover_script.replace is not a function');
            }
            $voice = js::trim(preg_replace('/[' . js::WS . ']{2,}/u', ' ', str_replace("\n", ' ', $voice)));
        }
        if (!is_string($explain)) {
            throw new \RuntimeException('parsed_ai.explain_text.replace is not a function');
        }
        $explain = preg_replace('/^Here is what you need to know\.' . js::WSB . '*/i', '', $explain, 1);
        $explain = js::trim(preg_replace(
            '/^Listen' . js::WSB . '*&' . js::WSB . '*Learn' . js::WSB . '*:?' . js::WSB . '*\n?/i',
            '',
            $explain,
            1
        ));
        $ai->explain_text = $explain;
        if (property_exists($ai, 'voiceover_script')) {
            $ai->voiceover_script = $voice;
        }
        $ai->feedback = $feedback;
        $all = js::lower(js::stringify($ai));
        $found = array_filter(self::BANNED, fn($w) => strpos($all, $w) !== false);
        if ($found) {
            $clean = function (string $s): string {
                foreach (self::REPLACEMENTS as $bad => $good) {
                    $s = preg_replace('/' . $bad . '/i', $good, $s);
                }
                return $s;
            };
            $explain = $clean($explain);
            if (js::truthy($voice)) {
                $voice = $clean($voice);
            }
            foreach ($feedback as $item) {
                $text = js::get($item, 'explain');
                if (js::truthy($text)) {
                    if (!is_string($text)) {
                        throw new \RuntimeException('fb.explain.replace is not a function');
                    }
                    $item->explain = $clean($text);
                }
            }
        }
        return (object) ['success' => true, 'explain_text' => $explain, 'voiceover_script' => js::truthy($voice) ? $voice : null,
            'check_question' => $cq, 'feedback' => $feedback];
    }

    // The lesson_v1 tutor lesson (server/lib/remedialLesson.ts).

    /**
     * generateLessonV1(): up to two calls, the second repairing the first; null when neither passes.
     *
     * @param array $source quiz, question, questionOptions, studentAnswer, correctAnswer, teacherFeedback, locale
     * @return \stdClass|null
     */
    public static function lesson(array $source): ?\stdClass {
        $diagnostics = null;
        $draft = null;
        $schema = json_decode(json_encode(self::lesson_schema()), true);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                [$system, $user] = self::lesson_messages($source, $diagnostics, $draft);
                $raw = (self::$text)($system, $user, self::LESSON_MAX_TOKENS, 0.4, $schema);
                $draft = $raw;
                $parsed = self::lesson_json($raw);
                $diagnostics = self::validate($parsed, $source['locale'] ?: 'en-AU', $source);
                if (!$diagnostics) {
                    $parsed->voiceover_script = $parsed->tts_script;
                    $index = $parsed->check_question->correct_index;
                    $feedback = [];
                    foreach ($parsed->feedback as $i => $item) {
                        $copy = clone $item;
                        $copy->label = $i === $index || (is_float($index) && $i == $index) ? 'Correct' : 'Incorrect';
                        $feedback[] = $copy;
                    }
                    $parsed->feedback = $feedback;
                    return $parsed;
                }
            } catch (\Throwable $e) {
                $diagnostics = [$e->getMessage() !== '' ? $e->getMessage() : 'unknown validation error'];
            }
        }
        return null;
    }

    /**
     * buildLessonV1Messages(), as one system and one user message.
     *
     * The service sends a repair as a further turn after its own draft; the site's AI takes one request, so the draft
     * goes into the request, followed by the service's repair instructions.
     *
     * @param array $source
     * @param array|null $diagnostics
     * @param string|null $draft
     * @return string[] [system, user]
     */
    public static function lesson_messages(array $source, ?array $diagnostics = null, ?string $draft = null): array {
        $locale = $source['locale'] ?: 'en-AU';
        $system = strtr(prompts::LESSON_SYSTEM_TAIL, ['%%base%%' => prompts::LESSON_SYSTEM, '%%locale%%' => $locale,
            '%%reference%%' => self::dementia($source) ? prompts::LESSON_REFERENCE : '']);
        $json = new \stdClass();
        $json->locale = $locale;
        $json->quiz = $source['quiz'];
        $json->question = $source['question'];
        if ($source['questionOptions'] !== null) {
            $json->question_options = $source['questionOptions'];
        }
        $json->student_answer = $source['studentAnswer'] !== '' ? $source['studentAnswer'] : '(no answer)';
        $json->correct_answer = $source['correctAnswer'] !== '' ? $source['correctAnswer'] : '(not available)';
        if ($source['teacherFeedback'] !== null) {
            $json->teacher_feedback = $source['teacherFeedback'];
        }
        $user = strtr(prompts::LESSON_USER, ['%%json%%' => js::stringify($json)]);
        if ($diagnostics && $draft !== null) {
            $user .= "\n\nYour previous JSON draft:\n" . $draft . "\n\n" .
                strtr(prompts::LESSON_REPAIR, ['%%diagnostics%%' => implode("\n- ", $diagnostics)]);
        }
        return [$system, $user];
    }

    /**
     * The lesson's parseJson().
     *
     * @param string $raw
     * @return mixed
     */
    protected static function lesson_json(string $raw) {
        try {
            return js::parse($raw);
        } catch (\RuntimeException $e) {
            if (!preg_match('/\{.*\}/s', $raw, $m)) {
                throw new \RuntimeException('response is not JSON');
            }
            return js::parse($m[0]);
        }
    }

    /**
     * Whether the source is the service's dementia reference case.
     *
     * @param array $source
     * @return bool
     */
    protected static function dementia(array $source): bool {
        $all = js::lower($source['question'] . ' ' . $source['studentAnswer'] . ' ' . $source['correctAnswer']);
        foreach (['mrs nguyen', 'dementia', 'shower', 'care plan', 'try later'] as $needle) {
            if (strpos($all, $needle) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * validateLessonV1(): what is wrong with a lesson, in the service's words.
     *
     * @param mixed $root
     * @param string $locale
     * @param array|null $source
     * @return string[]
     */
    public static function validate($root, string $locale = 'en-AU', ?array $source = null): array {
        $errors = [];
        if (!$root instanceof \stdClass) {
            return ['response must be an object'];
        }
        $lesson = js::get($root, 'lesson');
        if (!js::truthy($lesson) || !($lesson instanceof \stdClass || is_array($lesson))) {
            return ['lesson must be an object'];
        }
        $fields = [
            ['lesson.misconception_diagnosis', 'misconception_diagnosis', 1, 25],
            ['lesson.answer_strip', 'answer_strip', 1, 20],
            ['lesson.takeaway', 'takeaway', 1, 15],
        ];
        foreach ($fields as [$path, $k, $min, $max]) {
            $text = js::get($lesson, $k);
            self::text_rules($errors, $text, $path, $locale, $min, $max);
        }
        $cards = js::get($lesson, 'cards');
        if (!is_array($cards) || count($cards) !== 4) {
            $errors[] = 'lesson.cards must contain exactly 4 cards';
        } else {
            foreach ($cards as $i => $card) {
                if (!js::truthy($card) || js::get($card, 'id') !== self::IDS[$i]) {
                    $errors[] = "lesson.cards[{$i}].id must be " . self::IDS[$i];
                }
                if (!js::truthy($card) || js::get($card, 'heading') !== self::HEADINGS[$i]) {
                    $errors[] = "lesson.cards[{$i}].heading must be \"" . self::HEADINGS[$i] . '"';
                }
                self::text_rules(
                    $errors,
                    js::get($card, 'body'),
                    "lesson.cards[{$i}].body",
                    $locale,
                    self::BODY_LIMITS[$i][0],
                    self::BODY_LIMITS[$i][1]
                );
                $hook = js::get($card, 'hook_type');
                if ($i === 3 && !in_array($hook, self::HOOK_TYPES, true)) {
                    $errors[] = 'lesson.cards[3].hook_type is invalid';
                }
                if ($i !== 3 && $hook !== null) {
                    $errors[] = "lesson.cards[{$i}].hook_type must be null or omitted";
                }
            }
        }

        $check = js::get($root, 'check_question');
        self::text_rules($errors, js::get($check, 'prompt'), 'check_question.prompt', $locale, 1, 40);
        $options = js::get($check, 'options');
        if (!is_array($options) || count($options) < 3 || count($options) > 4) {
            $errors[] = 'check_question.options must contain 3-4 options';
        } else {
            foreach ($options as $i => $option) {
                self::text_rules($errors, $option, "check_question.options[{$i}]", $locale, 1, 20);
            }
        }
        $index = js::get($check, 'correct_index');
        $count = js::get($options, 'length');
        $limit = js::truthy($count) ? $count : 0;
        if (!js::is_integer($index) || $index < 0 || self::at_least($index, $limit)) {
            $errors[] = 'check_question.correct_index must identify one supplied option';
        }

        $feedback = js::get($root, 'feedback');
        if (!is_array($feedback)) {
            $errors[] = 'feedback must match the options count and order';
        } else {
            if (!self::same_number(count($feedback), $count)) {
                $errors[] = 'feedback must match the options count and order';
            }
            foreach ($feedback as $i => $item) {
                $explain = js::get($item, 'explain');
                self::text_rules($errors, $explain, "feedback[{$i}].explain", $locale, 1, 30);
                if ($check === null) {
                    throw new \RuntimeException(js::cannot_read('correct_index'));
                }
                $correct = self::same_number($i, js::get($check, 'correct_index'));
                if ($correct) {
                    if ($item === null) {
                        $errors[] = "feedback[{$i}].explain must start with \"That's correct.\"";
                    } else if ($explain === null) {
                        throw new \RuntimeException(js::cannot_read('startsWith'));
                    } else if (!is_string($explain)) {
                        throw new \RuntimeException('item?.explain.startsWith is not a function');
                    } else if (strpos($explain, "That's correct.") !== 0) {
                        $errors[] = "feedback[{$i}].explain must start with \"That's correct.\"";
                    }
                } else if ($explain !== null) {
                    if (!is_string($explain)) {
                        throw new \RuntimeException('item?.explain?.startsWith is not a function');
                    }
                    if (strpos($explain, "That's correct.") === 0) {
                        $errors[] = "feedback[{$i}].explain incorrectly marks a distractor correct";
                    }
                }
            }
        }

        $tts = js::get($root, 'tts_script');
        $actual = is_string($tts) ? js::trim(preg_replace('/[' . js::WS . ']+/u', ' ', $tts)) : '';
        $issue = self::plain_issue($tts, 'tts_script');
        if ($issue !== null) {
            $errors[] = $issue;
        }
        if (self::words($actual, $locale) > 230) {
            $errors[] = 'tts_script exceeds 230 words';
        }
        if (preg_match('/[\[\]{}<>#*_]/', $actual)) {
            $errors[] = 'tts_script contains symbols or brackets';
        }
        if (preg_match('/\b(?:Mr|Mrs|Dr)\.?(?=' . js::WSB . '+[A-Z])|\b(?:e\.g|i\.e)\.(?=' . js::WSB . '|$)/iD', $actual, $m)) {
            $errors[] = "tts_script contains unexpanded spoken abbreviation \"{$m[0]}\"";
        }
        array_push($errors, ...self::sentence_issues($actual, $locale, 'tts_script'));
        foreach (self::HEADINGS as $heading) {
            if (strpos(js::lower($actual), js::lower($heading)) !== false) {
                $errors[] = "tts_script must not narrate the heading \"{$heading}\"";
            }
        }
        if (!self::strict_equal(js::get($root, 'explain_text'), $tts, $root)) {
            $errors[] = 'explain_text must be identical to tts_script';
        }
        $spoken = self::narration($actual);
        $diagnosis = js::get($lesson, 'misconception_diagnosis');
        $diagnosis = self::narration(js::str(js::truthy($diagnosis) ? $diagnosis : ''));
        if ($diagnosis !== '' && strpos($spoken, $diagnosis) !== false) {
            $errors[] = 'misconception_diagnosis must not appear in narration';
        }
        if ($source && self::dementia($source)) {
            $or = fn($v) => js::str(js::truthy($v) ? $v : '');
            $hook = self::narration($or(js::get(js::get($cards, 3), 'body')));
            $example = self::narration($or(js::get(js::get($cards, 2), 'body')));
            $prompt = self::narration($or(js::get($check, 'prompt')));
            if (strpos($hook, 'stop sign') === false || strpos($hook, 'speed bump') === false) {
                $errors[] = 'supplied dementia test hook must include both "stop sign" and "speed bump"';
            }
            if (strpos($example, 'mr rossi') === false || strpos($example, 'lunch') === false) {
                $errors[] = 'supplied dementia test example must use Mr Rossi and lunch';
            }
            if (strpos($prompt, 'mrs kaur') === false || strpos($prompt, 'tablet') === false) {
                $errors[] = 'supplied dementia test check question must use Mrs Kaur and tablets';
            }
        }
        return $errors;
    }

    /**
     * explain_text !== tts_script, telling a missing explain_text from a null one.
     *
     * @param mixed $explain
     * @param mixed $tts
     * @param \stdClass $root
     * @return bool Whether they are the same.
     */
    protected static function strict_equal($explain, $tts, \stdClass $root): bool {
        if ($explain === null && $tts === null) {
            // Undefined === undefined and null === null; undefined !== null.
            return property_exists($root, 'explain_text') === property_exists($root, 'tts_script');
        }
        if ((is_int($explain) || is_float($explain)) && (is_int($tts) || is_float($tts))) {
            return $explain == $tts;
        }
        if (is_array($explain) || $explain instanceof \stdClass) {
            return false;
        }
        return $explain === $tts;
    }

    /**
     * index >= length, for a length that may not be a number.
     *
     * @param int|float $index
     * @param mixed $length
     * @return bool
     */
    protected static function at_least($index, $length): bool {
        if (is_bool($length)) {
            $length = (int) $length;
        }
        if (is_string($length)) {
            $t = js::trim($length);
            $length = $t === '' ? 0 : (is_numeric($t) && !preg_match('/^0[xob]/i', $t) ? (float) $t : NAN);
        }
        if (!is_int($length) && !is_float($length)) {
            return false;
        }
        return !is_nan((float) $length) && $index >= $length;
    }

    /**
     * a === b for numbers.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected static function same_number($a, $b): bool {
        return (is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) && $a == $b;
    }

    /**
     * plainTextIssue(), then the word band and sentence lengths when the text is fine.
     *
     * @param string[] $errors
     * @param mixed $text
     * @param string $path
     * @param string $locale
     * @param int $min
     * @param int $max
     */
    protected static function text_rules(array &$errors, $text, string $path, string $locale, int $min, int $max): void {
        $issue = self::plain_issue($text, $path);
        if ($issue !== null) {
            $errors[] = $issue;
            return;
        }
        $count = self::words($text, $locale);
        if ($count < $min || $count > $max) {
            $errors[] = "{$path} has {$count} words; expected {$min}-{$max}";
        }
        array_push($errors, ...self::sentence_issues($text, $locale, $path));
    }

    /**
     * plainTextIssue().
     *
     * @param mixed $value
     * @param string $path
     * @return string|null
     */
    protected static function plain_issue($value, string $path): ?string {
        if (!is_string($value) || js::trim($value) === '') {
            return "{$path} must be non-empty text";
        }
        if (preg_match('/<\/?[a-z][^>]*>/i', $value)) {
            return "{$path} contains HTML";
        }
        if (
            preg_match(
                '/\x60{3}|^' . js::WSB . '{0,3}#{1,6}' . js::WSB . '|^' . js::WSB . '*[-*+]' . js::WSB . '/m',
                self::js_lines($value)
            )
        ) {
            return "{$path} contains markdown";
        }
        return null;
    }

    /**
     * Line ends as JavaScript's multiline ^ sees them: \n, \r, U+2028 and U+2029 all start a line.
     *
     * @param string $s
     * @return string
     */
    protected static function js_lines(string $s): string {
        return str_replace(["\r", "\u{2028}", "\u{2029}"], ["\n", "\n", "\n"], $s);
    }

    /**
     * Word count by Intl.Segmenter (word-like segments).
     *
     * @param string $value
     * @param string $locale
     * @return int
     */
    public static function words(string $value, string $locale = 'en-AU'): int {
        if ($value === '') {
            return 0;
        }
        $it = \IntlBreakIterator::createWordInstance($locale);
        $it->setText($value);
        $count = 0;
        $it->first();
        while ($it->next() !== \IntlBreakIterator::DONE) {
            if ($it->getRuleStatus() >= 100) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Sentences by Intl.Segmenter, trimmed, without empty ones.
     *
     * @param string $value
     * @param string $locale
     * @return string[]
     */
    public static function sentences(string $value, string $locale = 'en-AU'): array {
        $it = \IntlBreakIterator::createSentenceInstance($locale);
        $it->setText($value);
        $out = [];
        $start = $it->first();
        while (($end = $it->next()) !== \IntlBreakIterator::DONE) {
            $part = js::trim(substr($value, $start, $end - $start));
            if ($part !== '') {
                $out[] = $part;
            }
            $start = $end;
        }
        return $out;
    }

    /**
     * sentenceLimitIssues(): sentences over 15 words.
     *
     * @param string $value
     * @param string $locale
     * @param string $path
     * @return string[]
     */
    protected static function sentence_issues(string $value, string $locale, string $path): array {
        $out = [];
        foreach (self::sentences($value, $locale) as $i => $sentence) {
            $count = self::words($sentence, $locale);
            if ($count > 15) {
                $out[] = "{$path} sentence " . ($i + 1) . " has {$count} words; maximum is 15";
            }
        }
        return $out;
    }

    /**
     * normalisedNarrationText().
     *
     * @param string $value
     * @return string
     */
    protected static function narration(string $value): string {
        return js::trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', js::lower($value)));
    }

    /**
     * LESSON_V1_RESPONSE_FORMAT's schema, for structured output.
     *
     * @return array ['name' => ..., 'schema' => ...]
     */
    public static function lesson_schema(): array {
        $text = ['type' => 'string'];
        return ['name' => 'remedial_lesson_v1', 'schema' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['lesson', 'check_question', 'feedback', 'tts_script', 'explain_text'],
            'properties' => [
                'lesson' => [
                    'type' => 'object',
                    'description' => 'Write this first. Diagnose the misconception before composing learner-facing content.',
                    'additionalProperties' => false,
                    'required' => ['misconception_diagnosis', 'answer_strip', 'cards', 'takeaway'],
                    'properties' => [
                        'misconception_diagnosis' => $text + ['description' => 'Teacher-only diagnosis, 1-25 words.'],
                        'answer_strip' => $text + ['description' => 'Neutral answer strip, 1-20 words.'],
                        'cards' => [
                            'type' => 'array',
                            'description' => 'Exactly four ordered cards. Aim near each word band\'s midpoint, not its minimum.',
                            'minItems' => 4, 'maxItems' => 4,
                            'items' => [
                                'type' => 'object', 'additionalProperties' => false,
                                'required' => ['id', 'heading', 'body', 'hook_type'],
                                'properties' => [
                                    'id' => $text + ['enum' => self::IDS],
                                    'heading' => $text,
                                    'body' => $text + ['description' => 'Required bands by id: tempting 30-45; principle 30-45; ' .
                                        'example 35-50; hook 15-30 words. Aim for 38, 38, 42, and 22 words.'],
                                    'hook_type' => ['type' => ['string', 'null'],
                                        'enum' => ['rule', 'contrast', 'image', 'analogy', 'mnemonic', null]],
                                ],
                            ],
                        ],
                        'takeaway' => $text + ['description' => 'The hook as one line, 1-15 words.'],
                    ],
                ],
                'check_question' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['prompt', 'options', 'correct_index'],
                    'properties' => [
                        'prompt' => $text,
                        'options' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 4, 'items' => $text],
                        'correct_index' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3],
                    ],
                ],
                'feedback' => [
                    'type' => 'array', 'minItems' => 3, 'maxItems' => 4,
                    'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['explain'],
                        'properties' => ['explain' => $text]],
                ],
                'tts_script' => $text + ['description' => 'Natural spoken version of the answer strip, four bodies and ' .
                    'takeaway; at most 230 words. Expand abbreviations and omit headings and brackets.'],
                'explain_text' => $text + ['description' => 'Exactly identical to tts_script.'],
            ],
        ]];
    }

    // Request input (server/lib/remedialTextInput.ts).

    /**
     * stringifyRemedialAnswer().
     *
     * @param mixed $value
     * @return string
     */
    public static function answer($value): string {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return js::str($value);
        }
        return js::stringify($value);
    }

    /**
     * boundedRemedialTextInput().
     *
     * @param string $value
     * @param string $field question or answer
     * @return string
     */
    public static function bounded(string $value, string $field): string {
        $clean = self::clean($value);
        $max = $field === 'question' ? self::QUESTION_MAX : self::ANSWER_MAX;
        if (js::len($clean) > $max) {
            throw new \LengthException(($field === 'question' ? 'Question content' : 'Answer content') .
                " is too long. Please shorten it to {$max} characters or fewer.");
        }
        return $clean;
    }

    /**
     * cleanRemedialTextInput(): markup stripped, entities decoded, "VOICEOVER:" removed, whitespace collapsed.
     *
     * @param string $value
     * @return string
     */
    public static function clean(string $value): string {
        $text = self::entities(self::strip($value));
        $text = preg_replace('/VOICEOVER' . js::WSB . '*:/i', '', $text);
        return js::trim(preg_replace('/[' . js::WS . ']+/u', ' ', $text));
    }

    /**
     * stripHtml(): known elements and comments become a space; anything else is kept as text.
     *
     * @param string $text
     * @return string
     */
    protected static function strip(string $text): string {
        $out = '';
        $cursor = 0;
        $n = strlen($text);
        while ($cursor < $n) {
            if (substr_compare($text, '<!--', $cursor, 4) === 0) {
                $end = strpos($text, '-->', $cursor + 4);
                $out .= ' ';
                $cursor = $end === false ? $n : $end + 3;
                continue;
            }
            if ($text[$cursor] !== '<') {
                $out .= $text[$cursor++];
                continue;
            }
            if (
                !preg_match('/^\/?([a-z][a-z0-9:-]*)\b/i', substr($text, $cursor + 1, 200), $m)
                    || !in_array(strtolower($m[1]), self::HTML_ELEMENTS, true)
            ) {
                $out .= $text[$cursor++];
                continue;
            }
            $name = strtolower($m[1]);
            $end = $cursor + 1 + strlen($m[0]);
            $quote = '';
            for (; $end < $n; $end++) {
                $c = $text[$end];
                if ($quote !== '') {
                    if ($c === $quote) {
                        $quote = '';
                    }
                } else if ($c === '"' || $c === "'") {
                    $quote = $c;
                } else if ($c === '>') {
                    break;
                }
            }
            if ($end >= $n) {
                if ($name === 'script' || $name === 'style') {
                    $out .= ' ';
                    $cursor = $n;
                    continue;
                }
                $out .= $text[$cursor++];
                continue;
            }
            $out .= ' ';
            $cursor = $end + 1;
            if (($name === 'script' || $name === 'style') && $m[0][0] !== '/') {
                $close = self::lower_index($text, '</' . $name, $cursor);
                if ($close === false) {
                    $cursor = $n;
                    continue;
                }
                $closeend = strpos($text, '>', $close + strlen($name) + 2);
                $cursor = $closeend === false ? $n : $closeend + 1;
            }
        }
        return $out;
    }

    /**
     * text.toLowerCase().indexOf(needle, from) used as a position in text, as stripHtml() does.
     *
     * Lowercasing can change a string's length (İ becomes two characters), which shifts the position; this keeps
     * the service's arithmetic, in UTF-16 code units.
     *
     * @param string $text
     * @param string $needle ASCII
     * @param int $from byte offset in $text
     * @return int|false byte offset in $text
     */
    protected static function lower_index(string $text, string $needle, int $from) {
        $lower = js::lower($text);
        if (strlen($lower) === strlen($text)) {
            return stripos($text, $needle, $from);
        }
        $start = strlen(js::slice($lower, 0, js::len(substr($text, 0, $from))));
        $pos = strpos($lower, $needle, $start);
        if ($pos === false) {
            return false;
        }
        return strlen(js::slice($text, 0, js::len(substr($lower, 0, $pos))));
    }

    /**
     * The input cleaner's decodeHtmlEntities().
     *
     * @param string $text
     * @return string
     */
    protected static function entities(string $text): string {
        return preg_replace_callback('/&(?:#(\d{1,7})|#x([0-9a-f]{1,6})|([a-z]+));/i', function ($m) {
            if (($m[3] ?? '') !== '') {
                return self::ENTITIES[strtolower($m[3])] ?? $m[0];
            }
            $code = ($m[1] ?? '') !== '' ? (int) $m[1] : hexdec($m[2]);
            if ($code <= 0 || $code > 0x10ffff || ($code >= 0xd800 && $code <= 0xdfff)) {
                return $m[0];
            }
            return mb_chr($code, 'UTF-8');
        }, $text);
    }

    // POST /api/quiz-remediation/translate (server/lib/remedialTranslation.ts).

    /**
     * Translate a module's text and narrate it in that language, as the translate route does.
     *
     * @param string $text
     * @param string $language A language code, such as fr.
     * @return array [HTTP status, answer]
     */
    public static function translate(string $text, string $language): array {
        $length = js::len($text);
        $codelength = js::len($language);
        if ($length < 1 || $length > 20000 || $codelength < 2 || $codelength > 10) {
            return [400, ['success' => false, 'error' => 'Invalid request parameters']];
        }
        if (!isset(self::LANGUAGES[$language])) {
            return [400, ['success' => false, 'error' => "Unsupported language: {$language}"]];
        }
        [$name, $voice, $code] = self::LANGUAGES[$language];
        try {
            $reply = (self::$text)('', self::translation_prompt($text, $name), 4000, 0.3, null);
            $translated = self::reconstruct($text, $reply);
            [$audio, , $mime] = self::speak($translated, $voice, $code);
            return [200, ['success' => true, 'translated_text' => $translated,
                'audio_url' => 'data:' . $mime . ';base64,' . base64_encode($audio)]];
        } catch (\Throwable $e) {
            return [500, ['success' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Translation failed']];
        }
    }

    /**
     * The text's lines (split keeping \r\n, \n and \r), with the ones to translate.
     *
     * @param string $text
     * @return array [parts (even indexes are lines), lines to translate as [index, text]]
     */
    protected static function lines(string $text): array {
        $parts = preg_split('/(\r\n|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $lines = [];
        for ($p = 0, $i = 0; $p < count($parts); $p += 2, $i++) {
            $t = js::trim($parts[$p]);
            if (js::len($t) > 0 && $t !== '-') {
                $lines[] = [$i, $parts[$p]];
            }
        }
        return [$parts, $lines];
    }

    /**
     * buildRemedialTranslationPrompt().
     *
     * @param string $text
     * @param string $language The language's English name.
     * @return string
     */
    public static function translation_prompt(string $text, string $language): string {
        $items = array_map(fn($l) => (object) ['index' => $l[0], 'text' => $l[1]], self::lines($text)[1]);
        return strtr(prompts::TRANSLATE, ['%%language%%' => $language, '%%structure%%' => prompts::TRANSLATE_STRUCTURE,
            '%%lines%%' => js::stringify($items)]);
    }

    /**
     * reconstructRemedialTranslation(): the translated lines put back between the original line breaks.
     *
     * @param string $source
     * @param string $reply
     * @return string
     */
    public static function reconstruct(string $source, string $reply): string {
        $fail = fn(string $why) => new \RuntimeException('Invalid translation response: ' . $why);
        try {
            $decoded = js::parse($reply);
        } catch (\RuntimeException $e) {
            throw $fail('expected JSON');
        }
        $returned = js::get($decoded, 'lines');
        if (!$decoded instanceof \stdClass || !is_array($returned)) {
            throw $fail('expected an object containing a "lines" array');
        }
        [$parts, $expected] = self::lines($source);
        if (count($returned) !== count($expected)) {
            throw $fail('expected ' . count($expected) . ' translated lines but received ' . count($returned));
        }
        $translations = [];
        foreach ($returned as $position => $item) {
            $index = js::get($item, 'index');
            $text = js::get($item, 'text');
            if (!$item instanceof \stdClass || !js::is_integer($index) || !is_string($text)) {
                throw $fail("line item {$position} has an invalid shape");
            }
            $index = js::number($index);
            if ($index !== (string) $expected[$position][0]) {
                throw $fail("expected line index {$expected[$position][0]} at position {$position}, received {$index}");
            }
            if (js::len(js::trim($text)) === 0 || preg_match('/[\r\n]/', $text)) {
                throw $fail("translation for line index {$index} must be one non-empty line");
            }
            if (array_key_exists($index, $translations)) {
                throw $fail("duplicate line index {$index}");
            }
            $translations[$index] = $text;
        }
        for ($p = 0, $i = 0; $p < count($parts); $p += 2, $i++) {
            if (array_key_exists($i, $translations)) {
                $parts[$p] = $translations[$i];
            }
        }
        return implode('', $parts);
    }

    // POST /api/tts/generate.

    /**
     * Narrate text as the TTS route does: a data: URL with Ogg Opus audio, or MP3 when the text is long.
     *
     * @param string $text
     * @return array [HTTP status, answer]
     */
    public static function tts(string $text): array {
        $length = js::len($text);
        if ($length < 1 || $length > 20000) {
            return [400, ['success' => false, 'error' => 'Invalid request parameters']];
        }
        try {
            [$audio, , $mime] = self::speak($text, self::VOICE, 'en-AU');
            return [200, ['success' => true, 'audio_url' => 'data:' . $mime . ';base64,' . base64_encode($audio)]];
        } catch (\Throwable $e) {
            return [500, ['success' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'TTS generation failed']];
        }
    }

    /**
     * The service's generateVoiceover(): one Ogg Opus clip, or MP3 pieces joined when the text is long.
     *
     * @param string $text
     * @param string $voiceid
     * @param string $language
     * @return array [audio bytes, seconds (0 when not measured), mime type]
     */
    public static function speak(string $text, string $voiceid, string $language): array {
        $chunks = self::chunks($text, self::TTS_MAX_BYTES);
        if (count($chunks) === 1) {
            return [self::synthesise($chunks[0], $voiceid, $language, 'ogg'), 0, 'audio/ogg'];
        }
        $audio = '';
        foreach ($chunks as $chunk) {
            $audio .= self::synthesise($chunk, $voiceid, $language, 'mp3');
        }
        return [$audio, 0, 'audio/mpeg'];
    }

    /**
     * One request to the voice, tried up to four times as the service does.
     *
     * @param string $text
     * @param string $voiceid
     * @param string $language
     * @param string $format ogg or mp3.
     * @return string
     */
    protected static function synthesise(string $text, string $voiceid, string $language, string $format): string {
        $wait = 1000;
        for ($attempt = 1;; $attempt++) {
            try {
                $audio = (self::$tts)($text, $voiceid, $language, $format);
                if ($audio === '') {
                    throw new \RuntimeException('No audio content in TTS response');
                }
                return $audio;
            } catch (\Throwable $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
                $sleep = self::$sleep ?? function (int $ms): void {
                    usleep($ms * 1000);
                };
                $sleep(min(5000, $wait));
                $wait *= 2;
            }
        }
    }

    /**
     * The service's splitTextIntoChunks(): sentences packed into pieces of at most $max UTF-8 bytes.
     *
     * @param string $text
     * @param int $max
     * @return string[]
     */
    public static function chunks(string $text, int $max): array {
        if (strlen($text) <= $max) {
            return [$text];
        }
        $chunks = [];
        $current = '';
        foreach (preg_split('/(?<=[.!?])[' . js::WS . ']+/u', $text) as $sentence) {
            $test = $current . ($current !== '' ? ' ' : '') . $sentence;
            if (strlen($test) <= $max) {
                $current = $test;
                continue;
            }
            if ($current !== '') {
                $chunks[] = $current;
            }
            if (strlen($sentence) <= $max) {
                $current = $sentence;
                continue;
            }
            $remaining = $sentence;
            while (strlen($remaining) > $max) {
                $cut = (int) floor($max / 2);
                $sub = js::slice($remaining, 0, $cut);
                $space = strrpos($sub, ' ');
                if ($space !== false && js::len(substr($sub, 0, $space)) > js::len($sub) * 0.3) {
                    $cut = js::len(substr($sub, 0, $space));
                }
                $chunks[] = js::trim(js::slice($remaining, 0, $cut));
                $remaining = js::trim(js::slice($remaining, $cut));
            }
            $current = $remaining;
        }
        if (js::trim($current) !== '') {
            $chunks[] = js::trim($current);
        }
        return $chunks;
    }

    // The remediation_image action of POST /api/image/generate (server/lib/remedialImageGeneration.ts).

    /**
     * buildRemedialImagePrompt().
     *
     * @param string $context
     * @param array|null $question text_html and name
     * @return array [prompt, promptMode]
     */
    public static function image_prompt(string $context, ?array $question): array {
        $context = self::image_field('context', $context);
        $text = self::image_field('question.text_html', (string) ($question['text_html'] ?? ''));
        $name = self::image_field('question.name', (string) ($question['name'] ?? ''));
        if (js::len($context) + js::len($text) + js::len($name) > 32000) {
            throw new \LengthException('Question and context are too large for remedial image generation ' .
                '(maximum 32000 characters combined)');
        }
        if ($context === '') {
            throw new \LengthException('context must contain usable text');
        }
        $mode = $text !== '' ? 'question_with_context' : 'context_only';
        $source = $text !== '' ? strtr(prompts::IMAGE_SOURCE_QUESTION, ['%%question%%' => $text, '%%context%%' => $context])
            : strtr(prompts::IMAGE_SOURCE_CONTEXT, ['%%context%%' => $context]);
        return [strtr(prompts::IMAGE, ['%%mode%%' => $mode, '%%source%%' => $source]), $mode];
    }

    /**
     * boundedField().
     *
     * @param string $label
     * @param string $value
     * @return string
     */
    protected static function image_field(string $label, string $value): string {
        if (js::len($value) > 64000) {
            throw new \LengthException("{$label} is too large for remedial image generation (maximum 64000 raw characters)");
        }
        $clean = self::image_text($value);
        if (js::len($clean) > 16000) {
            throw new \LengthException("{$label} is too large after HTML cleaning (maximum 16000 characters)");
        }
        return $clean;
    }

    /**
     * sanitiseRemedialImageText().
     *
     * @param string $value
     * @return string
     */
    public static function image_text(string $value): string {
        $ws = js::WSB;
        $value = preg_replace([
            '/<script\b[^>]*>.*?<\/script' . $ws . '*>/is',
            '/<style\b[^>]*>.*?<\/style' . $ws . '*>/is',
            '/<!--.*?-->/s',
            '/<\/?[A-Za-z][A-Za-z0-9:-]*(?:' . $ws . '[^<>]*?)?' . $ws . '*\/?>/',
        ], ' ', $value);
        $value = preg_replace_callback('/&(#x[0-9a-f]+|#\d+|amp|apos|gt|lt|nbsp|quot);/i', function ($m) {
            $entity = strtolower($m[1]);
            if ($entity[0] !== '#') {
                return ['amp' => '&', 'apos' => "'", 'gt' => '>', 'lt' => '<', 'nbsp' => "\u{a0}", 'quot' => '"'][$entity];
            }
            $digits = $entity[1] === 'x' ? substr($entity, 2) : substr($entity, 1);
            $code = $entity[1] === 'x' ? (strlen(ltrim($digits, '0')) > 7 ? INF : hexdec($digits))
                : (strlen(ltrim($digits, '0')) > 8 ? INF : (int) $digits);
            if ($code < 0 || $code > 0x10ffff) {
                return ' ';
            }
            if ($code >= 0xd800 && $code <= 0xdfff) {
                return "\u{fffd}";
            }
            return mb_chr((int) $code, 'UTF-8');
        }, $value);
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', ' ', $value);
        return js::trim(preg_replace('/[' . js::WS . ']+/u', ' ', $value));
    }
}
