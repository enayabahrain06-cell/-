<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\StudentProgress;
use App\Models\Subject;
use App\Support\Quran;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Education follow-up
 * @subgroup Quran lessons
 *
 * دروس القرآن: the memorization ledger (student_progress) of the term, read-only. Entries are recorded from the
 * attendance and evaluation sheets (and the profile); this list only reads them. evaluations.view; teachers see the
 * classes where they teach Quran.
 */
class QuranLessonsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('evaluations.view'), 403);
        $term = TermScope::single($request);
        $f = $request->validate([
            'lesson_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'], 'type' => ['nullable', 'in:memorized,revised'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $lessons = Lesson::query()->tap(fn ($q) => TermScope::via($q, $term->id))->tap(fn ($q) => Track::scope($q, $user))
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereIn('lessons.id', TeacherScope::lessonIds($user, Subject::quranId())));
        $classes = (clone $lessons)->orderBy('name')->get(['lessons.id', 'lessons.name']);

        $query = StudentProgress::with(['student:id,full_name,student_no,gender', 'lesson:id,name'])
            ->whereIn('lesson_id', (clone $lessons)->select('lessons.id'))
            ->when($f['lesson_id'] ?? null, fn ($q, $v) => $q->where('lesson_id', $v))
            ->when($f['student_id'] ?? null, fn ($q, $v) => $q->where('student_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('recorded_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('recorded_on', '<=', $v));
        $ayahs = (int) (clone $query)->sum('ayah_count');
        $page = $query->orderByDesc('recorded_on')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 50));

        $recorders = \App\Models\User::whereIn('id', collect($page->items())->pluck('recorded_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $locale = app()->getLocale();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'classes' => $classes->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values(),
            'data' => collect($page->items())->map(fn (StudentProgress $p) => [
                'id' => $p->id,
                'date' => $p->recorded_on?->toDateString(),
                'type' => $p->type->value,
                'student' => $p->student ? ['id' => $p->student->id, 'full_name' => $p->student->full_name, 'student_no' => $p->student->student_no] : null,
                'lesson' => $p->lesson ? ['id' => $p->lesson->id, 'name' => $p->lesson->name] : null,
                'surah_number' => $p->surah_number,
                'surah' => Quran::name($p->surah_number, $locale),
                'from_ayah' => $p->from_ayah,
                'to_ayah' => $p->to_ayah,
                'ayah_count' => $p->ayah_count,
                'recorded_by' => $recorders[$p->recorded_by] ?? null,
                'note' => $p->note,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'ayahs' => $ayahs],
        ]);
    }
}
