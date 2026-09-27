<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One month of the honor board for one gender track. */
class HonorPeriod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weights' => 'array', 'published_to_students' => 'boolean', 'finalized_at' => 'datetime', 'honored_at' => 'datetime'];
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(HonorRanking::class);
    }

    public function circleOfMonth(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'circle_of_month_lesson_id');
    }
}
