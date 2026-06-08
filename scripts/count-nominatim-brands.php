<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$bbox = config('stations.import_bbox');
$brands = [
    'Атан', 'Atan', 'ТЭС', 'TES', 'Грифон', 'Grifon', 'Red Petrol',
    'Севастопольская', 'Мустанг', 'Mustang', 'Sota', 'WOG', 'OKKO',
    'Лукойл', 'Роснефть', 'Shell', 'АЗС', 'заправка',
];

$seen = [];

// all fuel in bbox
$r = Http::timeout(60)->withHeaders([
    'User-Agent' => 'sevastopol-fuel/1.0 (local fuel map)',
    'Accept-Language' => 'ru',
])->get('https://nominatim.openstreetmap.org/search', [
    'amenity' => 'fuel',
    'format' => 'json',
    'limit' => 100,
    'viewbox' => "{$bbox['west']},{$bbox['north']},{$bbox['east']},{$bbox['south']}",
    'bounded' => 1,
    'addressdetails' => 1,
]);

foreach ($r->json() as $item) {
    if (($item['class'] ?? '') === 'amenity' && ($item['type'] ?? '') === 'fuel') {
        $key = ($item['osm_type'] ?? '').'/'.($item['osm_id'] ?? '');
        $seen[$key] = $item['name'] ?? 'fuel';
    }
}

echo 'amenity=fuel: total='.count($seen).PHP_EOL;

foreach ($brands as $brand) {
    $r = Http::timeout(30)->withHeaders([
        'User-Agent' => 'sevastopol-fuel/1.0 (local fuel map)',
        'Accept-Language' => 'ru',
    ])->get('https://nominatim.openstreetmap.org/search', [
        'q' => "{$brand} Севастополь",
        'format' => 'json',
        'limit' => 50,
        'viewbox' => "{$bbox['west']},{$bbox['north']},{$bbox['east']},{$bbox['south']}",
        'bounded' => 1,
        'addressdetails' => 1,
    ]);

    $added = 0;
    foreach ($r->json() as $item) {
        if (($item['class'] ?? '') !== 'amenity' || ($item['type'] ?? '') !== 'fuel') {
            continue;
        }
        $key = ($item['osm_type'] ?? '').'/'.($item['osm_id'] ?? '');
        if ($key === '/' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = $brand;
        $added++;
    }

    echo "{$brand}: +{$added} total=".count($seen).PHP_EOL;
    usleep(1_100_000);
}

echo PHP_EOL.'Unique fuel stations: '.count($seen).PHP_EOL;
