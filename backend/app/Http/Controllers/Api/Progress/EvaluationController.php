<?php

namespace App\Http\Controllers\Api\Progress;

use App\Enums\EvaluationType;
use App\Enums\LessonStudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluations\SaveDailyEvaluationsRequest;
use App\Http\Requests\Evaluations\SaveMonthlyEvaluationsRequest;
use App\Http\Requests\Evaluations\UpdateEvaluationRequest;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Division;
use App\Models\Evaluation;
use App\Models\Subject;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Services\Evaluation\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Memorization & evaluation
 */
class EvaluationController extends Controller
{
    public function __construct(private EvaluationService $service) {}

    /**
     * Evaluations list. Filters: student_id, lesson_id, type (daily|monthly), period, from, to.
     * Teachers without lessons.manage see only their own circles.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Evaluation::class);
        $user = $request->user();

        $subjectId = $request->integer('subject_id') ?: Subject::quranId();
        $page = Evaluation::with(['student', 'evaluator:id,name', 'scores'])
            // One subject per list (Quran unless another is asked for), so score columns never mix subjects.
            ->when($subjectId === Subject::quranId(), fn ($q) => $q->quran(), fn ($q) => $q->where('subject_id', $subjectId))
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereIn('lesson_id', \App\Support\TeacherScope::lessonIds($user, $subjectId)))
            ->tap(fn ($q) => \App\Support\Track::scopeVia($q, $user, 'student'))
            ->tap(fn ($q) => \App\Support\TermScope::via($q, \App\Support\TermScope::fromRequest($request), 'lesson.package'))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('period'), fn ($q) => $q->where('period', $request->string('period')))
            ->when($request->filled('from'), fn ($q) => $q->where('evaluated_on', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn ($q) => $q->where('evaluated_on', '<=', $request->date('to')->toDateString()))
            ->orderByDesc('evaluated_on')->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Evaluation $e) => $this->service->scoreRow($e) + [
                'student' => new StudentSummaryResource($e->student),
                'lesson_id' => $e->lesson_id,
                'sent_to_guardian_at' => display_tz($e->sent_to_guardian_at)?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Evaluation sheet for a session: every active student with their saved scores (or null). ?subject_id= picks the
     * subject (Quran by default) and brings its active criteria; ?division_id= keeps the students of one division.
     */
    public function sessionSheet(Request $request, LessonSession $session): JsonResponse
    {
        $lesson = $session->lesson;
        $subjectId = $request->integer('subject_id') ?: Subject::quranId();
        $this->authorize('record', [Evaluation::class, $lesson, $subjectId]);
        $subjects = $this->service->subjectsFor($lesson);
        abort_unless($subjects->contains('id', $subjectId), 422, __('evaluations.errors.subject_not_in_class'));
        $division = $request->filled('division_id') ? Division::where('lesson_id', $lesson->id)->findOrFail($request->integer('division_id')) : null;
        $inDivision = $division ? $division->students()->pluck('students.id')->all() : null;

        $saved = Evaluation::with('scores')->where('lesson_session_id', $session->id)->where('type', EvaluationType::Daily->value)
            ->when($subjectId === Subject::quranId(), fn ($q) => $q->quran(), fn ($q) => $q->where('subject_id', $subjectId))
            ->get()->keyBy('student_id');
        $rows = LessonStudent::with('student')->where('lesson_id', $session->lesson_id)
            ->where('status', LessonStudentStatus::Active->value)
            ->when($inDivision !== null, fn ($q) => $q->whereIn('student_id', $inDivision ?: [0]))->get()
            ->sortBy('student.full_name')->values()
            ->map(fn (LessonStudent $ls) => [
                'student' => new StudentSummaryResource($ls->student),
                'evaluation' => ($e = $saved->get($ls->student_id)) ? $this->service->scoreRow($e) : null,
            ]);

        $session->loadMissing(['lesson.teacher:id,name', 'location:id,name']);

        return response()->json([
            'session_id' => $session->id,
            'date' => $session->session_date->toDateString(),
            'start_time' => substr((string) $session->start_time, 0, 5),
            'end_time' => substr((string) $session->end_time, 0, 5),
            'lesson' => ['id' => $session->lesson_id, 'name' => $session->lesson?->name, 'teacher' => $session->lesson?->teacher?->name],
            'location' => $session->location?->name,
            'threshold' => (int) setting('evaluation.issue_threshold', 6),
            'subject' => ($s = $subjects->firstWhere('id', $subjectId)) ? ['id' => $s->id, 'name' => $s->name(), 'code' => $s->code, 'is_quran' => $s->code === Subject::QURAN] : null,
            'subjects' => $this->service->evaluableSubjects($request->user(), $lesson)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name(), 'code' => $s->code])->values(),
            'criteria' => $this->service->criteriaRows($this->service->criteria($subjectId)),
            'division' => $division ? ['id' => $division->id, 'name' => $division->name] : null,
            'divisions' => Division::where('lesson_id', $lesson->id)->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'data' => $rows,
        ]);
    }

    /**
     * Save daily scores for a session. Each entry may carry "progress" ranges (memorized/revised)
     * that are appended to the ledger. The response lists suggested difficulties for scores below 6.
     */
    public function storeDaily(SaveDailyEvaluationsRequest $request, LessonSession $session): JsonResponse
    {
        $divisionId = $request->validated('division_id');
        $division = $divisionId ? Division::where('lesson_id', $session->lesson_id)->find($divisionId) : null;
        if ($divisionId && ! $division) {
            throw ValidationException::withMessages(['division_id' => __('evaluations.errors.not_in_division')]);
        }
        $this->assertSubjectOfClass($session->lesson, $request->subjectId());
        $result = $this->service->saveDaily($session, $request->validated('entries'), $request->user(), $request->subjectId(), $division);

        return response()->json([
            'message' => __('evaluations.saved'),
            'data' => $result['evaluations']->map(fn ($e) => $this->service->scoreRow($e))->values(),
            'suggested_issues' => $result['suggestions'],
        ]);
    }

    /** Save the monthly evaluation for a circle and period (YYYY-MM). */
    public function storeMonthly(SaveMonthlyEvaluationsRequest $request, Lesson $lesson): JsonResponse
    {
        $this->assertSubjectOfClass($lesson, $request->subjectId());
        $result = $this->service->saveMonthly($lesson, $request->validated('period'), $request->validated('entries'), $request->user(), $request->subjectId());

        return response()->json([
            'message' => __('evaluations.saved'),
            'data' => $result['evaluations']->map(fn ($e) => $this->service->scoreRow($e))->values(),
            'suggested_issues' => $result['suggestions'],
        ]);
    }

    public function update(UpdateEvaluationRequest $request, Evaluation $evaluation): JsonResponse
    {
        $evaluation = $this->service->update($evaluation, $request->validated());

        return response()->json([
            'message' => __('api.saved'),
            'data' => $this->service->scoreRow($evaluation),
            'suggested_issues' => $this->service->suggestions([$evaluation]),
        ]);
    }

    public function destroy(Evaluation $evaluation): JsonResponse
    {
        $this->authorize('delete', $evaluation);
        $evaluation->delete();

        return response()->json(['message' => __('evaluations.deleted')]);
    }

    /**
     * The subjects this user may evaluate in the session's class (U5). The sheet opens on Quran; a subject teacher
     * who does not teach Quran there is sent to their first subject with this list.
     */
    public function sessionSubjects(Request $request, LessonSession $session): JsonResponse
    {
        abort_unless($request->user()->can('evaluations.record'), 403);
        $subjects = $this->service->evaluableSubjects($request->user(), $session->lesson);
        abort_if($subjects->isEmpty(), 403);

        return response()->json(['data' => $subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name(), 'code' => $s->code])->values()]);
    }

    private function assertSubjectOfClass(Lesson $lesson, ?int $subjectId): void
    {
        if (! $this->service->subjectsFor($lesson)->contains('id', $subjectId)) {
            throw ValidationException::withMessages(['subject_id' => __('evaluations.errors.subject_not_in_class')]);
        }
    }

    /** Send the evaluation to the guardian by WhatsApp in the student's language. */
    public function send(Evaluation $evaluation): JsonResponse
    {
        $this->authorize('send', $evaluation);
        $count = $this->service->sendToGuardian($evaluation);

        return response()->json(['message' => __('evaluations.sent'), 'queued' => $count]);
    }
}
