<?php

return [
    'placed' => 'وُضع :name في :class.',
    'placed_count' => 'تم توزيع :count من :total.',
    'promoted_count' => 'تم تنفيذ :count من :total قرار.',
    'level_changed' => 'تم تحديث مستوى :name ونقله إلى :class.',
    'decided' => [
        'promote' => 'رُفّع :name إلى :class.',
        'repeat' => 'أُعيد :name في المستوى نفسه في :class.',
        'graduate' => 'سُجّل تخرّج :name.',
    ],
    'decisions' => [
        'promote' => 'ترفيع',
        'repeat' => 'إعادة المستوى',
        'graduate' => 'تخرّج',
        'level_change' => 'تحديث المستوى',
    ],
    'errors' => [
        'no_class' => 'لا يوجد صف بمقاعد شاغرة يناسب :name في هذا المستوى.',
        'class_not_in_term' => 'هذا الصف ليس من صفوف هذا الفصل الدراسي.',
        'class_not_in_level' => 'هذا الصف ليس من صفوف هذا المستوى.',
        'already_decided' => 'سبق اتخاذ قرار بشأن :name في هذا الفصل.',
        'not_in_level' => ':name ليس في صف من صفوف هذا المستوى.',
        'same_level' => 'الطالب في هذا المستوى بالفعل؛ اختر مستوى آخر أو صفاً آخر.',
    ],
];
