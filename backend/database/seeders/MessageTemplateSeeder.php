<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $t = [
            ['otp', 'رمز الدخول', 'Login code',
                "رمز الدخول إلى {authority}: {code}\nصالح لمدة {minutes} دقائق. لا تشاركه مع أحد.",
                "Your {authority} login code: {code}\nValid for {minutes} minutes. Do not share it.",
                ['code', 'minutes', 'authority']],
            ['registration_received', 'استلام طلب التسجيل', 'Registration received',
                "تم استلام طلب تسجيل {name} في باقة {package}.\nرقم الطلب: {request_no}\nيمكنكم متابعة الحالة عبر الرابط: {link}",
                "We received the registration of {name} for {package}.\nRequest no: {request_no}\nTrack it here: {link}",
                ['name', 'package', 'request_no', 'link']],
            ['registration_accepted', 'قبول التسجيل', 'Registration accepted',
                "تهانينا! تم قبول {name} في باقة {package}.\nللدخول إلى النظام استخدم رقم هاتفك وسيصلك رمز التحقق عبر واتساب: {link}\nرسوم الباقة: {amount}",
                "Congratulations! {name} has been accepted into {package}.\nLog in with your phone number; a code will arrive on WhatsApp: {link}\nPackage fee: {amount}",
                ['name', 'package', 'link', 'amount']],
            ['registration_waitlist', 'قائمة الانتظار', 'Waitlist',
                "تم وضع طلب {name} (رقم {request_no}) في قائمة الانتظار لباقة {package}. سنتواصل معكم عند توفر مقعد.",
                "Request {request_no} for {name} is on the waitlist for {package}. We will contact you when a seat opens.",
                ['name', 'request_no', 'package']],
            ['registration_rejected', 'اعتذار عن التسجيل', 'Registration declined',
                "نعتذر، لم يتم قبول طلب {name} (رقم {request_no}) في باقة {package}.",
                "We are sorry, request {request_no} for {name} in {package} was not accepted.",
                ['name', 'request_no', 'package']],
            ['pre_lesson_reminder', 'تذكير قبل الصف', 'Pre-lesson reminder',
                "تذكير: صف {lesson} مع الأستاذ {teacher} اليوم الساعة {time} في {location}.\nالمقرر: {assignment}\n{map_link}",
                "Reminder: {lesson} with {teacher} today at {time} in {location}.\nAssignment: {assignment}\n{map_link}",
                ['name', 'lesson', 'teacher', 'time', 'location', 'assignment', 'map_link']],
            ['absence', 'تنبيه غياب', 'Absence notice',
                "تغيّب {name} عن صف {lesson} بتاريخ {date}.\nالمقرر الذي فاته: {assignment}",
                "{name} was absent from {lesson} on {date}.\nMissed assignment: {assignment}",
                ['name', 'lesson', 'date', 'assignment']],
            ['location_change', 'تغيير المكان', 'Location change',
                "تنبيه: تم نقل صف {lesson} بتاريخ {date} إلى {location}.\nالموقع: {map_link}",
                "Notice: {lesson} on {date} has moved to {location}.\nMap: {map_link}",
                ['lesson', 'date', 'location', 'map_link']],
            ['lottery_result', 'نتيجة التوزيع', 'Class assignment',
                "تم توزيع {name} على صف {lesson} مع الأستاذ {teacher}.\nأول درس: {date} الساعة {time} في {location}.",
                "{name} has been assigned to {lesson} with {teacher}.\nFirst lesson: {date} at {time} in {location}.",
                ['name', 'lesson', 'teacher', 'date', 'time', 'location']],
            ['evaluation_result', 'نتيجة التقييم', 'Evaluation result',
                "تقييم {name} بتاريخ {date}: {score}",
                "Evaluation for {name} on {date}: {score}",
                ['name', 'date', 'score']],
            ['exam_reminder', 'تذكير بالامتحان', 'Exam reminder',
                "تذكير: امتحان {lesson} بتاريخ {date} الساعة {time}.",
                "Reminder: {lesson} exam on {date} at {time}.",
                ['name', 'lesson', 'date', 'time']],
            ['exam_result', 'نتيجة الامتحان', 'Exam result',
                "نتيجة {name} في امتحان {lesson}: {score}. الحالة: {status}",
                "{name}'s result in {lesson}: {score}. Status: {status}",
                ['name', 'lesson', 'score', 'status']],
            ['certificate_issued', 'تهنئة بشهادة', 'Certificate congratulations',
                "مبارك! حصل {name} على {title}.\n{achievement}\nرقم الشهادة: {certificate_no}\nللتحقق من الشهادة: {link}",
                "Congratulations! {name} has earned the {title}.\n{achievement}\nCertificate no: {certificate_no}\nVerify it here: {link}",
                ['name', 'title', 'achievement', 'certificate_no', 'link']],
            ['payment_receipt', 'إيصال دفع', 'Payment receipt',
                "تم استلام مبلغ {amount} لحساب {name}.\nرقم الإيصال: {invoice_no}\nالرصيد الحالي: {balance}",
                "Received {amount} for {name}.\nReceipt no: {invoice_no}\nCurrent balance: {balance}",
                ['name', 'amount', 'invoice_no', 'balance']],
            ['payment_due_reminder', 'تذكير بالسداد', 'Payment reminder',
                "تذكير: فاتورة {invoice_no} لـ {name} بمبلغ {amount} مستحقة بتاريخ {date}.",
                "Reminder: invoice {invoice_no} for {name} of {amount} is due on {date}.",
                ['name', 'invoice_no', 'amount', 'date']],
            ['weekly_report', 'التقرير الأسبوعي', 'Weekly report',
                "التقرير الأسبوعي — {authority}\n{body}",
                "Weekly report — {authority}\n{body}",
                ['body', 'authority']],
            ['student_progress_update', 'تحديث التقدم الشهري', 'Monthly progress update',
                "تحديث شهري عن {name}:\nالموضع الحالي: {position}\nالمحفوظ: {percent} من القرآن ({juz_count} أجزاء مكتملة)\nالخطة السنوية: {plan_percent}\nمتوسطات الشهر: {averages}\nصعوبات قيد المتابعة: {issues}",
                "Monthly update for {name}:\nCurrent position: {position}\nMemorized: {percent} of the Quran ({juz_count} complete ajza)\nYearly plan: {plan_percent}\nThis month's averages: {averages}\nDifficulties being followed: {issues}",
                ['name', 'position', 'percent', 'juz_count', 'plan_percent', 'averages', 'issues']],
        ];

        foreach ($t as [$key, $nameAr, $nameEn, $bodyAr, $bodyEn, $vars]) {
            MessageTemplate::updateOrCreate(['key' => $key], [
                'name_ar' => $nameAr, 'name_en' => $nameEn,
                'body_ar' => $bodyAr, 'body_en' => $bodyEn,
                'variables' => $vars, 'is_active' => true,
            ]);
        }
    }
}
