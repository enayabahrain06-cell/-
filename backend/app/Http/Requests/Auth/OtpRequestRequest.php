<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class OtpRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:20']];
    }
}
