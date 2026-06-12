<?php

namespace App\Http\Requests;

use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\SaleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAiChatParseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:20', 'max:12000'],
        ];
    }
}
