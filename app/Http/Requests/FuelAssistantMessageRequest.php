<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FuelAssistantMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'context_station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ];
    }
}
