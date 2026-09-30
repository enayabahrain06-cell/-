<?php

namespace App\Services\Activities;

use App\Enums\LessonStudentStatus;
use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\ActivityBookDelivery;
use App\Models\ActivityRegistration;
use App\Models\Invoice;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletService;
use App\Support\TermScope;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registration in a program or trip. Eligibility (active student, gender track, age range on the first day, level
 * of the student's class in the activity's term, open status) and seats (waiting list when full) are decided here
 * only. The fee is an ordinary invoice (WalletService::createInvoice) in the activity's term; cancelling cancels
 * the unpaid invoices and is refused once anything was paid.
 */
class ActivityRegistrar
{
    public function __construct(private WalletService $wallets, private AuditLogger $audit) {}

    /**
     * Outcome for each student, in order, as if they were registered one after another:
     * register | waitlist | already | refused (with a reason key under activities.reasons).
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, array{outcome: string, reason: ?string}>
     */
    public function check(Activity $activity, Collection $students, User $user): array
    {
        $existing = ActivityRegistration::where('activity_id', $activity->id)->get()->keyBy('student_id');
        $taken = $existing->where('status', 'registered')->count();
        $levels = $this->levelsInTerm($activity, $students->pluck('id')->all());
        $out = [];
        foreach ($students as $s) {
            $reason = $this->refusal($activity, $s, $user, $levels);
            $prev = $existing->get($s->id);
            if ($reason === null && $prev && $prev->status !== 'cancelled') {
                $out[$s->id] = ['outcome' => 'already', 'reason' => $prev->status === 'waitlist' ? 'on_waitlist' : 'already_registered'];

                continue;
            }
            if ($reason !== null) {
                $out[$s->id] = ['outcome' => 'refused', 'reason' => $reason];

                continue;
            }
            if ($activity->seats !== null && $taken >= $activity->seats) {
                $out[$s->id] = ['outcome' => 'waitlist', 'reason' => 'full'];

                continue;
            }
            $taken++;
            $out[$s->id] = ['outcome' => 'register', 'reason' => null];
        }

        return $out;
    }

    /**
     * Register the eligible students (waiting list when full). $chargeBook bills the book now instead of at delivery.
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, array{outcome: string, reason: ?string}>
     */
    public function register(Activity $activity, Collection $students, User $user, bool $chargeBook = false): array
    {
        return DB::transaction(function () use ($activity, $students, $user, $chargeBook) {
            // Serialise registrations of the same activity so two desks cannot both take the last seat.
            Activity::whereKey($activity->id)->lockForUpdate()->first();
            $results = $this->check($activity, $students, $user);
            foreach ($students as $s) {
                $r = $results[$s->id];
                if (! in_array($r['outcome'], ['register', 'waitlist'], true)) {
                    continue;
                }
                $reg = ActivityRegistration::firstOrNew(['activity_id' => $activity->id, 'student_id' => $s->id]);
                $reg->fill(['status' => $r['outcome'] === 'register' ? 'registered' : 'waitlist', 'registered_by' => $user->id, 'registered_at' => now(),
                    'fee_invoice_id' => null, 'book_invoice_id' => null]);
                $reg->save();
                if ($reg->status === 'registered') {
                    $this->bill($activity, $reg, $s, $user, $chargeBook);
                }
                $this->audit->record('activity.registered', $reg, [], $reg->only(['activity_id', 'student_id', 'status', 'fee_invoice_id', 'book_invoice_id']));
            }

            return $results;
        });
    }

    /** Move a waiting-list student into a free seat; the fee is billed then. */
    public function confirm(Activity $activity, ActivityRegistration $reg, User $user): ActivityRegistration
    {
        return DB::transaction(function () use ($activity, $reg, $user) {
            Activity::whereKey($activity->id)->lockForUpdate()->first();
            if ($reg->status !== 'waitlist') {
                throw ValidationException::withMessages(['registration' => __('activities.errors.not_waitlist')]);
            }
            $taken = ActivityRegistration::where('activity_id', $activity->id)->where('status', 'registered')->count();
            if ($activity->seats !== null && $taken >= $activity->seats) {
                throw ValidationException::withMessages(['registration' => __('activities.reasons.full')]);
            }
            $reg->update(['status' => 'registered', 'registered_by' => $user->id, 'registered_at' => now()]);
            $this->bill($activity, $reg, $reg->student, $user, false);
            $this->audit->record('activity.confirmed', $reg, ['status' => 'waitlist'], $reg->only(['status', 'fee_invoice_id']));

            return $reg;
        });
    }

    /** Cancel a registration: refused once anything of its invoices was paid or its book was handed over. */
    public function cancel(Activity $activity, ActivityRegistration $reg): ActivityRegistration
    {
        if ($reg->status === 'cancelled') {
            return $reg;
        }
        $invoices = Invoice::whereIn('id', array_filter([$reg->fee_invoice_id, $reg->book_invoice_id]))->get();
        if ($invoices->contains(fn (Invoice $i) => $i->paid_fils > 0)) {
            throw ValidationException::withMessages(['registration' => __('activities.errors.cancel_paid')]);
        }
        if (ActivityBookDelivery::where('activity_id', $activity->id)->where('student_id', $reg->student_id)->exists()) {
            throw ValidationException::withMessages(['registration' => __('activities.errors.cancel_book')]);
        }

        return DB::transaction(function () use ($activity, $reg, $invoices) {
            $note = __('activities.cancel_note', ['name' => $activity->name_ar], 'ar');
            foreach ($invoices as $i) {
                $this->wallets->cancelInvoice($i, $note);
            }
            $old = $reg->only(['status', 'fee_invoice_id', 'book_invoice_id']);
            $reg->update(['status' => 'cancelled']);
            $this->audit->record('activity.cancelled', $reg, $old, ['status' => 'cancelled']);

            return $reg;
        });
    }

    /** Bill the book price for a registration that has no book invoice yet (at delivery, or at registration). */
    public function billBook(Activity $activity, ActivityRegistration $reg, Student $student, User $user, ?Carbon $on = null): ?Invoice
    {
        if (! $activity->has_book || $activity->book_price_fils <= 0 || $reg->book_invoice_id) {
            return null;
        }
        $invoice = $this->wallets->createInvoice($student, null, $activity->book_price_fils, $on ?? $this->dueDate($activity),
            __('activities.book_invoice_description', ['name' => $activity->name_ar, 'title' => $activity->book_title ?: $activity->name_ar], 'ar'),
            null, $user->id, $activity->academic_term_id);
        $reg->update(['book_invoice_id' => $invoice->id]);

        return $invoice;
    }

    private function bill(Activity $activity, ActivityRegistration $reg, Student $student, User $user, bool $chargeBook): void
    {
        if ($activity->price_fils > 0) {
            $invoice = $this->wallets->createInvoice($student, null, $activity->price_fils, $this->dueDate($activity),
                __('activities.fee_invoice_description.'.$activity->type, ['name' => $activity->name_ar], 'ar'), null, $user->id, $activity->academic_term_id);
            $reg->update(['fee_invoice_id' => $invoice->id]);
        }
        if ($chargeBook) {
            $this->billBook($activity, $reg, $student, $user);
        }
    }

    private function dueDate(Activity $activity): Carbon
    {
        return $activity->starts_on->isPast() ? today() : $activity->starts_on->copy();
    }

    /** @param array<int, ?int> $levels student id => level id of their active class in the activity's term */
    private function refusal(Activity $activity, Student $s, User $user, array $levels): ?string
    {
        if ($activity->status !== 'open') {
            return 'not_open';
        }
        if ($s->status !== StudentStatus::Active) {
            return 'inactive';
        }
        if (! Track::allows($user, $s->gender)) {
            return 'track';
        }
        $gender = $s->gender?->value;
        if (in_array($activity->gender, ['male', 'female'], true) && $gender !== $activity->gender) {
            return 'gender';
        }
        if ($activity->min_age !== null || $activity->max_age !== null) {
            if (! $s->birth_date) {
                return 'no_birth_date';
            }
            $age = (int) floor($s->birth_date->diffInYears($activity->starts_on));
            if (($activity->min_age !== null && $age < $activity->min_age) || ($activity->max_age !== null && $age > $activity->max_age)) {
                return 'age';
            }
        }
        if ($activity->level_id && ($levels[$s->id] ?? null) !== $activity->level_id) {
            return 'level';
        }

        return null;
    }

    /** @return array<int, ?int> */
    private function levelsInTerm(Activity $activity, array $studentIds): array
    {
        if (! $activity->level_id || $studentIds === []) {
            return [];
        }

        return LessonStudent::with('lesson:id,level_id')->whereIn('student_id', $studentIds)
            ->where('status', LessonStudentStatus::Active->value)
            ->whereHas('lesson', fn ($q) => TermScope::via($q, $activity->academic_term_id))
            ->get()->sortBy(fn ($r) => $r->lesson?->level_id === $activity->level_id ? 0 : 1)
            ->unique('student_id')->mapWithKeys(fn ($r) => [$r->student_id => $r->lesson?->level_id])->all();
    }
}
