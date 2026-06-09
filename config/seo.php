<?php

return [
    'site_name' => env('SEO_SITE_NAME', 'sevazs.ru'),

    'title' => env(
        'SEO_TITLE',
        'АЗС Севастополь — карта заправок, цены и наличие топлива',
    ),

    'description' => env(
        'SEO_DESCRIPTION',
        'Интерактивная карта АЗС Севастополя: актуальные цены, наличие топлива, отчёты пользователей и напоминания о QR-коде на заправку.',
    ),

    'keywords' => env(
        'SEO_KEYWORDS',
        'АЗС Севастополь, заправки Севастополь, цены на бензин, карта АЗС, топливо Севастополь, sevazs',
    ),

    'og_image' => env('SEO_OG_IMAGE', '/icons/icon-512.png'),

    'locale' => 'ru_RU',
];
