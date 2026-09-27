<?php

namespace App\Support;

use App\Enums\MemorizationDirection;

/**
 * Static Quran reference (Hafs, Madani mushaf, 6236 ayahs).
 *
 * The quran_surahs table is seeded from this class once (reference data); services read
 * this class directly so position maths never needs a query.
 *
 * Two orderings are supported:
 *  - forward:  Al-Fatiha (1) → An-Nas (114), ayahs ascending;
 *  - backward: An-Nas (114) → Al-Fatiha (1), surahs descending but ayahs ascending inside
 *              each surah (how most halaqat memorize: An-Nas, Al-Falaq, … each from ayah 1).
 */
final class Quran
{
    public const TOTAL_AYAHS = 6236;

    public const JUZ_COUNT = 30;

    /** number => [name_ar, name_en, ayah_count] */
    public const SURAHS = [
        1 => ['الفاتحة', 'Al-Fatihah', 7],
        2 => ['البقرة', 'Al-Baqarah', 286],
        3 => ['آل عمران', 'Aal-Imran', 200],
        4 => ['النساء', 'An-Nisa', 176],
        5 => ['المائدة', "Al-Ma'idah", 120],
        6 => ['الأنعام', "Al-An'am", 165],
        7 => ['الأعراف', "Al-A'raf", 206],
        8 => ['الأنفال', 'Al-Anfal', 75],
        9 => ['التوبة', 'At-Tawbah', 129],
        10 => ['يونس', 'Yunus', 109],
        11 => ['هود', 'Hud', 123],
        12 => ['يوسف', 'Yusuf', 111],
        13 => ['الرعد', "Ar-Ra'd", 43],
        14 => ['إبراهيم', 'Ibrahim', 52],
        15 => ['الحجر', 'Al-Hijr', 99],
        16 => ['النحل', 'An-Nahl', 128],
        17 => ['الإسراء', 'Al-Isra', 111],
        18 => ['الكهف', 'Al-Kahf', 110],
        19 => ['مريم', 'Maryam', 98],
        20 => ['طه', 'Ta-Ha', 135],
        21 => ['الأنبياء', 'Al-Anbiya', 112],
        22 => ['الحج', 'Al-Hajj', 78],
        23 => ['المؤمنون', "Al-Mu'minun", 118],
        24 => ['النور', 'An-Nur', 64],
        25 => ['الفرقان', 'Al-Furqan', 77],
        26 => ['الشعراء', "Ash-Shu'ara", 227],
        27 => ['النمل', 'An-Naml', 93],
        28 => ['القصص', 'Al-Qasas', 88],
        29 => ['العنكبوت', 'Al-Ankabut', 69],
        30 => ['الروم', 'Ar-Rum', 60],
        31 => ['لقمان', 'Luqman', 34],
        32 => ['السجدة', 'As-Sajdah', 30],
        33 => ['الأحزاب', 'Al-Ahzab', 73],
        34 => ['سبأ', 'Saba', 54],
        35 => ['فاطر', 'Fatir', 45],
        36 => ['يس', 'Ya-Sin', 83],
        37 => ['الصافات', 'As-Saffat', 182],
        38 => ['ص', 'Sad', 88],
        39 => ['الزمر', 'Az-Zumar', 75],
        40 => ['غافر', 'Ghafir', 85],
        41 => ['فصلت', 'Fussilat', 54],
        42 => ['الشورى', 'Ash-Shura', 53],
        43 => ['الزخرف', 'Az-Zukhruf', 89],
        44 => ['الدخان', 'Ad-Dukhan', 59],
        45 => ['الجاثية', 'Al-Jathiyah', 37],
        46 => ['الأحقاف', 'Al-Ahqaf', 35],
        47 => ['محمد', 'Muhammad', 38],
        48 => ['الفتح', 'Al-Fath', 29],
        49 => ['الحجرات', 'Al-Hujurat', 18],
        50 => ['ق', 'Qaf', 45],
        51 => ['الذاريات', 'Adh-Dhariyat', 60],
        52 => ['الطور', 'At-Tur', 49],
        53 => ['النجم', 'An-Najm', 62],
        54 => ['القمر', 'Al-Qamar', 55],
        55 => ['الرحمن', 'Ar-Rahman', 78],
        56 => ['الواقعة', "Al-Waqi'ah", 96],
        57 => ['الحديد', 'Al-Hadid', 29],
        58 => ['المجادلة', 'Al-Mujadilah', 22],
        59 => ['الحشر', 'Al-Hashr', 24],
        60 => ['الممتحنة', 'Al-Mumtahanah', 13],
        61 => ['الصف', 'As-Saff', 14],
        62 => ['الجمعة', "Al-Jumu'ah", 11],
        63 => ['المنافقون', 'Al-Munafiqun', 11],
        64 => ['التغابن', 'At-Taghabun', 18],
        65 => ['الطلاق', 'At-Talaq', 12],
        66 => ['التحريم', 'At-Tahrim', 12],
        67 => ['الملك', 'Al-Mulk', 30],
        68 => ['القلم', 'Al-Qalam', 52],
        69 => ['الحاقة', 'Al-Haqqah', 52],
        70 => ['المعارج', "Al-Ma'arij", 44],
        71 => ['نوح', 'Nuh', 28],
        72 => ['الجن', 'Al-Jinn', 28],
        73 => ['المزمل', 'Al-Muzzammil', 20],
        74 => ['المدثر', 'Al-Muddaththir', 56],
        75 => ['القيامة', 'Al-Qiyamah', 40],
        76 => ['الإنسان', 'Al-Insan', 31],
        77 => ['المرسلات', 'Al-Mursalat', 50],
        78 => ['النبأ', 'An-Naba', 40],
        79 => ['النازعات', "An-Nazi'at", 46],
        80 => ['عبس', 'Abasa', 42],
        81 => ['التكوير', 'At-Takwir', 29],
        82 => ['الانفطار', 'Al-Infitar', 19],
        83 => ['المطففين', 'Al-Mutaffifin', 36],
        84 => ['الانشقاق', 'Al-Inshiqaq', 25],
        85 => ['البروج', 'Al-Buruj', 22],
        86 => ['الطارق', 'At-Tariq', 17],
        87 => ['الأعلى', "Al-A'la", 19],
        88 => ['الغاشية', 'Al-Ghashiyah', 26],
        89 => ['الفجر', 'Al-Fajr', 30],
        90 => ['البلد', 'Al-Balad', 20],
        91 => ['الشمس', 'Ash-Shams', 15],
        92 => ['الليل', 'Al-Layl', 21],
        93 => ['الضحى', 'Ad-Duha', 11],
        94 => ['الشرح', 'Ash-Sharh', 8],
        95 => ['التين', 'At-Tin', 8],
        96 => ['العلق', "Al-'Alaq", 19],
        97 => ['القدر', 'Al-Qadr', 5],
        98 => ['البينة', 'Al-Bayyinah', 8],
        99 => ['الزلزلة', 'Az-Zalzalah', 8],
        100 => ['العاديات', "Al-'Adiyat", 11],
        101 => ['القارعة', "Al-Qari'ah", 11],
        102 => ['التكاثر', 'At-Takathur', 8],
        103 => ['العصر', "Al-'Asr", 3],
        104 => ['الهمزة', 'Al-Humazah', 9],
        105 => ['الفيل', 'Al-Fil', 5],
        106 => ['قريش', 'Quraysh', 4],
        107 => ['الماعون', "Al-Ma'un", 7],
        108 => ['الكوثر', 'Al-Kawthar', 3],
        109 => ['الكافرون', 'Al-Kafirun', 6],
        110 => ['النصر', 'An-Nasr', 3],
        111 => ['المسد', 'Al-Masad', 5],
        112 => ['الإخلاص', 'Al-Ikhlas', 4],
        113 => ['الفلق', 'Al-Falaq', 5],
        114 => ['الناس', 'An-Nas', 6],
    ];

    /** juz => [surah, ayah] where that juz begins. */
    public const JUZ_STARTS = [
        1 => [1, 1], 2 => [2, 142], 3 => [2, 253], 4 => [3, 93], 5 => [4, 24],
        6 => [4, 148], 7 => [5, 82], 8 => [6, 111], 9 => [7, 88], 10 => [8, 41],
        11 => [9, 93], 12 => [11, 6], 13 => [12, 53], 14 => [15, 1], 15 => [17, 1],
        16 => [18, 75], 17 => [21, 1], 18 => [23, 1], 19 => [25, 21], 20 => [27, 56],
        21 => [29, 46], 22 => [33, 31], 23 => [36, 28], 24 => [39, 32], 25 => [41, 47],
        26 => [46, 1], 27 => [51, 31], 28 => [58, 1], 29 => [67, 1], 30 => [78, 1],
    ];

    /** @var array<int,int>|null surah => ayahs before it in mushaf order */
    private static ?array $forwardOffsets = null;

    /** @var array<int,int>|null surah => ayahs before it in backward order */
    private static ?array $backwardOffsets = null;

    /** @var array<int,int>|null juz => ayah total */
    private static ?array $juzTotals = null;

    public static function exists(int $surah, ?int $ayah = null): bool
    {
        if (! isset(self::SURAHS[$surah])) {
            return false;
        }

        return $ayah === null || ($ayah >= 1 && $ayah <= self::SURAHS[$surah][2]);
    }

    public static function ayahCount(int $surah): int
    {
        return self::SURAHS[$surah][2];
    }

    public static function name(int $surah, string $locale = 'ar'): string
    {
        return self::SURAHS[$surah][$locale === 'en' ? 1 : 0];
    }

    /** Mushaf juz (1–30) containing the ayah. Independent of direction. */
    public static function juzOf(int $surah, int $ayah): int
    {
        $juz = 1;
        foreach (self::JUZ_STARTS as $j => [$s, $a]) {
            if ($s < $surah || ($s === $surah && $a <= $ayah)) {
                $juz = $j;
            } else {
                break;
            }
        }

        return $juz;
    }

    /**
     * Juz ordinal in the student's own journey: forward students count juz 1 first,
     * backward students count juz 30 (Amma) as their first juz.
     */
    public static function juzOrdinal(int $juz, MemorizationDirection $direction): int
    {
        return $direction === MemorizationDirection::Backward ? self::JUZ_COUNT + 1 - $juz : $juz;
    }

    /** Position 1..6236 in mushaf order. */
    public static function globalIndex(int $surah, int $ayah): int
    {
        return self::forwardOffsets()[$surah] + $ayah;
    }

    /** Inverse of globalIndex. @return array{0:int,1:int} [surah, ayah] */
    public static function fromGlobalIndex(int $index): array
    {
        $offsets = self::forwardOffsets();
        for ($s = 114; $s >= 1; $s--) {
            if ($index > $offsets[$s]) {
                return [$s, $index - $offsets[$s]];
            }
        }

        return [1, 1];
    }

    /** Position 1..6236 in the chosen memorization order. */
    public static function sequenceIndex(int $surah, int $ayah, MemorizationDirection $direction): int
    {
        return $direction === MemorizationDirection::Forward
            ? self::globalIndex($surah, $ayah)
            : self::backwardOffsets()[$surah] + $ayah;
    }

    /** Inverse of sequenceIndex. @return array{0:int,1:int} */
    public static function fromSequenceIndex(int $index, MemorizationDirection $direction): array
    {
        if ($direction === MemorizationDirection::Forward) {
            return self::fromGlobalIndex($index);
        }
        $offsets = self::backwardOffsets();
        for ($s = 1; $s <= 114; $s++) {
            if ($index > $offsets[$s]) {
                return [$s, $index - $offsets[$s]];
            }
        }

        return [114, 1];
    }

    /** Where a student with nothing memorized starts. @return array{0:int,1:int} */
    public static function startOf(MemorizationDirection $direction): array
    {
        return $direction === MemorizationDirection::Forward ? [1, 1] : [114, 1];
    }

    /** @return array<int,int> juz => ayah count */
    public static function juzTotals(): array
    {
        if (self::$juzTotals !== null) {
            return self::$juzTotals;
        }
        $starts = [];
        foreach (self::JUZ_STARTS as $j => [$s, $a]) {
            $starts[$j] = self::globalIndex($s, $a);
        }
        $totals = [];
        foreach ($starts as $j => $start) {
            $end = $j < self::JUZ_COUNT ? $starts[$j + 1] - 1 : self::TOTAL_AYAHS;
            $totals[$j] = $end - $start + 1;
        }

        return self::$juzTotals = $totals;
    }

    /** Rows for the quran_surahs reference table. */
    public static function surahRows(): array
    {
        $rows = [];
        foreach (self::SURAHS as $n => [$ar, $en, $count]) {
            $rows[] = ['number' => $n, 'name_ar' => $ar, 'name_en' => $en, 'ayah_count' => $count, 'juz_start' => self::juzOf($n, 1)];
        }

        return $rows;
    }

    private static function forwardOffsets(): array
    {
        if (self::$forwardOffsets === null) {
            $sum = 0;
            foreach (self::SURAHS as $n => $s) {
                self::$forwardOffsets[$n] = $sum;
                $sum += $s[2];
            }
        }

        return self::$forwardOffsets;
    }

    private static function backwardOffsets(): array
    {
        if (self::$backwardOffsets === null) {
            $sum = 0;
            for ($n = 114; $n >= 1; $n--) {
                self::$backwardOffsets[$n] = $sum;
                $sum += self::SURAHS[$n][2];
            }
        }

        return self::$backwardOffsets;
    }
}
