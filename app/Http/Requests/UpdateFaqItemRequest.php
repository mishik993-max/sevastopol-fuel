<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFaqItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question' => ['sometimes', 'required', 'string', 'min:5', 'max:300'],
            'answer' => ['sometimes', 'required', 'string', 'min:10', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
