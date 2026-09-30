<?php

return [
    'saved' => 'Attendance saved (:count).',
    'deleted' => 'Record deleted.',
    'reminded' => 'Reminder sent to :name.',
    'remind_body' => 'Hello :name, the attendance of the class :lesson on :date (:time) has not been recorded yet. Please record it in the system.',
    'errors' => [
        'not_in_division' => 'Some students are not in this division.',
        'outside_term' => 'The date is outside the chosen term.',
        'role_supervisor' => 'Choose supervisors only.',
        'role_teacher' => 'Choose teachers only.',
        'times' => 'The leaving time is before the arrival time.',
        'range' => 'The period is longer than :days days.',
        'already_taken' => 'This session\'s attendance is already recorded, or it is cancelled.',
        'not_session_teacher' => 'This teacher does not teach the class on this night.',
        'no_phone' => 'This teacher has no phone number.',
        'not_sent' => 'The reminder could not be sent (notifications are off or the number is invalid).',
    ],
];
