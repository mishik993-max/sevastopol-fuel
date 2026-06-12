<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FuelAssistantConfirmRequest extends FormRequest
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
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ];
    }
}
