<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Attendance of a registered student on one day of a program or trip. */
class ActivityAttendance extends Model
{
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attendance_date' => \App\Casts\DateOnly::class, 'activity_id' => 'integer', 'student_id' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
