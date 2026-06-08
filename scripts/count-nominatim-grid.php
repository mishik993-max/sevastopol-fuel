<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$bbox = config('stations.import_bbox');
$seen = [];

$cols = 3;
$rows = 3;
$latStep = ($bbox['north'] - $bbox['south']) / $rows;
$lngStep = ($bbox['east'] - $bbox['west']) / $cols;

for ($row = 0; $row < $rows; $row++) {
    for ($col = 0; $col < $cols; $col++) {
        $south = $bbox['south'] + $row * $latStep;
        $north = $south + $latStep;
        $west = $bbox['west'] + $col * $lngStep;
        $east = $west + $lngStep;

        $r = Http::timeout(60)->withHeaders([
            'User-Agent' => 'sevastopol-fuel/1.0 (local fuel map)',
            'Accept-Language' => 'ru',
        ])->get('https://nominatim.openstreetmap.org/search', [
            'amenity' => 'fuel',
            'format' => 'json',
            'limit' => 50,
            'viewbox' => "{$west},{$north},{$east},{$south}",
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
            $seen[$key] = $item['name'] ?? '?';
            $added++;
        }

        echo "cell {$row}x{$col}: +{$added} total=".count($seen).PHP_EOL;
        usleep(1_100_000);
    }
}

echo PHP_EOL.'Grid total: '.count($seen).PHP_EOL;
