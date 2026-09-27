<?php

namespace App\Support;

use App\Enums\Gender;
use App\Enums\LocationGender;
use App\Enums\PackageGender;
use App\Models\Lesson;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * Server-side gender separation checks used by Form Requests (never only the UI):
 * male teachers teach boys only, female teachers girls only; halls must accept the gender;
 * a scoped supervisor may only create or edit records of their own track.
 */
final class GenderRules
{
    public static function value(\BackedEnum|string|null $gender): ?string
    {
        return $gender instanceof \BackedEnum ? (string) $gender->value : $gender;
    }

    public static function packageGender(?int $packageId): ?string
    {
        return $packageId ? Package::whereKey($packageId)->toBase()->value('gender') : null;
    }

    public static function lessonGender(?int $lessonId): ?string
    {
        return $lessonId ? Lesson::whereKey($lessonId)->toBase()->value('gender') : null;
    }

    public static function teacherMatches(?int $teacherId, \BackedEnum|string|null $gender): bool
    {
        $gender = self::value($gender);
        if (! $teacherId || ! $gender) {
            return true;
        }
        $teacher = User::with('teacher')->find($teacherId);

        // Mixed early-years groups are taught by female teachers.
        $required = PackageGender::tryFrom($gender)?->staffGender()->value ?? $gender;

        return $teacher && Track::staffGender($teacher)?->value === $required;
    }

    public static function locationAccepts(?int $locationId, \BackedEnum|string|null $gender): bool
    {
        $gender = self::value($gender);
        if (! $locationId || ! $gender) {
            return true;
        }
        $hall = Location::find($locationId);

        return ! $hall || ($hall->gender ?? LocationGender::Shared)->accepts($gender);
    }

    /** Add the standard errors for a teacher / hall / track mismatch to a validator. */
    public static function check(Validator $v, ?User $actor, ?string $gender, ?int $teacherId = null, ?int $locationId = null, string $teacherField = 'teacher_id', string $locationField = 'location_id'): void
    {
        if (! $gender) {
            return;
        }
        if (! Track::allows($actor, $gender)) {
            $v->errors()->add('gender', __('gender.outside_track'));
        }
        if ($teacherId && ! self::teacherMatches($teacherId, $gender)) {
            $v->errors()->add($teacherField, __('gender.teacher_mismatch'));
        }
        if ($locationId && ! self::locationAccepts($locationId, $gender)) {
            $v->errors()->add($locationField, __('gender.location_mismatch'));
        }
    }
}
