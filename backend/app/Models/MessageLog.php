<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'recipient_type' => RecipientType::class,
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
