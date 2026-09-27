<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum CertificateType: string
{
    use HasLabel;

    case Completion = 'completion';
    case Exam = 'exam';
    case Excellence = 'excellence';
    case Competition = 'competition';
}
