<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Controller;
use App\Services\Reports\FinanceReport;
use App\Services\Reports\ReportResponder;
use Illuminate\Http\Request;

/**
 * @group Reports
 */
class FinanceReportController extends Controller
{
    /**
     * Collected per package and month, refunds, payment methods and outstanding per student.
     * Filters: from, to, package_id, gender. Add format=xlsx or format=pdf to export.
     */
    public function show(Request $request, FinanceReport $report, ReportResponder $responder)
    {
        abort_unless($request->user()->can('reports.view') || $request->user()->can('wallets.view'), 403);
        if (in_array($request->string('format')->toString(), ['xlsx', 'pdf'], true)) {
            abort_unless($request->user()->can('reports.export'), 403);
        }
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'package_id' => ['nullable', 'integer'], 'gender' => ['nullable', 'in:male,female'],
        ]);

        return $responder->respond($request, $report->build($request->user(), $data), 'finance-report');
    }
}
