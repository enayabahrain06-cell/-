<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Difficulty categories tracked on the student profile. Tajweed issues carry a sub-aspect (TajweedAspect). */
enum IssueCategory: string
{
    use HasLabel;

    case Tajweed = 'tajweed';
    case WeakMemorization = 'weak_memorization';
    case WeakRevision = 'weak_revision';
    case SlowPace = 'slow_pace';
    case Mutashabihat = 'mutashabihat';
    case ReadingFluency = 'reading_fluency';
    case Concentration = 'concentration';
    case Behavior = 'behavior';
    case Attendance = 'attendance';
    case HomeSupport = 'home_support';
    case HealthOther = 'health_other';

    /** Category suggested when an evaluation criterion scores below the threshold. */
    public static function forCriterion(string $criterion): self
    {
        return match ($criterion) {
            'memorization' => self::WeakMemorization,
            'tajweed' => self::Tajweed,
            'revision' => self::WeakRevision,
            'behavior' => self::Behavior,
        };
    }
}
