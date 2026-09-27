<?php

namespace App\Models;

use App\Enums\EvaluationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => EvaluationType::class,
            'evaluated_on' => 'date',
            'sent_to_guardian_at' => 'datetime',
            'memorization' => 'integer', 'tajweed' => 'integer', 'revision' => 'integer', 'behavior' => 'integer',
        ];
    }

    public function total(): int
    {
        return $this->memorization + $this->tajweed + $this->revision + $this->behavior;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LessonSession::class, 'lesson_session_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }
}
