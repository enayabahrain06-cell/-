<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "حلقة" is now "صف" in the UI. The default WhatsApp templates and one badge text were seeded into the database,
 * so seeding again does not change them. This updates a stored text only while it still equals the old default
 * exactly: a template an admin has edited is left alone. down() puts the old default back the same way.
 */
return new class extends Migration
{
    /** [table, key, column, old default, new default] */
    private const RENAMES = [
        ['message_templates', 'pre_lesson_reminder', 'name_ar', 'تذكير قبل الحلقة', 'تذكير قبل الصف'],
        ['message_templates', 'pre_lesson_reminder', 'body_ar', 'تذكير: حلقة {lesson} مع الأستاذ {teacher} اليوم الساعة {time} في {location}.
المقرر: {assignment}
{map_link}', 'تذكير: صف {lesson} مع الأستاذ {teacher} اليوم الساعة {time} في {location}.
المقرر: {assignment}
{map_link}'],
        ['message_templates', 'absence', 'body_ar', 'تغيّب {name} عن حلقة {lesson} بتاريخ {date}.
المقرر الذي فاته: {assignment}', 'تغيّب {name} عن صف {lesson} بتاريخ {date}.
المقرر الذي فاته: {assignment}'],
        ['message_templates', 'location_change', 'body_ar', 'تنبيه: تم نقل حلقة {lesson} بتاريخ {date} إلى {location}.
الموقع: {map_link}', 'تنبيه: تم نقل صف {lesson} بتاريخ {date} إلى {location}.
الموقع: {map_link}'],
        ['message_templates', 'lottery_result', 'name_en', 'Circle assignment', 'Class assignment'],
        ['message_templates', 'lottery_result', 'body_ar', 'تم توزيع {name} على حلقة {lesson} مع الأستاذ {teacher}.
أول درس: {date} الساعة {time} في {location}.', 'تم توزيع {name} على صف {lesson} مع الأستاذ {teacher}.
أول درس: {date} الساعة {time} في {location}.'],
        ['message_templates', 'attendance_reminder_long', 'name_ar', 'تذكير الحلقة (قبل ساعتين)', 'تذكير بموعد الصف (قبل ساعتين)'],
        ['message_templates', 'attendance_reminder_long', 'body_ar', 'السلام عليكم {guardian_name}،
تذكير بحلقة {lesson} لـ{name} اليوم {date} الساعة {time} في {location}.
المقرر: {assignment}
الموقع: {map_link}
للتأكيد أرسل: حاضر · للاعتذار أرسل: عذر', 'السلام عليكم {guardian_name}،
تذكير بموعد صف {lesson} لـ{name} اليوم {date} الساعة {time} في {location}.
المقرر: {assignment}
الموقع: {map_link}
للتأكيد أرسل: حاضر · للاعتذار أرسل: عذر'],
        ['message_templates', 'attendance_reminder_short', 'name_ar', 'تذكير الحلقة (قبل ساعة)', 'تذكير بموعد الصف (قبل ساعة)'],
        ['message_templates', 'attendance_reminder_short', 'body_ar', 'تبدأ حلقة {lesson} لـ{name} بعد ساعة، الساعة {time} في {location}.', 'يبدأ صف {lesson} لـ{name} بعد ساعة، الساعة {time} في {location}.'],
        ['message_templates', 'absence_notice', 'body_ar', 'السلام عليكم {guardian_name}،
نفيدكم بغياب {name} عن حلقة {lesson} يوم {date}.
المقرر الفائت: {assignment}
الحلقة القادمة: {next_date}
للاستفسار: {supervisor_phone}', 'السلام عليكم {guardian_name}،
نفيدكم بغياب {name} عن صف {lesson} يوم {date}.
المقرر الفائت: {assignment}
موعد الصف القادم: {next_date}
للاستفسار: {supervisor_phone}'],
        ['message_templates', 'session_cancelled', 'name_ar', 'إلغاء الحلقة', 'إلغاء حصة الصف'],
        ['message_templates', 'session_cancelled', 'body_ar', 'نعتذر، أُلغيت حلقة {lesson} ليوم {date}. الحلقة القادمة: {next_date}.', 'نعتذر، أُلغي صف {lesson} ليوم {date}. موعد الصف القادم: {next_date}.'],
        ['message_templates', 'notifications_stopped', 'body_ar', 'تم إيقاف رسائل الحلقات لهذا الرقم. لإعادة التشغيل أرسل: تشغيل', 'تم إيقاف رسائل الصفوف لهذا الرقم. لإعادة التشغيل أرسل: تشغيل'],
        ['message_templates', 'notifications_resumed', 'body_ar', 'تمت إعادة تشغيل رسائل الحلقات لهذا الرقم.', 'تمت إعادة تشغيل رسائل الصفوف لهذا الرقم.'],
        ['badges', 'full_attendance', 'description_ar', 'حضر جميع حلقات الشهر.', 'حضر جميع حصص الشهر.'],
    ];

    public function up(): void
    {
        $this->swap(3, 4);
    }

    public function down(): void
    {
        $this->swap(4, 3);
    }

    private function swap(int $from, int $to): void
    {
        foreach (self::RENAMES as $r) {
            if (! Schema::hasTable($r[0]) || ! Schema::hasColumn($r[0], $r[2])) {
                continue;
            }
            DB::table($r[0])->where('key', $r[1])->where($r[2], $r[$from])->update([$r[2] => $r[$to], 'updated_at' => now()]);
        }
    }
};
