<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تحديث دروس المواد: a plan item taught to a class (when, by whom, optionally in which session). */
class SubjectLessonProgress extends Model
{
    protected $table = 'subject_lesson_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['taught_on' => \App\Casts\DateOnly::class];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taught_by');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LessonSession::class, 'lesson_session_id');
    }
}
