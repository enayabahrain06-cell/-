<?php

namespace App\Services\Lessons;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\Student;
use App\Support\WeekDays;
use App\Models\Alert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonService
{
    public function __construct(
        private LocationConflictDetector $conflicts,
        private SessionGenerator $sessions,
        private StudentMessenger $messenger,
    ) {}

    /** @return array{lesson: Lesson, conflicts: list<array>} */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $lesson = Lesson::create($this->normalize($data));
            $this->sessions->generateFor($lesson);

            return ['lesson' => $lesson->fresh(), 'conflicts' => $this->syncConflicts($lesson)];
        });
    }

    /** @return array{lesson: Lesson, conflicts: list<array>} */
    public function update(Lesson $lesson, array $data): array
    {
        return DB::transaction(function () use ($lesson, $data) {
            $lesson->fill($this->normalize($data));
            $scheduleChanged = $lesson->isDirty(['days', 'start_time', 'end_time', 'start_date', 'end_date', 'location_id']);
            $lesson->save();

            if ($scheduleChanged) {
                $this->sessions->regenerate($lesson);
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

    /** @param  list<int>  $studentIds */
    public function enroll(Lesson $lesson, array $studentIds): array
    {
        return DB::transaction(function () use ($lesson, $studentIds) {
            $active = $lesson->lessonStudents()->where('status', LessonStudentStatus::Active->value)->pluck('student_id')->all();
            $new = array_values(array_diff(array_unique($studentIds), $active));

            if (count($active) + count($new) > $lesson->capacity) {
                throw ValidationException::withMessages(['student_ids' => __('lessons.capacity_exceeded', ['capacity' => $lesson->capacity])]);
            }

            foreach ($new as $id) {
                LessonStudent::updateOrCreate(
                    ['lesson_id' => $lesson->id, 'student_id' => $id],
                    ['status' => LessonStudentStatus::Active, 'joined_at' => today(), 'left_at' => null]
                );
            }

            return $new;
        });
    }

    public function unenroll(Lesson $lesson, Student $student): void
    {
        LessonStudent::where('lesson_id', $lesson->id)->where('student_id', $student->id)
            ->update(['status' => LessonStudentStatus::Left->value, 'left_at' => today()]);
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
                $sessions = $this->sessions->emptyFutureSessions($lesson);
                foreach ($sessions as $s) {
                    $s->update(['location_id' => $location->id]);
                }
                // Overrides in the future pointing at the OLD hall are now moot.
                $dates = $sessions->map(fn ($s) => $s->session_date->toDateString())->all();
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

        return $data;
    }
}
