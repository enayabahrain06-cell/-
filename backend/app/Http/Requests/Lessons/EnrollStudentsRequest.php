<?php

namespace App\Http\Requests\Lessons;

use App\Enums\LessonStatus;
use App\Models\Student;
use App\Services\Lessons\LessonService;
use Illuminate\Foundation\Http\FormRequest;

class EnrollStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('addStudents', $this->route('lesson')) ?? false;
    }

    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
            // Confirms moving students who are active in another circle (their old row gets left_at).
            'move' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Each student must fit the circle: own track, active, the circle's gender (mixed early-years
     * circles take both) and the package age range. A student active in another circle needs move=1
     * and an old circle the user may manage. The seat limit is checked under a lock in the service.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $lesson = $this->route('lesson');
            $ids = array_filter((array) $this->input('student_ids'), 'is_numeric');
            if (! $lesson || ! $ids || $v->errors()->isNotEmpty()) {
                return;
            }
            if ($lesson->status !== LessonStatus::Active) {
                $v->errors()->add('student_ids', __('lessons.add.lesson_inactive'));

                return;
            }

            $service = app(LessonService::class);
            $lesson->loadMissing('package');
            foreach (Student::whereIn('id', $ids)->get() as $student) {
                $reason = $service->ineligibility($lesson, $student, $this->user());
                // "Already in" is harmless: the service skips students who are already active here.
                if ($reason === 'already_in') {
                    continue;
                }
                if ($reason) {
                    $v->errors()->add('student_ids', $service->ineligibilityMessage($reason, $lesson, $student));

                    continue;
                }

                $others = $service->otherCircles($lesson, $student, $this->user());
                if (! $others) {
                    continue;
                }
                $circles = collect($others)->pluck('name')->join(app()->getLocale() === 'ar' ? '، ' : ', ');
                if (! $this->boolean('move')) {
                    $v->errors()->add('student_ids', $service->ineligibilityMessage('in_other_circle', $lesson, $student, ['circle' => $circles]));
                } elseif (collect($others)->contains('movable', false)) {
                    $v->errors()->add('student_ids', $service->ineligibilityMessage('cannot_move', $lesson, $student, ['circle' => $circles]));
                }
            }
        });
    }
}
