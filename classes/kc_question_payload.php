<?php
namespace local_aiquizremedial;

defined('MOODLE_INTERNAL') || die();

class kc_question_payload {

    public static function build(\stdClass $kc, \stdClass $attempt, \stdClass $question, array $answerdata): array {
        // answer1..answer4 are the four option texts (1-indexed DB column names).
        $options = [
            (string) ($question->answer1 ?? ''),
            (string) ($question->answer2 ?? ''),
            (string) ($question->answer3 ?? ''),
            (string) ($question->answer4 ?? ''),
        ];

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
