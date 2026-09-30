<?php

namespace App\Services\Lessons;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Enums\StudentStatus;
use App\Models\AgeGroup;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\Package;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\Student;
use App\Models\User;
use App\Policies\LessonPolicy;
use App\Services\AuditLogger;
use App\Services\Circles\CircleEnrollmentService;
use App\Services\Circles\CircleMatcher;
use App\Services\Registration\PackageSuitability;
use App\Support\Track;
use App\Support\WeekDays;
use App\Models\Alert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonService
{
    public function __construct(
        private LocationConflictDetector $conflicts,
        private SessionSync $sessions,
        private ClassSchedule $schedule,
        private StudentMessenger $messenger,
        private AuditLogger $audit,
        private CircleEnrollmentService $circles,
    ) {}

    /** @return array{lesson: Lesson, conflicts: list<array>} */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $lesson = Lesson::create($this->normalize($data));
            // The form's days/times become the class's own periods in الجدول الدراسي (the only schedule source).
            if (! empty($lesson->days)) {
                $this->schedule->setOwnSchedule($lesson, $lesson->days, (string) $lesson->start_time, (string) $lesson->end_time);
            }
            $this->schedule->syncCopy($lesson);
            $this->sessions->apply($lesson);

            return ['lesson' => $lesson->fresh(), 'conflicts' => $this->syncConflicts($lesson)];
        });
    }

    /** @return array{lesson: Lesson, conflicts: list<array>} */
    public function update(Lesson $lesson, array $data): array
    {
        return DB::transaction(function () use ($lesson, $data) {
            $lesson->fill($this->normalize($data));
            $timesChanged = $lesson->isDirty(['days', 'start_time', 'end_time']);
            $levelChanged = $lesson->isDirty('level_id');
            $scheduleChanged = $timesChanged || $levelChanged || $lesson->isDirty(['start_date', 'end_date', 'location_id', 'status', 'package_id']);
            $lesson->save();

            // The class's own periods follow its level; the form's days/times rewrite its simple own schedule.
            if ($levelChanged) {
                \App\Models\TimetableSlot::where('lesson_id', $lesson->id)->update(['level_id' => $lesson->level_id]);
            }
            if ($timesChanged) {
                $this->schedule->setOwnSchedule($lesson, $lesson->days ?? [], (string) $lesson->start_time, (string) $lesson->end_time);
            }
            if ($scheduleChanged) {
                $this->schedule->syncCopy($lesson);
                $this->sessions->apply($lesson->fresh());
            }

            return ['lesson' => $lesson->fresh(), 'conflicts' => $this->syncConflicts($lesson)];
        });
    }

    public function delete(Lesson $lesson): void
    {
        if (LessonSession::where('lesson_id', $lesson->id)->whereHas('attendances')->exists()) {
            throw ValidationException::withMessages(['lesson' => __('lessons.cannot_delete_with_attendance')]);
        }

        $lesson->delete();
    }

    /** Run the detector, raise or resolve the dashboard alert, return the conflicts. */
    public function syncConflicts(Lesson $lesson): array
    {
        $conflicts = $this->conflicts->forLesson($lesson);

        if ($conflicts) {
            Alert::resolveFor(AlertType::LocationConflict, $lesson);
            Alert::raise(
                AlertType::LocationConflict,
                __('lessons.alert_conflict_title', ['lesson' => $lesson->name]),
                collect($conflicts)->map(fn ($c) => "{$c['title']} ({$c['start_time']}–{$c['end_time']}".($c['date'] ? ", {$c['date']}" : '').')')->implode(' | '),
                $lesson,
                AlertSeverity::Danger,
            );
        } else {
            Alert::resolveFor(AlertType::LocationConflict, $lesson);
        }

        return $conflicts;
    }

    /**
     * Add existing students (already validated by EnrollStudentsRequest). Students who are already
     * active here are skipped. With $move, a student's other active circles are ended (left_at today)
     * in the same transaction; without it the request refuses such students, so nobody is
     * double-enrolled silently. The seat count is re-read under a row lock: two staff may fill the same circle.
     *
     * @param  list<int>  $studentIds
     * @return array{added: list<int>, moved: list<array{student_id: int, from_lesson_id: int}>}
     */
    public function enroll(Lesson $lesson, array $studentIds, ?int $actorId = null, bool $move = false): array
    {
        $result = DB::transaction(function () use ($lesson, $studentIds, $move, $actorId) {
            $capacity = Lesson::whereKey($lesson->id)->lockForUpdate()->value('capacity');
            $active = $lesson->lessonStudents()->where('status', LessonStudentStatus::Active->value)->pluck('student_id')->all();
            $new = array_values(array_diff(array_unique(array_map('intval', $studentIds)), $active));

            if (count($active) + count($new) > $capacity) {
                throw ValidationException::withMessages(['student_ids' => __('lessons.capacity_exceeded', ['capacity' => $capacity])]);
            }

            $moved = LessonStudent::whereIn('student_id', $new)->where('lesson_id', '!=', $lesson->id)
                ->where('status', LessonStudentStatus::Active->value)->get()
                ->map(fn ($row) => ['student_id' => $row->student_id, 'from_lesson_id' => $row->lesson_id])->values()->all();

            // One new lesson_students row per stay; a move closes the old row with its history.
            $actor = $actorId ? User::find($actorId) : null;
            foreach (Student::whereIn('id', $new)->get() as $student) {
                $this->circles->join($lesson, $student, $actor, $move);
            }

            return ['added' => $new, 'moved' => $moved];
        });

        if ($result['added']) {
            $this->audit->record('lesson.students_added', $lesson, [], ['student_ids' => $result['added'], 'moved' => $result['moved']], $actorId);
        }

        return $result;
    }

    /**
     * Why a student cannot join this circle, or null when they can. Being active in another
     * circle is not a reason: it turns the action into a move (see otherCircles()).
     * The same rules drive the add-student search (as badges) and the save (as errors).
     *
     * @return 'outside_track'|'inactive'|'gender'|'age'|'already_in'|null
     */
    public function ineligibility(Lesson $lesson, Student $student, User $actor): ?string
    {
        if (! Track::allows($actor, $student->gender)) {
            return 'outside_track';
        }
        if ($student->status !== StudentStatus::Active) {
            return 'inactive';
        }
        if ($lesson->gender && $student->gender && ! $lesson->gender->accepts($student->gender)) {
            return 'gender';
        }
        if ($student->birth_date) {
            // The circle's own age range, age as of the package start date (CircleMatcher).
            $age = CircleMatcher::ageFor($lesson, $student->birth_date);
            [$min, $max] = CircleMatcher::range($lesson);
            if ($age < $min || ($max !== null && $age > $max)) {
                return 'age';
            }
        }
        if (LessonStudent::where('lesson_id', $lesson->id)->where('student_id', $student->id)->where('status', LessonStudentStatus::Active->value)->exists()) {
            return 'already_in';
        }

        return null;
    }

    /**
     * The student's other active circles, each flagged with whether this actor may move the
     * student out of it (the same reach as viewing it: own circles, or managers within their track).
     *
     * @return list<array{id: int, name: string, teacher: ?string, movable: bool}>
     */
    public function otherCircles(Lesson $lesson, Student $student, User $actor): array
    {
        return LessonStudent::with('lesson.teacher:id,name')
            ->where('student_id', $student->id)->where('lesson_id', '!=', $lesson->id)
            ->where('status', LessonStudentStatus::Active->value)
            ->get()
            ->filter(fn (LessonStudent $ls) => $ls->lesson !== null)
            ->map(fn (LessonStudent $ls) => [
                'id' => $ls->lesson->id,
                'name' => $ls->lesson->name,
                'teacher' => $ls->lesson->teacher?->name,
                'movable' => LessonPolicy::ownsOrManages($actor, $ls->lesson),
            ])->values()->all();
    }

    public function ineligibilityMessage(string $reason, Lesson $lesson, Student $student, array $params = []): string
    {
        return match ($reason) {
            'outside_track' => __('gender.outside_track'),
            'gender' => __('gender.student_mismatch'),
            default => __('lessons.add.'.$reason, $params + ['name' => $student->full_name, 'min' => CircleMatcher::range($lesson)[0], 'max' => CircleMatcher::range($lesson)[1] ?? '+']),
        };
    }

    public function unenroll(Lesson $lesson, Student $student, ?User $by = null, ?string $reason = null): void
    {
        $this->circles->leave($lesson, $student, $by, $reason);
    }

    /**
     * Change the hall for one day (override) or for all upcoming sessions.
     *
     * @return array{notified: int}
     */
    public function changeLocation(Lesson $lesson, string $mode, int $locationId, ?string $date, bool $notify, ?string $reason = null): array
    {
        $location = Location::findOrFail($locationId);

        if ($mode === 'one_day') {
            $day = Carbon::parse($date)->startOfDay();
            $conflicts = $this->conflicts->forDate($locationId, $day, $lesson->start_time, $lesson->end_time, $lesson->id);
        } else {
            $conflicts = $this->conflicts->forRecurring($locationId, $lesson->days ?? [], today(), $lesson->end_date, $lesson->start_time, $lesson->end_time, $lesson->id);
        }

        if ($conflicts) {
            throw ValidationException::withMessages(['location_id' => __('lessons.target_hall_busy')]);
        }

        $override = null;
        $dates = [];

        DB::transaction(function () use ($lesson, $mode, $location, $date, $reason, &$override, &$dates) {
            if ($mode === 'one_day') {
                $override = LessonLocationOverride::updateOrCreate(
                    ['lesson_id' => $lesson->id, 'override_date' => $date],
                    ['location_id' => $location->id, 'reason' => $reason, 'created_by' => auth()->id()]
                );
                $session = LessonSession::where('lesson_id', $lesson->id)->where('session_date', $date)->first();
                if ($session && ! $session->hasAttendance()) {
                    $session->update(['location_id' => $location->id]);
                }
                $dates = [Carbon::parse($date)->toDateString()];
            } else {
                $lesson->update(['location_id' => $location->id]);
                // The sync moves every upcoming session without attendance to the new room (one-day overrides still win).
                $plan = $this->sessions->apply($lesson->fresh());
                $dates = collect($plan['update'])->filter(fn ($u) => array_key_exists('location_id', $u['changes']))->pluck('date')->values()->all();
            }
        });

        $this->syncConflicts($lesson->fresh());

        $notified = 0;
        if ($notify) {
            $dateText = $mode === 'one_day' ? Carbon::parse($date)->toDateString() : __('lessons.all_upcoming');
            foreach ($lesson->activeStudents()->get() as $student) {
                $notified += $this->messenger->notify($student, MessageType::LocationChange, [
                    'lesson' => $lesson->name,
                    'date' => $dateText,
                    'location' => $location->name,
                    'map_link' => $location->map_link ?? '',
                ]);
            }
            $override?->update(['notified_at' => now()]);
        }

        return ['notified' => $notified, 'dates' => $dates];
    }

    private function normalize(array $data): array
    {
        foreach (['start_time', 'end_time'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = WeekDays::time($data[$k]);
            }
        }
        if (isset($data['days'])) {
            $data['days'] = array_values(array_unique($data['days']));
        }
        // A circle picks up its age group's range unless one is given explicitly.
        if (! empty($data['age_group_id']) && ! array_key_exists('min_age', $data) && ($group = AgeGroup::find($data['age_group_id']))) {
            $data['min_age'] = $group->min_age;
            $data['max_age'] = $group->max_age;
        }
        if (! empty($data['package_id']) && empty($data['age_group_id']) && ! array_key_exists('min_age', $data) && ($package = Package::find($data['package_id']))) {
            $data['min_age'] ??= $package->min_age;
            $data['max_age'] ??= $package->max_age;
        }

        return $data;
    }
}
