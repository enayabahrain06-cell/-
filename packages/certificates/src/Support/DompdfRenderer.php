<?php

namespace Ahl\Certificates\Support;

use Ahl\Certificates\Contracts\PdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;

/** dompdf through barryvdh/laravel-dompdf. Bind your own renderer for custom fonts or text shaping. */
class DompdfRenderer implements PdfRenderer
{
    public function render(string $view, array $data, string $orientation = 'landscape', string $paper = 'a4'): string
    {
        return Pdf::setOptions(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true, 'dpi' => 96])
            ->loadView($view, $data)->setPaper($paper, $orientation)->output();
    }
}
