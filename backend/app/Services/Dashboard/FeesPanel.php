<?php

namespace App\Services\Dashboard;

use App\Enums\InvoiceStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Models\Invoice;
use App\Models\LessonStudent;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Messaging\MessageService;
use App\Support\Money;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard "Fees & dues" card. Scoped by the student's track; with a term (or for a teacher-only
 * viewer) to students actively enrolled in the circles DashboardService::lessonScope allows.
 * Dates are Bahrain calendar dates; overdue = open/partial invoice with due_date before today.
 */
class FeesPanel
{
    public function __construct(private DashboardService $dashboard, private MessageService $messages, private AuditLogger $audit) {}

    public function build(User $user, ?string $term = null): array
    {
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $now = now($tz);
        $today = Carbon::parse($now->toDateString());

        $thisMonth = (int) $this->scoped(Payment::query(), $user, $term)
            ->where('paid_at', '>=', $now->copy()->startOfMonth()->utc())->sum('amount_fils');
        $lastMonth = (int) $this->scoped(Payment::query(), $user, $term)
            ->where('paid_at', '>=', $now->copy()->subMonthNoOverflow()->startOfMonth()->utc())
            ->where('paid_at', '<', $now->copy()->startOfMonth()->utc())->sum('amount_fils');

        $open = $this->scoped(Invoice::query(), $user, $term)
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Partial->value])
            ->with('student:id,full_name,guardian_phone,student_phone')
            ->get(['id', 'student_id', 'amount_fils', 'paid_fils', 'due_date']);

        $overdue = $open->filter(fn (Invoice $i) => $i->due_date->lt($today) && $i->outstandingFils() > 0);
        $dueWeek = $open->filter(fn (Invoice $i) => $i->due_date->gte($today) && $i->due_date->lte($today->copy()->addDays(6)) && $i->outstandingFils() > 0);

        $top = $overdue->groupBy('student_id')->map(function ($invoices) use ($today) {
            $student = $invoices->first()->student;

            return [
                'student_id' => $student->id,
                'name' => $student->full_name,
                'days_overdue' => (int) $invoices->max(fn (Invoice $i) => $i->due_date->diffInDays($today)),
                'outstanding_fils' => (int) $invoices->sum(fn (Invoice $i) => $i->outstandingFils()),
                'invoices' => $invoices->count(),
                'has_phone' => (bool) ($student->guardian_phone || $student->student_phone),
            ];
        })->sortByDesc(fn ($r) => [$r['outstanding_fils'], $r['days_overdue']])->values();

        return [
            'collected' => [
                'this_month_fils' => $thisMonth,
                'last_month_fils' => $lastMonth,
                'change_percent' => $lastMonth > 0 ? (int) round(($thisMonth - $lastMonth) * 100 / $lastMonth) : null,
            ],
            'overdue' => [
                'amount_fils' => (int) $overdue->sum(fn (Invoice $i) => $i->outstandingFils()),
                'students' => $overdue->pluck('student_id')->unique()->count(),
                'invoices' => $overdue->count(),
            ],
            'due_this_week' => [
                'amount_fils' => (int) $dueWeek->sum(fn (Invoice $i) => $i->outstandingFils()),
                'invoices' => $dueWeek->count(),
                'students' => $dueWeek->pluck('student_id')->unique()->count(),
            ],
            'top_overdue' => $top->take(5)->all(),
            'can_remind' => $this->canRemind($user),
        ];
    }

    public function canRemind(User $user): bool
    {
        return $user->can('payments.record') || $user->can('messages.send');
    }

    /** Whether the student is inside what this user may see on the card. */
    public function visible(User $user, Student $student, ?string $term = null): bool
    {
        return $this->scoped(Student::query()->whereKey($student->id), $user, $term, 'self')->exists();
    }

    /**
     * Send the existing payment-due reminder for everything overdue, to guardian and student phones
     * (as SendInvoiceReminders does). At most once per phone per Bahrain day; invoice reminder columns are untouched.
     *
     * @return int messages queued
     */
    public function remind(User $user, Student $student): int
    {
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $today = Carbon::parse(now($tz)->toDateString());

        $overdue = $student->invoices()->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Partial->value])->get()
            ->filter(fn (Invoice $i) => $i->due_date->lt($today) && $i->outstandingFils() > 0);
        if ($overdue->isEmpty()) {
            throw ValidationException::withMessages(['student' => __('dashboard_panels.fees.nothing_overdue')]);
        }

        $phones = array_values(array_unique(array_filter([$student->guardian_phone, $student->student_phone])));
        if ($phones === []) {
            throw ValidationException::withMessages(['student' => __('dashboard_panels.fees.no_phone')]);
        }

        $locale = $student->locale?->value ?? 'ar';
        $oldest = $overdue->sortBy(fn (Invoice $i) => $i->due_date->toDateString())->first();
        $vars = [
            'invoice_no' => $overdue->count() === 1 ? $oldest->invoice_no : $overdue->pluck('invoice_no')->implode('، '),
            'amount' => Money::format((int) $overdue->sum(fn (Invoice $i) => $i->outstandingFils()), $locale),
            'date' => $oldest->due_date->toDateString(),
        ];

        $sent = 0;
        foreach ($phones as $phone) {
            $key = "dashboard-fee-reminder:{$student->id}:{$phone}:{$today->toDateString()}";
            if ($this->messages->send($phone, MessageType::PaymentDueReminder, $vars, $locale, $student, dedupeKey: $key)) {
                $sent++;
            }
        }

        if ($sent === 0) {
            throw ValidationException::withMessages(['student' => __('dashboard_panels.fees.already_reminded')]);
        }

        $this->audit->record('invoice.reminder_sent', $student, [], [
            'invoices' => $overdue->pluck('id')->all(),
            'amount_fils' => (int) $overdue->sum(fn (Invoice $i) => $i->outstandingFils()),
            'messages' => $sent,
            'via' => 'dashboard',
        ], $user->id);

        return $sent;
    }

    /** Track via the student; term / teacher-only via active enrolment in the allowed circles. */
    private function scoped(Builder $query, User $user, ?string $term, string $via = 'student'): Builder
    {
        $restrict = $term !== null || $this->dashboard->teacherOnly($user);
        $studentIds = fn () => LessonStudent::where('status', LessonStudentStatus::Active->value)
            ->whereIn('lesson_id', $this->dashboard->lessonScope($user, $term)->select('id'))->select('student_id');

        if ($via === 'self') {
            return $query->tap(fn ($q) => Track::scope($q, $user))
                ->when($restrict, fn ($q) => $q->whereIn('id', $studentIds()));
        }

        return $query->tap(fn ($q) => Track::scopeVia($q, $user, 'student'))
            ->when($restrict, fn ($q) => $q->whereIn('student_id', $studentIds()));
    }
}
