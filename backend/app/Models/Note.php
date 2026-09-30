<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * U9: one note model. scope student (optionally written in a class), general (the whole term), level (a level of
 * the term) or level_subject (a row of مواد المستويات).
 */
class Note extends Model
{
    public const SCOPES = ['student', 'general', 'level', 'level_subject'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
