<?php

namespace App\Http\Controllers\Api\Grades;

use App\Http\Controllers\Controller;
use App\Models\GradeComponent;
use App\Models\Lesson;
use App\Models\LevelSubject;
use App\Models\User;
use App\Services\Grades\Gradebook;
use App\Services\Lessons\ClassSchedule;
use App\Support\TermScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Grades
 * @subgroup Grade submission monitor
 *
 * مراقبة تسليم الدرجات: for every class of the term × level subject × grade component, how many active students still
 * have no score (exam components: no graded attempt in the linked exam), and who is responsible: the teachers of the
 * subject's periods in الجدول الدراسي, else the level subject's teacher, else the class teacher. A level subject
 * without components is listed too. grades.manage; managers see their track.
 */
class GradeMonitorController extends Controller
{
    public function index(Request $request, ClassSchedule $schedule): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('grades.manage'), 403);
        $term = TermScope::single($request);
        $filters = $request->validate(['level_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'], 'only_missing' => ['nullable', 'boolean']]);

        $lessons = Gradebook::termLessons($user, $term)->whereNotNull('lessons.level_id')->with(['level', 'package:id,academic_term_id'])
            ->when($filters['level_id'] ?? null, fn ($q, $v) => $q->where('lessons.level_id', $v))
            ->orderBy('lessons.level_id')->orderBy('lessons.name')->get();
        $periods = $schedule->periodsFor($lessons);
        $levelSubjects = LevelSubject::with('subject')->where('academic_term_id', $term->id)
            ->whereIn('level_id', $lessons->pluck('level_id')->unique()->all() ?: [0])->orderBy('sort')->orderBy('id')->get()->groupBy('level_id');
        $components = GradeComponent::whereIn('level_subject_id', $levelSubjects->flatten()->pluck('id')->all() ?: [0])
            ->with('exam:id,name,total_marks,status,lesson_id,package_id')->ordered()->get()->groupBy('level_subject_id');

        $rows = [];
        $teacherIds = [];
        foreach ($lessons as $lesson) {
            $students = Gradebook::students($lesson)->pluck('id')->all();
            foreach ($levelSubjects->get($lesson->level_id) ?? [] as $ls) {
                $teachers = collect($periods[$lesson->id] ?? [])->where('subject_id', $ls->subject_id)->pluck('teacher_id')->filter()->unique()->values()->all();
                $teachers = $teachers ?: array_values(array_filter([$ls->teacher_id ?: null])) ?: array_values(array_filter([$lesson->teacher_id]));
                $teacherIds = array_merge($teacherIds, $teachers);
                $base = [
                    'lesson' => ['id' => $lesson->id, 'name' => $lesson->name],
                    'level' => ['id' => $lesson->level->id, 'name' => $lesson->level->name()],
                    'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()],
                    'teacher_ids' => array_map('intval', $teachers),
                    'students' => count($students),
                ];
                $comps = $components->get($ls->id) ?? collect();
                if ($comps->isEmpty()) {
                    $rows[] = $base + ['component' => null, 'exam' => null, 'missing' => count($students), 'exam_covers_class' => null];

                    continue;
                }
                $cells = Gradebook::cells($comps, $students);
                foreach ($comps as $c) {
                    $missing = count(array_filter($students, fn ($sid) => ($cells[$sid][$c->id]['score'] ?? null) === null));
                    $covers = $c->isExam() && $c->exam
                        ? ($c->exam->lesson_id ? (int) $c->exam->lesson_id === (int) $lesson->id : (int) $c->exam->package_id === (int) $lesson->package_id)
                        : null;
                    $rows[] = $base + [
                        'component' => ['id' => $c->id, 'name' => $c->name(), 'kind' => $c->kind],
                        'exam' => $c->exam ? ['id' => $c->exam->id, 'name' => $c->exam->name, 'status' => $c->exam->status?->value] : null,
                        'missing' => $missing,
                        'exam_covers_class' => $covers,
                    ];
                }
            }
        }

        $names = User::whereIn('id', array_unique($teacherIds) ?: [0])->pluck('name', 'id');
        $teacherOptions = collect($names)->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->sortBy('name')->values();
        $rows = collect($rows)
            ->when($filters['teacher_id'] ?? null, fn ($c, $tid) => $c->filter(fn ($r) => in_array((int) $tid, $r['teacher_ids'], true)))
            ->when($request->boolean('only_missing'), fn ($c) => $c->filter(fn ($r) => $r['missing'] > 0))
            ->map(fn ($r) => $r + ['teachers' => array_values(array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? null], $r['teacher_ids']))])
            ->values();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'levels' => \App\Models\Level::whereIn('id', LevelSubject::where('academic_term_id', $term->id)->select('level_id'))->ordered()->get()
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name()])->values(),
            'teachers' => $teacherOptions,
            'rows' => $rows,
            'summary' => [
                'rows' => $rows->count(),
                'incomplete' => $rows->where('missing', '>', 0)->count(),
                'missing' => $rows->sum('missing'),
            ],
        ]);
    }
}
