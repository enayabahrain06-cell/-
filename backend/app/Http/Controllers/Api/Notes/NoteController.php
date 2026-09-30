<?php

namespace App\Http\Controllers\Api\Notes;

use App\Enums\LessonStudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\IssueNote;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\LevelSubject;
use App\Models\Note;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Services\AuditLogger;
use App\Services\Notes\NoteReach;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Education follow-up
 * @subgroup Notes
 *
 * U9 one notes model: ملاحظات الطلبة، الملاحظات العامة، ملاحظات المستويات، ملاحظات مواد المستويات (and their "view"
 * screens). notes.view reads, notes.manage writes within the user's reach (NoteReach). Authors edit or delete their
 * own notes; managers (lessons.manage) any.
 */
class NoteController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Notes of one scope in the term. Filters: student_id, lesson_id, level_id, level_subject_id, author=mine, q, from, to. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $user = $request->user();
        $term = TermScope::single($request);
        $f = $request->validate([
            'scope' => ['required', Rule::in(Note::SCOPES)],
            'student_id' => ['nullable', 'integer'], 'lesson_id' => ['nullable', 'integer'], 'level_id' => ['nullable', 'integer'],
            'level_subject_id' => ['nullable', 'integer'], 'author' => ['nullable', 'in:mine'], 'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $scope = $f['scope'];

        $query = Note::with(['author:id,name', 'student', 'lesson:id,name', 'level', 'levelSubject.level', 'levelSubject.subject'])
            ->where('scope', $scope)
            // Student notes migrated from the old free field may have no term; they show in every term.
            ->where(fn ($q) => $q->where('academic_term_id', $term->id)->when($scope === 'student', fn ($w) => $w->orWhereNull('academic_term_id')));

        if ($scope === 'student') {
            $query->whereHas('student', fn ($q) => Track::scope($q, $user));
            if (! NoteReach::manager($user)) {
                $query->whereIn('student_id', LessonStudent::select('student_id')->where('status', LessonStudentStatus::Active->value)
                    ->whereIn('lesson_id', NoteReach::lessons($user, $term)->select('lessons.id')));
            }
        } elseif ($scope === 'level' && ! NoteReach::manager($user)) {
            $query->whereIn('level_id', NoteReach::levels($user, $term)->pluck('id')->all() ?: [0]);
        } elseif ($scope === 'level_subject' && ! NoteReach::manager($user)) {
            $query->whereIn('level_subject_id', NoteReach::levelSubjects($user, $term)->pluck('id')->all() ?: [0]);
        }

        $page = $query
            ->when($f['student_id'] ?? null, fn ($q, $v) => $q->where('student_id', $v))
            ->when($f['lesson_id'] ?? null, fn ($q, $v) => $q->where('lesson_id', $v))
            ->when($f['level_id'] ?? null, fn ($q, $v) => $scope === 'level_subject'
                ? $q->whereIn('level_subject_id', LevelSubject::select('id')->where('level_id', $v))
                : $q->where('level_id', $v))
            ->when($f['level_subject_id'] ?? null, fn ($q, $v) => $q->where('level_subject_id', $v))
            ->when(($f['author'] ?? null) === 'mine', fn ($q) => $q->where('author_id', $user->id))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('body', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('pinned')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 50));

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'data' => collect($page->items())->map(fn (Note $n) => $this->present($n, $request)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** What the screens pick from: the term's classes, levels and level subjects this user reaches. */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $user = $request->user();
        $term = TermScope::single($request);

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'classes' => NoteReach::lessons($user, $term)->orderBy('name')->get(['lessons.id', 'lessons.name', 'lessons.level_id'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'level_id' => $l->level_id]),
            'levels' => NoteReach::levels($user, $term)->map(fn ($l) => ['id' => $l->id, 'name' => $l->name()])->values(),
            'level_subjects' => NoteReach::levelSubjects($user, $term)->map(fn (LevelSubject $ls) => [
                'id' => $ls->id, 'level' => ['id' => $ls->level->id, 'name' => $ls->level->name()], 'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()],
            ])->values(),
            'can_manage' => $user->can('notes.manage'),
            'is_manager' => NoteReach::manager($user),
        ]);
    }

    /** ملاحظات الطلبة: a class's active students with how many notes each has this term. */
    public function students(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $user = $request->user();
        $term = TermScope::single($request);
        $request->validate(['lesson_id' => ['required', 'integer']]);
        $lesson = NoteReach::lessons($user, $term)->whereKey($request->integer('lesson_id'))->first();
        abort_if($lesson === null, 403);

        $rows = LessonStudent::with('student')->where('lesson_id', $lesson->id)->where('status', LessonStudentStatus::Active->value)->get()
            ->filter(fn ($r) => $r->student)->sortBy('student.full_name')->values();
        $counts = Note::where('scope', 'student')->whereIn('student_id', $rows->pluck('student_id'))
            ->where(fn ($q) => $q->where('academic_term_id', $term->id)->orWhereNull('academic_term_id'))
            ->get(['student_id', 'created_at'])->groupBy('student_id');

        return response()->json([
            'lesson' => ['id' => $lesson->id, 'name' => $lesson->name],
            'data' => $rows->map(fn (LessonStudent $r) => [
                'id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no, 'gender' => $r->student->gender?->value,
                'notes_count' => $counts->get($r->student_id)?->count() ?? 0,
                'last_note_at' => $counts->get($r->student_id)?->max('created_at')?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('notes.manage'), 403);
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'scope' => ['required', Rule::in(Note::SCOPES)],
            'body' => ['required', 'string', 'max:5000'],
            'pinned' => ['boolean'],
            'student_id' => ['nullable', 'required_if:scope,student', 'integer', 'exists:students,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'level_id' => ['nullable', 'required_if:scope,level', 'integer', 'exists:levels,id'],
            'level_subject_id' => ['nullable', 'required_if:scope,level_subject', 'integer', 'exists:level_subjects,id'],
        ]);
        $term = AcademicTerm::findOrFail($data['academic_term_id']);
        // Only the target of the scope is kept (a level note has no student, and so on).
        $keep = ['student' => ['student_id', 'lesson_id'], 'general' => [], 'level' => ['level_id'], 'level_subject' => ['level_subject_id']][$data['scope']];
        foreach (['student_id', 'lesson_id', 'level_id', 'level_subject_id'] as $k) {
            if (! in_array($k, $keep, true)) {
                $data[$k] = null;
            }
        }
        if (($data['lesson_id'] ?? null) && ! TermScope::matches(Lesson::find($data['lesson_id'])?->package, $term->id)) {
            throw ValidationException::withMessages(['lesson_id' => __('notes.errors.term')]);
        }
        if (($data['level_subject_id'] ?? null) && (int) LevelSubject::whereKey($data['level_subject_id'])->value('academic_term_id') !== $term->id) {
            throw ValidationException::withMessages(['level_subject_id' => __('notes.errors.term')]);
        }

        $note = new Note($data + ['pinned' => false, 'author_id' => $request->user()->id]);
        abort_unless(NoteReach::canWrite($request->user(), $note, $term), 403);
        $note->save();
        $this->audit->record('note.created', $note, [], $note->only(['scope', 'student_id', 'level_id', 'level_subject_id', 'academic_term_id']));

        return response()->json(['message' => __('notes.saved'), 'data' => $this->present($note->fresh(['author:id,name', 'student', 'lesson:id,name', 'level', 'levelSubject.level', 'levelSubject.subject']), $request)], 201);
    }

    public function update(Request $request, Note $note): JsonResponse
    {
        abort_unless(NoteReach::canChange($request->user(), $note), 403);
        $data = $request->validate(['body' => ['sometimes', 'required', 'string', 'max:5000'], 'pinned' => ['sometimes', 'boolean']]);
        $old = $note->only(['body', 'pinned']);
        $note->update($data);
        $this->audit->record('note.updated', $note, $old, $note->only(['body', 'pinned']));

        return response()->json(['message' => __('notes.saved'), 'data' => $this->present($note->fresh(['author:id,name', 'student', 'lesson:id,name', 'level', 'levelSubject.level', 'levelSubject.subject']), $request)]);
    }

    public function destroy(Request $request, Note $note): JsonResponse
    {
        abort_unless(NoteReach::canChange($request->user(), $note), 403);
        $this->audit->record('note.deleted', $note, $note->only(['scope', 'body', 'student_id', 'level_id', 'level_subject_id']), []);
        $note->delete();

        return response()->json(['message' => __('notes.deleted')]);
    }

    /**
     * The student's notes timeline (profile tab الملاحظات): their notes, plus — read through, never copied — their
     * difficulties and follow-up notes, attendance notes and evaluation notes. Newest first.
     */
    public function timeline(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);
        $this->authorizeView($request);
        $locale = app()->getLocale();
        $items = new Collection;

        Note::with(['author:id,name', 'lesson:id,name'])->where('scope', 'student')->where('student_id', $student->id)->get()
            ->each(fn (Note $n) => $items->push($this->item('note', 'note-'.$n->id, $n->created_at, $n->body, $n->author?->name, $n->lesson?->name, [
                'id' => $n->id, 'pinned' => $n->pinned, 'can_edit' => NoteReach::canChange($request->user(), $n), 'term' => AcademicTerm::nameFor($n->academic_term_id),
            ])));

        StudentIssue::with(['opener:id,name', 'lesson:id,name'])->where('student_id', $student->id)->get()
            ->each(fn (StudentIssue $i) => $items->push($this->item('issue', 'issue-'.$i->id, $i->opened_at ?? $i->created_at, $i->description, $i->opener?->name, $i->lesson?->name, [
                'issue_id' => $i->id, 'category' => $i->category?->label($locale), 'status' => $i->status?->value,
            ])));

        IssueNote::with(['author:id,name', 'issue:id,category'])->whereIn('student_issue_id', StudentIssue::select('id')->where('student_id', $student->id))->get()
            ->each(fn (IssueNote $n) => $items->push($this->item('issue_note', 'issue-note-'.$n->id, $n->noted_on ?? $n->created_at, $n->note, $n->author?->name, null, [
                'issue_id' => $n->student_issue_id, 'category' => $n->issue?->category?->label($locale),
            ])));

        Attendance::with(['recorder:id,name', 'session.lesson:id,name'])->where('student_id', $student->id)->whereNotNull('note')->where('note', '!=', '')->get()
            ->each(fn (Attendance $a) => $items->push($this->item('attendance', 'attendance-'.$a->id, $a->session?->session_date ?? $a->created_at, $a->note, $a->recorder?->name, $a->session?->lesson?->name, [
                'status' => $a->status?->value,
            ])));

        Evaluation::with(['evaluator:id,name', 'lesson:id,name', 'subject'])->where('student_id', $student->id)->whereNotNull('note')->where('note', '!=', '')->get()
            ->each(fn (Evaluation $e) => $items->push($this->item('evaluation', 'evaluation-'.$e->id, $e->evaluated_on, $e->note, $e->evaluator?->name, $e->lesson?->name, [
                'subject' => $e->subject?->name(), 'type' => $e->type->value,
            ])));

        return response()->json([
            'data' => $items->sortByDesc(fn ($i) => $i['date'].'|'.$i['key'])->values()->take(300)->values(),
            'can_add' => $request->user()->can('notes.manage'),
        ]);
    }

    private function item(string $kind, string $key, mixed $date, ?string $body, ?string $author, ?string $lesson, array $meta): array
    {
        $at = $date instanceof \DateTimeInterface ? \Carbon\Carbon::instance($date) : ($date ? \Carbon\Carbon::parse((string) $date) : null);

        return [
            'kind' => $kind, 'key' => $key, 'date' => $at?->toDateString() ?? '', 'at' => display_tz($at)?->toIso8601String(),
            'body' => (string) $body, 'author' => $author, 'lesson' => $lesson, 'meta' => $meta,
        ];
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('notes.view') || $request->user()->can('notes.manage'), 403);
    }

    private function present(Note $n, Request $request): array
    {
        $ls = $n->levelSubject;

        return [
            'id' => $n->id, 'scope' => $n->scope, 'academic_term_id' => $n->academic_term_id, 'body' => $n->body, 'pinned' => $n->pinned,
            'author' => $n->author ? ['id' => $n->author->id, 'name' => $n->author->name] : null,
            'student' => $n->student ? ['id' => $n->student->id, 'full_name' => $n->student->full_name, 'student_no' => $n->student->student_no] : null,
            'lesson' => $n->lesson ? ['id' => $n->lesson->id, 'name' => $n->lesson->name] : null,
            'level' => $n->level ? ['id' => $n->level->id, 'name' => $n->level->name()] : null,
            'level_subject' => $ls ? ['id' => $ls->id, 'level' => ['id' => $ls->level->id, 'name' => $ls->level->name()], 'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()]] : null,
            'created_at' => display_tz($n->created_at)?->toIso8601String(),
            'updated_at' => display_tz($n->updated_at)?->toIso8601String(),
            'can_edit' => NoteReach::canChange($request->user(), $n),
        ];
    }
}
