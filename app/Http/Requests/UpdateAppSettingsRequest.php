<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'geo_bbox' => ['sometimes', 'array'],
            'geo_bbox.south' => ['required_with:geo_bbox', 'numeric', 'between:-90,90'],
            'geo_bbox.west' => ['required_with:geo_bbox', 'numeric', 'between:-180,180'],
            'geo_bbox.north' => ['required_with:geo_bbox', 'numeric', 'between:-90,90'],
            'geo_bbox.east' => ['required_with:geo_bbox', 'numeric', 'between:-180,180'],
            'map_center' => ['sometimes', 'array'],
            'map_center.lat' => ['required_with:map_center', 'numeric', 'between:-90,90'],
            'map_center.lng' => ['required_with:map_center', 'numeric', 'between:-180,180'],
            'network_priority' => ['sometimes', 'array', 'min:1'],
            'network_priority.*' => ['string', 'max:80'],
            'freshness_fresh_minutes' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'freshness_stale_minutes' => ['sometimes', 'integer', 'min:2', 'max:1440'],
            'closure_reports_required' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'correction_confirmations_required' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'duplicate_radius_m' => ['sometimes', 'integer', 'min:10', 'max:500'],
            'qr_reminders' => ['sometimes', 'array', 'min:1', 'max:10'],
            'qr_reminders.*.time' => ['required', 'date_format:H:i'],
            'qr_reminders.*.title' => ['required', 'string', 'max:120'],
            'qr_reminders.*.body' => ['required', 'string', 'max:300'],
            'qr_reminders.*.url' => ['nullable', 'string', 'max:500', 'regex:/^(|https:\/\/[^\s]+)$/i'],
        ];
    }
}
