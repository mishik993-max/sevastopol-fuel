<?php

return [
    'enabled' => env('SEVTECH_FUEL_ENABLED', true),

    'base_url' => rtrim((string) env('SEVTECH_FUEL_BASE_URL', 'https://fuel.sevtech.org'), '/'),

    'stations_path' => env('SEVTECH_FUEL_STATIONS_PATH', '/map/a'),

    'network_hint' => env('SEVTECH_FUEL_NETWORK', 'ТЭС'),

    /** Заполненность ниже порога (%) → статус «мало» */
    'low_percent_threshold' => (int) env('SEVTECH_FUEL_LOW_PERCENT', 25),

    /** Макс. расстояние для сопоставления АЗС по GPS (метры) */
    'match_max_distance_m' => (int) env('SEVTECH_MATCH_MAX_DISTANCE_M', 400),

    'user_agent' => env(
        'SEVTECH_FUEL_USER_AGENT',
        'Mozilla/5.0 (compatible; SevastopolFuel/1.0; +https://sevastopol-fuel.local)',
    ),

    /** Минуты: не создавать повторный отчёт, если статус не изменился */
    'dedup_minutes' => (int) env('SEVTECH_FUEL_DEDUP_MINUTES', 15),

    'schedule_minutes' => (int) env('SEVTECH_FUEL_SCHEDULE_MINUTES', 0),
];
