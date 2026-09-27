<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A guardian (or student) replied "حاضر / Yes" to a reminder for this session. */
class AttendanceConfirmation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LessonSession::class, 'lesson_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
