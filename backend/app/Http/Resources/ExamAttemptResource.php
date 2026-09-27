<?php

namespace App\Http\Resources;

use App\Enums\MediaCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class ExamAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sheet = $this->relationLoaded('media') ? $this->mediaIn(MediaCollection::ExamSheet) : null;

        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => new StudentSummaryResource($this->student)),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'started_at' => display_tz($this->started_at)?->toIso8601String(),
            'expires_at' => display_tz($this->expires_at)?->toIso8601String(),
            'submitted_at' => display_tz($this->submitted_at)?->toIso8601String(),
            'remaining_seconds' => $this->when($this->getAttribute('remaining_seconds') !== null, fn () => $this->getAttribute('remaining_seconds')),
            'auto_score' => $this->auto_score,
            'manual_score' => $this->manual_score,
            'total_score' => $this->total_score,
            'passed' => $this->passed,
            'graded_at' => display_tz($this->graded_at)?->toIso8601String(),
            'graded_by' => $this->graded_by,
            'sheet_media_id' => $sheet?->id,
            'sheet_url' => $sheet && Route::has('media.show') ? route('media.show', $sheet) : null,
            'answers' => ExamAnswerResource::collection($this->whenLoaded('answers')),
            'questions' => ExamQuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}
