<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

/** Reference data: default badge rules (editable later from the admin panel; re-seeding keeps admin edits to rules). */
class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            ['hafiz_juz_amma', 'حافظ جزء عمّ', 'Hafiz of Juz Amma', 'أتمّ حفظ جزء عمّ كاملاً.', 'Completed memorizing Juz Amma.', 'medal', 'completed_juz', 30, false, 50],
            ['hafiz_juz_tabarak', 'حافظ جزء تبارك', 'Hafiz of Juz Tabarak', 'أتمّ حفظ جزء تبارك كاملاً.', 'Completed memorizing Juz Tabarak.', 'medal', 'completed_juz', 29, false, 50],
            ['full_attendance', 'حضور كامل', 'Full attendance', 'حضر جميع حلقات الشهر.', 'Attended every session of the month.', 'calendar', 'full_attendance', 100, true, 10],
            ['excellent_tajweed', 'تجويد ممتاز', 'Excellent tajweed', 'متوسط التجويد ٩ فأكثر خلال الشهر.', 'Tajweed average of 9 or more in the month.', 'star', 'tajweed_average', 900, true, 10],
            ['most_improved', 'الأكثر تقدماً', 'Most improved', 'أكبر زيادة في النقاط مقارنة بالشهر السابق.', 'Largest points increase compared with the previous month.', 'trend', 'most_improved', null, true, 15],
            ['competition_winner', 'فائز في مسابقة', 'Competition winner', 'حصل على أحد المراكز الأولى في مسابقة.', 'Placed in the top ranks of a competition.', 'trophy', 'competition', null, true, 0],
            ['challenge_champion', 'بطل التحدي', 'Challenge champion', 'أكمل تحدياً بنجاح.', 'Completed a challenge.', 'flag', 'challenge', null, true, 0],
        ];

        foreach ($badges as $i => [$key, $ar, $en, $dAr, $dEn, $icon, $rule, $value, $monthly, $bonus]) {
            $badge = Badge::firstOrNew(['key' => $key]);
            // Names and descriptions are refreshed; rule values keep any admin edits after the first seed.
            $badge->fill(['name_ar' => $ar, 'name_en' => $en, 'description_ar' => $dAr, 'description_en' => $dEn, 'icon' => $icon, 'sort_order' => $i + 1]);
            if (! $badge->exists) {
                $badge->fill(['rule_type' => $rule, 'rule_value' => $value, 'repeatable_monthly' => $monthly, 'bonus_points' => $bonus, 'is_active' => true]);
            }
            $badge->save();
        }
    }
}
