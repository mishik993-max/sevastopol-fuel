<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

class AppSettingsService
{
    private const CACHE_KEY = 'app_settings_merged';

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $defaults = config('app_settings.defaults', []);
            $stored = AppSetting::query()->pluck('value', 'key')->all();

            return array_replace_recursive($defaults, $stored);
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function public(): array
    {
        $keys = config('app_settings.public_keys', []);

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): void
    {
        $allowed = array_keys(config('app_settings.defaults', []));

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{south: float, west: float, north: float, east: float} */
    public function geoBbox(): array
    {
        if ($this->hasStored('geo_bbox')) {
            return $this->get('geo_bbox');
        }

        return config('stations.import_bbox', $this->get('geo_bbox'));
    }

    public function closureReportsRequired(): int
    {
        if ($this->hasStored('closure_reports_required')) {
            return max(1, (int) $this->get('closure_reports_required'));
        }

        return max(1, (int) config('stations.closure.reports_required', $this->get('closure_reports_required', 5)));
    }

    public function correctionConfirmationsRequired(): int
    {
        if ($this->hasStored('correction_confirmations_required')) {
            return max(1, (int) $this->get('correction_confirmations_required'));
        }

        return max(1, (int) config('stations.correction.confirmations_required', $this->get('correction_confirmations_required', 5)));
    }

    public function duplicateRadiusM(): int
    {
        if ($this->hasStored('duplicate_radius_m')) {
            return max(10, (int) $this->get('duplicate_radius_m'));
        }

        return max(10, (int) config('stations.user_submission.duplicate_radius_m', $this->get('duplicate_radius_m', 80)));
    }

    public function freshnessFreshMinutes(): int
    {
        return max(1, (int) $this->get('freshness_fresh_minutes', 15));
    }

    public function freshnessStaleMinutes(): int
    {
        return max(2, (int) $this->get('freshness_stale_minutes', 60));
    }

    private function hasStored(string $key): bool
    {
        return AppSetting::query()->where('key', $key)->exists();
    }
}
