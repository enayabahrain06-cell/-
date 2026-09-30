<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Package;
use App\Services\Reports\AbsenceReport;
use App\Services\Reports\AttendanceReport;
use App\Services\Reports\EvaluationReport;
use App\Services\Reports\ExamResultsReport;
use App\Services\Reports\MessagesReport;
use App\Services\Reports\ProgressReports;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportResponder;
use App\Services\Reports\TeacherPerformanceReport;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group Reports
 */
class ReportsController extends Controller
{
    /**
     * Reports this user may open, with their filters and export formats, plus the circles, packages and teachers the
     * filter controls can offer (already limited to the user's track and, for teachers, their own circles).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reports = ReportCatalog::for($user);
        abort_if($reports === [], 403);

        $term = \App\Support\TermScope::fromRequest($request);
        $lessons = (new ReportContext($user, ['term' => $term]))->lessonQuery()->with('teacher:id,name')->orderBy('name')->get(['id', 'name', 'package_id', 'teacher_id', 'gender', 'status']);
        $packages = Package::query()->tap(fn ($q) => Track::scope($q, $user))->tap(fn ($q) => \App\Support\TermScope::packages($q, $term))
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereIn('id', $lessons->pluck('package_id')->unique()->all() ?: [0]))
            ->orderByDesc('id')->get(['id', 'name', 'name_ar', 'name_en', 'gender']);

        return response()->json([
            'data' => $reports,
            'groups' => array_map(fn ($g) => ['key' => $g, 'label' => __("reports.groups.{$g}")], ReportCatalog::GROUPS),
            'options' => [
                'lessons' => $lessons->map(fn (Lesson $l) => ['id' => $l->id, 'name' => $l->name, 'package_id' => $l->package_id, 'teacher_id' => $l->teacher_id, 'active' => $l->status?->value === 'active'])->values(),
                'packages' => $packages->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->localizedName(app()->getLocale())])->values(),
                'teachers' => $lessons->pluck('teacher')->filter()->unique('id')->sortBy('name')->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
                'track' => Track::genderFor($user)?->value ?? 'both',
            ],
        ]);
    }

    /**
     * One report in the common shape (title, period, summary, sections, data). Filters: from, to, lesson_id,
     * package_id, teacher_id, gender (both-track users only), min_absences, months, status. format=xlsx|pdf exports.
     */
    public function show(Request $request, string $key, ReportResponder $responder): JsonResponse|Response
    {
        $user = $request->user();
        abort_unless(ReportCatalog::allows($user, $key), 403);
        if (in_array($request->string('format')->toString(), ['xlsx', 'pdf'], true)) {
            abort_unless($user->can('reports.export'), 403);
        }

        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'lesson_id' => ['nullable', 'integer'],
            'package_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'gender' => ['nullable', 'in:male,female'],
            'min_absences' => ['nullable', 'integer', 'min:1', 'max:100'],
            'months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'status' => ['nullable', 'string', 'max:32'],
            'format' => ['nullable', 'in:json,xlsx,pdf'],
        ]);
        $f['term'] = \App\Support\TermScope::fromRequest($request);
        // A track-limited user cannot widen their view with a gender filter.
        if (Track::genderFor($user) !== null) {
            unset($f['gender']);
            $request->query->remove('gender');
        }

        $report = match ($key) {
            'attendance' => app(AttendanceReport::class)->build($user, $f),
            'absence' => app(AbsenceReport::class)->build($user, $f),
            'evaluation' => app(EvaluationReport::class)->build($user, $f),
            'exams' => app(ExamResultsReport::class)->build($user, $f),
            'teachers' => app(TeacherPerformanceReport::class)->build($user, $f),
            'messages' => app(MessagesReport::class)->build($user, $f),
            'juz' => app(ProgressReports::class)->juz($request),
            'issues' => app(ProgressReports::class)->issues($request),
            'high-issues' => app(ProgressReports::class)->highIssues($request),
            'issue-trend' => app(ProgressReports::class)->issueTrend($request),
            'tracks' => app(ProgressReports::class)->tracks($request),
        };

        return $responder->respond($request, $report, "{$key}-report-".now(config('ahl.display_timezone', 'Asia/Bahrain'))->format('Ymd'));
    }
}
