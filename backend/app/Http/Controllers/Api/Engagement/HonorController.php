<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\HonorPeriod;
use App\Models\HonorRanking;
use App\Models\Student;
use App\Models\StudentBadge;
use App\Services\Engagement\HonorService;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Honor board (section 13). One board per month and per gender track: a supervisor only ever sees their own
 * track, and the public TV display shows one track at a time.
 *
 * @group Honor board
 */
class HonorController extends Controller
{
    public function __construct(private HonorService $service) {}

    /** The board for a month and track. Level: track (default), package or circle. */
    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HonorPeriod::class);
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'gender' => ['nullable', Rule::in(HonorService::GENDERS)],
            'level' => ['nullable', Rule::in(['track', 'package', 'circle'])],
            'lesson_id' => ['nullable', 'integer'],
            'package_id' => ['nullable', 'integer'],
        ]);
        $gender = $this->genderFor($request, $data['gender'] ?? null);
        $period = $data['period'] ?? HonorService::currentPeriod();
        $hp = HonorPeriod::where('period', $period)->where('gender', $gender)->first();
        if ($hp) {
            $this->authorize('view', $hp);
        }

        return response()->json(['data' => $this->payload($hp, $period, $gender, $data['level'] ?? 'track', $data['lesson_id'] ?? null, $data['package_id'] ?? null, full: true),
            // Managers get the TV display key to build the screen link.
            'display_key' => $request->user()->can('honor.manage') ? ((string) setting('honor.display_key', '') ?: null) : null]);
    }

    /** Months that have a board for the track (newest first). */
    public function periods(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HonorPeriod::class);
        $gender = $this->genderFor($request, $request->string('gender')->toString() ?: null);

        return response()->json(['data' => HonorPeriod::where('gender', $gender)->orderByDesc('period')->limit(24)->get()
            ->map(fn ($p) => ['id' => $p->id, 'period' => $p->period, 'status' => $p->status, 'published' => (bool) $p->published_to_students])]);
    }

    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m'], 'gender' => ['nullable', Rule::in(HonorService::GENDERS)]]);
        $gender = $this->genderFor($request, $data['gender'] ?? null);
        $hp = $this->service->period($data['period'] ?? HonorService::currentPeriod(), $gender);
        $this->authorize('update', $hp);
        $hp = $this->service->compute($hp->period, $gender);

        return response()->json(['message' => __('honor.computed'), 'data' => $this->payload($hp, $hp->period, $gender, 'track', null, null, full: true)]);
    }

    /** Honor the top three: certificate drafts (approved in the certificates screen), WhatsApp congratulations, publish. */
    public function honor(Request $request, HonorPeriod $period): JsonResponse
    {
        $this->authorize('update', $period);
        $data = $request->validate(['certificates' => ['boolean'], 'messages' => ['boolean'], 'publish' => ['boolean']]);
        if ($period->status === 'open') {
            $period = $this->service->finalize($period);
        }
        $already = $period->status === 'honored';
        $result = $this->service->honor($period, $request->user(),
            $data['certificates'] ?? true,
            ! $already && ($data['messages'] ?? true), // congratulations go out once per month
            $data['publish'] ?? true);

        return response()->json(['message' => __('honor.honored'), 'data' => $result]);
    }

    public function publish(Request $request, HonorPeriod $period): JsonResponse
    {
        $this->authorize('update', $period);
        $period->update(['published_to_students' => $request->boolean('published', true)]);

        return response()->json(['message' => __('honor.published'), 'data' => ['published' => (bool) $period->published_to_students]]);
    }

    /** Student / guardian view: own rank, points breakdown and badges; the top of the board only when published. */
    public function me(Request $request): JsonResponse
    {
        $student = $this->ownStudent($request);
        $gender = $student->gender?->value;
        $period = $request->input('period', HonorService::currentPeriod());
        $hp = HonorPeriod::where('period', $period)->where('gender', $gender)->first();
        $mine = $hp ? $hp->rankings()->where('student_id', $student->id)->first() : null;

        return response()->json(['data' => [
            'student' => ['id' => $student->id, 'full_name' => $student->full_name],
            'period' => $period,
            'published' => (bool) $hp?->published_to_students,
            'mine' => $mine && $hp->published_to_students ? $this->rankRow($mine, withStudent: false) : null,
            'board' => $hp?->published_to_students ? $this->payload($hp, $period, $gender, 'track', null, null, full: false, limit: 10) : null,
            'badges' => $this->badgesOf($student),
        ]]);
    }

    /** Public TV display (no login): needs the display key from settings; shows only published boards. */
    public function display(Request $request): JsonResponse
    {
        $key = (string) setting('honor.display_key', '');
        abort_if($key === '' || ! hash_equals($key, (string) $request->query('key', '')), 403);
        $gender = $request->query('gender') === 'female' ? 'female' : 'male';
        $hp = HonorPeriod::where('gender', $gender)->where('published_to_students', true)->orderByDesc('period')->first();
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'authority' => ['ar' => setting('authority.name_ar'), 'en' => setting('authority.name_en')],
            'board' => $hp ? $this->payload($hp, $hp->period, $gender, 'track', null, null, full: false, limit: 10) : null,
            'badges' => $hp ? StudentBadge::with(['badge', 'student:id,full_name,gender'])->where('period', $hp->period)
                ->whereHas('student', fn ($q) => $q->where('gender', $gender))->latest('awarded_at')->limit(12)->get()
                ->map(fn ($b) => ['student' => $this->publicName($b->student?->full_name), 'badge' => $b->badge?->localizedName($locale), 'icon' => $b->badge?->icon])->values() : [],
        ]]);
    }

    /**
     * تحديد المتفوقين: the honor board's grades source. Students of the term ranked by their weighted grade totals
     * (الدرجات), per level or class and optionally one subject; ties share a rank. grades.view; teachers see the
     * classes they teach (with a subject: the classes they teach it in).
     */
    public function topStudents(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('grades.view'), 403);
        $term = \App\Support\TermScope::single($request);
        $data = $request->validate([
            'level_id' => ['nullable', 'integer'], 'lesson_id' => ['nullable', 'integer'], 'subject_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'gender' => ['nullable', Rule::in(HonorService::GENDERS)],
        ]);
        $gender = $this->genderFor($request, $data['gender'] ?? null);
        $reach = \App\Services\Grades\Gradebook::termLessons($user, $term)->whereNotNull('lessons.level_id')->with('level');
        $classes = (clone $reach)->orderBy('lessons.name')->get(['lessons.id', 'lessons.name', 'lessons.level_id']);
        $lessons = $reach
            ->when($data['level_id'] ?? null, fn ($q, $v) => $q->where('lessons.level_id', $v))
            ->when($data['lesson_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->when(($data['subject_id'] ?? null) && ! $user->can('lessons.manage'), fn ($q) => $q->whereIn('lessons.id', \App\Support\TeacherScope::lessonIds($user, (int) $data['subject_id'])))
            ->get();
        $rows = \App\Services\Grades\Gradebook::ranking($lessons, $term, $data['subject_id'] ?? null, $gender);
        $limit = $data['limit'] ?? 20;
        // Keep everyone tied with the last place shown.
        $cut = isset($rows[$limit - 1]) ? $rows[$limit - 1]['rank'] : null;
        $shown = $cut === null ? $rows : array_values(array_filter($rows, fn ($r) => $r['rank'] <= $cut));
        $levelSubjects = \App\Models\LevelSubject::with('subject')->where('academic_term_id', $term->id)->get();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'gender' => $gender,
            'levels' => $classes->pluck('level')->filter()->unique('id')->sortBy('sort')->map(fn ($l) => ['id' => $l->id, 'name' => $l->name()])->values(),
            'classes' => $classes->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'level_id' => $l->level_id])->values(),
            'subjects' => $levelSubjects->pluck('subject')->filter()->unique('id')->sortBy('sort')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name()])->values(),
            'rows' => $shown,
            'ranked' => count($rows),
        ]);
    }

    public function badges(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HonorPeriod::class);

        return response()->json(['data' => Badge::withCount('awards')->orderBy('sort_order')->get()->map(fn (Badge $b) => $this->badgeRow($b))]);
    }

    public function updateBadge(Request $request, Badge $badge): JsonResponse
    {
        abort_unless($request->user()->can('honor.manage'), 403);
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:120'], 'name_en' => ['sometimes', 'string', 'max:120'],
            'description_ar' => ['nullable', 'string', 'max:500'], 'description_en' => ['nullable', 'string', 'max:500'],
            'rule_value' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'bonus_points' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $badge->update($data);

        return response()->json(['message' => __('api.saved'), 'data' => $this->badgeRow($badge->loadCount('awards'))]);
    }

    // ---------------------------------------------------------------------------------------------

    private function ownStudent(Request $request): Student
    {
        $user = $request->user();
        $student = $user->student ?? ($request->filled('student_id') ? $user->children()->whereKey($request->integer('student_id'))->first() : null);
        abort_unless($student, 403);

        return $student;
    }

    private function genderFor(Request $request, ?string $asked): string
    {
        $limit = Track::genderFor($request->user())?->value;

        return $limit ?? ($asked ?: 'male');
    }

    private function payload(?HonorPeriod $hp, string $period, string $gender, string $level, ?int $lessonId, ?int $packageId, bool $full, int $limit = 0): array
    {
        $col = ['track' => 'rank_in_track', 'package' => 'rank_in_package', 'circle' => 'rank_in_circle'][$level];
        $rows = collect();
        if ($hp) {
            $rows = $hp->rankings()->with(['student', 'lesson:id,name', 'package'])
                ->when($level === 'circle' && $lessonId, fn ($q) => $q->where('lesson_id', $lessonId))
                ->when($level === 'package' && $packageId, fn ($q) => $q->where('package_id', $packageId))
                ->orderBy($col)->orderByDesc('points_x100')->orderBy('student_id')
                ->when($limit, fn ($q) => $q->limit($limit))->get();
            if ($level !== 'track' && ! $lessonId && ! $packageId) {
                // Top three of every circle / package.
                $rows = $rows->filter(fn ($r) => $r->{$col} !== null && $r->{$col} <= 3)
                    ->sortBy([[$level === 'circle' ? 'lesson_id' : 'package_id', 'asc'], [$col, 'asc']])->values();
            }
        }

        $circle = $hp?->circleOfMonth()->with('teacher:id,name')->first();

        return [
            'id' => $hp?->id,
            'period' => $period,
            'gender' => $gender,
            'status' => $hp?->status ?? 'none',
            'published' => (bool) $hp?->published_to_students,
            'weights' => $hp?->weights ?? HonorService::weights(),
            'honored_at' => $hp?->honored_at?->toIso8601String(),
            'level' => $level,
            'computed_at' => $hp?->updated_at?->toIso8601String(),
            'rows' => $rows->map(fn ($r) => $this->rankRow($r, withStudent: true, full: $full) + ['rank' => $r->{$col}])->values(),
            'circle_of_month' => $circle ? [
                'id' => $circle->id, 'name' => $circle->name, 'teacher' => $circle->teacher?->name,
                'avg_points' => round(($hp->rankings()->where('lesson_id', $circle->id)->avg('points_x100') ?? 0) / 100, 1),
                'students' => $hp->rankings()->where('lesson_id', $circle->id)->count(),
            ] : null,
            'totals' => [
                'students' => $hp ? $hp->rankings()->count() : 0,
                'badges' => $hp ? StudentBadge::where('period', $period)->whereHas('student', fn ($q) => $q->where('gender', $gender))->count() : 0,
            ],
        ];
    }

    private function rankRow(HonorRanking $r, bool $withStudent, bool $full = true): array
    {
        $row = [
            'rank_in_track' => $r->rank_in_track, 'rank_in_package' => $r->rank_in_package, 'rank_in_circle' => $r->rank_in_circle,
            'points' => round($r->points_x100 / 100, 2),
            'points_change' => $r->points_change_x100 === null ? null : round($r->points_change_x100 / 100, 2),
            'breakdown' => [
                'attendance' => round($r->attendance_points_x100 / 100, 2), 'evaluation' => round($r->evaluation_points_x100 / 100, 2),
                'memorization' => round($r->memorization_points_x100 / 100, 2), 'bonus' => round($r->bonus_points_x100 / 100, 2),
            ],
            'attendance_pct' => $r->attendance_pct, 'evaluation_avg' => round($r->evaluation_avg_x100 / 100, 2), 'new_ayahs' => $r->new_ayahs,
            'lesson' => $r->relationLoaded('lesson') && $r->lesson ? ['id' => $r->lesson->id, 'name' => $r->lesson->name] : null,
            'package' => $r->relationLoaded('package') && $r->package ? ['id' => $r->package->id, 'name' => $r->package->name] : null,
        ];
        if ($withStudent && $r->student) {
            $row['student'] = $full
                ? ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'initial' => $r->student->initial(), 'photo_url' => $r->student->photoUrl('thumb')]
                // Minimal data on public and student-facing screens: two names and an initial, no photo.
                : ['id' => $r->student->id, 'full_name' => $this->publicName($r->student->full_name), 'initial' => $r->student->initial()];
        }

        return $row;
    }

    private function publicName(?string $name): string
    {
        return implode(' ', array_slice(preg_split('/\s+/u', trim((string) $name)) ?: [], 0, 2));
    }

    private function badgesOf(Student $s): array
    {
        $locale = app()->getLocale();

        return StudentBadge::with('badge')->where('student_id', $s->id)->latest('awarded_at')->get()
            ->map(fn ($b) => ['id' => $b->id, 'badge' => $b->badge?->localizedName($locale), 'icon' => $b->badge?->icon, 'key' => $b->badge?->key, 'period' => $b->period, 'awarded_at' => $b->awarded_at?->toIso8601String()])->all();
    }

    private function badgeRow(Badge $b): array
    {
        return [
            'id' => $b->id, 'key' => $b->key, 'name_ar' => $b->name_ar, 'name_en' => $b->name_en,
            'name' => $b->localizedName(app()->getLocale()),
            'description_ar' => $b->description_ar, 'description_en' => $b->description_en,
            'icon' => $b->icon, 'rule_type' => $b->rule_type, 'rule_value' => $b->rule_value,
            'repeatable_monthly' => (bool) $b->repeatable_monthly, 'bonus_points' => $b->bonus_points, 'is_active' => (bool) $b->is_active,
            'awarded' => (int) ($b->awards_count ?? 0),
        ];
    }
}
