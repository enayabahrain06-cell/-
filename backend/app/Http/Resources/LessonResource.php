<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gender' => $this->gender?->value,
            'name' => $this->name,
            'package_id' => $this->package_id,
            'package' => $this->whenLoaded('package', fn () => [
                'id' => $this->package->id,
                'name' => $this->package->localizedName(app()->getLocale()),
                'gender' => $this->package->gender?->value,
            ]),
            'teacher_id' => $this->teacher_id,
            'teacher' => $this->whenLoaded('teacher', fn () => ['id' => $this->teacher->id, 'name' => $this->teacher->name, 'phone' => $this->teacher->phone]),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'name' => $this->location->name, 'map_link' => $this->location->map_link] : null),
            'days' => $this->days,
            'days_labels' => collect($this->days ?? [])->map(fn ($d) => __("enums.week_day.{$d}"))->values(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'capacity' => $this->capacity,
            'student_count' => $this->when(isset($this->active_students_count), fn () => $this->active_students_count, fn () => $this->activeStudentCount()),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'students' => LessonStudentResource::collection($this->whenLoaded('lessonStudents')),
            'next_sessions' => LessonSessionResource::collection($this->whenLoaded('sessions')),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
