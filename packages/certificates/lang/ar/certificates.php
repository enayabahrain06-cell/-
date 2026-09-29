<?php

return [
    'types' => [
        'achievement' => 'إنجاز',
        'excellence' => 'تميّز',
        'participation' => 'مشاركة',
        'attendance' => 'انتظام في الحضور',
    ],
    'grades' => ['excellent' => 'ممتاز', 'very_good' => 'جيد جداً', 'good' => 'جيد'],
    'sources' => ['manual' => 'يدوي'],
    'status' => ['draft' => 'مسودة', 'approved' => 'معتمدة', 'revoked' => 'ملغاة'],

    // نصوص القوالب الافتراضية لكل نوع (قابلة للتعديل من محرر القوالب). "default" للأنواع التي ليس لها نص خاص.
    'defaults' => [
        'default' => ['title' => 'شهادة', 'body' => 'تُمنح هذه الشهادة تقديراً لـ {achievement}.'],
        'achievement' => ['title' => 'شهادة إنجاز', 'body' => 'تُمنح هذه الشهادة تقديراً لـ {achievement}.'],
        'excellence' => ['title' => 'شهادة تفوّق', 'body' => 'يُكرَّم/تُكرَّم للتفوّق في {achievement}.'],
        'participation' => ['title' => 'شهادة شكر وتقدير', 'body' => 'نشكره/نشكرها على المشاركة في {achievement}.'],
        'attendance' => ['title' => 'شهادة الحضور الكامل', 'body' => 'يُكرَّم/تُكرَّم للالتزام بالحضور الكامل في {achievement}.'],
    ],

    // على ملف PDF.
    'certify' => 'تشهد بأن',
    'grade_line' => 'التقدير',
    'issued_on' => 'تاريخ الإصدار',
    'certificate_no' => 'رقم الشهادة',
    'verify_hint' => 'امسح للتحقق',
    'draft_mark' => 'مسودة — غير معتمدة',
    'revoked_mark' => 'ملغاة',
    'sample_name' => 'فاطمة أحمد',
    'sample_achievement' => 'الدورة المتقدمة',

    'messages' => [
        'created' => '{1} تم إنشاء مسودة شهادة واحدة.|[2,*] تم إنشاء :count مسودات شهادات.',
        'updated' => 'تم تحديث الشهادة.',
        'approved' => '{0} لم تُعتمد أي شهادة.|{1} تم اعتماد شهادة واحدة.|[2,*] تم اعتماد :count شهادات.',
        'revoked' => 'تم إلغاء الشهادة.',
        'deleted' => 'تم حذف مسودة الشهادة.',
        'sent' => 'تم إرسال الشهادة.',
        'template_saved' => 'تم حفظ القالب.',
        'signature_saved' => 'تم حفظ التوقيع.',
        'signature_removed' => 'تم حذف التوقيع.',
    ],
    'errors' => [
        'not_draft' => 'لا يمكن تعديل إلا الشهادات التي في حالة مسودة.',
        'not_approved' => 'لا يمكن إلغاء أو إرسال إلا الشهادات المعتمدة.',
        'not_found' => 'لا توجد شهادة بهذا الرمز.',
        'unknown_type' => 'نوع شهادة غير معروف.',
        'no_recipients' => 'لم يُضبط نوع المستلم.',
        'recipient_missing' => 'تعذّر العثور على مستلم واحد أو أكثر.',
    ],
    'verify' => [
        'valid' => 'شهادة صحيحة',
        'revoked' => 'هذه الشهادة ملغاة',
    ],
];
