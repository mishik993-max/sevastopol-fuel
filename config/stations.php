<?php

return [
    /*
    | Границы Севастополя и окрестностей (юг, запад, север, восток)
    | Источник координат: OpenStreetMap (можно хранить в БД)
    */
    'import_bbox' => [
        'south' => 44.48,
        'west' => 33.38,
        'north' => 44.72,
        'east' => 33.78, // Верхнесадово, трасса А-291 (восточнее 33.72)
    ],

    'overpass_url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),

    /*
    | Сетка для Nominatim: amenity=fuel отдаёт макс. ~50 точек за запрос,
    | поэтому область делим на ячейки (3×3 ≈ 80+ АЗС в Севастополе).
    */
    'nominatim_grid' => [
        'cols' => 3,
        'rows' => 3,
    ],

    /*
    | Скрытие закрытых АЗС: после N уникальных сообщений «не работает» (без ограничения по времени)
    */
    'closure' => [
        'reports_required' => (int) env('STATION_CLOSURE_REPORTS', 5),
    ],

    /*
    | Применение исправлений (название, адрес, координаты) после N подтверждений
    */
    'correction' => [
        'confirmations_required' => (int) env('STATION_CORRECTION_CONFIRMATIONS', 5),
    ],

    'user_submission' => [
        'duplicate_radius_m' => 80,
    ],

    'nominatim_queries' => [
        // Местные сети Севастополя и полуострова
        'Атан', 'Atan',
        'ТЭС', 'TES',
        'Грифон', 'Grifon', 'Griffon',
        'Red Petrol',
        'Севастопольская',
        'Мустанг', 'Mustang',
        'Sota',
        // Федеральные
        'WOG', 'OKKO', 'Shell', 'Лукойл', 'Роснефть', 'Gulf', 'Татнефть', 'EKO', 'Prime',
        // Общие
        'АЗС', 'заправка',
    ],
];
