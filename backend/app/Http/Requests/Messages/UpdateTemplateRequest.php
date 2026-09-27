<?php

namespace App\Http\Requests\Messages;

use App\Services\Messaging\TemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('messages.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['sometimes', 'string', 'max:150'],
            'body_ar' => ['sometimes', 'string', 'max:2000'],
            'body_en' => ['sometimes', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $template = $this->route('template');
            $allowed = array_unique(array_merge($template?->variables ?? [], TemplateRenderer::VARIABLES));

            foreach (['body_ar', 'body_en'] as $field) {
                if (! $this->filled($field)) {
                    continue;
                }
                preg_match_all('/\{([a-z_]+)\}/', (string) $this->input($field), $m);
                $unknown = array_values(array_diff(array_unique($m[1]), $allowed));
                if ($unknown) {
                    $v->errors()->add($field, __('messages.unknown_placeholders', ['list' => implode(', ', array_map(fn ($u) => '{'.$u.'}', $unknown))]));
                }
            }
        });
    }
}
