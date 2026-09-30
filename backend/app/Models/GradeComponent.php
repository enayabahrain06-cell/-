<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One part of a level subject's grade (توزيع الدرجات): marks and a weight (percent share of the subject total).
 * A component of kind "exam" links one exam and reads its scores from exam_attempts.total_score (never copied);
 * every other kind keeps its scores in grade_entries.
 */
class GradeComponent extends Model
{
    public const KINDS = ['exam', 'homework', 'participation', 'attendance', 'project', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['max_marks' => 'float', 'weight' => 'float', 'sort' => 'integer'];
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GradeEntry::class);
    }

    public function isExam(): bool
    {
        return $this->kind === 'exam';
    }

    /** Full marks of the component: the linked exam's total for exam components. */
    public function maxMarks(): float
    {
        return $this->isExam() && $this->exam ? (float) $this->exam->total_marks : (float) $this->max_marks;
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('id');
    }
}
