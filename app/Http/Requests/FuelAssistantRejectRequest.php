<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FuelAssistantRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
