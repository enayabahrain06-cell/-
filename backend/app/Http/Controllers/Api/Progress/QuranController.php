<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Models\QuranSurah;
use App\Support\Quran;
use Illuminate\Http\JsonResponse;

/**
 * @group Memorization & evaluation
 */
class QuranController extends Controller
{
    /** Surah list for the two-tap picker (surah + ayah range), plus juz boundaries. */
    public function surahs(): JsonResponse
    {
        $locale = app()->getLocale();

        return response()->json([
            'data' => QuranSurah::orderBy('number')->get()->map(fn (QuranSurah $s) => [
                'number' => $s->number,
                'name' => $locale === 'en' ? $s->name_en : $s->name_ar,
                'name_ar' => $s->name_ar,
                'name_en' => $s->name_en,
                'ayah_count' => $s->ayah_count,
                'juz_start' => $s->juz_start,
            ]),
            'juz_starts' => collect(Quran::JUZ_STARTS)->map(fn ($p, $j) => ['juz' => $j, 'surah' => $p[0], 'ayah' => $p[1], 'ayah_total' => Quran::juzTotals()[$j]])->values(),
            'total_ayahs' => Quran::TOTAL_AYAHS,
        ]);
    }
}
