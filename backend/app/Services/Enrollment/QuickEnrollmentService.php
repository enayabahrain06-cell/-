<?php

namespace App\Services\Enrollment;

use App\Enums\Gender;
use App\Enums\LessonStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Enums\PackageStatus;
use App\Enums\PaymentMethod;
use App\Enums\RegistrationStatus;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Payment;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Policies\LessonPolicy;
use App\Services\AuditLogger;
use App\Services\Circles\CircleEnrollmentService;
use App\Services\Circles\CircleMatcher;
use App\Services\Media\StudentPhotoService;
use App\Services\Registration\AcceptRegistrationAction;
use App\Services\Registration\PackageSuitability;
use App\Services\Registration\RegistrationService;
use App\Services\Wallet\WalletService;
use App\Support\GenderRules;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff quick enrollment (spec section 15): one call creates the student, links or creates the guardian,
 * opens the wallet and package invoice, places the student in a circle and optionally records a cash payment,
 * all in one transaction. Messages, photo and audit follow only after the commit.
 *
 * Scope: supervisors see their own track (mixed early-years groups belong to both); teachers without
 * lessons.manage see only their own circles and cannot record payments.
 */
class QuickEnrollmentService
{
    public const SOURCE = 'staff';

    public function __construct(
        private AcceptRegistrationAction $accept,
        private RegistrationService $registrations,
        private WalletService $wallets,
        private StudentPhotoService $photos,
        private AuditLogger $audit,
        private CircleMatcher $matcher,
        private CircleEnrollmentService $circles,
    ) {}

    /** Open packages this actor may enrol into, suitable for the age and gender, each with its eligible circles. */
    public function options(User $actor, ?Carbon $birthDate, ?Gender $gender, string $locale = 'ar'): Collection
    {
        $packages = Track::scope(Package::query(), $actor)
            ->where('status', PackageStatus::Open->value)
            ->orderBy('start_date')->orderBy('id')
            ->get();

        return $packages->map(function (Package $package) use ($actor, $birthDate, $gender, $locale) {
            $check = $birthDate && $gender ? PackageSuitability::check($package, $birthDate, $gender) : null;
            if ($check && ! $check['suitable']) {
                return null;
            }
            $circles = $this->eligibleCircles($actor, $package, $birthDate, $gender);
            // A teacher only sees packages that hold one of their circles.
            if ($circles->isEmpty() && ! $this->managesLessons($actor)) {
                return null;
            }

            return [
                'id' => $package->id,
                'name' => $package->localizedName($locale),
                'gender' => $package->gender->value,
                'min_age' => $package->min_age,
                'max_age' => $package->max_age,
                'price_fils' => $package->price_fils,
                'start_date' => $package->start_date->toDateString(),
                'age_at_start' => $check['age_at_start'] ?? null,
                'seats_left' => max(0, $package->seats - $package->acceptedCount()),
                'is_full' => $package->isFull(),
                'circles' => $circles->values()->all(),
            ];
        })->filter()->values();
    }

    /**
     * Active circles of the package visible to the actor, with a free seat and a teacher of the right gender.
     * Given the student's birth date and gender, only circles of their gender track and age range are
     * listed, best match first (own age group, then most free seats), the first one flagged recommended.
     */
    public function eligibleCircles(User $actor, Package $package, ?Carbon $birthDate = null, ?Gender $gender = null): Collection
    {
        if ($birthDate && $gender) {
            return $this->matcher->candidates($gender, $birthDate, $actor, $package->id)
                ->map(fn (array $row, int $i) => $this->circleRow($row['lesson'], $row['free_seats']) + [
                    'age_group' => $row['lesson']->ageGroup?->name(),
                    'recommended' => $i === 0,
                ])->values();
        }

        return Lesson::with(['teacher:id,name', 'location:id,name', 'ageGroup'])
            ->withCount(['lessonStudents as active_count' => fn ($q) => $q->where('status', LessonStudentStatus::Active->value)])
            ->where('package_id', $package->id)
            ->where('status', LessonStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->filter(fn (Lesson $l) => LessonPolicy::ownsOrManages($actor, $l)
                && $l->active_count < $l->capacity
                && GenderRules::teacherMatches($l->teacher_id, $package->gender))
            ->map(fn (Lesson $l) => $this->circleRow($l, $l->capacity - $l->active_count) + ['age_group' => $l->ageGroup?->name(), 'recommended' => false])
            ->values();
    }

    private function circleRow(Lesson $l, int $free): array
    {
        [$min, $max] = CircleMatcher::range($l);

        return [
            'id' => $l->id,
            'name' => $l->name,
            'teacher' => $l->teacher?->name,
            'location' => $l->location?->name,
            'days' => $l->days,
            'start_time' => substr((string) $l->start_time, 0, 5),
            'end_time' => substr((string) $l->end_time, 0, 5),
            'free_seats' => $free,
            'min_age' => $min,
            'max_age' => $max,
        ];
    }

    /**
     * Guardian (by phone) with the children the actor may see, plus duplicate warnings:
     * the same name and birth date anywhere, or the same name under this guardian.
     *
     * @return array{guardian: ?array, duplicates: list<array>}
     */
    public function lookup(User $actor, ?string $guardianPhone, ?string $fullName, ?string $birthDate): array
    {
        $guardian = $guardianPhone ? User::where('phone', $guardianPhone)->first() : null;
        $children = $guardian ? Student::where('guardian_user_id', $guardian->id)->orderBy('birth_date')->get() : collect();
        $visible = $children->filter(fn (Student $s) => Track::allows($actor, $s->gender));

        return [
            'guardian' => $guardian ? [
                'id' => $guardian->id,
                'name' => $guardian->name,
                'phone' => $guardian->phone,
                'children' => $visible->map(fn (Student $s) => $this->studentRow($s))->values()->all(),
                'hidden_children' => $children->count() - $visible->count(),
            ] : null,
            'duplicates' => $this->duplicates($actor, $guardian, $fullName, $birthDate),
        ];
    }

    /** @return list<array{reason: string, student_no: ?string, full_name: ?string, birth_date: ?string}> */
    public function duplicates(User $actor, ?User $guardian, ?string $fullName, ?string $birthDate): array
    {
        $name = self::normalizeName($fullName);
        if ($name === '') {
            return [];
        }
        $out = [];
        $seen = [];

        if ($birthDate) {
            foreach (Student::whereDate('birth_date', $birthDate)->get() as $s) {
                if (self::normalizeName($s->full_name) === $name) {
                    $out[] = $this->duplicateRow($actor, $s, 'same_name_birth_date');
                    $seen[$s->id] = true;
                }
            }
        }
        if ($guardian) {
            foreach (Student::where('guardian_user_id', $guardian->id)->get() as $s) {
                if (! isset($seen[$s->id]) && self::normalizeName($s->full_name) === $name) {
                    $out[] = $this->duplicateRow($actor, $s, 'same_name_guardian');
                }
            }
        }

        return $out;
    }

    /**
     * Business-rule errors for one enrollment (the Form Request checks field formats first).
     * Shared by the single form and every row of a bulk import.
     *
     * @return array<string, string> field => message
     */
    public function errors(User $actor, array $data, bool $hasPhoto = false): array
    {
        $errors = [];
        $package = Package::find($data['package_id'] ?? null);
        if (! $package) {
            return ['package_id' => __('enrollment.errors.package_required')];
        }
        if (! Track::allows($actor, $package->gender)) {
            return ['package_id' => __('gender.outside_track')];
        }

        $check = PackageSuitability::check($package, Carbon::parse($data['birth_date']), Gender::from($data['gender']));
        if (! $check['suitable']) {
            return ['package_id' => __('registration.errors.'.$check['reason'], ['age' => $check['age_at_start'], 'min' => $package->min_age, 'max' => $package->max_age])];
        }

        $waitlist = ! empty($data['waitlist']);
        if ($package->isFull() && ! $waitlist) {
            $errors['package_id'] = __('enrollment.errors.package_full');
        } elseif (! $waitlist) {
            $lesson = Lesson::find($data['lesson_id'] ?? null);
            if (! $lesson || $lesson->package_id !== $package->id) {
                $errors['lesson_id'] = __('enrollment.errors.lesson_required');
            } elseif (! LessonPolicy::ownsOrManages($actor, $lesson)) {
                $errors['lesson_id'] = __('enrollment.errors.not_your_circle');
            } elseif (! $this->eligibleCircles($actor, $package, Carbon::parse($data['birth_date']), Gender::from($data['gender']))->contains('id', $lesson->id)) {
                $errors['lesson_id'] = __('enrollment.errors.lesson_unavailable');
            }
            if (! $hasPhoto && (bool) setting('registration.photo_required', false)) {
                $errors['photo'] = __('registration.errors.photo_required');
            }
        }

        if (! empty($data['record_payment'])) {
            if ($waitlist) {
                $errors['record_payment'] = __('enrollment.errors.no_payment_on_waitlist');
            } elseif (! $actor->can('payments.record')) {
                $errors['record_payment'] = __('enrollment.errors.cannot_record_payment');
            } elseif ((int) ($data['payment_amount_fils'] ?? 0) <= 0) {
                $errors['payment_amount_fils'] = __('wallet.errors.amount_positive');
            }
        }

        if (empty($data['confirm_duplicate'])) {
            $guardian = User::where('phone', $data['guardian_phone'])->first();
            $dups = $this->duplicates($actor, $guardian, $data['full_name'], $data['birth_date']);
            if ($dups) {
                $errors['duplicate'] = __('enrollment.errors.duplicate', ['names' => collect($dups)->pluck('full_name')->filter()->unique()->join('، ') ?: '—']);
            }
        }

        return $errors;
    }

    /**
     * Save and enroll. Validated data only (run errors() first).
     *
     * @return array{status: 'enrolled'|'waitlist', request: RegistrationRequest, student: ?Student, payment: ?Payment}
     */
    public function enroll(User $actor, array $data, ?UploadedFile $photo = null): array
    {
        $package = Package::findOrFail($data['package_id']);
        $check = PackageSuitability::check($package, Carbon::parse($data['birth_date']), Gender::from($data['gender']));
        $attributes = [
            'package_id' => $package->id,
            'full_name' => trim($data['full_name']),
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'],
            'student_phone' => $data['student_phone'] ?? null,
            'guardian_name' => trim($data['guardian_name']),
            'guardian_phone' => $data['guardian_phone'],
            'memorization_level' => $data['memorization_level'],
            'locale' => $data['locale'] ?? 'ar',
            'age_at_start' => $check['age_at_start'],
            'source' => self::SOURCE,
            'notes' => $data['notes'] ?? null,
        ];

        if (! empty($data['waitlist'])) {
            return $this->waitlist($actor, $package, $attributes, $photo);
        }

        [$request, $student, $payment] = DB::transaction(function () use ($actor, $package, $attributes, $data) {
            $request = RegistrationRequest::create($attributes + [
                'request_no' => RegistrationRequest::nextRequestNo(),
                'status' => RegistrationStatus::Pending,
            ]);
            $student = $this->accept->createStudent($request, $actor->id);

            // Re-checks gender, age range and the seat under a row lock: two staff may be filling the same circle.
            $this->circles->join(Lesson::findOrFail($data['lesson_id']), $student, $actor);
            $request->update(['lesson_id' => (int) $data['lesson_id']]);

            $payment = null;
            if (! empty($data['record_payment'])) {
                $payment = $this->wallets->recordPayment($student, (int) $data['payment_amount_fils'], PaymentMethod::Cash, [
                    'received_by' => $actor->id,
                    'note' => __('enrollment.payment_note', [], $attributes['locale']),
                    'notify' => false, // the receipt goes out after the commit
                ]);
            }

            return [$request, $student, $payment];
        });

        if ($photo) {
            try {
                $this->photos->setPhoto($student, $photo);
            } catch (\Throwable $e) {
                report($e); // the enrollment stands; the photo can be added from the profile
            }
        }

        $this->audit->record('enrollment.quick', $student, [], [
            'request_no' => $request->request_no, 'package_id' => $package->id, 'lesson_id' => (int) $data['lesson_id'],
            'payment_id' => $payment?->id, 'source' => self::SOURCE,
        ], $actor->id);

        $this->accept->notifyAccepted($request);
        if ($payment) {
            $this->wallets->sendReceipt($payment);
        }

        return ['status' => 'enrolled', 'request' => $request->fresh(), 'student' => $student->fresh(), 'payment' => $payment];
    }

    private function waitlist(User $actor, Package $package, array $attributes, ?UploadedFile $photo): array
    {
        $request = DB::transaction(fn () => RegistrationRequest::create($attributes + [
            'request_no' => RegistrationRequest::nextRequestNo(),
            'status' => RegistrationStatus::Waitlist,
            'waitlist_position' => $this->registrations->nextWaitlistPosition($package),
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]));

        if ($photo) {
            try {
                $this->photos->setRequestPhoto($request, $photo);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->audit->record('enrollment.waitlist', $request, [], ['request_no' => $request->request_no, 'package_id' => $package->id, 'source' => self::SOURCE], $actor->id);
        $this->registrations->notify($request, MessageType::RegistrationWaitlist, [
            'request_no' => $request->request_no,
            'package' => $package->localizedName($request->locale->value),
        ]);

        return ['status' => 'waitlist', 'request' => $request->fresh(), 'student' => null, 'payment' => null];
    }

    public function managesLessons(User $actor): bool
    {
        return $actor->can('lessons.manage');
    }

    /** Arabic-aware comparison: trim, collapse spaces, unify alef forms, ta marbuta and final ya. */
    public static function normalizeName(?string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $name); // harakat and tatweel

        return mb_strtolower(strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']));
    }

    private function studentRow(Student $s): array
    {
        return [
            'id' => $s->id,
            'student_no' => $s->student_no,
            'full_name' => $s->full_name,
            'gender' => $s->gender?->value,
            'birth_date' => $s->birth_date?->toDateString(),
            'status' => $s->status?->value,
        ];
    }

    private function duplicateRow(User $actor, Student $s, string $reason): array
    {
        // Other-track students are reported without their details.
        return Track::allows($actor, $s->gender)
            ? ['reason' => $reason] + $this->studentRow($s)
            : ['reason' => $reason, 'student_no' => null, 'full_name' => null, 'birth_date' => null];
    }
}
