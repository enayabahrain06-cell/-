<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One level decision about a student (ترفيع الطلبة / تحديث المستوى): promote, repeat, graduate or level_change. */
class StudentPromotion extends Model
{
    public const DECISIONS = ['promote', 'repeat', 'graduate', 'level_change'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['student_id' => 'integer', 'from_term_id' => 'integer', 'to_term_id' => 'integer', 'from_level_id' => 'integer', 'to_level_id' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'from_term_id');
    }

    public function toTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'to_term_id');
    }

    public function fromLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'from_level_id');
    }

    public function toLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'to_level_id');
    }

    public function fromLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'from_lesson_id');
    }

    public function toLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'to_lesson_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
