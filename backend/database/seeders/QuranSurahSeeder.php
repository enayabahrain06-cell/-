<?php

namespace Database\Seeders;

use App\Models\QuranSurah;
use App\Support\Quran;
use Illuminate\Database\Seeder;

/** Reference data: the 114 surahs (also inserted by their migration; this keeps them in sync). */
class QuranSurahSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Quran::surahRows() as $row) {
            QuranSurah::updateOrCreate(['number' => $row['number']], $row);
        }
    }
}
