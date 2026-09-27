<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Where a certificate was issued from. */
enum CertificateSource: string
{
    use HasLabel;

    case Manual = 'manual';
    case AutoJuz = 'auto_juz';
    case Exam = 'exam';
    case HonorPeriod = 'honor_period';
    case Competition = 'competition';
    case Legacy = 'legacy';
}
