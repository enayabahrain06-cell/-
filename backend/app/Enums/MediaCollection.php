<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MediaCollection: string
{
    use HasLabel;

    case Photo = 'photo';
    case PhotoThumb = 'photo_thumb';
    case ReceiptImage = 'receipt_image';
    case ReceiptPdf = 'receipt_pdf';
    case ExamSheet = 'exam_sheet';
    case Recitation = 'recitation';
    case Certificate = 'certificate';
    case Logo = 'logo';
    case Signature = 'signature';
}
