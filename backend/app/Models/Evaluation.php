<?php

namespace App\Models;

use App\Enums\EvaluationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    protected $guarded = ['id'];

    /** An evaluation without a subject is of Quran (the four fixed criteria are Quran's until التقييمات arrives). */
    protected static function booted(): void
    {
        static::creating(function (Evaluation $e) {
            $e->subject_id ??= Subject::quranId();
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** One score per criterion (U5). For Quran the four columns hold the same values (dual write). */
    public function scores(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Quran evaluations only: every reader that averages the four columns (honor board, challenges, reports, portal,
     * messages, the profile) goes through this, so evaluations of other subjects never mix in.
     */
    public function scopeQuran(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $quran = Subject::quranId();

        return $q->where(fn ($w) => $w->whereNull($q->qualifyColumn('subject_id'))->when($quran, fn ($x) => $x->orWhere($q->qualifyColumn('subject_id'), $quran)));
    }

    public function isQuran(): bool
    {
        return $this->subject_id === null || (int) $this->subject_id === (int) Subject::quranId();
    }

    protected function casts(): array
    {
        return [
            'type' => EvaluationType::class,
            'evaluated_on' => \App\Casts\DateOnly::class,
            'sent_to_guardian_at' => 'datetime',
            'memorization' => 'integer', 'tajweed' => 'integer', 'revision' => 'integer', 'behavior' => 'integer',
        ];
    }

    public function total(): int
    {
        return (int) $this->memorization + (int) $this->tajweed + (int) $this->revision + (int) $this->behavior;
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

    public function issues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StudentIssue::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }
}
