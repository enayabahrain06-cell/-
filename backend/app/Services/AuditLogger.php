<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * Record who changed what and when. Used for payments, grades, permissions,
     * wallet adjustments and any other sensitive write.
     */
    public function record(string $action, Model|string|null $subject, array $old = [], array $new = [], ?int $userId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $subject instanceof Model ? $subject->getMorphClass() : (string) $subject,
            'auditable_id' => $subject instanceof Model ? $subject->getKey() : 0,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }
}
