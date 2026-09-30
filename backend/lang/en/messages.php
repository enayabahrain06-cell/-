<?php

return [
    'unknown_placeholders' => 'Unknown placeholders in the template: :list',
    'requeued' => ':count messages were re-queued.',
    'queued' => ':count messages queued for sending.',
    'not_your_students' => 'You can only message students in your own classes.',
    'phones_need_manage' => 'Sending to free-form phone numbers requires the manage messages permission.',
    'send_now_done' => 'Reminders queued: :n.',
    'send_results_done' => 'Attendance result sent to guardians: :n messages.',
    'attendance_result_body' => "Peace be upon you {guardian_name},
{name}'s status in {lesson} on {date}: {status}.
Memorization: {assignment}
Next lesson: {next_date}",
    'excuse_reviewed' => 'This excuse has already been reviewed.',
    'rules' => [
        'order' => 'The second reminder must be closer to the lesson than the first.',
    ],
];
