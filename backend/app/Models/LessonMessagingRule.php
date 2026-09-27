<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-circle override of the messaging rules (no row = the global settings apply). */
class LessonMessagingRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reminders_enabled' => 'boolean', 'second_reminder_enabled' => 'boolean'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
