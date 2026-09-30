<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of the teaching plan (الخطة): a curriculum lesson (or a free title) in a week of the term. */
class PlanItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['week_no' => 'integer', 'sort' => 'integer'];
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function subjectLesson(): BelongsTo
    {
        return $this->belongsTo(SubjectLesson::class);
    }

    public function displayTitle(): string
    {
        return $this->title ?: ($this->subjectLesson?->title ?? '');
    }
}
