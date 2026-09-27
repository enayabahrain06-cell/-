<?php

namespace App\Support;

use App\Enums\Gender;
use App\Enums\PackageGender;
use App\Enums\Role;
use App\Enums\Track as TrackEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gender-track scoping for staff. Every list query and policy asks this class
 * which gender a user may see, so the rule lives in one place.
 *
 * - Super Admin: always both tracks.
 * - Other staff: users.track (male / female / both).
 * - Mixed early-years groups (PackageGender::Mixed) are visible to both tracks.
 * - Students and guardians are not scoped here; their own policies limit them to their records.
 */
final class Track
{
    /** The single gender this user is limited to, or null when they see both tracks. */
    public static function genderFor(?User $user): ?Gender
    {
        if (! $user || $user->hasRole(Role::SuperAdmin->value)) {
            return null;
        }
        $track = $user->track instanceof TrackEnum ? $user->track : TrackEnum::tryFrom((string) $user->track);

        return match ($track) {
            TrackEnum::Male => Gender::Male,
            TrackEnum::Female => Gender::Female,
            default => null,
        };
    }

    public static function allows(?User $user, \BackedEnum|string|null $gender): bool
    {
        $limit = self::genderFor($user);
        if ($limit === null || $gender === null) {
            return true;
        }
        $value = $gender instanceof \BackedEnum ? $gender->value : $gender;

        // Mixed early-years groups belong to both tracks.
        return $value === $limit->value || $value === PackageGender::Mixed->value;
    }

    /** Filter a query on its own gender column. */
    public static function scope(Builder $query, ?User $user, string $column = 'gender'): Builder
    {
        $limit = self::genderFor($user);

        return $limit ? $query->whereIn($query->qualifyColumn($column), [$limit->value, PackageGender::Mixed->value]) : $query;
    }

    /** Filter a query through a relation that has a gender column (e.g. "student"). */
    public static function scopeVia(Builder $query, ?User $user, string $relation, string $column = 'gender'): Builder
    {
        $limit = self::genderFor($user);

        return $limit ? $query->whereHas($relation, fn (Builder $q) => $q->whereIn($q->qualifyColumn($column), [$limit->value, PackageGender::Mixed->value])) : $query;
    }

    /** Halls: a scoped user sees halls of their gender plus shared halls. */
    public static function scopeLocations(Builder $query, ?User $user): Builder
    {
        $limit = self::genderFor($user);

        return $limit ? $query->whereIn($query->qualifyColumn('gender'), [$limit->value, 'shared']) : $query;
    }

    /** Gender of a staff member for photo visibility and teacher assignment (teacher profile first, then user). */
    public static function staffGender(User $user): ?Gender
    {
        $user->loadMissing('teacher');
        $value = $user->teacher?->gender ?? $user->gender;

        return $value instanceof Gender ? $value : Gender::tryFrom((string) $value);
    }
}
