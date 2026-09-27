<?php

use App\Enums\MemorizationDirection as Dir;
use App\Support\Quran;

it('holds the full Hafs reference: 114 surahs, 6236 ayahs, 30 juz covering every ayah', function () {
    expect(Quran::SURAHS)->toHaveCount(114)
        ->and(array_sum(array_column(Quran::SURAHS, 2)))->toBe(6236)
        ->and(Quran::juzTotals())->toHaveCount(30)
        ->and(array_sum(Quran::juzTotals()))->toBe(6236);
});

it('derives the mushaf juz from surah and ayah at every boundary', function (int $surah, int $ayah, int $juz) {
    expect(Quran::juzOf($surah, $ayah))->toBe($juz);
})->with([
    'Al-Fatihah 1' => [1, 1, 1],
    'Al-Baqarah 141 (last of juz 1)' => [2, 141, 1],
    'Al-Baqarah 142 (first of juz 2)' => [2, 142, 2],
    'Al-Kahf 74' => [18, 74, 15],
    'Al-Kahf 75' => [18, 75, 16],
    'At-Tahrim 12 (end of juz 28)' => [66, 12, 28],
    'Al-Mulk 1 (juz Tabarak)' => [67, 1, 29],
    'An-Naba 1 (juz Amma)' => [78, 1, 30],
    'An-Nas 6' => [114, 6, 30],
]);

it('counts juz from the student side for each direction', function () {
    // The mushaf juz is the same in both directions; the ordinal is the student's own count.
    expect(Quran::juzOrdinal(30, Dir::Backward))->toBe(1)
        ->and(Quran::juzOrdinal(29, Dir::Backward))->toBe(2)
        ->and(Quran::juzOrdinal(1, Dir::Backward))->toBe(30)
        ->and(Quran::juzOrdinal(30, Dir::Forward))->toBe(30)
        ->and(Quran::juzOrdinal(1, Dir::Forward))->toBe(1);
});

it('orders ayahs forward from Al-Fatihah and backward from An-Nas (ayahs ascending inside each surah)', function () {
    expect(Quran::sequenceIndex(1, 1, Dir::Forward))->toBe(1)
        ->and(Quran::sequenceIndex(2, 1, Dir::Forward))->toBe(8)
        ->and(Quran::sequenceIndex(114, 6, Dir::Forward))->toBe(6236)
        ->and(Quran::sequenceIndex(114, 1, Dir::Backward))->toBe(1)
        ->and(Quran::sequenceIndex(114, 6, Dir::Backward))->toBe(6)
        ->and(Quran::sequenceIndex(113, 1, Dir::Backward))->toBe(7)
        ->and(Quran::sequenceIndex(1, 7, Dir::Backward))->toBe(6236);

    foreach ([Dir::Forward, Dir::Backward] as $dir) {
        foreach ([[1, 1], [2, 286], [18, 75], [78, 40], [114, 6]] as [$s, $a]) {
            expect(Quran::fromSequenceIndex(Quran::sequenceIndex($s, $a, $dir), $dir))->toBe([$s, $a]);
        }
    }
});
