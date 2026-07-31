<?php
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
