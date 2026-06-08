<?php

namespace App\Http\Requests;

use App\Enums\FeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(FeedbackType::class)],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'contact' => ['nullable', 'string', 'max:120'],
        ];
    }
}
