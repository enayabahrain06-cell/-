<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $staff = $user && ($user->can('exams.manage') || $user->can('exams.grade'));

        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'prompt' => $this->prompt,
            'options' => $this->getAttribute('display_options') ?? $this->options,
            'marks' => $this->marks,
            'sort_order' => $this->sort_order,
            'position' => $this->when($this->getAttribute('position') !== null, fn () => $this->getAttribute('position')),
            'correct_answer' => $this->when($staff, fn () => $this->correct_answer),
        ];
    }
}
