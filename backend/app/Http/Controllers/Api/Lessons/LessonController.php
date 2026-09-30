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
use App\Services\Registration\PackageSuitability;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Lessons & locations
 */
class LessonController extends Controller
{
    public function __construct(private LessonService $service) {}

    /** Filters: package_id, teacher_id, location_id, status, search, gender, age_group_id (0 = none), level_id (0 = none), term_id, age, has_seats. Teachers only see their own circles. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lesson::class);
        $user = $request->user();

        $lessons = Lesson::with(['package', 'teacher', 'location', 'ageGroup', 'level'])
            ->withCount(['lessonStudents as active_students_count' => fn ($q) => $q->where('status', LessonStudentStatus::Active->value)])
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereIn('id', \App\Support\TeacherScope::lessonIds($user)))
            ->tap(fn ($q) => \App\Support\Track::scope($q, $user))
            ->tap(fn ($q) => \App\Support\TermScope::via($q, \App\Support\TermScope::fromRequest($request)))
            ->when($request->filled('level_id'), fn ($q) => $request->integer('level_id') === 0 ? $q->whereNull('level_id') : $q->where('level_id', $request->integer('level_id')))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('age_group_id'), fn ($q) => $request->integer('age_group_id') === 0 ? $q->whereNull('age_group_id') : $q->where('age_group_id', $request->integer('age_group_id')))
            // A student's age: circles whose range covers it.
            ->when($request->filled('age'), fn ($q) => $q->where(fn ($w) => $w->whereNull('min_age')->orWhere('min_age', '<=', $request->integer('age')))
                ->where(fn ($w) => $w->whereNull('max_age')->orWhere('max_age', '>=', $request->integer('age'))))
            ->when($request->boolean('has_seats'), fn ($q) => $q->whereRaw('capacity > (select count(*) from lesson_students where lesson_students.lesson_id = lessons.id and lesson_students.status = ?)', [LessonStudentStatus::Active->value]))
            ->orderBy('name');

        return LessonResource::collection($request->boolean('all') ? $lessons->get() : $lessons->paginate((int) $request->integer('per_page', 25)));
    }

    public function store(StoreLessonRequest $request): JsonResponse
    {
        $result = $this->service->create($request->validated());

        return response()->json([
            'data' => new LessonResource($result['lesson']->load(['package', 'teacher', 'location', 'ageGroup', 'level'])),
            'conflicts' => $result['conflicts'],
        ], 201);
    }

    public function show(Lesson $lesson): LessonResource
    {
        $this->authorize('view', $lesson);

        $lesson->load([
            'package', 'teacher', 'location', 'ageGroup', 'level',
            'lessonStudents' => fn ($q) => $q->where('status', LessonStudentStatus::Active->value)->with('student.wallet'),
            'sessions' => fn ($q) => $q->where('session_date', '>=', today()->toDateString())->orderBy('session_date')->limit(10)->with('location'),
        ]);

        return new LessonResource($lesson);
    }

    public function update(StoreLessonRequest $request, Lesson $lesson): JsonResponse
    {
        $result = $this->service->update($lesson, $request->validated());

        return response()->json([
            'data' => new LessonResource($result['lesson']->load(['package', 'teacher', 'location', 'ageGroup', 'level'])),
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

    /** Add existing students (move=1 to move students out of their current circle). */
    public function enroll(EnrollStudentsRequest $request, Lesson $lesson): JsonResponse
    {
        $result = $this->service->enroll($lesson, $request->validated('student_ids'), $request->user()->id, $request->boolean('move'));

        return response()->json([
            'message' => $result['moved'] ? __('lessons.add.moved') : __('lessons.add.added'),
            'added' => $result['added'],
            'moved' => $result['moved'],
            'data' => new LessonResource($lesson->fresh()->load(['lessonStudents' => fn ($q) => $q->where('status', 'active')->with('student')])),
        ]);
    }

    /**
     * Students to add from the circle page, searched by name, number or phone within the user's track.
     * Every match comes back with its eligibility and the reason when it cannot be added
     * (gender, age, inactive, full, already here), or "move" when it sits in another circle.
     */
    public function candidates(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('addStudents', $lesson);
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $user = $request->user();
        $lesson->loadMissing('package');

        $term = trim(PhoneNumber::toLatinDigits($data['search']));
        // "3600 1234" or "+973-3600-1234" should still find the stored +97336001234.
        if (preg_match('/^[\d\s+\-]+$/', $term)) {
            $term = preg_replace('/\D+/', '', $term);
        }
        $like = '%'.$term.'%';
        $students = Student::query()
            ->tap(fn ($q) => \App\Support\Track::scope($q, $user))
            ->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('student_no', 'like', $like)->orWhere('guardian_phone', 'like', $like)->orWhere('student_phone', 'like', $like))
            ->orderBy('full_name')
            ->limit(15)
            ->get();

        $free = max(0, $lesson->capacity - $lesson->activeStudentCount());

        return response()->json([
            'free_seats' => $free,
            'data' => $students->map(function (Student $s) use ($lesson, $user, $free) {
                $reason = $this->service->ineligibility($lesson, $s, $user);
                $others = $reason ? [] : $this->service->otherCircles($lesson, $s, $user);
                if (! $reason && $free === 0) {
                    $reason = 'full';
                }
                if (! $reason && $others && collect($others)->contains('movable', false)) {
                    $reason = 'other_circle_locked';
                }

                return [
                    'id' => $s->id,
                    'student_no' => $s->student_no,
                    'full_name' => $s->full_name,
                    'initial' => $s->initial(),
                    'gender' => $s->gender?->value,
                    'photo_url' => $s->photoUrl('thumb'),
                    'age_at_start' => $s->birth_date && $lesson->package ? PackageSuitability::ageOn($s->birth_date, $lesson->package->start_date) : null,
                    'circles' => $others,
                    'reason' => $reason,
                    'action' => $reason ? null : ($others ? 'move' : 'add'),
                ];
            })->values(),
        ]);
    }

    public function unenroll(Lesson $lesson, Student $student): JsonResponse
    {
        $this->authorize('enroll', $lesson);
        $this->service->unenroll($lesson, $student, request()->user());

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
            'data' => new LessonResource($lesson->fresh()->load(['package', 'teacher', 'location', 'ageGroup', 'level'])),
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
