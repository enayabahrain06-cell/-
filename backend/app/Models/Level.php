<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A study level (المستوى): a layer above circles. One level holds several circles; a circle may have none. */
class Level extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer', 'min_age' => 'integer', 'max_age' => 'integer', 'memorization_levels' => 'array'];
    }

    /** Does a student of this age and memorization level fit the level's rules (U6)? Missing rules fit anyone. */
    public function accepts(?int $age, ?string $memorizationLevel): bool
    {
        if ($age !== null && (($this->min_age !== null && $age < $this->min_age) || ($this->max_age !== null && $age > $this->max_age))) {
            return false;
        }

        return empty($this->memorization_levels) || $memorizationLevel === null || in_array($memorizationLevel, $this->memorization_levels, true);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('id');
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }
}
