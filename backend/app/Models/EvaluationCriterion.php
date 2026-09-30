<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * U5 التقييمات: one criterion a subject is evaluated on. Quran's four system criteria (key memorization, tajweed,
 * revision, behavior) mirror the evaluation columns; they can be renamed or reordered only.
 */
class EvaluationCriterion extends Model
{
    protected $table = 'evaluation_criteria';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['max_score' => 'integer', 'weight' => 'integer', 'is_system' => 'boolean', 'sort' => 'integer', 'is_active' => 'boolean'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('id');
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }
}
