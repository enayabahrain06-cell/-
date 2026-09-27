<?php

namespace Database\Seeders;

use App\Models\AgeGroup;
use Illuminate\Database\Seeder;

/** Default age groups (editable in Settings → Age groups). Only seeded when the table is empty. */
class AgeGroupSeeder extends Seeder
{
    public function run(): void
    {
        if (AgeGroup::exists()) {
            return;
        }
        foreach ([
            ['الأشبال (٦–٨)', 'Juniors (6–8)', 6, 8],
            ['البراعم (٩–١١)', 'Buds (9–11)', 9, 11],
            ['الناشئة (١٢–١٤)', 'Youth (12–14)', 12, 14],
            ['الشباب (١٥+)', 'Seniors (15+)', 15, null],
        ] as $i => [$ar, $en, $min, $max]) {
            AgeGroup::create(['name_ar' => $ar, 'name_en' => $en, 'min_age' => $min, 'max_age' => $max, 'sort' => $i + 1]);
        }
    }
}
