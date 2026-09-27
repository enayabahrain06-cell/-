<?php

namespace App\Http\Controllers\Api\Progress;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\LessonStudentStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Services\Issues\IssueService;
use App\Support\Quran;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Memorization and difficulty reports. Aggregation is done in PHP over plain selects so the
 * same code runs on MySQL, PostgreSQL and SQLite.
 *
 * @group Reports
 */
class ProgressReportController extends Controller
{
    /** Students by current juz. Filters: lesson_id, package_id, gender. Pass juz=N to list that juz's students. */
    public function byJuz(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $students = $this->studentScope($request)->get(['id', 'student_no', 'full_name', 'gender', 'progress_juz', 'progress_surah', 'progress_ayah', 'memorized_ayahs', 'photo_thumb_path', 'photo_path', 'birth_date', 'memorization_level', 'status', 'guardian_name', 'guardian_phone', 'student_phone', 'locale']);
        $counts = $students->countBy(fn ($s) => $s->progress_juz ?? 0);

        $rows = [['juz' => null, 'label' => __('progress.monthly_update.not_started'), 'students' => $counts[0] ?? 0]];
        for ($j = 1; $j <= Quran::JUZ_COUNT; $j++) {
            $rows[] = ['juz' => $j, 'label' => (string) $j, 'students' => $counts[$j] ?? 0];
        }

        $list = null;
        if ($request->has('juz')) {
            $juz = $request->integer('juz') ?: null;
            $list = $students->filter(fn ($s) => $s->progress_juz === $juz)->sortBy('full_name')->values()
                ->map(fn ($s) => (new StudentSummaryResource($s))->toArray($request) + [
                    'position' => $s->progress_surah ? ['surah' => $s->progress_surah, 'surah_name' => Quran::name($s->progress_surah, app()->getLocale()), 'ayah' => $s->progress_ayah, 'juz' => $s->progress_juz] : null,
                    'memorized_ayahs' => $s->memorized_ayahs,
                ]);
        }

        return response()->json(['total' => $students->count(), 'data' => $rows, 'students' => $list]);
    }

    /** Unresolved high-severity difficulties with student, circle and teacher. Filters: lesson_id, teacher_id, category. */
    public function highSeverity(Request $request, IssueService $issues): JsonResponse
    {
        $this->authorize('reports.view');

        $rows = $this->issueScope($request)->unresolved()->where('severity', IssueSeverity::High->value)
            ->with(['student', 'lesson.teacher:id,name', 'opener:id,name', 'notes.author:id,name'])
            ->orderBy('next_follow_up_date')->orderByDesc('opened_at')->get();

        return response()->json(['total' => $rows->count(), 'data' => $rows->map(fn (StudentIssue $i) => $issues->present($i) + [
            'student' => new StudentSummaryResource($i->student),
            'lesson' => $i->lesson?->name,
            'teacher' => $i->lesson?->teacher?->name,
        ])->values()]);
    }

    /**
     * Most common difficulty categories per circle and per teacher.
     * Filters: status (default unresolved; "all" for every issue), from/to on opened_at.
     */
    public function categories(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $q = $this->issueScope($request)->with('lesson.teacher:id,name');
        $status = $request->string('status', 'unresolved')->toString();
        if ($status === 'unresolved') {
            $q->unresolved();
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }
        $issues = $q->get(['id', 'lesson_id', 'category', 'status', 'severity', 'opened_at']);

        $summarise = fn (Collection $group) => $group->countBy(fn ($i) => $i->category->value)->sortDesc()
            ->map(fn ($n, $cat) => ['category' => $cat, 'label' => IssueCategory::from($cat)->label(), 'count' => $n])->values();

        $byLesson = $issues->groupBy('lesson_id')->map(fn (Collection $g, $lessonId) => [
            'lesson_id' => $lessonId ?: null,
            'lesson' => $g->first()->lesson?->name,
            'teacher' => $g->first()->lesson?->teacher?->name,
            'total' => $g->count(),
            'categories' => $summarise($g),
        ])->sortByDesc('total')->values();

        $byTeacher = $issues->groupBy(fn ($i) => $i->lesson?->teacher_id ?? 0)->map(fn (Collection $g, $teacherId) => [
            'teacher_id' => $teacherId ?: null,
            'teacher' => $g->first()->lesson?->teacher?->name,
            'total' => $g->count(),
            'categories' => $summarise($g),
        ])->sortByDesc('total')->values();

        return response()->json(['total' => $issues->count(), 'overall' => $summarise($issues), 'by_lesson' => $byLesson, 'by_teacher' => $byTeacher]);
    }

    /** Issues opened and resolved per month (Asia/Bahrain), last N months (default 12). */
    public function resolvedMonthly(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $months = max(1, min(36, $request->integer('months', 12)));
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $start = now($tz)->startOfMonth()->subMonths($months - 1);

        $issues = $this->issueScope($request)
            ->where(fn ($q) => $q->where('opened_at', '>=', $start->copy()->utc())->orWhere('resolved_at', '>=', $start->copy()->utc()))
            ->get(['id', 'opened_at', 'resolved_at', 'category']);

        $opened = $issues->filter(fn ($i) => $i->opened_at)->countBy(fn ($i) => Carbon::parse($i->opened_at)->setTimezone($tz)->format('Y-m'));
        $resolved = $issues->filter(fn ($i) => $i->resolved_at)->countBy(fn ($i) => Carbon::parse($i->resolved_at)->setTimezone($tz)->format('Y-m'));

        $rows = [];
        for ($m = $start->copy(); $m->lte(now($tz)); $m->addMonth()) {
            $key = $m->format('Y-m');
            $rows[] = ['period' => $key, 'opened' => $opened[$key] ?? 0, 'resolved' => $resolved[$key] ?? 0];
        }

        return response()->json(['data' => $rows]);
    }

    private function studentScope(Request $request)
    {
        return Student::query()->where('status', StudentStatus::Active->value)
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('lesson_id'), fn ($q) => $q->whereIn('id', LessonStudent::where('status', LessonStudentStatus::Active->value)->where('lesson_id', $request->integer('lesson_id'))->select('student_id')))
            ->when($request->filled('package_id'), fn ($q) => $q->whereIn('id', LessonStudent::where('status', LessonStudentStatus::Active->value)->whereIn('lesson_id', Lesson::where('package_id', $request->integer('package_id'))->select('id'))->select('student_id')));
    }

    private function issueScope(Request $request)
    {
        return StudentIssue::query()
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('teacher_id'), fn ($q) => $q->whereIn('lesson_id', Lesson::where('teacher_id', $request->integer('teacher_id'))->select('id')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('from'), fn ($q) => $q->where('opened_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('opened_at', '<=', $request->date('to')->endOfDay()));
    }
}
