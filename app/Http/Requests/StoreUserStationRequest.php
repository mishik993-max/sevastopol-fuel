<?php

namespace App\Http\Requests;

use App\Services\AppSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUserStationRequest extends FormRequest
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
            'network' => ['required', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:'.$bbox['south'].','.$bbox['north']],
            'longitude' => ['required', 'numeric', 'between:'.$bbox['west'].','.$bbox['east']],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'latitude.between' => 'Точка должна быть в пределах Севастополя.',
            'longitude.between' => 'Точка должна быть в пределах Севастополя.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = trim((string) $this->input('name', ''));
            $network = trim((string) $this->input('network', ''));

            if ($name === '' && $network === '') {
                $validator->errors()->add('network', 'Укажите сеть или название АЗС.');
            }
        });
    }
}
