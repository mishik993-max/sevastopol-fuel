<?php

return [
    /*
    | Настройки по умолчанию. Переопределяются из таблицы app_settings через админку.
    */
    'defaults' => [
        'geo_bbox' => [
            'south' => 44.48,
            'west' => 33.38,
            'north' => 44.72,
            'east' => 33.78,
        ],
        'map_center' => [
            'lat' => 44.605,
            'lng' => 33.522,
        ],
        'network_priority' => [
            'Атан', 'ТЭС', 'Грифон', 'WOG', 'OKKO', 'Лукойл', 'Роснефть',
            'Севастопольская', 'Red Petrol', 'Мустанг', 'Sota', 'Shell', 'Татнефть', 'Gulf',
        ],
        'freshness_fresh_minutes' => 15,
        'freshness_stale_minutes' => 60,
        'closure_reports_required' => 5,
        'correction_confirmations_required' => 5,
        'duplicate_radius_m' => 80,
        'qr_reminders' => [
            ['time' => '21:30', 'title' => 'Скоро QR на топливо', 'body' => 'Через полчаса в чате можно будет получить QR-код на топливо', 'url' => ''],
            ['time' => '21:45', 'title' => 'QR на топливо', 'body' => 'Через 15 минут откроется получение QR-кода на топливо', 'url' => ''],
            ['time' => '21:55', 'title' => 'QR через 5 минут', 'body' => 'Через 5 минут можно получить QR-код на топливо', 'url' => ''],
            ['time' => '22:00', 'title' => 'QR доступен', 'body' => 'Сейчас можно получить QR-код на топливо в чате', 'url' => ''],
        ],
    ],

    /** Ключи, отдаваемые публичному API (без секретов) */
    'public_keys' => [
        'geo_bbox',
        'map_center',
        'network_priority',
        'freshness_fresh_minutes',
        'freshness_stale_minutes',
        'closure_reports_required',
        'correction_confirmations_required',
        'qr_reminders',
    ],
];
