<?php

return [
    'cannot_delete_with_attendance' => 'A circle with recorded attendance cannot be deleted.',
    'capacity_exceeded' => 'The circle is over its capacity (:capacity students).',
    'target_hall_busy' => 'The selected hall is busy at that time.',
    'slot_busy' => 'The hall is already booked at that time.',
    'location_in_use' => 'A hall linked to circles cannot be deleted.',
    'end_after_start' => 'End time must be after start time.',
    'teacher_role_required' => 'The selected user is not a teacher.',
    'all_upcoming' => 'all upcoming lessons',
    'alert_conflict_title' => 'Hall conflict: :lesson',
    'occupied_other_track' => 'Occupied (other track)',

    // Adding existing students from the circle page.
    'add' => [
        'added' => 'Student added to the circle.',
        'moved' => 'Student moved to this circle.',
        'lesson_inactive' => 'Students can only be added to an active circle.',
        'inactive' => ':name is not an active student.',
        'age' => ':name is outside this circle\'s age range (:min–:max years at the package start).',
        'already_in' => ':name is already in this circle.',
        'in_other_circle' => ':name is already in another circle (:circle). Confirm the move to transfer them.',
        'cannot_move' => ':name is in a circle you do not manage (:circle), so they cannot be moved from here.',
    ],
];
