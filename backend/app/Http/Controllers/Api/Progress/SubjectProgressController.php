<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LevelSubject;
use App\Models\PlanItem;
use App\Models\SubjectLessonProgress;
use App\Models\User;
use App\Policies\LessonPolicy;
use App\Services\AuditLogger;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Education follow-up
 * @subgroup Subject lessons progress
 *
 * تحديث دروس المواد: for a class and one of its level's subjects this term, the plan (الخطة, by week) against what was
 * actually taught. subject_progress.record; teachers only for subjects they teach in that class (TeacherScope with
 * the subject), managers (lessons.manage) every subject of their track's classes.
 *
 * Status per plan item: on_time (taught by the end of its week), late (taught after it), overdue (its week is over and
 * it is not taught yet) or upcoming.
 */
class SubjectProgressController extends Controller
{
    private const DEFAULT_WEEKS = 16;

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRecord($request);
        $term = TermScope::single($request);
        $request->validate(['lesson_id' => ['nullable', 'integer'], 'subject_id' => ['nullable', 'integer']]);

        $classes = $this->termLessons($user, $term)->orderBy('name')->get(['lessons.id', 'lessons.name', 'lessons.level_id', 'lessons.teacher_id', 'lessons.gender']);
        $payload = [
            'term' => ['id' => $term->id, 'name' => $term->name(), 'start_date' => $term->start_date?->toDateString(), 'end_date' => $term->end_date?->toDateString()],
            'classes' => $classes->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'level_id' => $l->level_id])->values(),
        ];
        if (! $request->filled('lesson_id')) {
            return response()->json($payload);
        }

        $lesson = Lesson::find($request->integer('lesson_id'));
        abort_if(! $lesson || ! $classes->contains('id', $lesson->id), 403);
        $subjects = $this->subjects($user, $lesson, $term);
        $payload['subjects'] = $subjects->map(fn (LevelSubject $ls) => ['id' => $ls->subject->id, 'name' => $ls->subject->name(), 'level_subject_id' => $ls->id])->values();
        $ls = $request->filled('subject_id') ? $subjects->firstWhere('subject_id', $request->integer('subject_id')) : $subjects->first();
        abort_if($request->filled('subject_id') && ! $ls, 403);
        if (! $ls) {
            return response()->json($payload + ['level_subject' => null, 'items' => [], 'sessions' => [], 'summary' => null]);
        }

        $items = PlanItem::with('subjectLesson')->where('level_subject_id', $ls->id)->orderBy('week_no')->orderBy('sort')->orderBy('id')->get();
        $done = SubjectLessonProgress::with(['teacher:id,name', 'session:id,session_date'])->where('lesson_id', $lesson->id)
            ->whereIn('plan_item_id', $items->pluck('id')->all() ?: [0])->get()->keyBy('plan_item_id');
        $start = $term->start_date ? Carbon::parse($term->start_date->toDateString()) : null;
        $today = today();

        $rows = $items->map(function (PlanItem $p) use ($done, $start, $today) {
            $from = $start?->copy()->addDays(7 * ($p->week_no - 1));
            $to = $from?->copy()->addDays(6);
            $d = $done->get($p->id);
            $status = match (true) {
                $d !== null && ($to === null || $d->taught_on->lte($to)) => 'on_time',
                $d !== null => 'late',
                $to !== null && $to->lt($today) => 'overdue',
                default => 'upcoming',
            };

            return [
                'plan_item_id' => $p->id, 'week_no' => $p->week_no, 'title' => $p->displayTitle(), 'notes' => $p->notes,
                'week_starts_on' => $from?->toDateString(), 'week_ends_on' => $to?->toDateString(), 'status' => $status,
                'progress' => $d ? self::progress($d) : null,
            ];
        })->values();

        return response()->json($payload + [
            'lesson' => ['id' => $lesson->id, 'name' => $lesson->name],
            'level_subject' => ['id' => $ls->id, 'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()], 'level' => ['id' => $ls->level->id, 'name' => $ls->level->name()]],
            'items' => $rows,
            'sessions' => LessonSession::where('lesson_id', $lesson->id)
                ->when($term->start_date, fn ($q, $d) => $q->where('session_date', '>=', $d->toDateString()))
                ->when($term->end_date, fn ($q, $d) => $q->where('session_date', '<=', $d->toDateString()))
                ->where('session_date', '<=', $today->toDateString())
                ->orderByDesc('session_date')->limit(120)->get(['id', 'session_date', 'status'])
                ->map(fn ($s) => ['id' => $s->id, 'date' => $s->session_date->toDateString()]),
            'summary' => [
                'total' => $rows->count(),
                'taught' => $rows->whereNotNull('progress')->count(),
                'on_time' => $rows->where('status', 'on_time')->count(),
                'late' => $rows->where('status', 'late')->count(),
                'overdue' => $rows->where('status', 'overdue')->count(),
                'upcoming' => $rows->where('status', 'upcoming')->count(),
            ],
        ]);
    }

    /** Mark a plan item taught to the class (again = correct the date, session or notes). */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeRecord($request);
        $data = $request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
            'plan_item_id' => ['required', 'integer', 'exists:plan_items,id'],
            'taught_on' => ['required', 'date', 'before_or_equal:today'],
            'lesson_session_id' => ['nullable', 'integer', 'exists:lesson_sessions,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $lesson = Lesson::with('package')->findOrFail($data['lesson_id']);
        $item = PlanItem::with('levelSubject')->findOrFail($data['plan_item_id']);
        $ls = $item->levelSubject;
        if ((int) $ls->level_id !== (int) $lesson->level_id || ! TermScope::matches($lesson->package, (int) $ls->academic_term_id)) {
            throw ValidationException::withMessages(['plan_item_id' => __('subject_progress.errors.not_class_plan')]);
        }
        abort_unless($this->canRecord($request->user(), $lesson, $ls->subject_id), 403);
        if (! empty($data['lesson_session_id']) && ! LessonSession::whereKey($data['lesson_session_id'])->where('lesson_id', $lesson->id)->exists()) {
            throw ValidationException::withMessages(['lesson_session_id' => __('subject_progress.errors.session')]);
        }

        $row = SubjectLessonProgress::firstOrNew(['lesson_id' => $lesson->id, 'plan_item_id' => $item->id]);
        $old = $row->exists ? $row->only(['taught_on', 'lesson_session_id', 'notes']) : [];
        $row->fill(['taught_on' => $data['taught_on'], 'lesson_session_id' => $data['lesson_session_id'] ?? null, 'notes' => $data['notes'] ?? null, 'taught_by' => $request->user()->id])->save();
        $this->audit->record($old ? 'subject_progress.updated' : 'subject_progress.recorded', $row, $old, $row->only(['lesson_id', 'plan_item_id', 'taught_on', 'lesson_session_id', 'notes']));

        return response()->json(['message' => __('subject_progress.saved'), 'data' => self::progress($row->fresh(['teacher:id,name', 'session:id,session_date']))]);
    }

    /** Not taught after all: remove the mark. */
    public function destroy(Request $request, SubjectLessonProgress $progress): JsonResponse
    {
        $this->authorizeRecord($request);
        $subjectId = PlanItem::with('levelSubject')->find($progress->plan_item_id)?->levelSubject?->subject_id;
        abort_unless($this->canRecord($request->user(), $progress->lesson, $subjectId), 403);
        $this->audit->record('subject_progress.removed', $progress, $progress->only(['lesson_id', 'plan_item_id', 'taught_on']), []);
        $progress->delete();

        return response()->json(['message' => __('subject_progress.removed')]);
    }

    /** The class's level subjects this term that the user may record (managers all; teachers those they teach there). */
    private function subjects(User $user, Lesson $lesson, AcademicTerm $term)
    {
        if (! $lesson->level_id) {
            return collect();
        }

        return LevelSubject::with(['subject', 'level'])->where('academic_term_id', $term->id)->where('level_id', $lesson->level_id)
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (LevelSubject $ls) => $this->canRecord($user, $lesson, $ls->subject_id))->values();
    }

    private function canRecord(User $user, ?Lesson $lesson, ?int $subjectId): bool
    {
        return $lesson !== null && $user->can('subject_progress.record') && LessonPolicy::ownsOrManages($user, $lesson, $subjectId);
    }

    private function termLessons(User $user, AcademicTerm $term): \Illuminate\Database\Eloquent\Builder
    {
        return Lesson::query()->tap(fn ($q) => TermScope::via($q, $term->id))->tap(fn ($q) => Track::scope($q, $user))
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereIn('lessons.id', TeacherScope::lessonIds($user)));
    }

    private function authorizeRecord(Request $request): void
    {
        abort_unless($request->user()->can('subject_progress.record'), 403);
    }

    private static function progress(SubjectLessonProgress $p): array
    {
        return [
            'id' => $p->id, 'taught_on' => $p->taught_on?->toDateString(), 'taught_by' => $p->teacher?->name,
            'lesson_session_id' => $p->lesson_session_id, 'session_date' => $p->session?->session_date?->toDateString(), 'notes' => $p->notes,
        ];
    }
}
