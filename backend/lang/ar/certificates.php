<?php

// نصوص الشهادات الخاصة بالنظام. رسائل سير العمل من حزمة الشهادات
// (packages/certificates/lang)، وصياغة هذا النظام في lang/vendor/certificates.
return [
    // نصوص القوالب الافتراضية لكل نوع شهادة (قابلة للتعديل من الشهادات ← القوالب).
    'defaults' => [
        'completion' => ['title' => 'شهادة إتمام حفظ', 'body' => 'قد أتمّ/ت حفظ {achievement}، جعل الله القرآن ربيع قلبه.'],
        'excellence' => ['title' => 'شهادة تفوّق', 'body' => 'يُكرَّم/تُكرَّم للتفوّق في {achievement}.'],
        'competition' => ['title' => 'شهادة مسابقة', 'body' => 'قد فاز/ت في {achievement}، مع تمنياتنا بدوام التوفيق.'],
        'exam' => ['title' => 'شهادة اجتياز', 'body' => 'قد اجتاز/ت {achievement}.'],
        'attendance' => ['title' => 'شهادة الحضور الكامل', 'body' => 'يُكرَّم/تُكرَّم للالتزام بالحضور الكامل في {achievement}.'],
        'participation' => ['title' => 'شهادة شكر وتقدير', 'body' => 'نشكره/نشكرها على المشاركة في {achievement}.'],
        'default' => ['title' => 'شهادة', 'body' => 'يُكرَّم/تُكرَّم لـ {achievement}.'],
    ],
    // على ملف PDF (resources/views/pdf/certificate.blade.php).
    'certify' => 'تشهد بأن الطالب/ـة',
    'grade_line' => 'التقدير',
    'issued_on' => 'تاريخ الإصدار',
    'certificate_no' => 'رقم الشهادة',
    'verify_hint' => 'امسح للتحقق',
    // شهادات الإتمام التلقائية.
    'auto_juz' => 'الجزء :juz',
    'auto_quran' => 'القرآن الكريم كاملاً',
];
