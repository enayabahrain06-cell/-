<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;

class SaveAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recordAttendance', $this->route('session')) ?? false;
    }

    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'],
            'records.*.status' => ['required', AttendanceStatus::rule()],
            'records.*.memorization_assignment' => ['nullable', 'string', 'max:1000'],
            'records.*.revision_assignment' => ['nullable', 'string', 'max:1000'],
            'records.*.note' => ['nullable', 'string', 'max:1000'],
            // Optional ledger entries (surah + ayah range) appended for the student in the same save.
            'records.*.progress' => ['nullable', 'array', 'max:4'],
        ] + \App\Http\Requests\Progress\ProgressRules::for('records.*.progress.*.');
    }
}
