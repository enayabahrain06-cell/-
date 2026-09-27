<?php

namespace App\Models;

use App\Enums\PackageGender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Editable age band (e.g. 6–8, 9–11, 12–14, 15+) used by circles, quick enrollment and reports.
 * max_age null means "and above"; track null means both tracks.
 */
class AgeGroup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['track' => PackageGender::class, 'is_active' => 'boolean', 'min_age' => 'integer', 'max_age' => 'integer', 'sort' => 'integer'];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort')->orderBy('min_age');
    }

    public function contains(int $age): bool
    {
        return $age >= $this->min_age && ($this->max_age === null || $age <= $this->max_age);
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }

    /** The active group for an age (and gender track, when groups are split by track). */
    public static function forAge(int $age, ?string $track = null): ?self
    {
        return static::active()->get()
            ->filter(fn (self $g) => $g->contains($age) && ($g->track === null || $track === null || $g->track->value === $track || $g->track === PackageGender::Mixed))
            ->sortBy(fn (self $g) => $g->track === null ? 1 : 0) // a track-specific group wins over a shared one
            ->first();
    }
}
