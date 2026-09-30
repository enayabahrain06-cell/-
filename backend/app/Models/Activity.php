<?php

namespace App\Models;

use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonPeriod;

/**
 * البرامج and الرحلات: one engine, `type` program | trip. A term-bound activity with optional room (programs) or
 * place (trips), seats, price, gender, age range, level and an optional book. Money lives in invoices only.
 */
class Activity extends Model
{
    public const TYPES = ['program', 'trip'];

    public const STATUSES = ['draft', 'open', 'closed', 'done'];

    public const GENDERS = ['male', 'female', 'mixed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => \App\Casts\DateOnly::class,
            'ends_on' => \App\Casts\DateOnly::class,
            'seats' => 'integer', 'price_fils' => 'integer', 'book_price_fils' => 'integer',
            'min_age' => 'integer', 'max_age' => 'integer', 'has_book' => 'boolean',
            'academic_term_id' => 'integer', 'location_id' => 'integer', 'level_id' => 'integer',
        ];
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    /** The last day of the activity (a one-day activity ends the day it starts). */
    public function lastDay(): \Carbon\Carbon
    {
        return ($this->ends_on ?? $this->starts_on)->copy();
    }

    /** @return list<string> every date of the activity, Y-m-d */
    public function dates(): array
    {
        return collect(CarbonPeriod::create($this->starts_on, $this->lastDay()))->map(fn ($d) => $d->toDateString())->all();
    }

    /** Activities a staff member may see: open to both, mixed, or their own gender track. */
    public function scopeForTrack(Builder $q, ?User $user): Builder
    {
        $limit = Track::genderFor($user);

        return $limit ? $q->where(fn ($w) => $w->whereNull('gender')->orWhereIn('gender', [$limit->value, 'mixed'])) : $q;
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(ActivityAttendance::class);
    }

    public function bookDeliveries(): HasMany
    {
        return $this->hasMany(ActivityBookDelivery::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ActivityEvaluation::class);
    }
}
