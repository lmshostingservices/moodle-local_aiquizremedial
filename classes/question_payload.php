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
 * local_aiquizremedial file.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_aiquizremedial;

defined('MOODLE_INTERNAL') || die();

class question_payload {
    public static function build(\stdClass $quiz, \stdClass $attempt, \question_attempt $qa): array {
        $question = $qa->get_question();
        $qtext = $question->questiontext ?? '';
        $response = $qa->get_last_qt_data();
        $correct = self::try_get_correct_answer($question);

        // Resolve the student's actual answer text so the AI receives the option wording
        // (e.g. "Bat") rather than a bare index number (e.g. "1"). get_response_summary()
        // is the Moodle API that returns the human-readable version of the student's choice.
        $responseSummary = '';
        try {
            $responseSummary = (string) ($qa->get_response_summary() ?? '');
        } catch (\Throwable $e) {
            // Silently continue — answertext will be empty and the server fallback applies.
        }
        if (!empty($responseSummary) && is_array($response)) {
            $response['answertext'] = $responseSummary;
        } elseif (!empty($responseSummary)) {
            $response = ['answertext' => $responseSummary];
        }

        return [
            'quiz' => [
                'id'   => (int) $quiz->id,
                'name' => (string) $quiz->name,
            ],
            'attempt' => [
                'id'      => (int) $attempt->id,
                'attempt' => (int) $attempt->attempt,
            ],
            'question' => [
                'id'        => (int) $question->id,
                'qtype'     => $question->get_type_name(),
                'name'      => (string) ($question->name ?? ''),
                'text_html' => (string) $qtext,
            ],
            'student_response' => $response,
            'correct_answer'   => $correct,
            'fraction'         => $qa->get_fraction(),
        ];
    }

    private static function try_get_correct_answer($question) {
        if (property_exists($question, 'answers') && is_array($question->answers)) {
            $correct = [];
            foreach ($question->answers as $ans) {
                if (isset($ans->fraction) && (float) $ans->fraction >= 1.0) {
                    $correct[] = [
                        'answer'   => $ans->answer ?? null,
                        'feedback' => $ans->feedback ?? null,
                    ];
                }
            }
            if (!empty($correct)) {
                return $correct;
            }
        }

        if (method_exists($question, 'get_right_answer_summary')) {
            $summary = $question->get_right_answer_summary();
            if (!empty($summary)) {
                return [['answer' => $summary, 'feedback' => null]];
            }
        }

        return null;
    }
}
