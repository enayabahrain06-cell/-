<?php

namespace App\Http\Requests\Engagement;

use App\Models\Competition;
use App\Support\Track;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create or update a competition with its rounds and prizes (the wizard sends everything at once). */
class SaveCompetitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $c = $this->route('competition');

        return $c instanceof Competition ? $this->user()->can('update', $c) : $this->user()->can('create', Competition::class);
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'type' => ['required', Rule::in(['memorization', 'tajweed', 'recitation', 'knowledge'])],
            'scope' => ['required', Rule::in(['circle', 'package', 'authority'])],
            'scope_lesson_id' => ['nullable', 'required_if:scope,circle', 'integer', 'exists:lessons,id'],
            'scope_package_id' => ['nullable', 'required_if:scope,package', 'integer', 'exists:packages,id'],
            'min_age' => ['nullable', 'integer', 'min:3', 'max:99'],
            'max_age' => ['nullable', 'integer', 'min:3', 'max:99', 'gte:min_age'],
            'registration_opens_at' => ['required', 'date'],
            'registration_closes_at' => ['required', 'date', 'after:registration_opens_at'],
            'starts_at' => ['required', 'date', 'after_or_equal:registration_closes_at'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'max_participants' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'tie_break' => ['required', Rule::in(['last_round', 'criterion_order', 'age_younger', 'registration_order'])],
            'criteria' => ['required', 'array', 'min:1', 'max:10'],
            'criteria.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z_]+$/', 'distinct'],
            'criteria.*.name_ar' => ['required', 'string', 'max:80'],
            'criteria.*.name_en' => ['nullable', 'string', 'max:80'],
            'criteria.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
            'criteria.*.max' => ['required', 'integer', 'min:1', 'max:100'],
            'rounds' => ['required', 'array', 'min:1', 'max:10'],
            'rounds.*.id' => ['nullable', 'integer'],
            'rounds.*.name' => ['required', 'string', 'max:120'],
            'rounds.*.round_date' => ['required', 'date'],
            'rounds.*.start_time' => ['nullable', 'date_format:H:i'],
            'rounds.*.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'prizes' => ['array', 'max:10'],
            'prizes.*.rank' => ['required', 'integer', 'min:1', 'max:10', 'distinct'],
            'prizes.*.title' => ['required', 'string', 'max:150'],
            'prizes.*.badge_id' => ['nullable', 'integer', 'exists:badges,id'],
            'prizes.*.points' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if (array_sum(array_column($this->input('criteria', []), 'weight')) !== 100) {
                $v->errors()->add('criteria', __('engagement.errors.criteria_weights'));
            }
            // A supervisor creates competitions only for their own track.
            if (! Track::allows($this->user(), $this->input('gender'))) {
                $v->errors()->add('gender', __('engagement.errors.track'));
            }
        }];
    }
}
