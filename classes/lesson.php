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
 * Structured "tutor lesson" for a revision module (v1.4.0).
 *
 * Four cards shown 2x2, designed from learning-science principles:
 *   1. tempting  — "Why it seemed right": name the appeal of the learner's choice, then
 *                  why it fails (refutation — fixes the misconception, not just the answer).
 *   2. principle — "The key idea": the rule AND why it exists (elaborated feedback).
 *   3. example   — "See it at work": the principle in a NEW real-world situation
 *                  (concrete examples + dual coding — the AI image illustrates this card).
 *   4. hook      — "Lock it in": one concept-specific memory hook that counters the
 *                  misconception (elaborative encoding / distinctive retrieval cue).
 * Plus an "answer strip" shown first and a one-line takeaway shown last.
 *
 * The AI service returns this as `lesson`; older modules (explain_text only) are rendered
 * with the same card styling via render_legacy().
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class lesson {
    /** Card ids in display order. */
    const CARD_IDS = ['tempting', 'principle', 'example', 'hook'];

    /** Response format the plugin asks the AI service for. */
    const FORMAT = 'lesson_v1';

    /**
     * Validate and clean a lesson returned by the AI service.
     *
     * @param mixed $raw decoded JSON
     * @return array|null clean lesson, or null if unusable (caller falls back to explain_text)
     */
    public static function normalise($raw): ?array {
        if (!is_array($raw) || empty($raw['cards']) || !is_array($raw['cards'])) {
            return null;
        }
        $byid = [];
        foreach (array_values($raw['cards']) as $i => $card) {
            if (!is_array($card)) {
                continue;
            }
            $id = (string) ($card['id'] ?? '');
            if (!in_array($id, self::CARD_IDS, true)) {
                $id = self::CARD_IDS[$i] ?? '';
            }
            $body = self::clean($card['body'] ?? '', 900);
            if ($id === '' || $body === '' || isset($byid[$id])) {
                continue;
            }
            $byid[$id] = [
                'id'      => $id,
                'heading' => self::clean($card['heading'] ?? '', 60),
                'body'    => $body,
            ];
        }
        if (count($byid) < 3) {
            return null; // Too incomplete to be worth the card layout.
        }
        $cards = [];
        foreach (self::CARD_IDS as $id) {
            if (isset($byid[$id])) {
                $cards[] = $byid[$id];
            }
        }
        return [
            'answer_strip'            => self::clean($raw['answer_strip'] ?? '', 300),
            'cards'                   => $cards,
            'takeaway'                => self::clean($raw['takeaway'] ?? '', 200),
            'misconception_diagnosis' => self::clean($raw['misconception_diagnosis'] ?? '', 400),
            'image_prompt'            => self::clean($raw['image_prompt'] ?? '', 500),
        ];
    }

    /**
     * Plain text, no HTML, collapsed whitespace, length-capped.
     *
     * @param mixed $s
     * @param int $max
     * @return string
     */
    protected static function clean($s, int $max): string {
        if (!is_scalar($s)) {
            return '';
        }
        $s = trim(preg_replace('/[ \t]+/u', ' ', strip_tags(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'))));
        return \core_text::strlen($s) > $max ? rtrim(\core_text::substr($s, 0, $max - 1)) . '…' : $s;
    }

    /**
     * Text sent to the translation service: one block per line group, blank-line separated,
     * so the translation can be split back into the same structure.
     *
     * @param array $lesson
     * @return string
     */
    public static function to_translation_text(array $lesson): string {
        $blocks = [$lesson['answer_strip'] !== '' ? $lesson['answer_strip'] : '-'];
        foreach ($lesson['cards'] as $card) {
            $blocks[] = ($card['heading'] !== '' ? $card['heading'] : '-') . "\n" . $card['body'];
        }
        $blocks[] = $lesson['takeaway'] !== '' ? $lesson['takeaway'] : '-';
        return implode("\n\n", $blocks);
    }

    /**
     * Rebuild a lesson from its translated text. Returns null when the structure did not
     * survive translation (the page then shows the translated text as a single card).
     *
     * @param string $text
     * @param array $english the English lesson (card ids / order)
     * @return array|null
     */
    public static function from_translation_text(string $text, array $english): ?array {
        $blocks = preg_split('/\n\s*\n/u', trim(str_replace("\r", '', $text)));
        $n = count($english['cards']);
        if (count($blocks) !== $n + 2) {
            return null;
        }
        $out = $english;
        $out['answer_strip'] = trim($blocks[0]) === '-' ? '' : trim($blocks[0]);
        foreach ($english['cards'] as $i => $card) {
            $lines = explode("\n", trim($blocks[$i + 1]), 2);
            if (count($lines) < 2) {
                return null;
            }
            $out['cards'][$i]['heading'] = trim($lines[0]) === '-' ? '' : trim($lines[0]);
            $out['cards'][$i]['body'] = trim($lines[1]);
        }
        $out['takeaway'] = trim($blocks[$n + 1]) === '-' ? '' : trim($blocks[$n + 1]);
        return $out;
    }

    /**
     * Default learner-facing heading for a card.
     *
     * @param string $id
     * @return string
     */
    public static function default_heading(string $id): string {
        return get_string('card_' . $id, 'local_aiquizremedial');
    }

    /**
     * Render the 4-card lesson.
     *
     * @param array $lesson normalised lesson
     * @param string|null $imageurl AI image (placed on the "See it at work" card)
     * @param bool $teacherview show the misconception diagnosis to teachers
     * @return string HTML
     */
    public static function render(array $lesson, ?string $imageurl, bool $teacherview): string {
        $html = '';
        if ($lesson['answer_strip'] !== '') {
            $html .= \html_writer::div(
                \html_writer::span(self::icon('check'), 'aiqr-answer-strip-icon') .
                \html_writer::span(s($lesson['answer_strip']), 'aiqr-answer-strip-text'),
                'aiqr-answer-strip'
            );
        }

        $html .= \html_writer::start_div('aiqr-lesson-grid');
        $n = 0;
        foreach ($lesson['cards'] as $card) {
            $n++;
            $heading = $card['heading'] !== '' ? $card['heading'] : self::default_heading($card['id']);
            $body = \html_writer::tag('p', nl2br(s($card['body'])), ['class' => 'aiqr-lesson-body']);
            if ($card['id'] === 'example' && !empty($imageurl)) {
                $body .= self::image($imageurl);
            }
            $html .= self::card($card['id'], $n, $heading, $body);
        }
        $html .= \html_writer::end_div();

        if ($lesson['takeaway'] !== '') {
            $html .= \html_writer::div(
                \html_writer::span(get_string('takeaway_label', 'local_aiquizremedial'), 'aiqr-takeaway-label') .
                \html_writer::span(s($lesson['takeaway']), 'aiqr-takeaway-text'),
                'aiqr-takeaway'
            );
        }
        if ($teacherview && $lesson['misconception_diagnosis'] !== '') {
            $html .= \html_writer::div(
                \html_writer::tag('strong', get_string('tutornote', 'local_aiquizremedial') . ' ') .
                s($lesson['misconception_diagnosis']),
                'aiqr-tutor-note'
            );
        }
        return $html;
    }

    /**
     * Render an older module (explain_text + optional "Pro Tip") with the same card styling.
     *
     * @param string $explaintext
     * @param string|null $imageurl
     * @return string HTML
     */
    public static function render_legacy(string $explaintext, ?string $imageurl): string {
        [$main, $tip] = self::split_legacy($explaintext);
        $html = \html_writer::start_div('aiqr-lesson-grid' . ($tip === '' ? ' aiqr-lesson-grid-single' : ''));
        $body = \html_writer::tag('p', nl2br(s($main)), ['class' => 'aiqr-lesson-body']);
        if (!empty($imageurl)) {
            $body .= self::image($imageurl);
        }
        $html .= self::card('principle', 1, self::default_heading('principle'), $body);
        if ($tip !== '') {
            $html .= self::card(
                'hook', 2, self::default_heading('hook'),
                \html_writer::tag('p', nl2br(s($tip)), ['class' => 'aiqr-lesson-body']));
        }
        $html .= \html_writer::end_div();
        return $html;
    }

    /**
     * Split legacy explain_text into main text and "Pro Tip".
     *
     * @param string $text
     * @return string[] [main, tip]
     */
    public static function split_legacy(string $text): array {
        $text = preg_replace('/^Here is what you need to know\.\s*/iu', '', $text);
        $text = preg_replace('/^Listen\s*&\s*Learn\s*:?\s*\n?/iu', '', $text);
        $text = trim(strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
        $tip = '';
        if (preg_match('/^(.*?)[\s\.]*\bPro\s*Tip\s*:\s*(.+)$/sui', $text, $m)) {
            $text = trim($m[1]);
            $tip = trim($m[2]);
        }
        if ($text !== '' && !preg_match('/[.?!]\s*$/u', $text)) {
            $text .= '.';
        }
        return [$text, $tip];
    }

    /**
     * Explanation image. The service returns 864×1536 portrait images, so the image is shown
     * whole (never cropped) at a sensible height, and opens full size in a new tab.
     *
     * @param string $url
     * @return string
     */
    protected static function image(string $url): string {
        $img = \html_writer::empty_tag('img', [
            'src' => $url, 'alt' => get_string('explain_image_alt', 'local_aiquizremedial'),
            'class' => 'aiqr-lesson-image', 'loading' => 'lazy',
        ]);
        return \html_writer::tag(
            'figure',
            \html_writer::link(
                $url, $img, ['target' => '_blank', 'rel' => 'noopener',
                'title' => get_string('image_open', 'local_aiquizremedial')]),
            ['class' => 'aiqr-lesson-figure']);
    }

    /**
     * One card.
     *
     * @param string $id
     * @param int $n position (1-based)
     * @param string $heading
     * @param string $bodyhtml
     * @return string
     */
    protected static function card(string $id, int $n, string $heading, string $bodyhtml): string {
        $icon = ['tempting' => 'signpost', 'principle' => 'bulb', 'example' => 'globe', 'hook' => 'brain'][$id] ?? 'bulb';
        return \html_writer::tag(
            'section',
            \html_writer::div(
                \html_writer::span(self::icon($icon), 'aiqr-lesson-icon') .
                \html_writer::tag('h3', s($heading), ['class' => 'aiqr-lesson-heading']) .
                \html_writer::span($n, 'aiqr-lesson-step', ['aria-hidden' => 'true']),
                'aiqr-lesson-head'
            ) . $bodyhtml,
            ['class' => 'aiqr-lesson-card aiqr-lesson-' . $id, 'tabindex' => '0']
        );
    }

    /**
     * Inline SVG icons (stroke = currentColor).
     *
     * @param string $name
     * @return string
     */
    public static function icon(string $name): string {
        $paths = [
            'signpost' => '<path d="M12 3v18"/><path d="M5 6h11l3 3-3 3H5z"/><path d="M19 14H9l-3 3 3 3h10"/>',
            'bulb'     => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V17h6v-.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"/>',
            'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/>',
            'brain'    => '<path d="M9 4a3 3 0 0 0-3 3v.2A3 3 0 0 0 4 10a3 3 0 0 0 1 2.2A3 3 0 0 0 6 17a3 3 0 0 0 3 3h0a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/>'
                        . '<path d="M15 4a3 3 0 0 1 3 3v.2A3 3 0 0 1 20 10a3 3 0 0 1-1 2.2A3 3 0 0 1 18 17a3 3 0 0 1-3 3h0a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>',
            'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        ];
        return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ($paths[$name] ?? $paths['bulb']) . '</svg>';
    }
}
