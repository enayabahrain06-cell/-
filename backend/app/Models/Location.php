<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(LocationBooking::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(LessonLocationOverride::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LessonSession::class);
    }
}
