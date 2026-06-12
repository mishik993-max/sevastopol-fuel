<?php

return [
    'enabled' => env('SEVTECH_FUEL_ENABLED', true),

    'base_url' => rtrim((string) env('SEVTECH_FUEL_BASE_URL', 'https://fuel.sevtech.org'), '/'),

    'stations_path' => env('SEVTECH_FUEL_STATIONS_PATH', '/map/api/stations'),

    'network_hint' => env('SEVTECH_FUEL_NETWORK', 'ТЭС'),

    'user_agent' => env(
        'SEVTECH_FUEL_USER_AGENT',
        'Mozilla/5.0 (compatible; SevastopolFuel/1.0; +https://sevastopol-fuel.local)',
    ),

    /** Минуты: не создавать повторный отчёт, если статус не изменился */
    'dedup_minutes' => (int) env('SEVTECH_FUEL_DEDUP_MINUTES', 15),

    'schedule_minutes' => (int) env('SEVTECH_FUEL_SCHEDULE_MINUTES', 0),
];
