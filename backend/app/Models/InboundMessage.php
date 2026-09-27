<?php

namespace App\Models;

use App\Enums\InboundIntent;
use App\Enums\InboundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'intent' => InboundIntent::class,
            'status' => InboundStatus::class,
            'received_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LessonSession::class, 'lesson_session_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
