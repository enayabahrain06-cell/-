<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Reference data: WhatsApp templates for the honor board, competitions and challenges (AR/EN).
 * Idempotent; admin edits to bodies survive re-seeding (only missing templates are created).
 * Variables: {name} {place} {month} {lesson} {points} {competition} {round} {location} {date} {challenge} {progress} {days_left}
 */
class EngagementTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $t = [
            ['honor_congrats', 'تهنئة لوحة التميز', 'Honor board congratulations',
                "مبارك! حصل {name} على {place} في لوحة التميز لشهر {month} ({lesson}) بمجموع {points} نقطة. بارك الله فيه.",
                "Congratulations! {name} took {place} on the honor board for {month} ({lesson}) with {points} points.",
                ['name', 'place', 'month', 'lesson', 'points']],
            ['competition_open', 'فتح التسجيل في مسابقة', 'Competition registration open',
                "فُتح التسجيل في مسابقة {competition}. آخر موعد للتسجيل: {date}.",
                "Registration is open for {competition}. Closing date: {date}.",
                ['name', 'competition', 'date']],
            ['competition_closing', 'قرب إغلاق التسجيل', 'Competition registration closing',
                "تذكير: يغلق التسجيل في مسابقة {competition} يوم {date}.",
                "Reminder: registration for {competition} closes on {date}.",
                ['name', 'competition', 'date']],
            ['competition_round', 'موعد جولة المسابقة', 'Competition round',
                "تذكير: جولة {round} من مسابقة {competition} يوم {date} في {location}.",
                "Reminder: {round} of {competition} is on {date} at {location}.",
                ['name', 'competition', 'round', 'date', 'location']],
            ['competition_result', 'نتيجة المسابقة', 'Competition result',
                "نتيجة {name} في مسابقة {competition}: {place}.",
                "{name}'s result in {competition}: {place}.",
                ['name', 'competition', 'place']],
            ['challenge_nudge', 'تشجيع في التحدي', 'Challenge progress',
                "أحسنت يا {name}! أنجزت {progress} من تحدي {challenge}. بقي {days_left} يوم.",
                "Well done {name}! You have completed {progress} of the {challenge} challenge. {days_left} days left.",
                ['name', 'challenge', 'progress', 'days_left']],
            ['challenge_deadline', 'قرب نهاية التحدي', 'Challenge ending soon',
                "بقي {days_left} أيام على نهاية تحدي {challenge}. إنجازك الحالي {progress}.",
                "{days_left} days left in the {challenge} challenge. Your progress: {progress}.",
                ['name', 'challenge', 'progress', 'days_left']],
            ['challenge_completed', 'إكمال التحدي', 'Challenge completed',
                "مبارك! أكمل {name} تحدي {challenge}.",
                "Congratulations! {name} completed the {challenge} challenge.",
                ['name', 'challenge']],
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
