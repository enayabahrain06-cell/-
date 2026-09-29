<?php

namespace App\Models;

use Ahl\Certificates\Models\CertificateTemplate as BaseTemplate;
use App\Models\Concerns\HasMedia;

/**
 * The certificates package template. Signature images are media in the "signature" collection,
 * told apart by original_name signature_1 / signature_2 (see App\Certificates\MediaFileStore).
 */
class CertificateTemplate extends BaseTemplate
{
    use HasMedia;
}
