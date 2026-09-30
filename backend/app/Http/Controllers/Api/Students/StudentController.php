<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Student;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Students
 */
class StudentController extends Controller
{
    /**
     * Students list with wallet balance (due badge) and thumbnail.
     * Filters: search (name / student_no / phone), status, gender, memorization_level, package_id, lesson_id, due=1,
     * age_min / age_max (whole years, inclusive).
     * Teachers see only students enrolled in their circles.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);
        $user = $request->user();

        $q = Student::with(['wallet', 'guardian', 'activeLessons.teacher:id,name'])
            ->tap(fn ($q) => \App\Support\Track::scope($q, $user))
            ->when($user->hasRole('teacher') && ! $user->can('students.manage'), fn ($q) => $q->whereHas('lessonStudents', fn ($w) => $w->where('status', 'active')->whereIn('lesson_id', \App\Support\TeacherScope::lessonIds($user))))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $s)->orWhere('student_no', 'like', $s)->orWhere('guardian_phone', 'like', $s)->orWhere('student_phone', 'like', $s)->orWhere('guardian_name', 'like', $s)->orWhere('cpr', 'like', $s));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('memorization_level'), fn ($q) => $q->where('memorization_level', $request->string('memorization_level')))
            // lesson_id=none: students in no active circle, so in no package yet (e.g. saved from quick enrollment without one).
            ->when($request->input('lesson_id') === 'none', fn ($q) => $q->whereDoesntHave('lessonStudents', fn ($w) => $w->where('status', 'active')))
            ->when($request->filled('lesson_id') && $request->input('lesson_id') !== 'none', fn ($q) => $q->whereHas('lessonStudents', fn ($w) => $w->where('status', 'active')->where('lesson_id', $request->integer('lesson_id'))))
            ->when($request->filled('package_id'), fn ($q) => $q->whereHas('lessonStudents', fn ($w) => $w->where('status', 'active')->whereIn('lesson_id', \App\Models\Lesson::where('package_id', $request->integer('package_id'))->select('id'))))
            ->when($request->boolean('due'), fn ($q) => $q->whereHas('wallet', fn ($w) => $w->where('balance_fils', '<', 0)))
            ->when($request->filled('juz'), fn ($q) => $request->integer('juz') === 0 ? $q->whereNull('progress_juz') : $q->where('progress_juz', $request->integer('juz')))
            ->when($request->boolean('no_photo'), fn ($q) => $q->whereNull('photo_path'))
            // Age in whole years today: age >= min means born on or before today minus min years;
            // age <= max means born after today minus (max + 1) years.
            ->when($request->filled('age_min'), fn ($q) => $q->whereDate('birth_date', '<=', today()->subYears($request->integer('age_min'))))
            ->when($request->filled('age_max'), fn ($q) => $q->whereDate('birth_date', '>', today()->subYears($request->integer('age_max') + 1)));

        // Sort: name (default), student_no, memorized (most first), newest, age (youngest first).
        match ($request->string('sort')->toString()) {
            'student_no' => $q->orderBy('student_no'),
            'age' => $q->orderByDesc('birth_date')->orderBy('full_name'),
            'memorized' => $q->orderByDesc('memorized_ayahs')->orderBy('full_name'),
            'newest' => $q->orderByDesc('id'),
            default => $q->orderBy('full_name'),
        };

        return StudentSummaryResource::collection($q->paginate((int) $request->integer('per_page', 25)))->response();
    }

    public function show(Student $student): StudentResource
    {
        $this->authorize('view', $student);

        return new StudentResource($student->load(['wallet', 'guardian', 'user', 'lessons.teacher', 'lessons.package', 'lessons.location']));
    }

    public function update(UpdateStudentRequest $request, Student $student, AuditLogger $audit): StudentResource
    {
        $this->authorize('update', $student);

        $old = $student->only(['cpr', 'address', 'full_name', 'birth_date', 'gender', 'guardian_name', 'memorization_level', 'status', 'yearly_target_ayahs']);
        $student->update($request->validated());
        $audit->record('student.updated', $student, $old, $student->only(array_keys($old)));

        return new StudentResource($student->fresh(['wallet', 'guardian', 'user', 'lessons.teacher', 'lessons.package', 'lessons.location']));
    }

    /** Placement test results from the student's registration, with recommended and confirmed levels. Staff only. */
    public function placement(Request $request, Student $student, \App\Services\Exams\PlacementService $placement): JsonResponse
    {
        $this->authorize('view', $student);
        abort_unless($request->user()->can('students.view'), 403);

        return response()->json(['data' => $placement->historyFor($student)]);
    }

    /**
     * Attendance history of one student (newest first) with totals. Filters: from, to, status.
     * Visible to whoever may view the student (staff in track, their teacher, the student, the guardian).
     */
    public function attendance(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $base = \App\Models\Attendance::where('student_id', $student->id)
            ->when($request->filled('from'), fn ($q) => $q->whereHas('session', fn ($s) => $s->where('session_date', '>=', $request->date('from')->toDateString())))
            ->when($request->filled('to'), fn ($q) => $q->whereHas('session', fn ($s) => $s->where('session_date', '<=', $request->date('to')->toDateString())));

        $totals = (clone $base)->get(['status'])->countBy(fn ($a) => $a->status->value);
        $counted = $totals->sum() - ($totals['excused'] ?? 0);

        $page = (clone $base)->with(['session:id,lesson_id,session_date,start_time', 'session.lesson:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc(\App\Models\LessonSession::select('session_date')->whereColumn('lesson_sessions.id', 'attendances.lesson_session_id'))
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'totals' => [
                'present' => (int) ($totals['present'] ?? 0),
                'late' => (int) ($totals['late'] ?? 0),
                'absent' => (int) ($totals['absent'] ?? 0),
                'excused' => (int) ($totals['excused'] ?? 0),
                'percent' => $counted > 0 ? (int) round((($totals['present'] ?? 0) + ($totals['late'] ?? 0)) * 100 / $counted) : null,
            ],
            'data' => collect($page->items())->map(fn ($a) => [
                'id' => $a->id,
                'date' => $a->session?->session_date?->toDateString(),
                'lesson' => $a->session?->lesson?->name,
                'status' => $a->status->value,
                'memorization_assignment' => $a->memorization_assignment,
                'revision_assignment' => $a->revision_assignment,
                'note' => $a->note,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** The authenticated student's own record, or a guardian's children. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['student.wallet', 'children.wallet']);
        $students = collect([$user->student])->filter()->merge($user->children);

        return response()->json(['data' => StudentSummaryResource::collection($students->values())]);
    }
}
