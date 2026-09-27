<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Attendance messaging follows time changes, cancellation and deletion (LessonSessionObserver). */
#[\Illuminate\Database\Eloquent\Attributes\ObservedBy(\App\Observers\LessonSessionObserver::class)]
class LessonSession extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'session_date' => DateOnly::class,
            'reminder_sent_at' => 'datetime',
            'attendance_taken_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function hasAttendance(): bool
    {
        return $this->attendance_taken_at !== null || $this->attendances()->exists();
    }
}
