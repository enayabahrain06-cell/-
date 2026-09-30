<?php

namespace App\Http\Controllers\Api\Progress;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\TajweedAspect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Issues\StoreIssueNoteRequest;
use App\Http\Requests\Issues\StoreIssueRequest;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Http\Resources\StudentSummaryResource;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Services\Issues\IssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Student difficulties
 */
class IssueController extends Controller
{
    public function __construct(private IssueService $service) {}

    /** Category, tajweed aspect, severity and status options in the current language. */
    public function options(): JsonResponse
    {
        return response()->json([
            'categories' => IssueCategory::options(),
            'tajweed_aspects' => TajweedAspect::options(),
            'severities' => IssueSeverity::options(),
            'statuses' => IssueStatus::options(),
        ]);
    }

    /**
     * Issues across students. Filters: status (open|improving|resolved|unresolved), severity, category,
     * lesson_id, student_id, follow_up_due=1. Teachers see only students in their circles.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudentIssue::class);
        $user = $request->user();

        $q = StudentIssue::with(['student', 'opener:id,name', 'notes.author:id,name'])
            ->when(! $user->can('students.manage') && ! $user->can('lessons.manage'), fn ($q) => $q->whereIn('student_id',
                LessonStudent::where('status', 'active')->whereIn('lesson_id', \App\Support\TeacherScope::lessonIds($user))->select('student_id')))
            ->tap(fn ($q) => \App\Support\Track::scopeVia($q, $user, 'student'))
            ->when($request->string('status')->toString() === 'unresolved', fn ($q) => $q->unresolved())
            ->when($request->filled('status') && $request->string('status')->toString() !== 'unresolved', fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->boolean('follow_up_due'), fn ($q) => $q->unresolved()->whereNotNull('next_follow_up_date')->where('next_follow_up_date', '<=', today()->toDateString()))
            ->orderByDesc('opened_at');

        $page = $q->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (StudentIssue $i) => $this->service->present($i) + ['student' => new StudentSummaryResource($i->student)]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** All issues of one student (guardian and student included, read-only). */
    public function forStudent(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $issues = $student->issues()->with(['opener:id,name', 'notes.author:id,name'])
            ->when($request->filled('status'), fn ($q) => $request->string('status')->toString() === 'unresolved' ? $q->unresolved() : $q->where('status', $request->string('status')))
            ->orderByDesc('opened_at')->get();

        return response()->json(['data' => $issues->map(fn ($i) => $this->service->present($i, notes: 50))->values()]);
    }

    public function store(StoreIssueRequest $request, Student $student): JsonResponse
    {
        $issue = $this->service->create($student, $request->validated(), $request->user());

        return response()->json(['message' => __('issues.created'), 'data' => $this->service->present($issue->load('opener:id,name'))], 201);
    }

    public function show(StudentIssue $issue): JsonResponse
    {
        $this->authorize('view', $issue);

        return response()->json(['data' => $this->service->present($issue->load(['opener:id,name', 'notes.author:id,name']), notes: 100)]);
    }

    public function update(UpdateIssueRequest $request, StudentIssue $issue): JsonResponse
    {
        $issue = $this->service->update($issue, $request->validated());

        return response()->json(['message' => __('issues.updated'), 'data' => $this->service->present($issue->load(['opener:id,name', 'notes.author:id,name']))]);
    }

    /** Add a follow-up note, optionally changing status or next follow-up date. */
    public function storeNote(StoreIssueNoteRequest $request, StudentIssue $issue): JsonResponse
    {
        $this->service->addNote($issue, $request->validated(), $request->user());

        return response()->json(['message' => __('issues.note_added'), 'data' => $this->service->present($issue->fresh(['opener:id,name', 'notes.author:id,name']), notes: 100)], 201);
    }
}
