<?php

namespace App\Models;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A difficulty the student is working through, with an action plan and follow-up notes. */
class StudentIssue extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => IssueCategory::class,
            'severity' => IssueSeverity::class,
            'status' => IssueStatus::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'next_follow_up_date' => \App\Casts\DateOnly::class,
        ];
    }

    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->whereIn('status', IssueStatus::unresolved());
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(IssueNote::class)->orderByDesc('noted_on')->orderByDesc('id');
    }
}
