<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SystemMetricsService
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return [
            'memory_used_mb' => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'memory_limit' => ini_get('memory_limit') ?: null,
            'load_avg' => is_array($load)
                ? array_map(static fn ($value) => round((float) $value, 2), $load)
                : null,
            'queue_pending' => $this->safeCount('jobs'),
            'queue_failed' => $this->safeCount('failed_jobs'),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'disk_free_gb' => $this->diskFreeGb(),
            'disk_total_gb' => $this->diskTotalGb(),
            'php_version' => PHP_VERSION,
            'app_env' => config('app.env'),
        ];
    }

    private function safeCount(string $table): int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function diskFreeGb(): ?float
    {
        $path = storage_path();

        if (! is_dir($path)) {
            return null;
        }

        $free = @disk_free_space($path);
        if ($free === false) {
            return null;
        }

        return round($free / 1073741824, 2);
    }

    private function diskTotalGb(): ?float
    {
        $path = storage_path();

        if (! is_dir($path)) {
            return null;
        }

        $total = @disk_total_space($path);
        if ($total === false) {
            return null;
        }

        return round($total / 1073741824, 2);
    }
}
