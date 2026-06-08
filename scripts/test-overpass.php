<?php

$query = '[out:json][timeout:60];node["amenity"="fuel"](44.48,33.38,44.72,33.72);out tags;';

$ch = curl_init('https://overpass.kumi.systems/api/interpreter');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['data' => $query],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_USERAGENT => 'sevastopol-fuel/1.0',
]);
$r = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP {$code} len=".strlen($r ?: '').PHP_EOL;
if ($r) {
    $j = json_decode($r, true);
    echo 'elements: '.count($j['elements'] ?? []).PHP_EOL;
    foreach (array_slice($j['elements'] ?? [], 0, 3) as $el) {
        echo ($el['tags']['name'] ?? '?').' @ '.($el['lat'] ?? '').','.($el['lon'] ?? '').PHP_EOL;
    }
}
