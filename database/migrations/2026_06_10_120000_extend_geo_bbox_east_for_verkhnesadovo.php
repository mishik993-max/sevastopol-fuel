<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        $setting = AppSetting::query()->where('key', 'geo_bbox')->first();

        if ($setting === null || ! is_array($setting->value)) {
            return;
        }

        $bbox = $setting->value;
        $east = (float) ($bbox['east'] ?? 0);

        if ($east >= 33.78) {
            return;
        }

        $bbox['east'] = 33.78;

        $setting->update([
            'value' => $bbox,
            'updated_at' => now(),
        ]);

        Cache::forget('app_settings_merged');
    }

    public function down(): void
    {
        $setting = AppSetting::query()->where('key', 'geo_bbox')->first();

        if ($setting === null || ! is_array($setting->value)) {
            return;
        }

        $bbox = $setting->value;

        if ((float) ($bbox['east'] ?? 0) !== 33.78) {
            return;
        }

        $bbox['east'] = 33.72;

        $setting->update([
            'value' => $bbox,
            'updated_at' => now(),
        ]);

        Cache::forget('app_settings_merged');
    }
};
