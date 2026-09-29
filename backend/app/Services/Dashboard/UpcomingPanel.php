<?php

namespace App\Services\Dashboard;

use Ahl\Certificates\Enums\CertificateStatus;
use App\Enums\ExamStatus;
use App\Enums\InboundStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\PackageStatus;
use App\Models\Certificate;
use App\Models\Exam;
use App\Models\InboundMessage;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\User;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "Coming up this week": one date-sorted list over today … today + 6 (display timezone) of
 * exams opening, packages ending, plus two standing piles that need a decision now
 * (certificates awaiting approval, unhandled inbound messages), aggregated into one item each.
 * Every item type is filtered by its own permission and by the viewer's track / own circles.
 */
class UpcomingPanel
{
    public function __construct(private DashboardService $dashboard) {}

    public function build(User $user, ?string $term = null, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $today = now($tz)->startOfDay();
        $last = $today->copy()->addDays(6);

        $items = collect()
            ->merge($user->can('exams.view') ? $this->exams($user, $term, $today, $last, $locale) : [])
            ->merge($user->can('packages.view') ? $this->packages($user, $term, $today, $last, $locale) : [])
            ->merge($user->can('certificates.approve') ? $this->certificates($user, $term, $today, $locale) : [])
            ->merge($user->can('messages.view') ? $this->messages($user, $today, $locale) : []);

        return [
            'from' => $today->toDateString(),
            'to' => $last->toDateString(),
            'items' => $items->sortBy(fn ($i) => [$i['date'], $i['at'] ?? '', $i['type']])->values()->all(),
        ];
    }

    private function exams(User $user, ?string $term, Carbon $today, Carbon $last, string $locale): Collection
    {
        $from = $today->copy()->utc();
        $to = $last->copy()->endOfDay()->utc();

        $exams = Exam::forStudents()->with(['lesson:id,name', 'package:id,name,name_ar,name_en'])
            ->whereIn('status', [ExamStatus::Draft->value, ExamStatus::Published->value])
            ->whereBetween('opens_at', [$from, $to])
            ->when($this->dashboard->teacherOnly($user) && ! $user->can('exams.manage'),
                fn ($q) => $q->whereIn('lesson_id', $this->dashboard->lessonScope($user)->select('id')))
            ->when($term, fn ($q) => $q->where(fn ($w) => $w->whereHas('package', fn ($p) => $p->where('term', $term))
                ->orWhereHas('lesson.package', fn ($p) => $p->where('term', $term))))
            ->tap(fn ($q) => Track::scope($q, $user))
            ->get();

        // Students sitting it: the circle's active students, or every active student in the package's circles.
        $byLesson = LessonStudent::where('status', LessonStudentStatus::Active->value)
            ->whereIn('lesson_id', $exams->pluck('lesson_id')->filter()->unique())
            ->get(['lesson_id'])->countBy('lesson_id');
        $packageIds = $exams->whereNull('lesson_id')->pluck('package_id')->filter()->unique();
        $byPackage = $packageIds->isEmpty() ? collect() : LessonStudent::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_students.lesson_id')
            ->where('lesson_students.status', LessonStudentStatus::Active->value)
            ->whereIn('lessons.package_id', $packageIds)
            ->get(['lessons.package_id', 'lesson_students.student_id'])
            ->groupBy('package_id')->map(fn ($rows) => $rows->pluck('student_id')->unique()->count());

        return $exams->map(function (Exam $e) use ($byLesson, $byPackage, $locale) {
            $at = display_tz($e->opens_at);
            $circle = $e->lesson?->name ?? $e->package?->localizedName($locale);

            return [
                'id' => "exam-{$e->id}",
                'type' => 'exam',
                'date' => $at->toDateString(),
                'at' => $at->toIso8601String(),
                'title' => $e->name,
                'subtitle' => $circle,
                'count' => (int) ($e->lesson_id ? ($byLesson[$e->lesson_id] ?? 0) : ($byPackage[$e->package_id] ?? 0)),
                'draft' => $e->status === ExamStatus::Draft,
                'link' => "/exams/{$e->id}",
            ];
        });
    }

    private function packages(User $user, ?string $term, Carbon $today, Carbon $last, string $locale): Collection
    {
        return Package::where('status', PackageStatus::Open->value)
            ->whereBetween('end_date', [$today->toDateString(), $last->toDateString()])
            ->when($term, fn ($q) => $q->where('term', $term))
            ->tap(fn ($q) => Track::scope($q, $user))
            ->get()
            ->map(fn (Package $p) => [
                'id' => "package-{$p->id}",
                'type' => 'package',
                'date' => $p->end_date->toDateString(),
                'at' => null,
                'title' => $p->localizedName($locale),
                'subtitle' => __('dashboard_feed.upcoming.package_ends', [], $locale),
                'count' => null,
                'link' => '/packages',
            ]);
    }

    /** One item: how many drafts wait, and since when. It sits on today; `since` is the oldest draft. */
    private function certificates(User $user, ?string $term, Carbon $today, string $locale): array
    {
        $drafts = Certificate::where('status', CertificateStatus::Draft->value)
            ->when($term, fn ($q) => $q->whereHas('lesson.package', fn ($p) => $p->where('term', $term)))
            ->tap(fn ($q) => Track::scopeVia($q, $user, 'student'));
        $count = (clone $drafts)->count();
        if ($count === 0) {
            return [];
        }

        return [[
            'id' => 'certificates',
            'type' => 'certificates',
            'date' => $today->toDateString(),
            'at' => null,
            'since' => display_tz(Carbon::parse((clone $drafts)->min('created_at')))?->toDateString(),
            'title' => __('dashboard_feed.upcoming.certificates_title', [], $locale),
            'subtitle' => __('dashboard_feed.upcoming.certificates_subtitle', [], $locale),
            'count' => $count,
            'link' => '/certificates',
        ]];
    }

    /** Inbound WhatsApp messages nobody has handled yet (scoped staff only see their track's students). */
    private function messages(User $user, Carbon $today, string $locale): array
    {
        $open = InboundMessage::where('status', InboundStatus::Open->value)
            ->when(Track::genderFor($user), fn ($q) => Track::scopeVia($q, $user, 'student'));
        $count = (clone $open)->count();
        if ($count === 0) {
            return [];
        }

        return [[
            'id' => 'messages',
            'type' => 'messages',
            'date' => $today->toDateString(),
            'at' => null,
            'since' => display_tz(Carbon::parse((clone $open)->min('received_at')))?->toDateString(),
            'title' => __('dashboard_feed.upcoming.messages_title', [], $locale),
            'subtitle' => __('dashboard_feed.upcoming.messages_subtitle', [], $locale),
            'count' => $count,
            'link' => '/messages',
        ]];
    }
}
