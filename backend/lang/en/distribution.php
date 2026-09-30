<?php

return [
    'placed' => ':name placed in :class.',
    'placed_count' => ':count of :total placed.',
    'promoted_count' => ':count of :total decisions applied.',
    'level_changed' => ':name moved to :class with the new level.',
    'decided' => [
        'promote' => ':name promoted to :class.',
        'repeat' => ':name repeats the level in :class.',
        'graduate' => ':name recorded as graduated.',
    ],
    'decisions' => [
        'promote' => 'Promote',
        'repeat' => 'Repeat level',
        'graduate' => 'Graduate',
        'level_change' => 'Level change',
    ],
    'errors' => [
        'no_class' => 'No class of this level with free seats fits :name.',
        'class_not_in_term' => 'This class is not one of this term\'s classes.',
        'class_not_in_level' => 'This class is not one of this level\'s classes.',
        'already_decided' => 'A decision was already taken for :name in this term.',
        'not_in_level' => ':name is not in a class of this level.',
        'same_level' => 'The student is already in this level; choose another level or class.',
    ],
];
