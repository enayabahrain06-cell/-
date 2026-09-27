<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Reports\EngagementReport;
use App\Services\Reports\ReportResponder;
use Illuminate\Http\Request;

/**
 * @group Reports
 */
class EngagementReportController extends Controller
{
    /** Competition participation and results, challenge completion, monthly top performers. Filters: from, to, gender. format=xlsx|pdf exports. */
    public function show(Request $request, EngagementReport $report, ReportResponder $responder)
    {
        abort_unless($request->user()->can('reports.view'), 403);
        if (in_array($request->string('format')->toString(), ['xlsx', 'pdf'], true)) {
            abort_unless($request->user()->can('reports.export'), 403);
        }
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'gender' => ['nullable', 'in:male,female']]);

        return $responder->respond($request, $report->build($request->user(), $data), 'engagement-report');
    }
}
