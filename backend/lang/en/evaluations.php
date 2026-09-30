<?php

return [
    'saved' => 'Evaluations saved.',
    'sent' => 'Evaluation sent to the guardian.',
    'deleted' => 'Evaluation deleted.',
    'score_text' => 'memorization :m/10, tajweed :t/10, revision :r/10, behavior :b/10 (total :total/40)',
    'student_not_in_circle' => 'This student is not enrolled in the class.',
    'suggestion' => ':criterion scored :score. Consider opening a ":category" difficulty.',
    'criteria' => ['memorization' => 'Memorization', 'tajweed' => 'Tajweed', 'revision' => 'Revision', 'behavior' => 'Behavior'],
    'errors' => [
        'criterion' => 'This criterion does not belong to the subject.',
        'score_max' => 'The score for ":criterion" is between 0 and :max.',
        'score_required' => 'Enter a score for ":criterion".',
        'no_criteria' => 'This subject has no active evaluation criteria. Add them in Evaluation criteria.',
        'not_in_division' => 'This student is not in the division.',
        'subject_not_in_class' => 'This subject is not taught in the level of this class this term.',
    ],
];
