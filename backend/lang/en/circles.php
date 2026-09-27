<?php

return [
    'reasons' => [
        'gender' => 'Other gender track',
        'age' => 'Outside the age group',
        'full' => 'No free seat',
        'teacher' => 'Teacher gender does not match',
    ],
    'errors' => [
        'gender' => ':name is not in this circle\'s gender track.',
        'age' => ':name is :age; this circle takes ages :min–:max.',
        'full' => 'This circle has no free seat.',
        'already_in' => ':name is already in this circle.',
        'in_other_circle' => ':name is already in :circle. Move them instead.',
        'no_circle' => 'Choose a circle, or put the request on the waitlist.',
        'same_circle' => 'Choose a different circle.',
        'age_group_in_use' => 'This age group is used by circles; deactivate it instead.',
        'range' => 'The maximum age must be greater than or equal to the minimum age.',
    ],
    'moved' => 'Student moved to :circle.',
    'age_group_saved' => 'Age group saved.',
    'age_group_deleted' => 'Age group deleted.',
];
