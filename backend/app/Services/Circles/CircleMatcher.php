<?php

namespace App\Services\Circles;

use App\Enums\Gender;
use App\Enums\LessonStatus;
use App\Enums\LessonStudentStatus;
use App\Models\AgeGroup;
use App\Models\Lesson;
use App\Models\User;
use App\Policies\LessonPolicy;
use App\Services\Registration\PackageSuitability;
use App\Support\GenderRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which circles a student may join: active, same gender track (or the mixed early-years exception),
 * the student's age inside the circle's age range, a free seat, and a teacher of the right gender.
 * Age is computed server-side on the package start date, as for registrations.
 * The best match is the circle of the student's own age group with the most free seats.
 */
class CircleMatcher
{
    /**
     * Every candidate circle with its fit, best first. Rows that do not fit carry a reason
     * (gender, age, full, teacher) so screens can explain why a circle is unavailable.
     *
     * @return Collection<int, array{lesson: Lesson, fits: bool, reason: ?string, age: int, free_seats: int, same_group: bool}>
     */
    public function evaluate(Gender $gender, CarbonInterface $birthDate, ?User $actor = null, ?int $packageId = null, ?int $excludeLessonId = null, ?CarbonInterface $on = null): Collection
    {
        $lessons = Lesson::with(['teacher:id,name,gender', 'location:id,name', 'package:id,name_ar,name_en,min_age,max_age,start_date', 'ageGroup'])
            ->withCount(['lessonStudents as active_count' => fn ($q) => $q->where('status', LessonStudentStatus::Active->value)])
            ->where('status', LessonStatus::Active->value)
            ->when($packageId, fn ($q) => $q->where('package_id', $packageId))
            ->when($excludeLessonId, fn ($q) => $q->whereKeyNot($excludeLessonId))
            ->get()
            ->filter(fn (Lesson $l) => ! $actor || LessonPolicy::ownsOrManages($actor, $l));

        return $lessons->map(fn (Lesson $l) => $this->fit($l, $gender, $birthDate, $on))
            ->sort(fn ($a, $b) => [! $a['fits'], ! $a['same_group'], -$a['free_seats'], $a['lesson']->name]
                <=> [! $b['fits'], ! $b['same_group'], -$b['free_seats'], $b['lesson']->name])
            ->values();
    }

    /** Only the circles the student can join, best first. */
    public function candidates(Gender $gender, CarbonInterface $birthDate, ?User $actor = null, ?int $packageId = null, ?int $excludeLessonId = null, ?CarbonInterface $on = null): Collection
    {
        return $this->evaluate($gender, $birthDate, $actor, $packageId, $excludeLessonId, $on)->where('fits', true)->values();
    }

    /** @return array{lesson: Lesson, fits: bool, reason: ?string, age: int, free_seats: int, same_group: bool} */
    public function fit(Lesson $lesson, Gender $gender, CarbonInterface $birthDate, ?CarbonInterface $on = null): array
    {
        $age = $on ? PackageSuitability::ageOn($birthDate, $on) : self::ageFor($lesson, $birthDate);
        [$min, $max] = self::range($lesson);
        $active = $lesson->active_count ?? $lesson->lessonStudents()->where('status', LessonStudentStatus::Active->value)->count();
        $free = max(0, $lesson->capacity - $active);

        $reason = match (true) {
            ! $lesson->gender?->accepts($gender) => 'gender',
            $age < $min || ($max !== null && $age > $max) => 'age',
            $free <= 0 => 'full',
            $lesson->package && ! GenderRules::teacherMatches($lesson->teacher_id, $lesson->gender) => 'teacher',
            default => null,
        };
        $group = AgeGroup::forAge($age, $gender->value);

        return [
            'lesson' => $lesson,
            'fits' => $reason === null,
            'reason' => $reason,
            'age' => $age,
            'free_seats' => $free,
            'same_group' => $group && $lesson->age_group_id === $group->id,
        ];
    }

    /** Age on the package start date, as for registrations (PackageSuitability); else the circle's start date. */
    public static function ageFor(Lesson $lesson, CarbonInterface $birthDate): int
    {
        return PackageSuitability::ageOn($birthDate, $lesson->package?->start_date ?? $lesson->start_date ?? today());
    }

    /** The circle's age range: its own, else its age group's, else its package's. @return array{0:int, 1:?int} */
    public static function range(Lesson $lesson): array
    {
        $min = $lesson->min_age ?? $lesson->ageGroup?->min_age ?? $lesson->package?->min_age ?? 0;
        $max = $lesson->min_age !== null ? $lesson->max_age : ($lesson->ageGroup ? $lesson->ageGroup->max_age : $lesson->package?->max_age);

        return [(int) $min, $max === null ? null : (int) $max];
    }

    /** API row for circle pickers. */
    public static function present(array $row, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $l = $row['lesson'];
        [$min, $max] = self::range($l);

        return [
            'id' => $l->id,
            'name' => $l->name,
            'package' => $l->package ? ['id' => $l->package->id, 'name' => $l->package->localizedName($locale)] : null,
            'age_group' => $l->ageGroup ? ['id' => $l->ageGroup->id, 'name' => $l->ageGroup->name($locale)] : null,
            'min_age' => $min,
            'max_age' => $max,
            'teacher' => $l->teacher?->name,
            'location' => $l->location?->name,
            'days' => $l->days,
            'start_time' => substr((string) $l->start_time, 0, 5),
            'end_time' => substr((string) $l->end_time, 0, 5),
            'capacity' => $l->capacity,
            'free_seats' => $row['free_seats'],
            'fits' => $row['fits'],
            'reason' => $row['reason'],
            'reason_label' => $row['reason'] ? __('circles.reasons.'.$row['reason'], [], $locale) : null,
            'student_age' => $row['age'],
            'same_group' => $row['same_group'],
        ];
    }
}
