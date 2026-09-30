<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gender' => $this->gender?->value,
            'name' => $this->name,
            'type' => $this->type?->value,
            'subject_id' => $this->subject_id,
            'type_label' => $this->type?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'package_id' => $this->package_id,
            'package_name' => $this->whenLoaded('package', fn () => $this->package?->localizedName(app()->getLocale())),
            'lesson_id' => $this->lesson_id,
            'lesson_name' => $this->whenLoaded('lesson', fn () => $this->lesson?->name),
            'exam_date' => $this->exam_date?->toDateString(),
            'opens_at' => display_tz($this->opens_at)?->toIso8601String(),
            'closes_at' => display_tz($this->closes_at)?->toIso8601String(),
            'is_open_now' => $this->isOpenAt(now()),
            'duration_minutes' => $this->duration_minutes,
            'total_marks' => $this->total_marks,
            'pass_mark' => $this->pass_mark,
            'level_bands' => $this->when($this->isPlacement(), fn () => collect($this->level_bands ?? [])
                ->map(fn ($b) => $b + ['level_label' => \App\Enums\MemorizationLevel::tryFrom($b['level'])?->label()])->values()),
            'syllabus' => $this->syllabus,
            // U10: the grade component the exam counts for and the subject lessons it covers.
            'grade_component' => $this->whenLoaded('gradeComponent', fn () => $this->gradeComponent ? [
                'id' => $this->gradeComponent->id, 'name' => $this->gradeComponent->name(), 'weight' => (float) $this->gradeComponent->weight,
                'subject' => $this->gradeComponent->levelSubject?->subject?->name(), 'level_subject_id' => $this->gradeComponent->level_subject_id,
            ] : null),
            'required_lessons' => $this->whenLoaded('requiredLessons', fn () => $this->requiredLessons->map(fn ($l) => \App\Services\Grades\ExamGradeLinks::presentLesson($l))->values()),
            'randomize' => $this->randomize,
            'questions_count' => $this->whenCounted('questions'),
            'attempts_count' => $this->whenCounted('attempts'),
            'questions' => ExamQuestionResource::collection($this->whenLoaded('questions')),
            'results_sent_at' => display_tz($this->results_sent_at)?->toIso8601String(),
            'created_at' => display_tz($this->created_at)?->toIso8601String(),
        ];
    }
}
