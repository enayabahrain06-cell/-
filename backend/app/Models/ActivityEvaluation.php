<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A registered student's evaluation in a program: score 0-100, an optional grade word and notes. */
class ActivityEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['score' => 'integer', 'activity_id' => 'integer', 'student_id' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }
}
