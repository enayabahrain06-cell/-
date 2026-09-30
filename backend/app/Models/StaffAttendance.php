<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حضور المشرفين والمعلمين: a staff member's attendance on one night, as supervisor or as teacher. */
class StaffAttendance extends Model
{
    public const KINDS = ['supervisor', 'teacher'];

    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    /** Statuses that count as attended. */
    public const ATTENDED = ['present', 'late'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attendance_date' => DateOnly::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
