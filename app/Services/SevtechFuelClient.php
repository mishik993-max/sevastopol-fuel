<?php

namespace App\Services;

use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\SaleType;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SevtechFuelClient
{
    /** @return array{items: list<array<string, mixed>>, raw: mixed, fetched_at: string} */
    public function fetch(): array
    {
        if (! config('sevtech.enabled')) {
            throw new RuntimeException('Импорт SevTech отключён (SEVTECH_FUEL_ENABLED=false)');
        }

        $url = config('sevtech.base_url').config('sevtech.stations_path');

        $response = Http::withHeaders([
            'Accept' => 'application/json, text/plain, */*',
            'User-Agent' => config('sevtech.user_agent'),
            'Referer' => config('sevtech.base_url').'/map/',
            'Origin' => config('sevtech.base_url'),
        ])
            ->timeout(45)
            ->get($url);

        if ($response->status() === 403) {
            throw new RuntimeException(
                'SevTech map вернул 403. API доступен только с разрешённых IP — запускайте синхронизацию с VPS в РФ/Крыму.',
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'SevTech map: HTTP '.$response->status().($response->body() ? ' — '.mb_substr(trim($response->body()), 0, 180) : ''),
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('SevTech map вернул не JSON');
        }

        $items = $this->normalizeList($payload);

        if ($items === []) {
            throw new RuntimeException('SevTech map: не удалось разобрать список АЗС — проверьте формат API');
        }

        return [
            'items' => $items,
            'raw' => $payload,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeList(array $payload): array
    {
        $rows = $this->extractRows($payload);
        $items = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeRow($row, $index);

            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return $items;
    }

    /** @return list<mixed> */
    private function extractRows(array $payload): array
    {
        if (isset($payload['gas_stations']) && is_array($payload['gas_stations'])) {
            return $payload['gas_stations'];
        }

        foreach (['data', 'stations', 'items', 'points', 'features', 'results'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return array_is_list($payload) ? $payload : [];
    }

    /** @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, int $index): ?array
    {
        if ($this->isSevtechStation($row)) {
            return $this->normalizeSevtechRow($row);
        }

        $geometry = is_array($row['geometry'] ?? null) ? $row['geometry'] : [];
        $coordinates = is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [];
        $properties = is_array($row['properties'] ?? null) ? $row['properties'] : $row;

        $externalId = $this->pickString($properties, ['uuid', 'id', 'station_id', 'stationId'])
            ?? $this->pickString($row, ['uuid', 'id', 'station_id', 'stationId'])
            ?? (string) ($index + 1);

        $name = $this->pickString($properties, ['title', 'name', 'station_name', 'label'])
            ?? $this->pickString($row, ['title', 'name', 'station_name', 'label']);

        $address = $this->pickString($properties, ['address', 'addr', 'location', 'street'])
            ?? $this->pickString($row, ['address', 'addr', 'location', 'street']);

        if ($name === null && $address === null) {
            return null;
        }

        $latLng = is_array($properties['lat_lng'] ?? null) ? $properties['lat_lng'] : [];
        if ($latLng === [] && is_array($row['lat_lng'] ?? null)) {
            $latLng = $row['lat_lng'];
        }

        $latitude = isset($latLng['lat']) && is_numeric($latLng['lat'])
            ? (float) $latLng['lat']
            : ($this->pickFloat($properties, ['latitude', 'lat', 'y'])
                ?? $this->pickFloat($row, ['latitude', 'lat', 'y'])
                ?? (isset($coordinates[1]) ? (float) $coordinates[1] : null));

        $longitude = isset($latLng['lng']) && is_numeric($latLng['lng'])
            ? (float) $latLng['lng']
            : ($this->pickFloat($properties, ['longitude', 'lng', 'lon', 'x'])
                ?? $this->pickFloat($row, ['longitude', 'lng', 'lon', 'x'])
                ?? (isset($coordinates[0]) ? (float) $coordinates[0] : null));

        $fuels = $this->extractFuels($properties);

        if ($fuels === []) {
            $fuels = $this->extractFuels($row);
        }

        if ($fuels === []) {
            return null;
        }

        return [
            'external_id' => 'sevtech:'.$externalId,
            'name' => $name ?? $address ?? 'АЗС',
            'address' => $address,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'network' => config('sevtech.network_hint'),
            'fuels' => $fuels,
            'inventory_at' => $this->pickString($row, ['last_inventory_at']) ?? $this->pickString($properties, ['last_inventory_at']),
        ];
    }

    /** @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeSevtechRow(array $row): ?array
    {
        $externalId = $this->pickString($row, ['uuid', 'id']) ?? 'unknown';
        $title = $this->pickString($row, ['title']) ?? 'АЗС';
        $address = $this->pickString($row, ['address']);
        $latLng = is_array($row['lat_lng'] ?? null) ? $row['lat_lng'] : [];
        $fuels = $this->extractSevtechFuels($row);

        if ($fuels === []) {
            return null;
        }

        return [
            'external_id' => 'sevtech:'.$externalId,
            'name' => $title,
            'address' => $address,
            'latitude' => isset($latLng['lat']) ? (float) $latLng['lat'] : null,
            'longitude' => isset($latLng['lng']) ? (float) $latLng['lng'] : null,
            'network' => config('sevtech.network_hint'),
            'fuels' => $fuels,
            'inventory_at' => $this->pickString($row, ['last_inventory_at']),
        ];
    }

    /** @param  array<string, mixed>  $row
     * @return list<array{fuel_type: string, status: string, sale_types: list<string>, fill_percent: int|null}>
     */
    private function extractSevtechFuels(array $row): array
    {
        $fields = [
            FuelType::A92->value => 'a92',
            FuelType::A95->value => 'a95',
            FuelType::A95Plus->value => 'a95_ultra',
            FuelType::A100->value => 'a100',
            FuelType::Dt->value => 'diesel',
            FuelType::DtPlus->value => 'diesel_ultra',
            FuelType::Gas->value => 'lpg',
        ];

        $mapped = [];

        foreach ($fields as $fuelType => $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $percent = isset($row[$key.'_percent']) && is_numeric($row[$key.'_percent'])
                ? (int) $row[$key.'_percent']
                : null;

            $status = $this->normalizeSevtechStatus($row[$key], $percent);

            if ($status === null) {
                continue;
            }

            $mapped[] = [
                'fuel_type' => $fuelType,
                'status' => $status,
                'sale_types' => [SaleType::Qr->value],
                'fill_percent' => $percent,
            ];
        }

        return $this->dedupeFuels($mapped);
    }

    private function normalizeSevtechStatus(mixed $raw, ?int $percent): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        return match ($raw) {
            'FUEL_STATUS_UNAVAILABLE' => null,
            'FUEL_STATUS_OUT_OF_STOCK' => FuelStatus::None->value,
            'FUEL_STATUS_AVAILABLE' => $this->statusFromPercent($percent),
            default => $this->normalizeStatus($raw),
        };
    }

    private function statusFromPercent(?int $percent): string
    {
        $threshold = (int) config('sevtech.low_percent_threshold', 25);

        if ($percent !== null && $percent < $threshold) {
            return FuelStatus::Low->value;
        }

        return FuelStatus::Available->value;
    }

    /** @param  array<string, mixed>  $row
     * @return list<array{fuel_type: string, status: string, sale_types: list<string>}>
     */
    private function extractFuels(array $row): array
    {
        if ($this->isSevtechStation($row)) {
            return $this->extractSevtechFuels($row);
        }

        if (isset($row['fuels']) && is_array($row['fuels'])) {
            return $this->parseFuelList($row['fuels']);
        }

        if (isset($row['availability']) && is_array($row['availability'])) {
            return $this->parseFuelMap($row['availability']);
        }

        $mapped = [];

        foreach ($this->fuelKeyMap() as $fuelType => $keys) {
            $status = null;

            foreach ($keys as $key) {
                if (! array_key_exists($key, $row)) {
                    continue;
                }

                $status = $this->normalizeStatus($row[$key]);

                if ($status !== null) {
                    break;
                }
            }

            if ($status !== null) {
                $mapped[] = [
                    'fuel_type' => $fuelType,
                    'status' => $status,
                    'sale_types' => [SaleType::Qr->value],
                ];
            }
        }

        return $this->dedupeFuels($mapped);
    }

    /** @param  array<string, mixed>  $row */
    private function isSevtechStation(array $row): bool
    {
        foreach (['a92', 'a95', 'diesel'] as $key) {
            if (! isset($row[$key]) || ! is_string($row[$key])) {
                continue;
            }

            if (str_starts_with($row[$key], 'FUEL_STATUS_')) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int|string, mixed>  $fuels
     * @return list<array{fuel_type: string, status: string, sale_types: list<string>}>
     */
    private function parseFuelList(array $fuels): array
    {
        $mapped = [];

        foreach ($fuels as $fuel) {
            if (! is_array($fuel)) {
                continue;
            }

            $fuelType = $this->normalizeFuelType(
                $this->pickString($fuel, ['fuel_type', 'type', 'code', 'name', 'label', 'fuel']),
            );

            if ($fuelType === null) {
                continue;
            }

            $status = $this->normalizeStatus(
                $fuel['status']
                    ?? $fuel['available']
                    ?? $fuel['is_available']
                    ?? $fuel['value']
                    ?? $fuel['state']
                    ?? null,
            );

            if ($status === null) {
                continue;
            }

            $mapped[] = [
                'fuel_type' => $fuelType,
                'status' => $status,
                'sale_types' => [SaleType::Qr->value],
            ];
        }

        return $this->dedupeFuels($mapped);
    }

    /** @param  array<string, mixed>  $map
     * @return list<array{fuel_type: string, status: string, sale_types: list<string>}>
     */
    private function parseFuelMap(array $map): array
    {
        $mapped = [];

        foreach ($map as $key => $value) {
            $fuelType = $this->normalizeFuelType(is_string($key) ? $key : null);

            if ($fuelType === null) {
                continue;
            }

            $status = $this->normalizeStatus($value);

            if ($status === null) {
                continue;
            }

            $mapped[] = [
                'fuel_type' => $fuelType,
                'status' => $status,
                'sale_types' => [SaleType::Qr->value],
            ];
        }

        return $this->dedupeFuels($mapped);
    }

    /** @return array<string, list<string>> */
    private function fuelKeyMap(): array
    {
        return [
            FuelType::A92->value => ['a92', 'ai92', 'ai_92', '92'],
            FuelType::A95->value => ['a95', 'ai95', 'ai_95', '95'],
            FuelType::A95Plus->value => ['a95_ultra', 'a95_plus', 'a95plus'],
            FuelType::A100->value => ['a100', 'ai100', '100'],
            FuelType::Dt->value => ['diesel', 'dt'],
            FuelType::DtPlus->value => ['diesel_ultra', 'dt_plus', 'dtplus'],
            FuelType::Gas->value => ['lpg', 'gas'],
        ];
    }

    private function normalizeFuelType(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = mb_strtolower(trim(str_replace([' ', '-', '.'], ['', '_', ''], $raw)));

        return match (true) {
            in_array($value, ['a92', 'ai92', '92'], true) => FuelType::A92->value,
            in_array($value, ['a95', 'ai95', '95'], true) => FuelType::A95->value,
            in_array($value, ['a95_ultra', 'a95plus'], true) => FuelType::A95Plus->value,
            in_array($value, ['a100', 'ai100', '100'], true) => FuelType::A100->value,
            in_array($value, ['diesel', 'dt', 'дт'], true) => FuelType::Dt->value,
            in_array($value, ['diesel_ultra', 'dt_plus', 'dtplus'], true) => FuelType::DtPlus->value,
            in_array($value, ['lpg', 'gas', 'газ'], true) => FuelType::Gas->value,
            FuelType::tryFrom($value) !== null => $value,
            default => null,
        };
    }

    private function normalizeStatus(mixed $raw): ?string
    {
        if (is_bool($raw)) {
            return $raw ? FuelStatus::Available->value : FuelStatus::None->value;
        }

        if (is_numeric($raw)) {
            return ((int) $raw) > 0 ? FuelStatus::Available->value : FuelStatus::None->value;
        }

        if (! is_string($raw)) {
            return null;
        }

        $value = mb_strtolower(trim($raw));

        return match (true) {
            str_starts_with($value, 'fuel_status_available') => FuelStatus::Available->value,
            str_starts_with($value, 'fuel_status_out_of_stock') => FuelStatus::None->value,
            str_starts_with($value, 'fuel_status_unavailable') => null,
            in_array($value, ['1', 'true', 'yes', 'available', 'есть'], true) => FuelStatus::Available->value,
            in_array($value, ['0', 'false', 'no', 'none', 'нет'], true) => FuelStatus::None->value,
            in_array($value, ['low', 'мало'], true) => FuelStatus::Low->value,
            FuelStatus::tryFrom($value) !== null => $value,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function pickString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! isset($row[$key])) {
                continue;
            }

            $value = trim((string) $row[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function pickFloat(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! isset($row[$key]) || ! is_numeric($row[$key])) {
                continue;
            }

            return (float) $row[$key];
        }

        return null;
    }

    /** @param  list<array{fuel_type: string, status: string, sale_types: list<string>}>  $fuels
     * @return list<array{fuel_type: string, status: string, sale_types: list<string>}>
     */
    private function dedupeFuels(array $fuels): array
    {
        $seen = [];
        $result = [];

        foreach ($fuels as $fuel) {
            if (isset($seen[$fuel['fuel_type']])) {
                continue;
            }

            $seen[$fuel['fuel_type']] = true;
            $result[] = $fuel;
        }

        return $result;
    }
}
