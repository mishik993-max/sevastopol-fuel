<?php

namespace App\Http\Requests;

use App\Enums\FuelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushWatchSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500'],
            'client_id' => ['nullable', 'uuid'],
            'station_ids' => ['present', 'array', 'max:7'],
            'station_ids.*' => ['integer', 'min:1'],
            'fuel_type' => ['required', 'string', Rule::enum(FuelType::class)],
        ];
    }
}
