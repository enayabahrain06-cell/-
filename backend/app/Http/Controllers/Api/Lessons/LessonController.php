<?php

namespace App\Http\Controllers\Api\Lessons;

use App\Enums\LessonStudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\ChangeLocationRequest;
use App\Http\Requests\Lessons\EnrollStudentsRequest;
use App\Http\Requests\Lessons\StoreLessonRequest;
use App\Http\Resources\LessonResource;
use App\Http\Resources\LessonSessionResource;
use App\Models\Lesson;
use App\Models\Student;
use App\Services\Lessons\LessonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Lessons & locations
 */
class LessonController extends Controller
{
    public function __construct(private LessonService $service) {}

    /** Filters: package_id, teacher_id, location_id, status, search. Teachers only see their own circles. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lesson::class);
        $user = $request->user();

        $lessons = Lesson::with(['package', 'teacher', 'location'])
            ->withCount(['lessonStudents as active_students_count' => fn ($q) => $q->where('status', LessonStudentStatus::Active->value)])
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->where('teacher_id', $user->id))
            ->tap(fn ($q) => \App\Support\Track::scope($q, $user))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name');

        return LessonResource::collection($request->boolean('all') ? $lessons->get() : $lessons->paginate((int) $request->integer('per_page', 25)));
    }

    public function store(StoreLessonRequest $request): JsonResponse
    {
        $result = $this->service->create($request->validated());

        return response()->json([
            'data' => new LessonResource($result['lesson']->load(['package', 'teacher', 'location'])),
            'conflicts' => $result['conflicts'],
        ], 201);
    }

    public function show(Lesson $lesson): LessonResource
    {
        $this->authorize('view', $lesson);

        $lesson->load([
            'package', 'teacher', 'location',
            'lessonStudents' => fn ($q) => $q->where('status', LessonStudentStatus::Active->value)->with('student.wallet'),
            'sessions' => fn ($q) => $q->where('session_date', '>=', today()->toDateString())->orderBy('session_date')->limit(10)->with('location'),
        ]);

        return new LessonResource($lesson);
    }

    public function update(StoreLessonRequest $request, Lesson $lesson): JsonResponse
    {
        $result = $this->service->update($lesson, $request->validated());

        return response()->json([
            'data' => new LessonResource($result['lesson']->load(['package', 'teacher', 'location'])),
            'conflicts' => $result['conflicts'],
        ]);
    }

    public function destroy(Lesson $lesson): JsonResponse
    {
        $this->authorize('delete', $lesson);
        $this->service->delete($lesson);

        return response()->json(['message' => __('api.deleted')]);
    }

    /** Conflicts for an existing lesson (used by the dashboard alert drill-down). */
    public function conflicts(Lesson $lesson): JsonResponse
    {
        $this->authorize('view', $lesson);

        return response()->json(['conflicts' => $this->service->syncConflicts($lesson)]);
    }

    public function enroll(EnrollStudentsRequest $request, Lesson $lesson): JsonResponse
    {
        $added = $this->service->enroll($lesson, $request->validated('student_ids'));

        return response()->json([
            'message' => __('api.saved'),
            'added' => $added,
            'data' => new LessonResource($lesson->fresh()->load(['lessonStudents' => fn ($q) => $q->where('status', 'active')->with('student')])),
        ]);
    }

    public function unenroll(Lesson $lesson, Student $student): JsonResponse
    {
        $this->authorize('enroll', $lesson);
        $this->service->unenroll($lesson, $student);

        return response()->json(['message' => __('api.saved')]);
    }

    public function changeLocation(ChangeLocationRequest $request, Lesson $lesson): JsonResponse
    {
        $d = $request->validated();
        $result = $this->service->changeLocation($lesson, $d['mode'], (int) $d['location_id'], $d['date'] ?? null, (bool) ($d['notify'] ?? false), $d['reason'] ?? null);

        return response()->json([
            'message' => __('api.saved'),
            'notified' => $result['notified'],
            'dates' => $result['dates'],
            'data' => new LessonResource($lesson->fresh()->load(['package', 'teacher', 'location'])),
        ]);
    }

    public function sessions(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $this->authorize('view', $lesson);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $sessions = $lesson->sessions()->with(['location', 'lesson'])
            ->withCount([
                'attendances as present_count' => fn ($q) => $q->where('status', 'present'),
                'attendances as absent_count' => fn ($q) => $q->where('status', 'absent'),
            ])
            ->when(isset($data['from']), fn ($q) => $q->where('session_date', '>=', $data['from']))
            ->when(isset($data['to']), fn ($q) => $q->where('session_date', '<=', $data['to']))
            ->orderBy('session_date')
            ->get();

        return LessonSessionResource::collection($sessions);
    }
}
