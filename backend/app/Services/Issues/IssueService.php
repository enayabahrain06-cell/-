<?php

namespace App\Services\Issues;

use App\Enums\IssueCategory;
use App\Enums\IssueStatus;
use App\Enums\LessonStudentStatus;
use App\Models\IssueNote;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueService
{
    public function create(Student $student, array $data, User $by): StudentIssue
    {
        $this->assertSubcategory($data['category'], $data['subcategory'] ?? null);

        $lessonId = $data['lesson_id'] ?? LessonStudent::where('student_id', $student->id)
            ->where('status', LessonStudentStatus::Active->value)->orderBy('joined_at')->value('lesson_id');

        return StudentIssue::create([
            'student_id' => $student->id,
            'lesson_id' => $lessonId,
            'evaluation_id' => $data['evaluation_id'] ?? null,
            'category' => $data['category'],
            'subcategory' => $data['subcategory'] ?? null,
            'description' => $data['description'],
            'action_plan' => $data['action_plan'] ?? null,
            'severity' => $data['severity'],
            'status' => IssueStatus::Open,
            'opened_by' => $by->id,
            'opened_at' => now(),
            'next_follow_up_date' => $data['next_follow_up_date'] ?? null,
        ]);
    }

    public function update(StudentIssue $issue, array $data): StudentIssue
    {
        $category = $data['category'] ?? $issue->category->value;
        $sub = array_key_exists('subcategory', $data) ? $data['subcategory'] : $issue->subcategory;
        if ($category !== IssueCategory::Tajweed->value && ! array_key_exists('subcategory', $data)) {
            $sub = null; // category changed away from tajweed
        }
        $this->assertSubcategory($category, $sub);

        $fill = array_intersect_key($data, array_flip(['category', 'description', 'action_plan', 'severity', 'next_follow_up_date']));
        $fill['subcategory'] = $sub;
        $issue->fill($fill);
        if (isset($data['status'])) {
            $this->applyStatus($issue, $data['status']);
        }
        $issue->save();

        return $issue;
    }

    /** Add a follow-up note; optionally move the status (e.g. to improving or resolved) at the same time. */
    public function addNote(StudentIssue $issue, array $data, User $by): IssueNote
    {
        return DB::transaction(function () use ($issue, $data, $by) {
            $note = $issue->notes()->create([
                'note' => $data['note'],
                'added_by' => $by->id,
                'noted_on' => $data['noted_on'] ?? today()->toDateString(),
            ]);
            if (! empty($data['status'])) {
                $this->applyStatus($issue, $data['status']);
            }
            if (array_key_exists('next_follow_up_date', $data)) {
                $issue->next_follow_up_date = $data['next_follow_up_date'];
            }
            $issue->save();

            return $note;
        });
    }

    private function applyStatus(StudentIssue $issue, string $status): void
    {
        $issue->status = $status;
        $issue->resolved_at = $status === IssueStatus::Resolved->value ? ($issue->resolved_at ?? now()) : null;
    }

    private function assertSubcategory(string $category, ?string $sub): void
    {
        if ($sub !== null && $category !== IssueCategory::Tajweed->value) {
            throw ValidationException::withMessages(['subcategory' => __('issues.subcategory_only_tajweed')]);
        }
    }

    /** Serialized issue for the profile and the guardian view. */
    public function present(StudentIssue $issue, ?string $locale = null, int $notes = 3): array
    {
        $locale ??= app()->getLocale();

        return [
            'id' => $issue->id,
            'student_id' => $issue->student_id,
            'lesson_id' => $issue->lesson_id,
            'category' => $issue->category->value,
            'category_label' => $issue->category->label($locale),
            'subcategory' => $issue->subcategory,
            'subcategory_label' => $issue->subcategory ? __("enums.tajweed_aspect.{$issue->subcategory}", [], $locale) : null,
            'description' => $issue->description,
            'action_plan' => $issue->action_plan,
            'severity' => $issue->severity->value,
            'severity_label' => $issue->severity->label($locale),
            'status' => $issue->status->value,
            'status_label' => $issue->status->label($locale),
            'opened_by' => $issue->relationLoaded('opener') ? $issue->opener?->name : null,
            'opened_at' => display_tz($issue->opened_at)?->toIso8601String(),
            'resolved_at' => display_tz($issue->resolved_at)?->toIso8601String(),
            'next_follow_up_date' => $issue->next_follow_up_date?->toDateString(),
            'notes' => $issue->relationLoaded('notes')
                ? $issue->notes->take($notes)->map(fn (IssueNote $n) => [
                    'id' => $n->id,
                    'note' => $n->note,
                    'noted_on' => $n->noted_on?->toDateString(),
                    'added_by' => $n->relationLoaded('author') ? $n->author?->name : null,
                ])->values()->all()
                : [],
        ];
    }
}
