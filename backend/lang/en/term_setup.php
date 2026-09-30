<?php

return [
    'saved' => 'Saved.',
    'deleted' => 'Deleted.',
    'copied' => 'Copied: :rooms rooms, :level_subjects subjects, :plan_items plan items, :supervisors supervisors, :slots periods.',
    'errors' => [
        'duplicate_subject' => 'This subject is already assigned to this level in this term.',
        'duplicate_room' => 'This room is already assigned to this level in this term.',
        'duplicate_supervisor' => 'This supervisor is already assigned to this night.',
        'supervisor_role' => 'Choose a user with the supervisor role.',
        'teacher_role' => 'Choose a user with the teacher role.',
        'plan_title' => 'Choose a curriculum lesson or write a title.',
        'lesson_subject' => 'This lesson does not belong to this subject or level.',
        'circle_level' => 'This class is not in this level.',
        'level_clash' => 'The level already has a period at this time: :subject (:from–:to).',
        'same_term' => 'Choose a different term to copy from.',
        'in_use' => 'Used in term setup (level subjects, lessons or the timetable); deactivate it instead of deleting it.',
    ],
    'warnings' => [
        'teacher' => ':name already teaches at this time (:level, :from–:to).',
        'room' => 'Room :name is taken at this time (:level, :from–:to).',
    ],
];
