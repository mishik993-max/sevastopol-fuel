<?php

namespace App\Http\Requests;

use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\SaleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAiChatApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.station_id' => ['required', 'integer', 'exists:stations,id'],
            'items.*.fuels' => ['required', 'array', 'min:1'],
            'items.*.fuels.*.fuel_type' => ['required', Rule::enum(FuelType::class)],
            'items.*.fuels.*.statuses' => ['required', 'array', 'min:1'],
            'items.*.fuels.*.statuses.*' => ['required', 'string', Rule::in([
                FuelStatus::Available->value,
                FuelStatus::Low->value,
                FuelStatus::None->value,
            ])],
            'items.*.fuels.*.sale_types' => ['required', 'array', 'min:1'],
            'items.*.fuels.*.sale_types.*' => ['required', 'string', Rule::enum(SaleType::class)],
            'items.*.fuels.*.comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
