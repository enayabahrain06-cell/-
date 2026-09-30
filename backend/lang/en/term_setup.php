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
        'circle_term' => 'This class belongs to another term; add the period in the class\'s term.',
        'level_or_class' => 'Choose a level or a class.',
        'edit_in_timetable' => 'This class has a detailed timetable; change its times in the timetable.',
        'level_clash' => 'The level already has a period at this time: :subject (:from–:to).',
        'same_term' => 'Choose a different term to copy from.',
        'in_use' => 'Used in term setup (level subjects, lessons or the timetable); deactivate it instead of deleting it.',
    ],
    'import' => [
        'done' => ':count lessons imported.',
        'subject_required' => 'The subject is required.',
        'unknown_subject' => 'Unknown subject: :value',
        'unknown_level' => 'Unknown level: :value',
        'title_required' => 'The lesson title is required.',
        'title_long' => 'The title is longer than 200 characters.',
        'description_long' => 'The description is longer than 2000 characters.',
        'bad_order' => 'The order must be a number from 0 to 9999.',
        'duplicate_in_file' => 'Duplicate in the file (row :row).',
        'exists' => 'This lesson already exists for this subject and level.',
    ],
    'warnings' => [
        'teacher' => ':name already teaches at this time (:level, :from–:to).',
        'room' => 'Room :name is taken at this time (:level, :from–:to).',
    ],
];
