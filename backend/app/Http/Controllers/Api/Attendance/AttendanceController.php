<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\SaveAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\LessonSessionResource;
use App\Http\Resources\StudentSummaryResource;
use App\Models\LessonSession;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Attendance
 */
class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $service) {}

    /** Roster for a session: every active student with their attendance (or null) and current assignment. */
    public function show(LessonSession $session): JsonResponse
    {
        $this->authorize('viewAttendance', $session);

        $rows = collect($this->service->roster($session))->map(fn ($row) => [
            'student' => new StudentSummaryResource($row['student']),
            'current_memorization' => $row['current_memorization'],
            'current_revision' => $row['current_revision'],
            'attendance' => $row['attendance'] ? new AttendanceResource($row['attendance']) : null,
        ]);

        return response()->json([
            'session' => new LessonSessionResource($session->load(['lesson.teacher', 'location'])),
            'roster' => $rows,
        ]);
    }

    public function save(SaveAttendanceRequest $request, LessonSession $session): JsonResponse
    {
        $result = $this->service->save($session, $request->validated('records'), $request->user());

        return response()->json(['message' => __('api.saved')] + $result);
    }

    public function markAllPresent(Request $request, LessonSession $session): JsonResponse
    {
        $this->authorize('recordAttendance', $session);
        $result = $this->service->markAllPresent($session, $request->user());

        return response()->json(['message' => __('api.saved')] + $result);
    }
}
