<?php

namespace App\Http\Controllers\Api\Divisions;

use App\Enums\LessonStudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Division;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Subject;
use App\Models\User;
use App\Policies\LessonPolicy;
use App\Services\AuditLogger;
use App\Services\Evaluation\EvaluationService;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Education follow-up
 * @subgroup Divisions
 *
 * التقسيمات: groups inside a class for the term. Reading needs lessons.view (teachers their own classes);
 * writing needs divisions.manage plus managing the class (lessons.manage) or teaching it. A student is in at most one
 * division of a class, and only active students of the class can be assigned.
 *
 * عرض تقييم طلبة التقسيم: per-student averages per criterion for one division's students (results()).
 */
class DivisionController extends Controller
{
    public function __construct(private AuditLogger $audit, private EvaluationService $evaluations) {}

    /** The term's classes the user reaches and, with ?lesson_id=, its divisions and active students. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('lessons.view'), 403);
        $term = TermScope::single($request);
        $classes = $this->termLessons($user, $term)->orderBy('name')->get(['lessons.id', 'lessons.name', 'lessons.level_id', 'lessons.teacher_id']);

        $lesson = $request->filled('lesson_id') ? $classes->firstWhere('id', $request->integer('lesson_id')) : null;
        abort_if($request->filled('lesson_id') && ! $lesson, 403);

        $payload = [
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'classes' => $classes->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'level_id' => $l->level_id])->values(),
            'teachers' => User::role('teacher')->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ];
        if ($lesson) {
            $divisions = Division::with(['teacher:id,name', 'students:id'])->where('lesson_id', $lesson->id)->where('academic_term_id', $term->id)
                ->orderBy('sort')->orderBy('name')->get();
            $of = [];
            foreach ($divisions as $d) {
                foreach ($d->students as $s) {
                    $of[$s->id] = $d->id;
                }
            }
            $payload += [
                'lesson' => ['id' => $lesson->id, 'name' => $lesson->name],
                'can_manage' => $this->canManage($user, Lesson::find($lesson->id)),
                'divisions' => $divisions->map(fn (Division $d) => self::division($d))->values(),
                'students' => LessonStudent::with('student')->where('lesson_id', $lesson->id)->where('status', LessonStudentStatus::Active->value)->get()
                    ->filter(fn ($r) => $r->student)->sortBy('student.full_name')->values()
                    ->map(fn ($r) => ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no, 'division_id' => $of[$r->student_id] ?? null]),
            ];
        }

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
        ] + $this->rules());
        $lesson = Lesson::with('package')->findOrFail($data['lesson_id']);
        abort_unless($this->canManage($request->user(), $lesson), 403);
        if (! TermScope::matches($lesson->package, (int) $data['academic_term_id'])) {
            throw ValidationException::withMessages(['lesson_id' => __('divisions.errors.term')]);
        }
        $this->assertTeacher($data['teacher_id'] ?? null);
        $division = Division::create($data + ['sort' => $data['sort'] ?? 0]);
        $this->audit->record('division.created', $division, [], $division->only(['name', 'lesson_id', 'teacher_id']));

        return response()->json(['message' => __('divisions.saved'), 'data' => self::division($division->load(['teacher:id,name', 'students:id']))], 201);
    }

    public function update(Request $request, Division $division): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $division->lesson), 403);
        $data = $request->validate($this->rules(true));
        $this->assertTeacher($data['teacher_id'] ?? null);
        $old = $division->only(['name', 'teacher_id', 'sort']);
        $division->update($data);
        $this->audit->record('division.updated', $division, $old, $division->only(array_keys($old)));

        return response()->json(['message' => __('divisions.saved'), 'data' => self::division($division->fresh(['teacher:id,name', 'students:id']))]);
    }

    public function destroy(Request $request, Division $division): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $division->lesson), 403);
        $this->audit->record('division.deleted', $division, $division->only(['name', 'lesson_id']) + ['students' => $division->students()->pluck('students.id')->all()], []);
        $division->delete(); // its memberships go with it; evaluations keep their scores (division_id becomes null)

        return response()->json(['message' => __('divisions.deleted')]);
    }

    /** The division's students (replaces the list). Only active students of the class, none of another division. */
    public function assign(Request $request, Division $division): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $division->lesson), 403);
        $data = $request->validate(['student_ids' => ['present', 'array', 'max:500'], 'student_ids.*' => ['integer', 'distinct']]);
        $ids = array_map('intval', $data['student_ids']);

        $active = LessonStudent::where('lesson_id', $division->lesson_id)->where('status', LessonStudentStatus::Active->value)
            ->whereIn('student_id', $ids)->pluck('student_id')->all();
        if ($missing = array_diff($ids, $active)) {
            throw ValidationException::withMessages(['student_ids' => __('divisions.errors.not_in_class')]);
        }
        $elsewhere = DB::table('division_students')->join('divisions', 'divisions.id', '=', 'division_students.division_id')
            ->where('divisions.lesson_id', $division->lesson_id)->where('divisions.id', '!=', $division->id)
            ->whereIn('division_students.student_id', $ids)->pluck('divisions.name')->unique()->all();
        if ($elsewhere) {
            throw ValidationException::withMessages(['student_ids' => __('divisions.errors.other_division', ['name' => implode('، ', $elsewhere)])]);
        }
        $old = $division->students()->pluck('students.id')->all();
        $division->students()->sync($ids);
        $this->audit->record('division.students', $division, ['students' => $old], ['students' => $ids]);

        return response()->json(['message' => __('divisions.assigned', ['count' => count($ids)]), 'data' => self::division($division->fresh(['teacher:id,name', 'students:id']))]);
    }

    /**
     * عرض تقييم طلبة التقسيم: for each student of the division, the average of each criterion of the subject over the
     * class's evaluations in the date range (daily and monthly), and the division's averages.
     */
    public function results(Request $request, Division $division): JsonResponse
    {
        $user = $request->user();
        $lesson = $division->lesson;
        $f = $request->validate(['subject_id' => ['nullable', 'integer', 'exists:subjects,id'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'type' => ['nullable', 'in:daily,monthly']]);
        $subjectId = (int) ($f['subject_id'] ?? 0) ?: Subject::quranId();
        abort_unless($user->can('evaluations.view') && LessonPolicy::ownsOrManages($user, $lesson, $subjectId), 403);

        $criteria = \App\Models\EvaluationCriterion::where('subject_id', $subjectId)->ordered()->get();
        $students = $division->students()->orderBy('full_name')->get(['students.id', 'full_name', 'student_no']);
        $evaluations = Evaluation::where('lesson_id', $lesson->id)->whereIn('student_id', $students->pluck('id')->all() ?: [0])
            ->when($subjectId === Subject::quranId(), fn ($q) => $q->quran(), fn ($q) => $q->where('subject_id', $subjectId))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('evaluated_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('evaluated_on', '<=', $v))
            ->get(['id', 'student_id']);
        $scores = EvaluationScore::whereIn('evaluation_id', $evaluations->pluck('id')->all() ?: [0])->get(['evaluation_id', 'criterion_id', 'score']);
        $studentOf = $evaluations->pluck('student_id', 'id');
        $byStudent = $scores->groupBy(fn ($s) => $studentOf[$s->evaluation_id]);
        $avg = fn ($rows) => $criteria->mapWithKeys(fn ($c) => [$c->id => ($v = $rows->where('criterion_id', $c->id)->avg('score')) === null ? null : round((float) $v, 1)])->all();
        $percent = function ($rows) use ($criteria) {
            // Weighted percentage of the maximum, so criteria with different maxima compare.
            $num = 0.0;
            $den = 0.0;
            foreach ($criteria as $c) {
                $v = $rows->where('criterion_id', $c->id)->avg('score');
                if ($v !== null && $c->max_score > 0) {
                    $num += ($v / $c->max_score) * $c->weight;
                    $den += $c->weight;
                }
            }

            return $den > 0 ? round($num / $den * 100, 1) : null;
        };

        return response()->json([
            'division' => ['id' => $division->id, 'name' => $division->name, 'lesson' => ['id' => $lesson->id, 'name' => $lesson->name]],
            'subjects' => $this->evaluations->subjectsFor($lesson)->filter(fn ($s) => LessonPolicy::ownsOrManages($user, $lesson, $s->id))
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name()])->values(),
            'subject_id' => $subjectId,
            'criteria' => $this->evaluations->criteriaRows($criteria),
            'data' => $students->map(fn ($s) => [
                'student' => ['id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no],
                'count' => $evaluations->where('student_id', $s->id)->count(),
                'averages' => (object) $avg($byStudent->get($s->id, collect())),
                'percent' => $percent($byStudent->get($s->id, collect())),
            ])->values(),
            'averages' => (object) $avg($scores),
            'percent' => $percent($scores),
        ]);
    }

    private function rules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'teacher_id' => ['nullable', 'integer', 'exists:users,id'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    private function assertTeacher(?int $id): void
    {
        if ($id && ! User::whereKey($id)->role('teacher')->exists()) {
            throw ValidationException::withMessages(['teacher_id' => __('divisions.errors.teacher')]);
        }
    }

    private function canManage(User $user, ?Lesson $lesson): bool
    {
        return $lesson !== null && $user->can('divisions.manage') && LessonPolicy::ownsOrManages($user, $lesson);
    }

    private function termLessons(User $user, AcademicTerm $term): \Illuminate\Database\Eloquent\Builder
    {
        return Lesson::query()->tap(fn ($q) => TermScope::via($q, $term->id))->tap(fn ($q) => Track::scope($q, $user))
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereIn('lessons.id', TeacherScope::lessonIds($user)));
    }

    private static function division(Division $d): array
    {
        return [
            'id' => $d->id, 'lesson_id' => $d->lesson_id, 'academic_term_id' => $d->academic_term_id, 'name' => $d->name, 'sort' => $d->sort,
            'teacher' => $d->teacher ? ['id' => $d->teacher->id, 'name' => $d->teacher->name] : null,
            'student_ids' => $d->students->pluck('id')->values(),
        ];
    }
}
