<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A subject a level studies in one term (مواد المستويات), with its default teacher. */
class LevelSubject extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekly_sessions' => 'integer', 'sort' => 'integer'];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(PlanItem::class);
    }

    /** توزيع الدرجات: the parts this subject is graded on. */
    public function gradeComponents(): HasMany
    {
        return $this->hasMany(GradeComponent::class)->orderBy('sort')->orderBy('id');
    }
}
