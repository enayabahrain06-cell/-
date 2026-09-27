<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_session_id' => $this->lesson_session_id,
            'student_id' => $this->student_id,
            'student' => new StudentSummaryResource($this->whenLoaded('student')),
            'session' => $this->whenLoaded('session', fn () => [
                'id' => $this->session->id,
                'session_date' => $this->session->session_date?->toDateString(),
                'start_time' => substr($this->session->start_time, 0, 5),
                'lesson_name' => $this->session->relationLoaded('lesson') ? $this->session->lesson?->name : null,
            ]),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'memorization_assignment' => $this->memorization_assignment,
            'revision_assignment' => $this->revision_assignment,
            'note' => $this->note,
            'recorded_by' => $this->recorded_by,
            'absence_notified_at' => display_tz($this->absence_notified_at)?->toIso8601String(),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
