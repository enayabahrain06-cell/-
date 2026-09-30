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

    // معرض الصور (Phase 8): served only by the gallery file route, never by media/{media}.
    case GalleryImage = 'gallery_image';
    case GalleryThumb = 'gallery_thumb';
    case GalleryVideo = 'gallery_video';
}
