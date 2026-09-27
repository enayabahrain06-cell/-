<?php

namespace App\Http\Controllers\Api\Lessons;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\UpdateSessionRequest;
use App\Http\Resources\LessonSessionResource;
use App\Models\LessonSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Lessons & locations
 */
class LessonSessionController extends Controller
{
    /** Today's sessions (or ?date=) with lesson, teacher, location and attendance flag. Teachers only see their own. */
    public function today(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', \App\Models\Lesson::class);
        $user = $request->user();
        $date = $request->filled('date') ? $request->string('date')->toString() : today()->toDateString();

        $sessions = LessonSession::with(['lesson.teacher', 'location'])
            ->withCount([
                'attendances as present_count' => fn ($q) => $q->where('status', 'present'),
                'attendances as absent_count' => fn ($q) => $q->where('status', 'absent'),
            ])
            ->where('session_date', $date)
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereHas('lesson', fn ($l) => $l->where('teacher_id', $user->id)))
            ->orderBy('start_time')
            ->get();

        return LessonSessionResource::collection($sessions);
    }

    public function show(LessonSession $session): LessonSessionResource
    {
        $this->authorize('view', $session);

        return new LessonSessionResource($session->load(['lesson.teacher', 'location']));
    }

    public function update(UpdateSessionRequest $request, LessonSession $session): LessonSessionResource
    {
        $session->update($request->validated());

        return new LessonSessionResource($session->fresh()->load(['lesson.teacher', 'location']));
    }
}
