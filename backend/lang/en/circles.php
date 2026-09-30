<?php

return [
    'reasons' => [
        'gender' => 'Other gender track',
        'age' => 'Outside the age group',
        'full' => 'No free seat',
        'teacher' => 'Teacher gender does not match',
    ],
    'errors' => [
        'gender' => ':name is not in this class\'s gender track.',
        'age' => ':name is :age; this class takes ages :min–:max.',
        'full' => 'This class has no free seat.',
        'already_in' => ':name is already in this class.',
        'in_other_circle' => ':name is already in :circle. Move them instead.',
        'no_circle' => 'Choose a class, or put the request on the waitlist.',
        'same_circle' => 'Choose a different class.',
        'age_group_in_use' => 'This age group is used by classes; deactivate it instead.',
        'range' => 'The maximum age must be greater than or equal to the minimum age.',
    ],
    'moved' => 'Student moved to :circle.',
    'age_group_saved' => 'Age group saved.',
    'age_group_deleted' => 'Age group deleted.',
];
