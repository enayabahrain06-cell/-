<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['noted_on' => \App\Casts\DateOnly::class];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(StudentIssue::class, 'student_issue_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
