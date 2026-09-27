<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Student portal
 */
class MyAttendanceController extends Controller
{
    /** Attendance of the authenticated student, or of all children of the authenticated guardian. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $studentIds = collect([$user->student?->id])->merge($user->children()->pluck('id'))->filter()->unique()->values();

        $rows = Attendance::with(['student', 'session.lesson'])
            ->whereIn('student_id', $studentIds->all())
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereHas('session', fn ($s) => $s->where('session_date', '>=', $request->string('from'))))
            ->when($request->filled('to'), fn ($q) => $q->whereHas('session', fn ($s) => $s->where('session_date', '<=', $request->string('to'))))
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 30));

        return AttendanceResource::collection($rows);
    }
}
