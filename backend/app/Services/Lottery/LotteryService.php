<?php

namespace App\Services\Lottery;

use App\Enums\LessonStudentStatus;
use App\Enums\LotteryStatus;
use App\Enums\MessageType;
use App\Enums\PackageGender;
use App\Enums\StudentStatus;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Lottery;
use App\Models\LotteryResult;
use App\Models\LotteryStudent;
use App\Models\LotteryTeacher;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Lessons\StudentMessenger;
use App\Support\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lottery (section 3): distribute a package's accepted students over participating teachers' circles.
 * One lottery per package, so one gender track (a mixed early-years package accepts both genders
 * with female teachers). Runs are seeded and repeatable; approval writes lesson_students exactly once.
 */
class LotteryService
{
    public function __construct(
        private LotteryAllocator $allocator,
        private StudentMessenger $messenger,
        private AuditLogger $audit,
    ) {}

    /** @param  array{package_id:int,name:string,balance_ages?:bool,keep_siblings?:bool,balance_levels?:bool,teachers:list<array{teacher_id:int,lesson_id:int,capacity:int}>}  $data */
    public function create(array $data, User $by): Lottery
    {
        return DB::transaction(function () use ($data, $by) {
            $lottery = Lottery::create([
                'package_id' => $data['package_id'],
                'name' => $data['name'],
                'status' => LotteryStatus::Draft,
                'balance_ages' => (bool) ($data['balance_ages'] ?? false),
                'keep_siblings' => (bool) ($data['keep_siblings'] ?? true),
                'balance_levels' => (bool) ($data['balance_levels'] ?? false),
                'created_by' => $by->id,
            ]);
            $this->syncTeachers($lottery, $data['teachers']);
            $this->syncPool($lottery);

            return $lottery;
        });
    }

    public function update(Lottery $lottery, array $data): Lottery
    {
        $this->assertEditable($lottery);

        return DB::transaction(function () use ($lottery, $data) {
            $lottery->fill(array_intersect_key($data, array_flip(['name', 'balance_ages', 'keep_siblings', 'balance_levels'])));
            // Any change sends the lottery back to draft: previous results no longer match the setup.
            $lottery->status = LotteryStatus::Draft;
            $lottery->save();
            if (isset($data['teachers'])) {
                $this->syncTeachers($lottery, $data['teachers']);
            }
            $lottery->results()->delete();

            return $lottery->fresh();
        });
    }

    private function syncTeachers(Lottery $lottery, array $rows): void
    {
        $lottery->teachers()->delete();
        foreach ($rows as $r) {
            LotteryTeacher::create(['lottery_id' => $lottery->id, 'teacher_id' => $r['teacher_id'], 'lesson_id' => $r['lesson_id'], 'capacity' => $r['capacity']]);
        }
    }

    /**
     * Pool = students accepted into the package, active, and not yet in an active circle of that package.
     * Existing pool rows stay (a supervisor may have added students manually).
     */
    public function syncPool(Lottery $lottery): int
    {
        $package = $lottery->package;
        $lessonIds = Lesson::where('package_id', $package->id)->pluck('id');
        $placed = LessonStudent::whereIn('lesson_id', $lessonIds)->where('status', LessonStudentStatus::Active->value)->pluck('student_id');

        $ids = RegistrationRequest::where('package_id', $package->id)->whereIn('status', ['pending_lottery', 'enrolled', 'accepted']) // "pending lottery" requests are the pool; enrolled-but-unplaced students join too
            ->whereNotNull('student_id')->pluck('student_id')
            ->diff($placed)
            ->filter(fn ($id) => Student::whereKey($id)->where('status', StudentStatus::Active->value)->exists());

        foreach ($ids as $id) {
            LotteryStudent::firstOrCreate(['lottery_id' => $lottery->id, 'student_id' => $id]);
        }

        return $lottery->pool()->count();
    }

    /**
     * Run (or re-run) the distribution. A new seed gives a new distribution; the same seed reproduces it.
     *
     * @return array{run_no:int, seed:string, assigned:int, unassigned:list<int>, split_families:list<string>}
     */
    public function run(Lottery $lottery, ?string $seed = null): array
    {
        if (in_array($lottery->status, [LotteryStatus::Approved, LotteryStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['lottery' => __('lottery.errors.locked')]);
        }
        $lottery->loadMissing(['package', 'teachers.lesson', 'pool.student']);
        if ($lottery->teachers->isEmpty()) {
            throw ValidationException::withMessages(['teachers' => __('lottery.errors.no_teachers')]);
        }
        $seed = $seed !== null && $seed !== '' ? $seed : Str::lower(Str::random(10));
        $start = $lottery->package->start_date ?? today();

        $students = $lottery->pool->map(fn (LotteryStudent $p) => $p->student)->filter()->map(fn (Student $s) => [
            'id' => $s->id,
            'age' => $s->birth_date ? $s->birth_date->diffInYears($start) : 0,
            'level' => $s->memorization_level?->value ?? 'none',
            'family' => (string) ($s->guardian_user_id ?: $s->guardian_phone),
        ])->values()->all();

        // Effective capacity: what the teacher offered, but never beyond the circle's free seats.
        $slots = $lottery->teachers->map(function (LotteryTeacher $t) {
            $taken = LessonStudent::where('lesson_id', $t->lesson_id)->where('status', LessonStudentStatus::Active->value)->count();

            return ['key' => $t->id, 'capacity' => max(0, min($t->capacity, ($t->lesson?->capacity ?? $t->capacity) - $taken))];
        })->values()->all();

        $out = $this->allocator->allocate($students, $slots, $seed, $lottery->keep_siblings, $lottery->balance_ages, $lottery->balance_levels);
        $byId = $lottery->teachers->keyBy('id');
        $runNo = $lottery->run_count + 1;

        DB::transaction(function () use ($lottery, $out, $byId, $runNo, $seed) {
            $lottery->results()->delete();
            foreach ($out['assignments'] as $studentId => $slotKey) {
                $slot = $byId[$slotKey];
                LotteryResult::create(['lottery_id' => $lottery->id, 'student_id' => $studentId, 'teacher_id' => $slot->teacher_id, 'lesson_id' => $slot->lesson_id, 'run_no' => $runNo]);
            }
            $lottery->update(['status' => LotteryStatus::Run, 'seed' => $seed, 'run_count' => $runNo, 'run_at' => now()]);
        });

        return ['run_no' => $runNo, 'seed' => $seed, 'assigned' => count($out['assignments']), 'unassigned' => $out['unassigned'], 'split_families' => $out['split_families']];
    }

    /** Move one result to another participating teacher (before approval), within that teacher's capacity. */
    public function move(LotteryResult $result, int $lotteryTeacherId): LotteryResult
    {
        $lottery = $result->lottery;
        $this->assertEditable($lottery);
        $target = $lottery->teachers()->whereKey($lotteryTeacherId)->firstOrFail();
        $used = $lottery->results()->where('lesson_id', $target->lesson_id)->where('id', '!=', $result->id)->count();
        if ($used >= $target->capacity) {
            throw ValidationException::withMessages(['teacher' => __('lottery.errors.full')]);
        }
        $result->update(['teacher_id' => $target->teacher_id, 'lesson_id' => $target->lesson_id]);

        return $result;
    }

    /**
     * Approve: enrol every result in its circle (once), then message each student and guardian with the
     * teacher and the first lesson date. Returns counts.
     *
     * @return array{enrolled:int, skipped:int, notified:int}
     */
    public function approve(Lottery $lottery, User $by, bool $notify = true): array
    {
        if ($lottery->status !== LotteryStatus::Run) {
            throw ValidationException::withMessages(['lottery' => $lottery->status === LotteryStatus::Approved ? __('lottery.errors.already_approved') : __('lottery.errors.not_run')]);
        }
        $lottery->loadMissing(['package', 'results.student', 'results.lesson.teacher', 'results.lesson.location']);

        $enrolled = 0;
        $skipped = 0;
        DB::transaction(function () use ($lottery, $by, &$enrolled, &$skipped) {
            $lessonIds = Lesson::where('package_id', $lottery->package_id)->pluck('id');
            foreach ($lottery->results as $r) {
                // One active circle per package per student (invariant 3): skip anyone already placed meanwhile.
                $already = LessonStudent::whereIn('lesson_id', $lessonIds)->where('student_id', $r->student_id)->where('status', LessonStudentStatus::Active->value)->exists();
                if ($already) {
                    $skipped++;

                    continue;
                }
                LessonStudent::updateOrCreate(
                    ['lesson_id' => $r->lesson_id, 'student_id' => $r->student_id],
                    ['status' => LessonStudentStatus::Active, 'joined_at' => max(today()->toDateString(), $r->lesson?->start_date?->toDateString() ?? today()->toDateString()), 'left_at' => null]
                );
                $enrolled++;
            }
            $lottery->update(['status' => LotteryStatus::Approved, 'approved_at' => now(), 'approved_by' => $by->id]);
        });

        $this->audit->record('lottery.approved', $lottery, ['status' => 'run'], ['status' => 'approved', 'enrolled' => $enrolled, 'seed' => $lottery->seed]);

        $notified = 0;
        if ($notify) {
            foreach ($lottery->results as $r) {
                if (! $r->student || ! $r->lesson) {
                    continue;
                }
                $first = LessonSession::where('lesson_id', $r->lesson_id)->where('session_date', '>=', today()->toDateString())->where('status', '!=', 'cancelled')->orderBy('session_date')->first();
                $locale = $r->student->locale?->value ?? 'ar';
                $notified += $this->messenger->notify($r->student, MessageType::LotteryResult, [
                    'lesson' => $r->lesson->name,
                    'teacher' => $r->lesson->teacher?->name ?? '',
                    'date' => ($first?->session_date ?? $r->lesson->start_date)?->toDateString() ?? '',
                    'time' => substr((string) ($first?->start_time ?? $r->lesson->start_time), 0, 5),
                    'location' => $first?->location?->name ?? $r->lesson->location?->name ?? '',
                ]);
                $r->update(['notified_at' => now()]);
            }
        }

        return ['enrolled' => $enrolled, 'skipped' => $skipped, 'notified' => $notified];
    }

    public function cancel(Lottery $lottery): Lottery
    {
        if ($lottery->status === LotteryStatus::Approved) {
            throw ValidationException::withMessages(['lottery' => __('lottery.errors.already_approved')]);
        }
        $lottery->update(['status' => LotteryStatus::Cancelled]);

        return $lottery;
    }

    private function assertEditable(Lottery $lottery): void
    {
        if (in_array($lottery->status, [LotteryStatus::Approved, LotteryStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['lottery' => __('lottery.errors.locked')]);
        }
    }

    /** Teacher gender that may take part: male for boys, female for girls and for mixed early years. */
    public static function staffGenderFor(Package $package): string
    {
        return $package->gender === PackageGender::Male ? 'male' : 'female';
    }

    /** Summary per participating teacher for the results screen. */
    public function summary(Lottery $lottery, ?string $locale = null): Collection
    {
        $lottery->loadMissing(['teachers.teacher:id,name', 'teachers.lesson:id,name,capacity', 'results.student']);

        return $lottery->teachers->map(function (LotteryTeacher $t) use ($lottery) {
            $rs = $lottery->results->where('lesson_id', $t->lesson_id);

            return [
                'lottery_teacher_id' => $t->id,
                'teacher' => ['id' => $t->teacher_id, 'name' => $t->teacher?->name],
                'lesson' => ['id' => $t->lesson_id, 'name' => $t->lesson?->name],
                'capacity' => $t->capacity,
                'assigned' => $rs->count(),
                'students' => $rs->values(),
            ];
        })->values();
    }

    public static function allowsTeacher(User $teacher, Package $package): bool
    {
        return Track::staffGender($teacher)?->value === self::staffGenderFor($package);
    }
}
