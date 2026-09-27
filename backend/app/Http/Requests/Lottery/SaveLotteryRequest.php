<?php

namespace App\Http\Requests\Lottery;

use App\Models\Lesson;
use App\Models\Lottery;
use App\Models\Package;
use App\Models\User;
use App\Services\Lottery\LotteryService;
use App\Support\Track;
use Illuminate\Foundation\Http\FormRequest;

class SaveLotteryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lottery = $this->route('lottery');

        return $lottery instanceof Lottery
            ? ($this->user()?->can('update', $lottery) ?? false)
            : ($this->user()?->can('create', Lottery::class) ?? false);
    }

    public function rules(): array
    {
        $req = $this->route('lottery') ? 'sometimes' : 'required';

        return [
            'package_id' => [$this->route('lottery') ? 'prohibited' : 'required', 'integer', 'exists:packages,id'],
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'balance_ages' => ['sometimes', 'boolean'],
            'keep_siblings' => ['sometimes', 'boolean'],
            'balance_levels' => ['sometimes', 'boolean'],
            'teachers' => [$req, 'array', 'min:1', 'max:50'],
            'teachers.*.teacher_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'teachers.*.lesson_id' => ['required', 'integer', 'distinct', 'exists:lessons,id'],
            'teachers.*.capacity' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    /** Gender separation and package consistency, enforced server-side. */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $package = $this->route('lottery')?->package ?? Package::find($this->input('package_id'));
            if (! $package) {
                return;
            }
            $g = $package->gender->value;
            if ($g !== 'mixed' && ! Track::allows($this->user(), $g)) {
                $v->errors()->add('package_id', __('gender.outside_track'));
            }
            foreach ((array) $this->input('teachers', []) as $i => $row) {
                $teacher = User::with('teacher')->find($row['teacher_id'] ?? 0);
                $lesson = Lesson::find($row['lesson_id'] ?? 0);
                if ($teacher && ! LotteryService::allowsTeacher($teacher, $package)) {
                    $v->errors()->add("teachers.$i.teacher_id", __('gender.teacher_mismatch'));
                }
                if ($lesson && $lesson->package_id !== $package->id) {
                    $v->errors()->add("teachers.$i.lesson_id", __('lottery.errors.lesson_package'));
                }
                if ($lesson && $teacher && $lesson->teacher_id !== $teacher->id) {
                    $v->errors()->add("teachers.$i.lesson_id", __('lottery.errors.lesson_teacher'));
                }
            }
        });
    }
}
