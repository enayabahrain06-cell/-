<?php

namespace App\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

/**
 * Renders Blade views to PDF with the embedded Arabic fonts (resources/fonts).
 * Views must wrap Arabic strings in pdf_ar() because dompdf does not shape Arabic.
 * Use @include('pdf.base') conventions: see resources/views/pdf/base.blade.php.
 */
class PdfService
{
    public function render(string $view, array $data = [], string $orientation = 'portrait', string $paper = 'a4'): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::setOptions([
            'fontDir' => storage_path('fonts'),
            'fontCache' => storage_path('fonts'),
            'chroot' => base_path(),
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'amiri',
            'dpi' => 96,
        ])->loadView($view, $data + ['fonts' => self::fontFaces()])->setPaper($paper, $orientation);

        return $pdf->output();
    }

    /** CSS @font-face block for the bundled fonts (absolute paths inside chroot). */
    public static function fontFaces(): string
    {
        $dir = str_replace('\\', '/', resource_path('fonts'));
        $css = '';
        foreach ([
            ['amiri', 'normal', 'Amiri-Regular.ttf'],
            ['amiri', 'bold', 'Amiri-Bold.ttf'],
            ['plexarabic', 'normal', 'IBMPlexSansArabic-Regular.ttf'],
        ] as [$family, $weight, $file]) {
            if (is_file("{$dir}/{$file}")) {
                $css .= "@font-face{font-family:'{$family}';font-style:normal;font-weight:{$weight};src:url('{$dir}/{$file}') format('truetype');}\n";
            }
        }

        return $css;
    }
}
