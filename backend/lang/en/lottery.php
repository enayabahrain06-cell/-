<?php

return [
    'created' => 'Lottery created.',
    'ran' => 'Distribution done (run :run, seed :seed).',
    'approved' => ':count students enrolled.',
    'moved' => 'Student moved.',
    'cancelled' => 'Lottery cancelled.',
    'errors' => [
        'locked' => 'An approved or cancelled lottery cannot be changed.',
        'no_teachers' => 'Add at least one participating teacher.',
        'full' => 'That teacher has no free seats in this lottery.',
        'already_approved' => 'This lottery was already approved.',
        'not_run' => 'Run the lottery before approving it.',
        'lesson_package' => 'The class must belong to the lottery package.',
        'lesson_teacher' => 'The class must be taught by the selected teacher.',
    ],
];
