<?php

namespace App\Http\Requests;

use App\Enums\FillVolume;
use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\QueueSize;
use App\Enums\SaleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'fuel_type' => ['required', Rule::enum(FuelType::class)],
            'statuses' => ['required', 'array', 'min:1'],
            'statuses.*' => ['required', 'string', Rule::in([
                FuelStatus::Available->value,
                FuelStatus::Low->value,
                FuelStatus::None->value,
            ])],
            'queue_size' => ['required', Rule::enum(QueueSize::class)],
            'sale_types' => ['required', 'array', 'min:1'],
            'sale_types.*' => ['required', 'string', Rule::enum(SaleType::class)],
            'fill_volume' => ['nullable', Rule::enum(FillVolume::class)],
            'comment' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
        ];
    }
}
