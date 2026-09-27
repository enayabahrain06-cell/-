<?php

namespace App\Services\Dashboard;

use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\Package;
use App\Models\Payment;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * "Recent activity": a merged, read-only feed over the tables that already record each event
 * (no activity table of its own). The audit log covers some events; attendance and hall changes are
 * not audited, so they are read from their own rows. The feed covers the last WINDOW_DAYS days,
 * each source capped at SOURCE_CAP rows, merged newest first; everything is scoped like the dashboard.
 *
 * Types: attendance, evaluation, registration, payment, schedule.
 */
class ActivityPanel
{
    public const WINDOW_DAYS = 30;

    public const SOURCE_CAP = 300;

    /** Schedule fields whose change on a package counts as a "schedule changed" event. */
    private const PACKAGE_SCHEDULE_KEYS = ['days', 'start_time', 'end_time', 'start_date', 'end_date'];

    public function __construct(private DashboardService $dashboard) {}

    /** @return array{data: list<array>, meta: array} */
    public function page(User $user, ?string $term, int $page, int $perPage, ?string $locale = null): array
    {
        $items = $this->items($user, $term, $locale ?? app()->getLocale());
        $lastPage = max(1, (int) ceil($items->count() / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'data' => $items->forPage($page, $perPage)->values()->all(),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $items->count(), 'per_page' => $perPage, 'window_days' => self::WINDOW_DAYS],
        ];
    }

    public function items(User $user, ?string $term, string $locale): Collection
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        return collect()
            ->merge($user->can('attendance.view') || $user->can('attendance.record') ? $this->attendance($user, $term, $since, $locale) : [])
            ->merge($user->can('evaluations.view') || $user->can('evaluations.record') ? $this->evaluations($user, $term, $since, $locale) : [])
            ->merge($this->registrations($user, $term, $since, $locale))
            ->merge($user->can('wallets.view') ? $this->payments($user, $since, $locale) : [])
            ->merge($user->can('lessons.view') ? $this->schedule($user, $term, $since, $locale) : [])
            ->sortByDesc('at')->values();
    }

    private function attendance(User $user, ?string $term, Carbon $since, string $locale): Collection
    {
        return LessonSession::with(['lesson:id,name', 'takenBy:id,name'])
            ->withCount(['attendances', 'attendances as present_count' => fn ($q) => $q->whereIn('status', ['present', 'late'])])
            ->whereNotNull('attendance_taken_at')->where('attendance_taken_at', '>=', $since)
            ->whereIn('lesson_id', $this->dashboard->lessonScope($user, $term)->select('id'))
            ->orderByDesc('attendance_taken_at')->limit(self::SOURCE_CAP)->get()
            ->map(fn (LessonSession $s) => $this->item('attendance', "attendance-{$s->id}", $s->attendance_taken_at, $s->takenBy?->name,
                __('dashboard_feed.activity.attendance', [
                    'circle' => $s->lesson?->name,
                    'present' => $this->digits((string) $s->present_count, $locale),
                    'total' => $this->digits((string) $s->attendances_count, $locale),
                ], $locale),
                $user->can('attendance.view') || $user->can('attendance.record') ? "/attendance/{$s->id}" : null));
    }

    /** One event per sheet: evaluations saved together (same session or circle + day, same evaluator) are grouped. */
    private function evaluations(User $user, ?string $term, Carbon $since, string $locale): Collection
    {
        return Evaluation::with(['lesson:id,name', 'student:id,full_name', 'evaluator:id,name'])
            ->where('created_at', '>=', $since)
            ->whereIn('lesson_id', $this->dashboard->lessonScope($user, $term)->select('id'))
            ->orderByDesc('created_at')->limit(self::SOURCE_CAP)->get()
            ->groupBy(fn (Evaluation $e) => ($e->lesson_session_id ? "s{$e->lesson_session_id}" : "l{$e->lesson_id}-".$e->evaluated_on?->toDateString()).'-'.$e->evaluated_by)
            ->map(function (Collection $group, string $key) use ($user, $locale) {
                $first = $group->sortByDesc('created_at')->first();
                $count = $group->pluck('student_id')->unique()->count();
                $description = $count === 1
                    ? __('dashboard_feed.activity.evaluation_one', ['student' => $first->student?->full_name, 'circle' => $first->lesson?->name], $locale)
                    : __('dashboard_feed.activity.evaluation_many', ['count' => $this->digits((string) $count, $locale), 'circle' => $first->lesson?->name], $locale);
                $link = $first->lesson_session_id && $user->can('evaluations.record') ? "/evaluation/{$first->lesson_session_id}"
                    : ($user->can('lessons.view') ? "/lessons/{$first->lesson_id}" : null);

                return $this->item('evaluation', "evaluation-{$key}", $first->created_at, $first->evaluator?->name, $description, $link);
            })->values();
    }

    /** Public registration requests, quick enrollments and accepted requests (the last two carry the staff member). */
    private function registrations(User $user, ?string $term, Carbon $since, string $locale): Collection
    {
        $out = collect();
        $studentLink = fn (?int $id) => $id && $user->can('students.view') ? "/students/{$id}" : null;

        if ($user->can('registrations.view')) {
            RegistrationRequest::with('package:id,name,name_ar,name_en')
                ->where('source', 'public')->where('created_at', '>=', $since)
                ->when($term, fn ($q) => $q->whereHas('package', fn ($p) => $p->where('term', $term)))
                ->tap(fn ($q) => Track::scope($q, $user))
                ->orderByDesc('created_at')->limit(self::SOURCE_CAP)->get()
                ->each(fn (RegistrationRequest $r) => $out->push($this->item('registration', "request-{$r->id}", $r->created_at, null,
                    __('dashboard_feed.activity.registration_public', ['name' => $r->full_name, 'package' => $r->package?->localizedName($locale)], $locale),
                    '/packages?tab=requests')));

            $this->audits(['registration.accepted'], $since)
                ->each(function (AuditLog $a) use ($user, $term, $out, $locale, $studentLink) {
                    $r = $a->getRelation('subject');
                    if (! $r instanceof RegistrationRequest || ! Track::allows($user, $r->gender) || ($term && $r->package?->term !== $term)) {
                        return;
                    }
                    $out->push($this->item('registration', "audit-{$a->id}", $a->created_at, $a->user?->name,
                        __('dashboard_feed.activity.registration_accepted', ['name' => $r->full_name], $locale), $studentLink($r->student_id)));
                });
        }

        if ($user->can('registrations.view') || $user->can('enrollment.quick')) {
            $own = $this->dashboard->teacherOnly($user) ? $this->dashboard->lessonScope($user)->pluck('id')->flip() : null;
            $this->audits(['enrollment.quick'], $since)
                ->each(function (AuditLog $a) use ($user, $term, $own, $out, $locale, $studentLink) {
                    $s = $a->getRelation('subject');
                    $lessonId = (int) ($a->new_values['lesson_id'] ?? 0);
                    if (! $s instanceof Student || ! Track::allows($user, $s->gender) || ($own && ! $own->has($lessonId))) {
                        return;
                    }
                    if ($term && Package::whereKey($a->new_values['package_id'] ?? 0)->value('term') !== $term) {
                        return;
                    }
                    $out->push($this->item('registration', "audit-{$a->id}", $a->created_at, $a->user?->name,
                        __('dashboard_feed.activity.registration_quick', ['name' => $s->full_name], $locale), $studentLink($s->id)));
                });
        }

        return $out;
    }

    private function payments(User $user, Carbon $since, string $locale): Collection
    {
        return Payment::with(['student:id,full_name', 'receiver:id,name'])
            ->where('paid_at', '>=', $since)
            ->tap(fn ($q) => Track::scopeVia($q, $user, 'student'))
            ->orderByDesc('paid_at')->limit(self::SOURCE_CAP)->get()
            ->map(fn (Payment $p) => $this->item('payment', "payment-{$p->id}", $p->paid_at, $p->receiver?->name,
                __('dashboard_feed.activity.payment', ['student' => $p->student?->full_name], $locale),
                $p->student_id && $user->can('students.view') ? "/students/{$p->student_id}?tab=wallet" : '/payments',
                ['amount_fils' => $p->amount_fils]));
    }

    /** One-day hall changes, package schedule edits and circle moves. */
    private function schedule(User $user, ?string $term, Carbon $since, string $locale): Collection
    {
        $scope = $this->dashboard->lessonScope($user, $term);
        $out = LessonLocationOverride::with(['lesson:id,name', 'location:id,name'])
            ->where('created_at', '>=', $since)
            ->whereIn('lesson_id', (clone $scope)->select('id'))
            ->orderByDesc('created_at')->limit(self::SOURCE_CAP)->get()
            ->map(fn (LessonLocationOverride $o) => $this->item('schedule', "override-{$o->id}", $o->created_at, null,
                __('dashboard_feed.activity.hall_changed', [
                    'circle' => $o->lesson?->name,
                    'date' => $this->date($o->override_date, $locale),
                    'hall' => $o->location?->name ?? '—',
                ], $locale),
                "/lessons/{$o->lesson_id}"));

        if ($user->can('packages.view')) {
            $this->audits(['package.updated'], $since)->each(function (AuditLog $a) use ($user, $term, $out, $locale) {
                $p = $a->getRelation('subject');
                $changed = array_intersect(self::PACKAGE_SCHEDULE_KEYS, array_keys(array_diff_assoc(
                    array_map('json_encode', (array) $a->new_values), array_map('json_encode', (array) $a->old_values))));
                if (! $p instanceof Package || ! $changed || ! Track::allows($user, $p->gender) || ($term && $p->term !== $term)) {
                    return;
                }
                $out->push($this->item('schedule', "audit-{$a->id}", $a->created_at, $a->user?->name,
                    __('dashboard_feed.activity.package_schedule', ['package' => $p->localizedName($locale)], $locale), '/packages'));
            });
        }

        $visible = (clone $scope)->pluck('name', 'id');
        $this->audits(['student.circle_moved'], $since)->each(function (AuditLog $a) use ($user, $visible, $out, $locale) {
            $s = $a->getRelation('subject');
            $to = (int) ($a->new_values['lesson_id'] ?? 0);
            $from = (int) ($a->old_values['lesson_id'] ?? 0);
            if (! $s instanceof Student || ! Track::allows($user, $s->gender) || (! $visible->has($to) && ! $visible->has($from))) {
                return;
            }
            $out->push($this->item('schedule', "audit-{$a->id}", $a->created_at, $a->user?->name,
                __('dashboard_feed.activity.circle_moved', ['student' => $s->full_name, 'circle' => $visible[$to] ?? Lesson::whereKey($to)->value('name')], $locale),
                $user->can('students.view') ? "/students/{$s->id}" : null));
        });

        return $out;
    }

    /** Audit rows with their subject attached as the "subject" relation (AuditLog has no morph relation; resolved here in one query per type). */
    private function audits(array $actions, Carbon $since): Collection
    {
        $rows = AuditLog::with('user:id,name')
            ->whereIn('action', $actions)->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->limit(self::SOURCE_CAP)->get();

        $rows->groupBy('auditable_type')->each(function (Collection $group, string $type) {
            $class = Relation::getMorphedModel($type) ?? $type;
            $models = class_exists($class) ? $class::whereKey($group->pluck('auditable_id')->unique())->get()->keyBy('id') : collect();
            $group->each(fn (AuditLog $a) => $a->setRelation('subject', $models->get($a->auditable_id)));
        });

        return $rows;
    }

    private function item(string $type, string $id, ?\DateTimeInterface $at, ?string $userName, string $description, ?string $link, array $extra = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'description' => $description,
            'user' => $userName,
            'at' => display_tz($at)?->toIso8601String(),
            'link' => $link,
            ...$extra,
        ];
    }

    private function date(?\DateTimeInterface $d, string $locale): string
    {
        return $d ? $this->digits(Carbon::instance($d)->locale($locale)->translatedFormat('j F'), $locale) : '—';
    }

    /** Arabic-Indic digits in Arabic text, as everywhere else in the UI. */
    private function digits(string $s, string $locale): string
    {
        return $locale === 'ar' ? strtr($s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) : $s;
    }
}
