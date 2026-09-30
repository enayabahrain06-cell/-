<?php

return [
    'cannot_delete_with_attendance' => 'لا يمكن حذف صف سُجّل فيه حضور.',
    'capacity_exceeded' => 'تجاوز الصف سعته القصوى (:capacity طالباً).',
    'target_hall_busy' => 'الغرفة المختارة مشغولة في هذا الوقت.',
    'slot_busy' => 'الغرفة محجوزة في هذا الوقت.',
    'location_in_use' => 'لا يمكن حذف غرفة مرتبطة بصفوف.',
    'end_after_start' => 'وقت الانتهاء يجب أن يكون بعد وقت البدء.',
    'teacher_role_required' => 'المستخدم المختار ليس معلّماً.',
    'all_upcoming' => 'جميع الدروس القادمة',
    'alert_conflict_title' => 'تعارض في الغرفة: :lesson',
    'occupied_other_track' => 'محجوزة (المسار الآخر)',

    // إضافة طلاب مسجّلين من صفحة الحلقة.
    'add' => [
        'added' => 'أُضيف الطالب إلى الصف.',
        'moved' => 'نُقل الطالب إلى هذا الصف.',
        'lesson_inactive' => 'لا تُضاف الطلبة إلا إلى صف نشط.',
        'inactive' => ':name ليس طالباً نشطاً.',
        'age' => 'عمر :name خارج الفئة العمرية لهذا الصف (:min–:max سنة عند بدء الباقة).',
        'already_in' => ':name مسجّل في هذا الصف مسبقاً.',
        'in_other_circle' => ':name مسجّل في صف آخر (:circle). أكّد النقل لتحويله.',
        'cannot_move' => ':name في صف لا تديره (:circle)، فلا يمكن نقله من هنا.',
    ],
];
