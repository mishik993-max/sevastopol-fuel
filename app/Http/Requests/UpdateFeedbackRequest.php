<?php

namespace App\Http\Requests;

use App\Enums\FeedbackStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(FeedbackStatus::class)],
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
