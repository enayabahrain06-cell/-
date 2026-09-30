<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Reference data: WhatsApp templates for automatic attendance messaging (section 23), AR/EN.
 * Idempotent; admin edits survive re-seeding (only missing templates are created).
 * Variables: {name} {guardian_name} {lesson} {teacher} {date} {time} {location} {map_link} {assignment}
 * {next_date} {supervisor_phone} {absence_count} {authority}
 */
class AttendanceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $common = ['name', 'guardian_name', 'lesson', 'teacher', 'date', 'time', 'location', 'map_link', 'assignment', 'next_date', 'supervisor_phone'];
        $t = [
            ['attendance_reminder_long', 'تذكير بموعد الصف (قبل ساعتين)', 'Lesson reminder (2 hours before)',
                "السلام عليكم {guardian_name}،\nتذكير بموعد صف {lesson} لـ{name} اليوم {date} الساعة {time} في {location}.\nالمقرر: {assignment}\nالموقع: {map_link}\nللتأكيد أرسل: حاضر · للاعتذار أرسل: عذر",
                "Peace be upon you {guardian_name},\nReminder: {name}'s lesson {lesson} is today {date} at {time} in {location}.\nAssignment: {assignment}\nMap: {map_link}\nReply \"present\" to confirm or \"excuse\" to apologise.",
                $common],
            ['attendance_reminder_short', 'تذكير بموعد الصف (قبل ساعة)', 'Lesson reminder (1 hour before)',
                'يبدأ صف {lesson} لـ{name} بعد ساعة، الساعة {time} في {location}.',
                "{name}'s lesson {lesson} starts in one hour, at {time} in {location}.",
                $common],
            ['absence_notice', 'إشعار غياب', 'Absence notice',
                "السلام عليكم {guardian_name}،\nنفيدكم بغياب {name} عن صف {lesson} يوم {date}.\nالمقرر الفائت: {assignment}\nموعد الصف القادم: {next_date}\nللاستفسار: {supervisor_phone}",
                "Peace be upon you {guardian_name},\n{name} was absent from {lesson} on {date}.\nMissed assignment: {assignment}\nNext lesson: {next_date}\nQuestions: {supervisor_phone}",
                $common],
            ['repeated_absence', 'غياب متكرر', 'Repeated absence',
                "السلام عليكم {guardian_name}،\nتغيّب {name} {absence_count} مرات خلال الثلاثين يومًا الماضية. نرجو التواصل مع المشرف على الرقم {supervisor_phone}.",
                "Peace be upon you {guardian_name},\n{name} has been absent {absence_count} times in the last 30 days. Please contact the supervisor on {supervisor_phone}.",
                array_merge($common, ['absence_count'])],
            ['session_cancelled', 'إلغاء حصة الصف', 'Lesson cancelled',
                'نعتذر، أُلغي صف {lesson} ليوم {date}. موعد الصف القادم: {next_date}.',
                'Sorry, the lesson {lesson} on {date} is cancelled. Next lesson: {next_date}.',
                $common],
            ['excuse_received', 'استلام العذر', 'Excuse received',
                'تم تسجيل عذر {name}، شكرًا لإبلاغنا.',
                "{name}'s excuse has been recorded. Thank you for letting us know.",
                ['name']],
            ['notifications_stopped', 'إيقاف الإشعارات', 'Notifications stopped',
                'تم إيقاف رسائل الصفوف لهذا الرقم. لإعادة التشغيل أرسل: تشغيل',
                'Lesson messages are stopped for this number. Send "start" to turn them back on.',
                []],
            ['notifications_resumed', 'تشغيل الإشعارات', 'Notifications resumed',
                'تمت إعادة تشغيل رسائل الصفوف لهذا الرقم.',
                'Lesson messages are back on for this number.',
                []],
            ['auto_reply_generic', 'رد تلقائي', 'Automatic reply',
                'شكرًا لتواصلكم مع {authority}. وصلت رسالتكم إلى المشرف وسيتم الرد قريبًا. للتأكيد أرسل: حاضر · للاعتذار: عذر · للإيقاف: إيقاف',
                'Thank you for contacting {authority}. Your message has reached the supervisor, who will reply soon. Send "present", "excuse" or "stop".',
                ['authority']],
        ];

        foreach ($t as [$key, $nameAr, $nameEn, $bodyAr, $bodyEn, $vars]) {
            MessageTemplate::firstOrCreate(['key' => $key], [
                'name_ar' => $nameAr, 'name_en' => $nameEn,
                'body_ar' => $bodyAr, 'body_en' => $bodyEn,
                'variables' => $vars, 'is_active' => true,
            ]);
        }
    }
}
