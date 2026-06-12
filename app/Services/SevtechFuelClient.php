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
        $geometry = is_array($row['geometry'] ?? null) ? $row['geometry'] : [];
        $coordinates = is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [];
        $properties = is_array($row['properties'] ?? null) ? $row['properties'] : $row;

        $externalId = $this->pickString($properties, ['id', 'station_id', 'stationId', 'azs_id', 'azsId', 'uuid'])
            ?? $this->pickString($row, ['id', 'station_id', 'stationId', 'azs_id', 'azsId', 'uuid'])
            ?? (string) ($index + 1);

        $name = $this->pickString($properties, ['name', 'title', 'station_name', 'stationName', 'label'])
            ?? $this->pickString($row, ['name', 'title', 'station_name', 'stationName', 'label']);

        $address = $this->pickString($properties, ['address', 'addr', 'location', 'street'])
            ?? $this->pickString($row, ['address', 'addr', 'location', 'street']);

        if ($name === null && $address === null) {
            return null;
        }

        $latitude = $this->pickFloat($properties, ['latitude', 'lat', 'y'])
            ?? $this->pickFloat($row, ['latitude', 'lat', 'y'])
            ?? (isset($coordinates[1]) ? (float) $coordinates[1] : null);

        $longitude = $this->pickFloat($properties, ['longitude', 'lng', 'lon', 'x'])
            ?? $this->pickFloat($row, ['longitude', 'lng', 'lon', 'x'])
            ?? (isset($coordinates[0]) ? (float) $coordinates[0] : null);

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
        ];
    }

    /** @param  array<string, mixed>  $row
     * @return list<array{fuel_type: string, status: string, sale_types: list<string>}>
     */
    private function extractFuels(array $row): array
    {
        if (isset($row['fuels']) && is_array($row['fuels'])) {
            return $this->parseFuelList($row['fuels']);
        }

        if (isset($row['fuel']) && is_array($row['fuel'])) {
            return $this->parseFuelList($row['fuel']);
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
            FuelType::A92->value => ['a92', 'ai92', 'ai_92', '92', 'has_a92', 'hasAi92', 'has92', 'gasoline92', 'petrol92'],
            FuelType::A95->value => ['a95', 'ai95', 'ai_95', '95', 'has_a95', 'hasAi95', 'has95', 'gasoline95', 'petrol95'],
            FuelType::A95Plus->value => ['a95_plus', 'a95plus', 'ai95_plus', '95_plus', '95plus', 'has_a95_plus'],
            FuelType::Dt->value => ['dt', 'diesel', 'has_dt', 'hasDt'],
            FuelType::DtPlus->value => ['dt_plus', 'dtplus', 'diesel_plus'],
            FuelType::Gas->value => ['gas', 'lpg', 'propane'],
        ];
    }

    private function normalizeFuelType(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = mb_strtolower(trim(str_replace([' ', '-', '.'], ['', '_', ''], $raw)));

        return match (true) {
            in_array($value, ['a92', 'ai92', '92', 'а92', 'аи92', 'бензин92'], true) => FuelType::A92->value,
            in_array($value, ['a95', 'ai95', '95', 'а95', 'аи95'], true) => FuelType::A95->value,
            str_contains($value, '95plus') || str_contains($value, '95_plus') || str_contains($value, 'ultra') => FuelType::A95Plus->value,
            in_array($value, ['dt', 'diesel', 'дт', 'диз'], true) => FuelType::Dt->value,
            str_contains($value, 'dtplus') || str_contains($value, 'dt_plus') => FuelType::DtPlus->value,
            in_array($value, ['gas', 'lpg', 'propane', 'газ'], true) => FuelType::Gas->value,
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
            in_array($value, ['1', 'true', 'yes', 'y', 'available', 'есть', 'вналичии', 'да', 'on', 'open'], true) => FuelStatus::Available->value,
            in_array($value, ['0', 'false', 'no', 'n', 'none', 'нет', 'отсутствует', 'off', 'closed'], true) => FuelStatus::None->value,
            in_array($value, ['low', 'мало', 'малоосталось'], true) => FuelStatus::Low->value,
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
