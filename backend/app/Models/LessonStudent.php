<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\LessonStudentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonStudent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => LessonStudentStatus::class, 'joined_at' => DateOnly::class, 'left_at' => DateOnly::class];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
