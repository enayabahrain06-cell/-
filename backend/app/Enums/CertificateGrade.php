<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Grade on memorization certificates, from the average evaluation over the memorized range (thresholds in settings). */
enum CertificateGrade: string
{
    use HasLabel;

    case Excellent = 'excellent';
    case VeryGood = 'very_good';
    case Good = 'good';
}
