<?php

namespace App\Certificates;

use Ahl\Certificates\Contracts\PdfRenderer;
use App\Services\Pdf\PdfService;

/** Renders through PdfService so certificates get the bundled Arabic fonts. */
class AhlPdfRenderer implements PdfRenderer
{
    public function __construct(private PdfService $pdf) {}

    public function render(string $view, array $data, string $orientation = 'landscape', string $paper = 'a4'): string
    {
        return $this->pdf->render($view, $data, $orientation, $paper);
    }
}
