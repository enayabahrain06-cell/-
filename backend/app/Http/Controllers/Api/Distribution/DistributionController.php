<?php

namespace App\Http\Controllers\Api\Distribution;

use App\Enums\LessonStudentStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Services\Distribution\ClassPlacement;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * @group Registration
 * @subgroup Distribution
 *
 * توزيع المستويات, ترفيع الطلبة and تحديث المستوى. Everything needs distribution.manage; the writes reuse the
 * enrollment logic (ClassPlacement) so capacity, gender, age and track rules apply.
 */
class DistributionController extends Controller
{
    public function __construct(private ClassPlacement $placement) {}

    /** Levels (with their rules) and the selected term's classes with free seats. */
    public function options(Request $request): JsonResponse
    {
        $this->authorize_($request);
        $term = TermScope::single($request);

        return response()->json(['data' => [
            'term' => self::term($term),
            'levels' => $this->levels()->map(fn (Level $l) => ClassPlacement::presentLevel($l))->values(),
            'classes' => $this->placement->termClasses($term, $request->user())->map(fn ($l) => ClassPlacement::presentClass($l))->values(),
        ]]);
    }

    /**
     * Students to place in the term: view=unplaced (active students with no active class in the term's classes) or
     * view=level (students of one level's classes this term). Each with age at the term start and a suggested level.
     */
    public function students(Request $request): JsonResponse
    {
        $this->authorize_($request);
        $term = TermScope::single($request);
        $data = $request->validate([
            'view' => ['nullable', Rule::in(['unplaced', 'level'])],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $view = $data['view'] ?? 'unplaced';
        $termLessons = fn ($q) => TermScope::via($q, $term->id);

        $students = Student::query()->tap(fn ($q) => Track::scope($q, $request->user()))
            ->when($view === 'unplaced', fn ($q) => $q->where('status', StudentStatus::Active->value)
                ->whereDoesntHave('lessonStudents', fn ($ls) => $ls->where('status', LessonStudentStatus::Active->value)->whereHas('lesson', $termLessons)))
            ->when($view === 'level', fn ($q) => $q->whereHas('lessonStudents', fn ($ls) => $ls->where('status', LessonStudentStatus::Active->value)
                ->whereHas('lesson', fn ($l) => $termLessons($l)->when($data['level_id'] ?? null, fn ($x, $id) => $x->where('level_id', $id)))))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('student_no', 'like', "%{$s}%")->orWhere('cpr', 'like', "%{$s}%")))
            ->orderBy('full_name')->limit(500)->get();

        $current = LessonStudent::with('lesson.level')->whereIn('student_id', $students->pluck('id'))
            ->where('status', LessonStudentStatus::Active->value)->whereHas('lesson', $termLessons)->get()->keyBy('student_id');
        $levels = $this->levels();

        return response()->json(['data' => $students->map(function (Student $s) use ($term, $levels, $current) {
            $age = ClassPlacement::ageAtStart($s, $term);
            $row = $current->get($s->id);
            $suggest = ClassPlacement::suggestLevel($levels, $s, $age);

            return self::student($s) + [
                'age' => $age,
                'current' => $row?->lesson ? ['lesson_id' => $row->lesson->id, 'lesson' => $row->lesson->name, 'level_id' => $row->lesson->level_id, 'level' => $row->lesson->level?->name()] : null,
                'suggested_level' => $suggest ? ['id' => $suggest->id, 'name' => $suggest->name()] : null,
            ];
        })->values()]);
    }

    /** Place the chosen students in a class of a level (lesson_id, or null for "auto": most free seats). */
    public function place(Request $request): JsonResponse
    {
        $this->authorize_($request);
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['integer', 'exists:students,id'],
        ]);
        $term = AcademicTerm::findOrFail($data['academic_term_id']);
        $results = $this->placement->placeMany($data['student_ids'], (int) $data['level_id'], $data['lesson_id'] ?? null, $term, $request->user());
        $ok = count(array_filter($results, fn ($r) => $r['ok']));

        return response()->json(['message' => __('distribution.placed_count', ['count' => $ok, 'total' => count($results)]), 'data' => $results]);
    }

    /**
     * ترفيع الطلبة: the students of a level's classes in the source term, each with any decision already taken,
     * plus the target term's classes of the target level (default: the next level by sort) and of the same level.
     */
    public function promotion(Request $request): JsonResponse
    {
        $this->authorize_($request);
        $data = $request->validate([
            'from_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'from_level_id' => ['required', 'integer', 'exists:levels,id'],
            'to_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'to_level_id' => ['nullable', 'integer', 'exists:levels,id'],
        ]);
        $user = $request->user();
        $from = AcademicTerm::findOrFail($data['from_term_id']);
        $to = AcademicTerm::findOrFail($data['to_term_id']);
        $fromLevel = Level::findOrFail($data['from_level_id']);
        $next = $this->nextLevel($fromLevel);
        $toLevel = isset($data['to_level_id']) ? Level::findOrFail($data['to_level_id']) : $next;

        $lessonIds = $this->placement->levelLessonIds($from, $fromLevel->id);
        $decided = StudentPromotion::with(['toLesson', 'toLevel'])->where('from_term_id', $from->id)->whereIn('decision', ['promote', 'repeat', 'graduate'])
            ->get()->keyBy('student_id');
        $rows = LessonStudent::with('lesson')->whereIn('lesson_id', $lessonIds)
            ->where(fn ($q) => $q->where('status', LessonStudentStatus::Active->value)->orWhereIn('student_id', $decided->keys()))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('id')->get()->unique('student_id');
        $students = Student::whereIn('id', $rows->pluck('student_id'))->tap(fn ($q) => Track::scope($q, $user))->orderBy('full_name')->get();
        $inTarget = LessonStudent::with('lesson.level')->whereIn('student_id', $students->pluck('id'))->where('status', LessonStudentStatus::Active->value)
            ->whereHas('lesson', fn ($q) => TermScope::via($q, $to->id))->get()->keyBy('student_id');
        $byStudent = $rows->keyBy('student_id');
        $classes = fn (?Level $l) => $l ? $this->placement->termClasses($to, $user, $l->id)->map(fn ($c) => ClassPlacement::presentClass($c))->values() : collect();

        return response()->json(['data' => [
            'from_term' => self::term($from),
            'to_term' => self::term($to),
            'from_level' => ClassPlacement::presentLevel($fromLevel),
            'to_level' => $toLevel ? ClassPlacement::presentLevel($toLevel) : null,
            'next_level_id' => $next?->id,
            'promote_classes' => $classes($toLevel),
            'repeat_classes' => $classes($fromLevel),
            'students' => $students->map(fn (Student $s) => self::student($s) + [
                'age' => ClassPlacement::ageAtStart($s, $to),
                'lesson' => ($l = $byStudent->get($s->id)?->lesson) ? ['id' => $l->id, 'name' => $l->name] : null,
                'target_class' => ($t = $inTarget->get($s->id)?->lesson) ? ['id' => $t->id, 'name' => $t->name, 'level' => $t->level?->name()] : null,
                'decision' => ($d = $decided->get($s->id)) ? ['decision' => $d->decision, 'label' => __('distribution.decisions.'.$d->decision), 'to_lesson' => $d->toLesson?->name, 'to_level' => $d->toLevel?->name()] : null,
            ])->values(),
        ]]);
    }

    public function promote(Request $request): JsonResponse
    {
        $this->authorize_($request);
        $data = $request->validate([
            'from_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'from_level_id' => ['required', 'integer', 'exists:levels,id'],
            'to_term_id' => ['required', 'integer', 'exists:academic_terms,id', 'different:from_term_id'],
            'to_level_id' => ['required', 'integer', 'exists:levels,id'],
            'mark_graduated' => ['boolean'],
            'decisions' => ['required', 'array', 'min:1', 'max:300'],
            'decisions.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'decisions.*.decision' => ['required', Rule::in(['promote', 'repeat', 'graduate'])],
            'decisions.*.lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'decisions.*.reason' => ['nullable', 'string', 'max:255'],
        ]);
        $results = $this->placement->promote(
            AcademicTerm::findOrFail($data['from_term_id']), Level::findOrFail($data['from_level_id']),
            AcademicTerm::findOrFail($data['to_term_id']), Level::findOrFail($data['to_level_id']),
            $data['decisions'], (bool) ($data['mark_graduated'] ?? false), $request->user(),
        );
        $ok = count(array_filter($results, fn ($r) => $r['ok']));

        return response()->json(['message' => __('distribution.promoted_count', ['count' => $ok, 'total' => count($results)]), 'data' => $results]);
    }

    /** تحديث المستوى: the student's class and level this term, and their level history. */
    public function levelShow(Request $request, Student $student): JsonResponse
    {
        $this->authorize_($request);
        abort_unless(Track::allows($request->user(), $student->gender), 403);
        $term = TermScope::single($request);
        $row = $this->placement->currentRow($student, $term);

        return response()->json(['data' => [
            'term' => self::term($term),
            'student' => self::student($student) + ['age' => ClassPlacement::ageAtStart($student, $term)],
            'current' => $row?->lesson ? ['lesson_id' => $row->lesson->id, 'lesson' => $row->lesson->name, 'level_id' => $row->lesson->level_id, 'level' => $row->lesson->level?->name()] : null,
            'history' => StudentPromotion::with(['fromTerm', 'toTerm', 'fromLevel', 'toLevel', 'fromLesson', 'toLesson', 'decider'])
                ->where('student_id', $student->id)->latest('id')->get()->map(fn ($p) => ClassPlacement::presentPromotion($p))->values(),
        ]]);
    }

    public function levelChange(Request $request, Student $student): JsonResponse
    {
        $this->authorize_($request);
        abort_unless(Track::allows($request->user(), $student->gender), 403);
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $row = $this->placement->changeLevel($student, AcademicTerm::findOrFail($data['academic_term_id']), Level::findOrFail($data['level_id']),
            $data['lesson_id'] ?? null, $data['reason'], $request->user());

        return response()->json(['message' => __('distribution.level_changed', ['name' => $student->full_name, 'class' => $row->toLesson?->name]),
            'data' => ClassPlacement::presentPromotion($row->load(['fromTerm', 'toTerm', 'fromLevel', 'toLevel', 'fromLesson', 'toLesson', 'decider']))]);
    }

    private function authorize_(Request $request): void
    {
        abort_unless($request->user()->can('distribution.manage'), 403);
    }

    private function levels(): Collection
    {
        return Level::where('is_active', true)->ordered()->get();
    }

    /** The next active level by sort, or null for the last one. */
    private function nextLevel(Level $level): ?Level
    {
        $all = $this->levels()->values();
        $i = $all->search(fn (Level $l) => $l->id === $level->id);

        return $i === false ? null : $all->get($i + 1);
    }

    private static function term(AcademicTerm $t): array
    {
        return ['id' => $t->id, 'name' => $t->name(), 'start_date' => $t->start_date?->toDateString(), 'is_current' => $t->is_current];
    }

    private static function student(Student $s): array
    {
        return [
            'id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'gender' => $s->gender?->value,
            'status' => $s->status?->value, 'birth_date' => $s->birth_date?->toDateString(),
            'memorization_level' => $s->memorization_level?->value, 'memorization_level_label' => $s->memorization_level?->label(),
        ];
    }
}
