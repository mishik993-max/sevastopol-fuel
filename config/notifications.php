<?php

return [
    'timezone' => env('APP_TIMEZONE', 'Europe/Simferopol'),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@sevastopol-fuel.local'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    'fuel_push' => [
        'cooldown_minutes' => (int) env('FUEL_PUSH_COOLDOWN_MINUTES', 45),
    ],

    'qr_reminders' => [
        '21_30' => [
            'title' => 'Скоро QR на топливо',
            'body' => 'Через полчаса в чате можно будет получить QR-код на топливо',
        ],
        '21_45' => [
            'title' => 'QR на топливо',
            'body' => 'Через 15 минут откроется получение QR-кода на топливо',
        ],
        '21_55' => [
            'title' => 'QR через 5 минут',
            'body' => 'Через 5 минут можно получить QR-код на топливо',
        ],
        '22_00' => [
            'title' => 'QR доступен',
            'body' => 'Сейчас можно получить QR-код на топливо в чате',
        ],
    ],
];
