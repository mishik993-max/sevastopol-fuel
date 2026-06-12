<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FuelAssistantSessionRequest extends FormRequest
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
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->query('client_id')) {
            $this->merge(['client_id' => $this->query('client_id')]);
        }
    }
}
