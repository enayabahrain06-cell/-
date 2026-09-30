<?php

namespace App\Models;

use App\Enums\WeekDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period of the term timetable (الجدول الدراسي): a level, or one of its circles (lesson_id), studies a subject
 * on a weekday between two times, with a teacher and a room. Circles keep their own schedule; this is separate.
 */
class TimetableSlot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekday' => WeekDay::class];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
