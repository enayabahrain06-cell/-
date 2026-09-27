<?php

namespace App\Http\Requests\Engagement;

use App\Models\Challenge;
use App\Services\Engagement\ChallengeService;
use App\Support\Quran;
use App\Support\Track;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $c = $this->route('challenge');

        return $c instanceof Challenge ? $this->user()->can('update', $c) : $this->user()->can('create', Challenge::class);
    }

    public function rules(): array
    {
        $range = in_array($this->input('goal_type'), ['memorize_range', 'revision_range'], true);

        return [
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'scope' => ['required', Rule::in(['circle', 'package', 'authority'])],
            'scope_lesson_id' => ['nullable', 'required_if:scope,circle', 'integer', 'exists:lessons,id'],
            'scope_package_id' => ['nullable', 'required_if:scope,package', 'integer', 'exists:packages,id'],
            'min_age' => ['nullable', 'integer', 'min:3', 'max:99'],
            'max_age' => ['nullable', 'integer', 'min:3', 'max:99', 'gte:min_age'],
            'goal_type' => ['required', Rule::in(ChallengeService::GOALS)],
            'goal_value' => [$range ? 'nullable' : 'required', 'integer', 'min:1', 'max:100000'],
            'surah_number' => [$range ? 'required' : 'nullable', 'integer', 'min:1', 'max:114'],
            'from_ayah' => ['nullable', 'integer', 'min:1'],
            'to_ayah' => ['nullable', 'integer', 'min:1', 'gte:from_ayah'],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'score_criterion' => ['nullable', Rule::in(['memorization', 'tajweed', 'revision', 'behavior', 'total'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'reward_badge_id' => ['nullable', 'integer', 'exists:badges,id'],
            'reward_points' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'finished', 'cancelled'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if ($this->filled('surah_number') && $this->filled('to_ayah') && $this->integer('to_ayah') > Quran::ayahCount($this->integer('surah_number'))) {
                $v->errors()->add('to_ayah', __('validation.max.numeric', ['attribute' => 'to_ayah', 'max' => Quran::ayahCount($this->integer('surah_number'))]));
            }
            if (! Track::allows($this->user(), $this->input('gender'))) {
                $v->errors()->add('gender', __('engagement.errors.track'));
            }
        }];
    }
}
