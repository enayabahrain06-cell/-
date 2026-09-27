<?php

namespace App\Http\Resources;

use App\Enums\MediaCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class ExamAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $audio = $this->relationLoaded('media') ? $this->mediaIn(MediaCollection::Recitation) : null;
        $user = $request->user();
        $staff = $user && ($user->can('exams.manage') || $user->can('exams.grade'));

        return [
            'id' => $this->id,
            'question_id' => $this->exam_question_id,
            'question' => $this->whenLoaded('question', fn () => new ExamQuestionResource($this->question)),
            'answer' => $this->answer,
            'saved_at' => display_tz($this->saved_at)?->toIso8601String(),
            'audio_media_id' => $audio?->id,
            'audio_url' => $audio && Route::has('media.show') ? route('media.show', $audio) : null,
            'score' => $this->when($staff || $this->attempt?->graded_at !== null, fn () => $this->score),
            'is_correct' => $this->when($staff || $this->attempt?->graded_at !== null, fn () => $this->is_correct),
            'grader_note' => $this->when($staff, fn () => $this->grader_note),
        ];
    }
}
