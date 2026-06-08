<?php

namespace App\Services;

use App\Models\Station;
use App\Support\AddressSanitizer;
use App\Support\OsmFuelStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StationImportService
{
    public function __construct(private StationOsmSyncService $osmSync) {}
    /** @return array{imported: int, updated: int, skipped: int} */
    public function importFromJsonFile(?string $path = null): array
    {
        $path ??= database_path('seeders/data/stations-osm.json');

        if (! file_exists($path)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }

        $payload = json_decode(file_get_contents($path), true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Некорректный JSON');
        }

        $elements = $payload['elements'] ?? $payload;

        return $this->importElements($elements);
    }

    /** @return array{elements: array<int, array<string, mixed>>, source: string, count: int} */
    public function collectElements(?array $bbox = null): array
    {
        $bbox ??= config('stations.import_bbox');
        $seen = [];
        $elements = [];

        $this->collectNominatimFuelGrid($bbox, $seen, $elements);

        foreach (config('stations.nominatim_queries', []) as $query) {
            $this->collectNominatimSearch("{$query} Севастополь", $bbox, $seen, $elements);
            usleep(1_100_000);
        }

        return [
            'elements' => $elements,
            'source' => 'nominatim',
            'count' => count($elements),
        ];
    }

    /** @return array{imported: int, updated: int, skipped: int} */
    public function importFromNominatim(?array $bbox = null): array
    {
        $collected = $this->collectElements($bbox);

        return $this->importElements($collected['elements']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array{
     *     summary: array<string, int|string|null>,
     *     new: list<array<string, mixed>>,
     *     updated: list<array<string, mixed>>,
     *     would_deactivate: list<array<string, mixed>>,
     *     would_reactivate: list<array<string, mixed>>,
     *     note: string|null
     * }
     */
    public function previewElements(array $elements): array
    {
        $new = [];
        $updated = [];
        $wouldDeactivate = [];
        $wouldReactivate = [];
        $unchanged = 0;
        $skipped = 0;
        $seenExternalIds = [];

        $existingByExternalId = Station::query()
            ->whereNotNull('external_id')
            ->get()
            ->keyBy('external_id');

        foreach ($elements as $element) {
            $parsed = $this->parseOsmElement($element);

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $seenExternalIds[$parsed['external_id']] = true;
            $tags = $element['tags'] ?? [];
            $existing = $existingByExternalId->get($parsed['external_id']);

            if ($existing === null) {
                $item = [
                    'external_id' => $parsed['external_id'],
                    'name' => $parsed['name'],
                    'network' => $parsed['network'],
                    'address' => $parsed['address'],
                    'latitude' => $parsed['latitude'],
                    'longitude' => $parsed['longitude'],
                ];

                $closedReason = OsmFuelStatus::closedReason($tags);

                if ($closedReason !== null) {
                    $item['note'] = 'Будет создана, но сразу отключена: '.$closedReason;
                }

                $new[] = $item;

                continue;
            }

            $changes = $this->diffStationFields($existing, $parsed);
            $closedReason = OsmFuelStatus::closedReason($tags);

            if ($closedReason !== null && $existing->is_active && $this->osmSync->canAutoSync($existing)) {
                $wouldDeactivate[] = [
                    'id' => $existing->id,
                    'external_id' => $existing->external_id,
                    'name' => $existing->name,
                    'network' => $existing->network,
                    'reason' => $closedReason,
                ];
            } elseif (
                $closedReason === null
                && ! $existing->is_active
                && OsmFuelStatus::isOsmClosureReason($existing->closed_reason)
                && $this->osmSync->canAutoSync($existing)
            ) {
                $wouldReactivate[] = [
                    'id' => $existing->id,
                    'external_id' => $existing->external_id,
                    'name' => $existing->name,
                    'network' => $existing->network,
                ];
            }

            if ($changes !== []) {
                $updated[] = [
                    'id' => $existing->id,
                    'external_id' => $existing->external_id,
                    'name' => $parsed['name'],
                    'network' => $parsed['network'],
                    'changes' => $changes,
                ];
            } else {
                $unchanged++;
            }
        }

        $osmActiveNotInImport = Station::query()
            ->where('source', 'osm')
            ->whereNotNull('external_id')
            ->where('is_active', true)
            ->whereNotIn('external_id', array_keys($seenExternalIds))
            ->count();

        return [
            'summary' => [
                'total_elements' => count($elements),
                'new' => count($new),
                'updated' => count($updated),
                'unchanged' => $unchanged,
                'skipped' => $skipped,
                'would_deactivate' => count($wouldDeactivate),
                'would_reactivate' => count($wouldReactivate),
                'osm_active_not_in_import' => $osmActiveNotInImport,
            ],
            'new' => $new,
            'updated' => $updated,
            'would_deactivate' => $wouldDeactivate,
            'would_reactivate' => $wouldReactivate,
            'note' => $osmActiveNotInImport > 0
                ? "Ещё {$osmActiveNotInImport} активных OSM-станций не вошли в выборку Nominatim. Полная проверка закрытия (syncAll) занимает ~1 с на станцию."
                : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array{import: array{imported: int, updated: int, skipped: int}, sync: array<string, int>|null}
     */
    public function runImport(array $elements, bool $runSync = false): array
    {
        $import = $this->importElements($elements);
        $sync = $runSync ? $this->osmSync->syncAll() : null;

        return [
            'import' => $import,
            'sync' => $sync,
        ];
    }

    /** @param  array{name: string, network: string, address: string, latitude: float, longitude: float}  $parsed */
    private function diffStationFields(Station $existing, array $parsed): array
    {
        $changes = [];

        foreach (['name', 'network', 'address'] as $field) {
            if ($existing->{$field} !== $parsed[$field]) {
                $changes[] = [
                    'field' => $field,
                    'from' => $existing->{$field},
                    'to' => $parsed[$field],
                ];
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (abs($existing->{$field} - $parsed[$field]) > 0.00001) {
                $changes[] = [
                    'field' => $field,
                    'from' => $existing->{$field},
                    'to' => $parsed[$field],
                ];
            }
        }

        return $changes;
    }

    /** @param array<string, bool> $seen */
    /** @param array<int, array<string, mixed>> $elements */
    private function collectNominatimFuelGrid(array $bbox, array &$seen, array &$elements): void
    {
        $grid = config('stations.nominatim_grid', ['cols' => 3, 'rows' => 3]);
        $cols = max(1, (int) ($grid['cols'] ?? 3));
        $rows = max(1, (int) ($grid['rows'] ?? 3));

        $latStep = ($bbox['north'] - $bbox['south']) / $rows;
        $lngStep = ($bbox['east'] - $bbox['west']) / $cols;

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $cell = [
                    'south' => $bbox['south'] + $row * $latStep,
                    'north' => $bbox['south'] + ($row + 1) * $latStep,
                    'west' => $bbox['west'] + $col * $lngStep,
                    'east' => $bbox['west'] + ($col + 1) * $lngStep,
                ];

                $this->collectNominatimFuelCell($cell, $seen, $elements);
                usleep(1_100_000);
            }
        }
    }

    /** @param array{south: float, west: float, north: float, east: float} $bbox */
    /** @param array<string, bool> $seen */
    /** @param array<int, array<string, mixed>> $elements */
    private function collectNominatimFuelCell(array $bbox, array &$seen, array &$elements): void
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'User-Agent' => 'sevastopol-fuel/1.0 (local fuel map)',
                'Accept-Language' => 'ru',
            ])
            ->get('https://nominatim.openstreetmap.org/search', [
                'amenity' => 'fuel',
                'format' => 'json',
                'limit' => 50,
                'viewbox' => "{$bbox['west']},{$bbox['north']},{$bbox['east']},{$bbox['south']}",
                'bounded' => 1,
                'addressdetails' => 1,
            ]);

        if ($response->successful()) {
            $this->mergeNominatimResults($response->json(), $seen, $elements);
        }
    }

    /** @param array<string, bool> $seen */
    /** @param array<int, array<string, mixed>> $elements */
    private function collectNominatimSearch(string $query, array $bbox, array &$seen, array &$elements): void
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'sevastopol-fuel/1.0 (local fuel map)',
                'Accept-Language' => 'ru',
            ])
            ->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'json',
                'limit' => 50,
                'viewbox' => "{$bbox['west']},{$bbox['north']},{$bbox['east']},{$bbox['south']}",
                'bounded' => 1,
                'addressdetails' => 1,
            ]);

        if ($response->successful()) {
            $this->mergeNominatimResults($response->json(), $seen, $elements);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    /** @param array<string, bool> $seen */
    /** @param array<int, array<string, mixed>> $elements */
    private function mergeNominatimResults(array $items, array &$seen, array &$elements): void
    {
        foreach ($items as $item) {
            if (($item['class'] ?? '') !== 'amenity' || ($item['type'] ?? '') !== 'fuel') {
                continue;
            }

            $key = ($item['osm_type'] ?? '').'/'.($item['osm_id'] ?? '');

            if ($key === '/' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $elements[] = $this->nominatimToOsmElement($item);
        }
    }

    /** @return array{imported: int, updated: int, skipped: int} */
    public function importFromOpenStreetMap(?array $bbox = null): array
    {
        $bbox ??= config('stations.import_bbox');
        $query = $this->buildOverpassQuery($bbox);

        $response = $this->requestOverpass($query);
        $response->throw();

        return $this->importElements($response->json('elements', []));
    }

    /** @param array<int, array<string, mixed>> $elements */
    private function importElements(array $elements): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($elements as $element) {
            $parsed = $this->parseOsmElement($element);

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $tags = $element['tags'] ?? [];

            $station = Station::query()->updateOrCreate(
                ['external_id' => $parsed['external_id']],
                [
                    'source' => 'osm',
                    'name' => $parsed['name'],
                    'network' => $parsed['network'],
                    'address' => $parsed['address'],
                    'latitude' => $parsed['latitude'],
                    'longitude' => $parsed['longitude'],
                ],
            );

            if ($tags !== []) {
                $this->osmSync->applyTagsToStation($station, $tags);
            }

            if ($station->wasRecentlyCreated) {
                $imported++;
            } else {
                $updated++;
            }
        }

        return compact('imported', 'updated', 'skipped');
    }

    private function requestOverpass(string $query)
    {
        $urls = array_unique([
            config('stations.overpass_url'),
            'https://overpass.kumi.systems/api/interpreter',
            'https://overpass.openstreetmap.ru/api/interpreter',
        ]);

        $lastException = null;

        foreach ($urls as $url) {
            try {
                $response = Http::timeout(180)
                    ->withHeaders(['User-Agent' => 'sevastopol-fuel/1.0'])
                    ->asForm()
                    ->post($url, ['data' => $query]);

                if ($response->successful()) {
                    return $response;
                }

                $lastException = new \RuntimeException("Overpass {$url}: HTTP {$response->status()}");
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('Overpass API unavailable');
    }

    /** @param array{south: float, west: float, north: float, east: float} $bbox */
    private function buildOverpassQuery(array $bbox): string
    {
        $s = $bbox['south'];
        $w = $bbox['west'];
        $n = $bbox['north'];
        $e = $bbox['east'];

        return <<<OVERPASS
[out:json][timeout:90];
(
  node["amenity"="fuel"]({$s},{$w},{$n},{$e});
  way["amenity"="fuel"]({$s},{$w},{$n},{$e});
);
out center tags;
OVERPASS;
    }

    /** @return array{external_id: string, name: string, network: string, address: string, latitude: float, longitude: float}|null */
    private function parseOsmElement(array $element): ?array
    {
        $type = $element['type'] ?? null;
        $id = $element['id'] ?? null;

        if ($type === null || $id === null) {
            return null;
        }

        $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
        $lng = $element['lon'] ?? $element['center']['lon'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        $tags = $element['tags'] ?? [];

        $brand = $this->cleanTag($tags['brand'] ?? $tags['operator'] ?? null);
        $name = $this->cleanTag($tags['name'] ?? $tags['official_name'] ?? null);
        $network = $this->normalizeNetwork($brand, $name);
        $displayName = $name ?? $brand ?? 'Заправка';

        $address = $this->buildAddress($tags);

        return [
            'external_id' => "{$type}/{$id}",
            'name' => $displayName,
            'network' => $network,
            'address' => $address,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    /** @param array<string, string> $tags */
    private function buildAddress(array $tags): string
    {
        if (! empty($tags['addr:full'])) {
            return AddressSanitizer::clean($this->cleanTag($tags['addr:full']));
        }

        $parts = array_filter([
            $tags['addr:street'] ?? null,
            $tags['addr:housenumber'] ?? null,
        ]);

        if ($parts !== []) {
            $street = implode(' ', array_map(fn ($p) => $this->cleanTag($p), $parts));

            $street = Str::startsWith(mb_strtolower($street), 'ул') ? $street : "ул. {$street}";

            return AddressSanitizer::clean($street);
        }

        return 'Севастополь, Россия';
    }

    public function sanitizeExistingAddresses(): int
    {
        $count = 0;

        Station::query()->cursor()->each(function (Station $station) use (&$count) {
            $clean = AddressSanitizer::clean($station->address);

            if ($clean !== $station->address) {
                $station->update(['address' => $clean]);
                $count++;
            }
        });

        return $count;
    }

    /** @param array<string, mixed> $item */
    private function nominatimToOsmElement(array $item): array
    {
        $addr = $item['address'] ?? [];

        return [
            'type' => $item['osm_type'],
            'id' => $item['osm_id'],
            'lat' => (float) $item['lat'],
            'lon' => (float) $item['lon'],
            'tags' => [
                'amenity' => 'fuel',
                'name' => $item['name'] ?? 'Заправка',
                'brand' => $item['name'] ?? null,
                'addr:street' => $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? null,
                'addr:housenumber' => $addr['house_number'] ?? null,
            ],
        ];
    }

    private function cleanTag(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeNetwork(?string $brand, ?string $name): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([$brand, $name])));

        $aliases = [
            'atan' => 'Атан',
            'атан' => 'Атан',
            'тэс' => 'ТЭС',
            'tes' => 'ТЭС',
            'grifon' => 'Грифон',
            'грифон' => 'Грифон',
            'griffon' => 'Грифон',
            'red petrol' => 'Red Petrol',
            'севастопольская' => 'Севастопольская',
            'мустанг' => 'Мустанг',
            'mustang' => 'Мустанг',
            'sota' => 'Sota',
            'wog' => 'WOG',
            'okko' => 'OKKO',
            'shell' => 'Shell',
            'лукойл' => 'Лукойл',
            'rosneft' => 'Роснефть',
            'роснефть' => 'Роснефть',
            'gulf' => 'Gulf',
            'татнефть' => 'Татнефть',
        ];

        foreach ($aliases as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        return $brand ?? 'АЗС';
    }
}
