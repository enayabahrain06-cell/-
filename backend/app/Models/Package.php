<?php

namespace App\Models;

use App\Enums\PackageGender;
use App\Enums\PackageStatus;
use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Mirrors the column default so a package created without a status serialises correctly. */
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'gender' => PackageGender::class,
            'status' => PackageStatus::class,
            'days' => 'array',
            'start_date' => \App\Casts\DateOnly::class,
            'end_date' => \App\Casts\DateOnly::class,
            'min_age' => 'integer',
            'max_age' => 'integer',
            'seats' => 'integer',
            'price_fils' => 'integer',
            'plan_ayahs' => 'integer',
            'memorization_direction' => \App\Enums\MemorizationDirection::class,
        ];
    }

    public function localizedName(string $locale): string
    {
        return ($locale === 'en' ? $this->name_en : $this->name_ar) ?: $this->name;
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function registrationRequests(): HasMany
    {
        return $this->hasMany(RegistrationRequest::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function lotteries(): HasMany
    {
        return $this->hasMany(Lottery::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    /** Seats consumed = enrolled requests, plus those held for a lottery (waitlist excluded). */
    public function acceptedCount(): int
    {
        return $this->registrationRequests()->whereIn('status', RegistrationStatus::seated())->count();
    }

    public function isFull(): bool
    {
        return $this->acceptedCount() >= $this->seats;
    }
}
