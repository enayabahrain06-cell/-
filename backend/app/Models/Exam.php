<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Enums\ExamType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ExamType::class,
            'status' => ExamStatus::class,
            'exam_date' => 'date',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'randomize' => 'boolean',
            'reminder_day_sent_at' => 'datetime',
            'reminder_hour_sent_at' => 'datetime',
            'results_sent_at' => 'datetime',
            'duration_minutes' => 'integer', 'total_marks' => 'integer', 'pass_mark' => 'integer',
        ];
    }

    public function isOpenAt(\DateTimeInterface $at): bool
    {
        return $this->status === ExamStatus::Published && $at >= $this->opens_at && $at <= $this->closes_at;
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
