<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Every certificate starts as a draft and becomes final only after a supervisor approves it. */
enum CertificateStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Approved = 'approved';
    case Revoked = 'revoked';
}
