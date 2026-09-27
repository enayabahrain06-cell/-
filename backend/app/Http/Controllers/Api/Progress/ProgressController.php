<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\StoreProgressRequest;
use App\Models\Student;
use App\Models\StudentProgress;
use App\Services\Progress\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Memorization & evaluation
 */
class ProgressController extends Controller
{
    /** Ledger rows (newest first). Filter: type=memorized|revised. */
    public function index(Request $request, Student $student, ProgressService $progress): JsonResponse
    {
        $this->authorize('view', $student);

        $page = $student->progress()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderByDesc('recorded_on')->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => $progress->ledgerRow($r)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Append today's memorized or revised range; returns the refreshed position. */
    public function store(StoreProgressRequest $request, Student $student, ProgressService $progress): JsonResponse
    {
        $row = $progress->append($student, $request->validated(), $request->user()->id);

        return response()->json([
            'message' => __('progress.recorded'),
            'entry' => $progress->ledgerRow($row),
            'position' => $progress->summary($student->fresh())['position'],
        ], 201);
    }

    /** Correct a mistaken entry (the position is recomputed). */
    public function destroy(Request $request, StudentProgress $progressEntry, ProgressService $progress): JsonResponse
    {
        $this->authorize('recordProgress', $progressEntry->student);
        $progress->remove($progressEntry);

        return response()->json(['message' => __('progress.removed')]);
    }
}
