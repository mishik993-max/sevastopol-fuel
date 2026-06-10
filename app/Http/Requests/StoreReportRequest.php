<?php

namespace App\Http\Requests;

use App\Enums\CanisterPolicy;
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
            'canister_policy' => ['nullable', Rule::enum(CanisterPolicy::class)],
            'comment' => ['nullable', 'string', 'max:500'],
            'photo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png',
                'max:'.config('reports.photo_max_kb', 5120),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maxMb = (int) ceil(config('reports.photo_max_kb', 5120) / 1024);

        return [
            'photo.image' => 'Фото должно быть в формате JPG или PNG.',
            'photo.mimes' => 'Фото должно быть в формате JPG или PNG.',
            'photo.max' => "Фото слишком большое (максимум {$maxMb} МБ).",
        ];
    }
}
