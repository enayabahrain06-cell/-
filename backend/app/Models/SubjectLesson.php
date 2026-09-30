<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lesson of a subject's curriculum (دروس المواد), for one level or (level_id null) every level.
 * Not tied to a term: the same curriculum is planned again each term. Not to be confused with Lesson (a circle).
 */
class SubjectLesson extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'is_active' => 'boolean'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /** Lessons that apply to a level: its own plus the ones for every level. */
    public function scopeForLevel(Builder $q, ?int $levelId): Builder
    {
        return $levelId ? $q->where(fn ($w) => $w->whereNull('level_id')->orWhere('level_id', $levelId)) : $q;
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('id');
    }
}
