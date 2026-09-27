<?php

namespace App\Services\Reports;

use App\Exports\ReportExport;
use App\Services\Pdf\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every report returns the same shape, so one responder serves JSON, Excel and PDF:
 *   ['title' => string, 'period' => ?string, 'filters' => array, 'summary' => [[label, value]...],
 *    'sections' => [['key', 'title', 'headings' => [...], 'rows' => [[...]...]]], 'data' => mixed (for the UI)]
 * ?format=xlsx|pdf (default json). Headings, titles and PDFs follow the request locale.
 */
class ReportResponder
{
    public function __construct(private PdfService $pdf) {}

    public function respond(Request $request, array $report, string $filename): JsonResponse|Response
    {
        return match ($request->string('format')->toString()) {
            'xlsx' => Excel::download(new ReportExport($report), $filename.'.xlsx'),
            'pdf' => response($this->pdf->render('pdf.report', ['report' => $report, 'locale' => app()->getLocale()], count($report['sections'][0]['headings'] ?? []) > 5 ? 'landscape' : 'portrait'), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'.pdf"',
            ]),
            default => response()->json($report),
        };
    }
}
