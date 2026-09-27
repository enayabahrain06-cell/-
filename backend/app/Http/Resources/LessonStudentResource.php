<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'student' => new StudentSummaryResource($this->whenLoaded('student')),
            'status' => $this->status?->value,
            'joined_at' => $this->joined_at?->toDateString(),
            'left_at' => $this->left_at?->toDateString(),
            'current_memorization' => $this->current_memorization,
            'current_revision' => $this->current_revision,
        ];
    }
}
