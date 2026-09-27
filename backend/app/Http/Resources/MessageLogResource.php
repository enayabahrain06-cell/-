<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient_phone' => $this->recipient_phone,
            'recipient_type' => $this->recipient_type?->value ?? $this->recipient_type,
            'student_id' => $this->student_id,
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->full_name),
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'type' => $this->type?->value ?? $this->type,
            'type_label' => $this->type?->label(),
            'template_key' => $this->template_key,
            'locale' => $this->locale,
            'body' => $this->body,
            'status' => $this->status?->value ?? $this->status,
            'status_label' => $this->status?->label(),
            'provider' => $this->provider,
            'provider_message_id' => $this->provider_message_id,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'scheduled_for' => display_tz($this->scheduled_for)?->toIso8601String(),
            'sent_at' => display_tz($this->sent_at)?->toIso8601String(),
            'delivered_at' => display_tz($this->delivered_at)?->toIso8601String(),
            'read_at' => display_tz($this->read_at)?->toIso8601String(),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
