<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Certificate kinds (spec section 16). "completion" is memorization completion (a juz, a group of surahs or the whole Quran). */
enum CertificateType: string
{
    use HasLabel;

    case Completion = 'completion';
    case Excellence = 'excellence';
    case Competition = 'competition';
    case Exam = 'exam';
    case Attendance = 'attendance';
    case Participation = 'participation';
}
