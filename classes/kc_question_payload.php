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

namespace local_aiquizremedial;

defined('MOODLE_INTERNAL') || die();

class kc_question_payload {
    public static function build(\stdClass $kc, \stdClass $attempt, \stdClass $question, array $answerdata): array {
        // Answer1..answer4 are the four option texts (1-indexed DB column names).
        $options = [
            (string) ($question->answer1 ?? ''),
            (string) ($question->answer2 ?? ''),
            (string) ($question->answer3 ?? ''),
            (string) ($question->answer4 ?? ''),
        ];
        // Version 1.3.1: Knowledge Check questions can have a 5th option.
        if (!empty($question->answer5)) {
            $options[] = (string) $question->answer5;
        }

        // BUG-KC-PAYLOAD-INDEX (v1.2.1): correctanswer and the stored answer index
        // are BOTH 0-indexed (JS sends answerIndex 0-3; KC ajax stores it via
        // $record->correctanswer = $q['correctIndex'] ?? 0).  The old code used
        // $correctIndex1 - 1 (treating it as 1-indexed), producing an off-by-one
        // on every option lookup.  When correctanswer=0 (first option), $options[-1]
        // resolves to '' (blank).  Similarly, the student answer guard
        // "$studentIndex1 >= 1" caused the first option (index 0) to always yield
        // a blank answertext — so the AI never received the student's actual choice.
        //
        // Fix: use correctanswer and answer directly as 0-based $options indices.
        // feedback columns are 1-indexed DB names (feedback1..feedback4), so add +1
        // only for the column name suffix.
        $correctIndex = (int) ($question->correctanswer ?? 0);
        $correctOptionText = $options[$correctIndex] ?? '';
        $correctFeedback   = (string) ($question->{'feedback' . ($correctIndex + 1)} ?? '');

        $studentIndex = (int) ($answerdata['answer'] ?? -1);
        $studentOptionText = $studentIndex >= 0 ? ($options[$studentIndex] ?? '') : '';

        return [
            'quiz' => [
                'id'   => (int) $kc->id,
                'name' => (string) $kc->name,
            ],
            'attempt' => [
                'id'      => (int) $attempt->id,
                'attempt' => 1,
            ],
            'question' => [
                'id'        => (int) $question->id,
                'qtype'     => 'multichoice',
                'name'      => 'Question ' . (int) ($question->questionnumber ?? $question->id),
                'text_html' => (string) ($question->questiontext ?? ''),
                'options'   => $options,
            ],
            'student_response' => [
                'answer'     => $studentIndex,
                'answertext' => $studentOptionText,
            ],
            'correct_answer' => [
                [
                    'answer'   => $correctOptionText,
                    'feedback' => $correctFeedback ?: null,
                ],
            ],
            'fraction' => 0.0,
        ];
    }
}
