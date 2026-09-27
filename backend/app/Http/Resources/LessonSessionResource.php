<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'lesson' => $this->whenLoaded('lesson', fn () => [
                'id' => $this->lesson->id,
                'name' => $this->lesson->name,
                'teacher' => $this->lesson->relationLoaded('teacher') ? ['id' => $this->lesson->teacher->id, 'name' => $this->lesson->teacher->name] : null,
                'default_location_id' => $this->lesson->location_id,
            ]),
            'session_date' => $this->session_date?->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'name' => $this->location->name, 'map_link' => $this->location->map_link] : null),
            'is_location_override' => $this->relationLoaded('lesson') && $this->lesson && $this->location_id !== $this->lesson->location_id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'attendance_taken' => $this->attendance_taken_at !== null,
            'attendance_taken_at' => display_tz($this->attendance_taken_at)?->toIso8601String(),
            'reminder_sent_at' => display_tz($this->reminder_sent_at)?->toIso8601String(),
            'attendance_summary' => $this->when(isset($this->present_count) || isset($this->absent_count), fn () => [
                'present' => (int) ($this->present_count ?? 0),
                'absent' => (int) ($this->absent_count ?? 0),
            ]),
            'notes' => $this->notes,
        ];
    }
}
