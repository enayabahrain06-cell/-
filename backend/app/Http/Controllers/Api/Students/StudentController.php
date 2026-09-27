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
     * Filters: search (name / student_no / phone), status, gender, memorization_level, package_id, lesson_id, due=1.
     * Teachers see only students enrolled in their circles.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);
        $user = $request->user();

        $q = Student::with(['wallet', 'guardian'])
            ->when($user->hasRole('teacher') && ! $user->can('students.manage'), fn ($q) => $q->whereHas('lessonStudents', fn ($w) => $w->where('status', 'active')->whereIn('lesson_id', $user->lessons()->select('id'))))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $s)->orWhere('student_no', 'like', $s)->orWhere('guardian_phone', 'like', $s)->orWhere('student_phone', 'like', $s)->orWhere('guardian_name', 'like', $s));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('memorization_level'), fn ($q) => $q->where('memorization_level', $request->string('memorization_level')))
            ->when($request->filled('lesson_id'), fn ($q) => $q->whereHas('lessonStudents', fn ($w) => $w->where('status', 'active')->where('lesson_id', $request->integer('lesson_id'))))
            ->when($request->filled('package_id'), fn ($q) => $q->whereHas('lessonStudents', fn ($w) => $w->where('status', 'active')->whereIn('lesson_id', \App\Models\Lesson::where('package_id', $request->integer('package_id'))->select('id'))))
            ->when($request->boolean('due'), fn ($q) => $q->whereHas('wallet', fn ($w) => $w->where('balance_fils', '<', 0)))
            ->orderBy('full_name');

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

        $old = $student->only(['full_name', 'birth_date', 'gender', 'guardian_name', 'memorization_level', 'status', 'yearly_target_ayahs']);
        $student->update($request->validated());
        $audit->record('student.updated', $student, $old, $student->only(array_keys($old)));

        return new StudentResource($student->fresh(['wallet', 'guardian', 'user', 'lessons.teacher', 'lessons.package', 'lessons.location']));
    }

    /** The authenticated student's own record, or a guardian's children. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['student.wallet', 'children.wallet']);
        $students = collect([$user->student])->filter()->merge($user->children);

        return response()->json(['data' => StudentSummaryResource::collection($students->values())]);
    }
}
