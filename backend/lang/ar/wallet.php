<?php

return [
    'receipt' => ['title' => 'إيصال استلام'],
    'errors' => [
        'amount_positive' => 'المبلغ يجب أن يكون أكبر من صفر.',
        'amount_nonzero' => 'المبلغ لا يمكن أن يكون صفراً.',
        'note_required' => 'يجب كتابة سبب التسوية.',
        'insufficient_credit' => 'لا يوجد رصيد كافٍ للاسترداد. الرصيد الحالي: :balance',
        'cancel_paid' => 'لا يمكن إلغاء فاتورة سُدّد جزء منها.',
    ],
    'alerts' => [
        'overdue_title' => 'فاتورة متأخرة :invoice — :name',
    ],
    'invoice' => [
        'package_fee' => 'رسوم باقة :package',
    ],
];
