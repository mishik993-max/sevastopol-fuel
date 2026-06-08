<?php

namespace App\Http\Requests;

use App\Services\AppSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStationCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bbox = app(AppSettingsService::class)->geoBbox();

        return [
            'corrections' => ['required', 'array', 'min:1', 'max:3'],
            'corrections.*.field' => ['required', 'string', Rule::in(['name', 'address', 'location'])],
            'corrections.*.name' => ['required_if:corrections.*.field,name', 'nullable', 'string', 'max:120'],
            'corrections.*.address' => ['required_if:corrections.*.field,address', 'nullable', 'string', 'max:255'],
            'corrections.*.latitude' => [
                'required_if:corrections.*.field,location',
                'nullable',
                'numeric',
                'between:'.$bbox['south'].','.$bbox['north'],
            ],
            'corrections.*.longitude' => [
                'required_if:corrections.*.field,location',
                'nullable',
                'numeric',
                'between:'.$bbox['west'].','.$bbox['east'],
            ],
        ];
    }
}
