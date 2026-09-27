<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Alert extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => AlertType::class, 'severity' => AlertSeverity::class, 'status' => AlertStatus::class, 'resolved_at' => 'datetime'];
    }

    /** Idempotent: one open alert per (type, subject). */
    public static function raise(AlertType $type, string $title, ?string $body = null, ?Model $subject = null, AlertSeverity $severity = AlertSeverity::Warning): self
    {
        return static::firstOrCreate(
            [
                'type' => $type->value,
                'status' => AlertStatus::Open->value,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ],
            ['title' => $title, 'body' => $body, 'severity' => $severity->value]
        );
    }

    public static function resolveFor(AlertType $type, Model $subject, ?int $by = null): void
    {
        static::where('type', $type->value)->where('status', AlertStatus::Open->value)
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->update(['status' => AlertStatus::Resolved->value, 'resolved_at' => now(), 'resolved_by' => $by]);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
