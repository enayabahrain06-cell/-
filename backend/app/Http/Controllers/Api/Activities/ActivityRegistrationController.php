<?php

namespace App\Http\Controllers\Api\Activities;

use App\Enums\LessonStudentStatus;
use App\Http\Resources\StudentSummaryResource;
use App\Models\Activity;
use App\Models\ActivityAttendance;
use App\Models\ActivityBookDelivery;
use App\Models\ActivityEvaluation;
use App\Models\ActivityRegistration;
use App\Models\Invoice;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Services\Activities\ActivityRegistrar;
use App\Services\Dashboard\FeesPanel;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @group Activities
 *
 * التسجيل في البرامج / الرحلة and the registered students (الطلبة المسجلين في البرامج), which also feeds the fee,
 * book, attendance and evaluation follow-ups: one roster per activity with everything about each student.
 */
class ActivityRegistrationController extends ActivityBase
{
    public function __construct(private ActivityRegistrar $registrar) {}

    /**
     * The roster: every registration (cancelled ones with ?all=1) with the student's class in the term, fee and
     * book invoices (fee users only), book delivery, attendance tally and rate, and evaluation.
     * Attendance rate = (present + late) / (days recorded - excused).
     */
    public function index(Request $request, Activity $activity, FeesPanel $panel): JsonResponse
    {
        $this->authorizeSee($request);
        $this->reach($request, $activity);
        $user = $request->user();
        $money = self::canMoney($request);
        $regs = ActivityRegistration::with(['student', 'feeInvoice', 'bookInvoice'])->where('activity_id', $activity->id)
            ->when(! $request->boolean('all'), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->whereHas('student', fn ($q) => Track::scope($q, $user))->get();
        $ids = $regs->pluck('student_id')->all();
        $classes = $this->classesInTerm($activity, $ids);
        $deliveries = ActivityBookDelivery::with('deliverer:id,name')->where('activity_id', $activity->id)->get()->keyBy('student_id');
        $evaluations = ActivityEvaluation::with('evaluator:id,name')->where('activity_id', $activity->id)->get()->keyBy('student_id');
        $attendance = ActivityAttendance::where('activity_id', $activity->id)->whereIn('student_id', $ids)->get()->groupBy('student_id');

        $rows = $regs->map(function (ActivityRegistration $r) use ($request, $money, $classes, $deliveries, $evaluations, $attendance) {
            $invoices = collect([$r->feeInvoice, $r->bookInvoice])->filter(fn (?Invoice $i) => $i && $i->status->value !== 'cancelled');
            $total = (int) $invoices->sum('amount_fils');
            $paid = (int) $invoices->sum('paid_fils');
            $d = $deliveries->get($r->student_id);
            $e = $evaluations->get($r->student_id);

            return [
                'id' => $r->id, 'status' => $r->status, 'registered_at' => $r->registered_at?->toIso8601String(),
                'student' => (new StudentSummaryResource($r->student))->toArray($request),
                'class' => $classes[$r->student_id] ?? null,
                'fee_invoice' => $money ? self::invoice($r->feeInvoice) : null,
                'book_invoice' => $money ? self::invoice($r->bookInvoice) : null,
                'money' => $money ? ['total_fils' => $total, 'paid_fils' => $paid, 'remaining_fils' => max(0, $total - $paid), 'status' => match (true) {
                    $invoices->isEmpty() => 'none', $paid >= $total => 'paid', $paid > 0 => 'partial', default => 'unpaid',
                }, 'overdue' => $invoices->contains(fn (Invoice $i) => $i->outstandingFils() > 0 && $i->due_date?->lt(today()))] : null,
                'book' => $d ? ['id' => $d->id, 'delivered_at' => $d->delivered_at?->toDateString(), 'delivered_by' => $d->deliverer?->name, 'notes' => $d->notes] : null,
                'attendance' => self::tally($attendance->get($r->student_id, collect())),
                'evaluation' => $e ? ['score' => $e->score, 'grade' => $e->grade, 'notes' => $e->notes, 'evaluated_by' => $e->evaluator?->name] : null,
            ];
        })->sortBy(fn ($r) => [$r['status'] === 'registered' ? 0 : ($r['status'] === 'waitlist' ? 1 : 2), $r['student']['full_name']])->values();

        $active = $rows->where('status', 'registered');

        return response()->json([
            'activity' => self::activity(self::withCounts(Activity::query())->find($activity->id)),
            'data' => $rows,
            'totals' => [
                'registered' => $active->count(), 'waitlist' => $rows->where('status', 'waitlist')->count(),
                'seats_left' => $activity->seats === null ? null : max(0, $activity->seats - $active->count()),
                'delivered' => $active->whereNotNull('book')->count(),
                'evaluated' => $active->whereNotNull('evaluation')->count(),
                'money' => $money ? [
                    'total_fils' => $active->sum('money.total_fils'), 'paid_fils' => $active->sum('money.paid_fils'), 'remaining_fils' => $active->sum('money.remaining_fils'),
                    'by_status' => collect(['paid', 'partial', 'unpaid', 'none'])->mapWithKeys(fn ($s) => [$s => $active->where('money.status', $s)->count()]),
                ] : null,
            ],
            // The existing fee reminder (dashboard fees card): the guardian gets the student's outstanding invoices.
            'can_remind' => $user->can('dashboard.view') && $user->can('wallets.view') && $panel->canRemind($user),
        ]);
    }

    /** Eligibility of students before registering: picked ones (student_ids) or a whole class (lesson_id). */
    public function candidates(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->can('activities.register'), 403);
        $this->reach($request, $activity);
        $data = $request->validate([
            'lesson_id' => ['required_without:student_ids', 'nullable', 'integer', 'exists:lessons,id'],
            'student_ids' => ['required_without:lesson_id', 'nullable', 'array', 'max:300'],
            'student_ids.*' => ['integer'],
        ]);
        $students = $this->students($request, $data);
        $check = $this->registrar->check($activity, $students, $request->user());

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'student' => (new StudentSummaryResource($s))->toArray($request), ...$check[$s->id],
        ])->values()]);
    }

    /** Register students (waiting list when full); the fee invoice is issued for each registered one. */
    public function store(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->can('activities.register'), 403);
        $this->reach($request, $activity);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:300'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'charge_book' => ['boolean'],
        ]);
        $students = $this->students($request, $data);
        $results = $this->registrar->register($activity, $students, $request->user(), (bool) ($data['charge_book'] ?? false));
        $count = fn (string $o) => collect($results)->where('outcome', $o)->count();

        return response()->json([
            'message' => __('activities.registered', ['registered' => $count('register'), 'waitlist' => $count('waitlist'), 'refused' => $count('refused') + $count('already')]),
            'data' => $students->map(fn (Student $s) => ['student_id' => $s->id, 'full_name' => $s->full_name, ...$results[$s->id]])->values(),
        ], 201);
    }

    public function confirm(Request $request, Activity $activity, ActivityRegistration $registration): JsonResponse
    {
        abort_unless($request->user()->can('activities.register'), 403);
        $this->own($request, $activity, $registration);
        $this->registrar->confirm($activity, $registration, $request->user());

        return response()->json(['message' => __('activities.confirmed')]);
    }

    public function cancel(Request $request, Activity $activity, ActivityRegistration $registration): JsonResponse
    {
        abort_unless($request->user()->can('activities.register'), 403);
        $this->own($request, $activity, $registration);
        $this->registrar->cancel($activity, $registration);

        return response()->json(['message' => __('activities.cancelled')]);
    }

    private function own(Request $request, Activity $activity, ActivityRegistration $registration): void
    {
        $this->reach($request, $activity);
        abort_unless($registration->activity_id === $activity->id, 404);
        abort_unless(Track::allows($request->user(), $registration->student?->gender), 404);
    }

    /** @return Collection<int, Student> */
    private function students(Request $request, array $data): Collection
    {
        $user = $request->user();
        if (! empty($data['lesson_id'])) {
            $ids = LessonStudent::where('lesson_id', $data['lesson_id'])->where('status', LessonStudentStatus::Active->value)->pluck('student_id');

            return Student::whereIn('id', $ids)->tap(fn ($q) => Track::scope($q, $user))->orderBy('full_name')->get();
        }
        $order = array_values(array_unique($data['student_ids'] ?? []));

        return Student::whereIn('id', $order)->get()->sortBy(fn ($s) => array_search($s->id, $order, true))->values();
    }

    /** @return array<int, array{id: int, name: string, level: ?string}> the student's active class in the activity's term */
    private function classesInTerm(Activity $activity, array $ids): array
    {
        return LessonStudent::with('lesson.level')->whereIn('student_id', $ids)->where('status', LessonStudentStatus::Active->value)
            ->whereHas('lesson', fn ($q) => TermScope::via($q, $activity->academic_term_id))->get()->unique('student_id')
            ->mapWithKeys(fn ($r) => [$r->student_id => ['id' => $r->lesson->id, 'name' => $r->lesson->name, 'level' => $r->lesson->level?->name()]])->all();
    }

    /** @param Collection<int, ActivityAttendance> $rows */
    public static function tally(Collection $rows): array
    {
        $c = fn (string $s) => $rows->where('status', $s)->count();
        $present = $c('present');
        $late = $c('late');
        $excused = $c('excused');
        $counted = $rows->count() - $excused;

        return ['present' => $present, 'late' => $late, 'absent' => $c('absent'), 'excused' => $excused, 'recorded' => $rows->count(),
            'rate' => $counted > 0 ? round(($present + $late) * 100 / $counted, 1) : null];
    }
}
