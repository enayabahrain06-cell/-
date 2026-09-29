<?php

namespace App\Services\Registration;

use App\Enums\LotteryStatus;
use App\Enums\MemorizationLevel;
use App\Enums\MessageType;
use App\Enums\RegistrationStatus;
use App\Enums\StudentStatus;
use App\Models\Lesson;
use App\Models\Lottery;
use App\Models\LotteryStudent;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Circles\CircleEnrollmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accepting a request: guardian user (find-or-create by phone) → optional student user →
 * student row → circle (lesson_students) or lottery pool → photo transfer → wallet → package invoice →
 * WhatsApp with the login link.
 */
class AcceptRegistrationAction
{
    public function __construct(
        private WalletService $wallets,
        private RegistrationService $registrations,
        private AuditLogger $audit,
        private CircleEnrollmentService $circles,
    ) {}

    /**
     * Enroll the request's student straight into a circle ($lessonId, which must match their gender and
     * age group and have a seat), or, when the supervisor explicitly takes the lottery path, hold a
     * package seat as pending_lottery with no circle yet.
     */
    public function execute(RegistrationRequest $request, ?int $by = null, bool $force = false, ?int $lessonId = null, bool $lottery = false, ?MemorizationLevel $finalLevel = null): Student
    {
        if ($request->status->isDecided()) {
            throw ValidationException::withMessages(['status' => __('registration.errors.already_accepted')]);
        }

        $package = $request->package;

        // The same child (by CPR) cannot be enrolled twice.
        if ($holder = \App\Support\Cpr::holder($request->cpr)) {
            throw ValidationException::withMessages(['cpr' => \App\Support\Cpr::takenMessage($holder)]);
        }

        if ((bool) setting('registration.photo_required', false) && ! $request->media()->where('collection', 'photo')->exists()) {
            throw ValidationException::withMessages(['photo' => __('registration.errors.photo_required')]);
        }

        if (! $force && $package->isFull()) {
            throw ValidationException::withMessages(['package_id' => __('registration.errors.full')]);
        }

        $lesson = null;
        if (! $lottery) {
            $lesson = $lessonId ? Lesson::find($lessonId) : null;
            if (! $lesson || $lesson->package_id !== $package->id) {
                throw ValidationException::withMessages(['lesson_id' => __('circles.errors.no_circle')]);
            }
        }

        $student = DB::transaction(function () use ($request, $by, $lesson, $finalLevel) {
            // The level staff confirmed (from the placement recommendation or their own judgement).
            if ($finalLevel) {
                $request->update(['final_level' => $finalLevel, 'level_confirmed_by' => $by ?? auth()->id(), 'level_confirmed_at' => now()]);
            }
            $student = $this->createStudent($request, $by, $lesson ? RegistrationStatus::Enrolled : RegistrationStatus::PendingLottery);
            if ($lesson) {
                $this->circles->join($lesson, $student, $by ? User::find($by) : null);
                $request->update(['lesson_id' => $lesson->id]);
            }

            return $student;
        });

        $this->transferPhoto($request, $student);

        $this->audit->record('registration.accepted', $request, ['status' => 'pending'], ['status' => $request->status->value, 'student_id' => $student->id, 'lesson_id' => $lesson?->id]);
        $this->registrations->refreshPendingAlert($package);

        $this->notifyAccepted($request);

        return $student;
    }

    /**
     * The transactional part of acceptance (no messages, photo or audit), so callers such as
     * quick enrollment can run it inside a larger transaction. Call it inside DB::transaction.
     */
    public function createStudent(RegistrationRequest $request, ?int $by = null, RegistrationStatus $status = RegistrationStatus::Enrolled): Student
    {
        $package = $request->package;

        $guardian = $this->findOrCreateUser($request->guardian_phone, $request->guardian_name, 'guardian', $request->locale->value);

        $studentUser = null;
        if ($request->student_phone && $request->student_phone !== $request->guardian_phone) {
            $studentUser = $this->findOrCreateUser($request->student_phone, $request->full_name, 'student', $request->locale->value, $request->gender->value);
        }

        $student = Student::create([
            'student_no' => Student::nextStudentNo(),
            'cpr' => $request->cpr,
            'address' => $request->address,
            'user_id' => $studentUser?->id,
            'guardian_user_id' => $guardian->id,
            'full_name' => $request->full_name,
            'birth_date' => $request->birth_date,
            'gender' => $request->gender,
            'student_phone' => $studentUser?->phone,
            'guardian_name' => $request->guardian_name,
            'guardian_phone' => $guardian->phone,
            // A level staff confirmed wins; otherwise the level the family declared. A placement recommendation
            // alone never becomes the student's level.
            'memorization_level' => $request->final_level ?? $request->memorization_level,
            'locale' => $request->locale,
            'status' => StudentStatus::Active,
        ]);

        app(\App\Services\Exams\PlacementService::class)->attachToStudent($request, $student);

        $wasWaitlist = $request->status === RegistrationStatus::Waitlist;
        $request->update([
            'status' => $status,
            'student_id' => $student->id,
            'waitlist_position' => null,
            'decided_by' => $by ?? auth()->id(),
            'decided_at' => now(),
        ]);
        if ($wasWaitlist) {
            $this->registrations->repackWaitlist($package);
        }

        $this->wallets->ensure($student);

        if ($package->price_fils > 0) {
            $this->wallets->createInvoice(
                $student,
                $package,
                $package->price_fils,
                $package->start_date->isFuture() ? $package->start_date : now()->addDays(7),
                __('wallet.invoice.package_fee', ['package' => $package->name], $request->locale->value),
                $package->term,
                $by ?? auth()->id(),
            );
        }

        // The lottery is optional: only students taken down the lottery path join the package's draft lotteries.
        if ($status === RegistrationStatus::PendingLottery) {
            Lottery::where('package_id', $package->id)->where('status', LotteryStatus::Draft->value)->get()
                ->each(fn (Lottery $l) => LotteryStudent::firstOrCreate(['lottery_id' => $l->id, 'student_id' => $student->id]));
        }

        return $student;
    }

    /** Welcome message with the login link, to the guardian and the student phone. */
    public function notifyAccepted(RegistrationRequest $request): void
    {
        $package = $request->package;
        $this->registrations->notify($request->fresh(), MessageType::RegistrationAccepted, [
            'package' => $package->localizedName($request->locale->value),
            'link' => config('ahl.frontend_url').'/login',
            'amount' => Money::format($package->price_fils, $request->locale->value),
        ]);
    }

    /**
     * A student saved by staff before any package is chosen (quick enrollment "save without package"):
     * guardian and student accounts and the wallet as in createStudent(), without a request or invoice.
     */
    public function createUnplacedStudent(array $data, ?int $by = null): Student
    {
        $locale = $data['locale'] ?? 'ar';
        $guardian = $this->findOrCreateUser($data['guardian_phone'], trim($data['guardian_name']), 'guardian', $locale);

        $studentUser = null;
        if (! empty($data['student_phone']) && $data['student_phone'] !== $data['guardian_phone']) {
            $studentUser = $this->findOrCreateUser($data['student_phone'], trim($data['full_name']), 'student', $locale, $data['gender']);
        }

        $student = Student::create([
            'student_no' => Student::nextStudentNo(),
            'cpr' => $data['cpr'] ?? null,
            'address' => $data['address'] ?? null,
            'user_id' => $studentUser?->id,
            'guardian_user_id' => $guardian->id,
            'full_name' => trim($data['full_name']),
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'],
            'student_phone' => $studentUser?->phone,
            'guardian_name' => trim($data['guardian_name']),
            'guardian_phone' => $guardian->phone,
            'memorization_level' => $data['memorization_level'],
            'locale' => $locale,
            'status' => StudentStatus::Active,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->wallets->ensure($student);

        return $student;
    }

    private function findOrCreateUser(string $phone, string $name, string $role, string $locale, ?string $gender = null): User
    {
        $user = User::withTrashed()->where('phone', $phone)->first();

        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            return $user;
        }

        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'password' => null,
            'gender' => $gender,
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function transferPhoto(RegistrationRequest $request, Student $student): void
    {
        $class = \App\Services\Media\StudentPhotoService::class;
        if (class_exists($class) && method_exists($class, 'transferPhoto')) {
            try {
                app($class)->transferPhoto($request, $student);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
