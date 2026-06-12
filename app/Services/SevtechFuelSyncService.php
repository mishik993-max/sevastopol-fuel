<?php

namespace App\Services;

use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\QueueSize;
use App\Models\Report;
use App\Models\Station;
use Illuminate\Support\Facades\DB;

class SevtechFuelSyncService
{
    private const COMMENT_PREFIX = 'Импорт SevTech map (fuel.sevtech.org)';

    public function __construct(
        private SevtechFuelClient $client,
        private StationMatcher $matcher,
    ) {}

    /** @return array<string, mixed> */
    public function preview(): array
    {
        $fetched = $this->client->fetch();

        return $this->buildPreview($fetched['items'], $fetched['fetched_at'], $fetched['raw']);
    }

    /** @param  list<int>  $stationIds
     * @param  list<array<string, mixed>>  $items
     */
    public function sync(array $stationIds = [], array $items = []): array
    {
        if ($items !== []) {
            return $this->syncExplicitItems($items);
        }

        $preview = $this->preview();
        $selectedIds = $stationIds !== [] ? array_flip($stationIds) : null;

        return $this->syncPreviewItems($preview['items'], $selectedIds);
    }

    /** @param  list<array<string, mixed>>  $sevtechFuels
     * @return array<string, mixed>
     */
    public function resolveFuels(int $stationId, array $sevtechFuels): array
    {
        $station = Station::query()->find($stationId);

        if ($station === null) {
            throw new \InvalidArgumentException('АЗС не найдена');
        }

        $normalized = array_map(function (array $fuel) {
            $status = $fuel['status'] ?? $fuel['new_status'] ?? null;

            return [
                'fuel_type' => $fuel['fuel_type'],
                'status' => $status,
                'sale_types' => $fuel['sale_types'] ?? ['qr'],
            ];
        }, $sevtechFuels);

        $fuelPreview = $this->buildFuelPreview($stationId, $normalized);

        return [
            'station_id' => $stationId,
            'station_label' => "{$station->network} · {$station->name}",
            'station_address' => $station->address,
            'fuels' => $fuelPreview['fuels'],
            'will_create' => $fuelPreview['will_create'],
            'selected' => $fuelPreview['will_create'],
        ];
    }

    /** @param  list<array<string, mixed>>  $items
     * @return array{created: int, skipped: int, stations: list<string>}
     */
    private function syncExplicitItems(array $items): array
    {
        $created = 0;
        $skipped = 0;
        $stations = [];

        DB::transaction(function () use ($items, &$created, &$skipped, &$stations) {
            foreach ($items as $item) {
                $stationId = (int) ($item['station_id'] ?? 0);
                $station = Station::query()->find($stationId);

                if ($station === null) {
                    continue;
                }

                foreach ($item['fuels'] as $fuel) {
                    if (! ($fuel['changed'] ?? false)) {
                        continue;
                    }

                    $newStatus = (string) ($fuel['new_status'] ?? $fuel['status'] ?? '');

                    if ($newStatus === '') {
                        continue;
                    }

                    if ($this->recentSevtechReportExists($station->id, $fuel['fuel_type'], $newStatus)) {
                        $skipped++;

                        continue;
                    }

                    Report::query()->create([
                        'station_id' => $station->id,
                        'fuel_type' => FuelType::from($fuel['fuel_type']),
                        'status' => FuelStatus::from($newStatus),
                        'statuses' => [$newStatus],
                        'queue_size' => QueueSize::Unknown,
                        'sale_types' => $fuel['sale_types'] ?? ['qr'],
                        'comment' => self::COMMENT_PREFIX,
                        'is_confirmation' => false,
                        'created_at' => now(),
                    ]);

                    $created++;
                }

                $stations[] = "{$station->network} · {$station->name}";
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'stations' => array_values(array_unique($stations)),
        ];
    }

    /** @param  list<array<string, mixed>>  $items
     * @param  array<int, int>|null  $selectedIds
     * @return array{created: int, skipped: int, stations: list<string>}
     */
    private function syncPreviewItems(array $items, ?array $selectedIds): array
    {
        $created = 0;
        $skipped = 0;
        $stations = [];

        DB::transaction(function () use ($items, $selectedIds, &$created, &$skipped, &$stations) {
            foreach ($items as $item) {
                if ($item['station_id'] === null) {
                    continue;
                }

                if ($selectedIds !== null && ! isset($selectedIds[$item['station_id']])) {
                    continue;
                }

                if (! $item['will_create']) {
                    $skipped++;

                    continue;
                }

                $station = Station::query()->find($item['station_id']);

                if ($station === null) {
                    continue;
                }

                foreach ($item['fuels'] as $fuel) {
                    if (! ($fuel['changed'] ?? false)) {
                        continue;
                    }

                    if ($this->recentSevtechReportExists($station->id, $fuel['fuel_type'], $fuel['new_status'])) {
                        $skipped++;

                        continue;
                    }

                    Report::query()->create([
                        'station_id' => $station->id,
                        'fuel_type' => FuelType::from($fuel['fuel_type']),
                        'status' => FuelStatus::from($fuel['new_status']),
                        'statuses' => [$fuel['new_status']],
                        'queue_size' => QueueSize::Unknown,
                        'sale_types' => $fuel['sale_types'],
                        'comment' => self::COMMENT_PREFIX,
                        'is_confirmation' => false,
                        'created_at' => now(),
                    ]);

                    $created++;
                }

                $stations[] = "{$station->network} · {$station->name}";
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'stations' => array_values(array_unique($stations)),
        ];
    }

    /** @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function buildPreview(array $items, string $fetchedAt, mixed $raw): array
    {
        $rows = [];
        $matched = 0;
        $unmatched = 0;
        $willCreate = 0;
        $unchanged = 0;

        foreach ($items as $item) {
            $row = $this->previewRow($item);
            $rows[] = $row;

            if ($row['station_id'] !== null) {
                $matched++;
            } else {
                $unmatched++;
            }

            if ($row['will_create']) {
                $willCreate++;
            } elseif ($row['station_id'] !== null) {
                $unchanged++;
            }
        }

        return [
            'fetched_at' => $fetchedAt,
            'source_url' => config('sevtech.base_url').config('sevtech.stations_path'),
            'summary' => [
                'total' => count($rows),
                'matched' => $matched,
                'unmatched' => $unmatched,
                'will_create' => $willCreate,
                'unchanged' => $unchanged,
            ],
            'items' => $rows,
            'station_catalog' => $this->stationCatalog(),
            'raw_sample' => $this->rawSample($raw),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function stationCatalog(): array
    {
        return Station::query()
            ->where('is_active', true)
            ->orderBy('network')
            ->orderBy('name')
            ->get()
            ->map(fn (Station $station) => [
                'station_id' => $station->id,
                'label' => "{$station->network} · {$station->name}",
                'address' => $station->address,
                'latitude' => $station->latitude !== null ? (float) $station->latitude : null,
                'longitude' => $station->longitude !== null ? (float) $station->longitude : null,
            ])
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function previewRow(array $item): array
    {
        $match = $this->matcher->bestMatch(
            (string) ($item['network'] ?? config('sevtech.network_hint')),
            (string) $item['name'],
            $item['address'] ?? null,
            isset($item['latitude']) ? (float) $item['latitude'] : null,
            isset($item['longitude']) ? (float) $item['longitude'] : null,
        );

        $candidates = $this->matcher->candidates(
            (string) ($item['network'] ?? config('sevtech.network_hint')),
            (string) $item['name'],
            $item['address'] ?? null,
            8,
            isset($item['latitude']) ? (float) $item['latitude'] : null,
            isset($item['longitude']) ? (float) $item['longitude'] : null,
        );

        $stationId = $match['station']->id ?? null;
        $fuelPreview = $this->buildFuelPreview($stationId, $item['fuels']);

        return [
            'external_id' => $item['external_id'],
            'name' => $item['name'],
            'address' => $item['address'],
            'latitude' => $item['latitude'],
            'longitude' => $item['longitude'],
            'station_id' => $stationId,
            'station_label' => $match
                ? "{$match['station']->network} · {$match['station']->name}"
                : null,
            'station_address' => $match ? $match['station']->address : null,
            'confidence' => $match['score'] ?? null,
            'match_type' => $match['match_type'] ?? null,
            'match_distance_m' => $match['distance_m'] ?? null,
            'candidates' => array_map(fn (array $candidate) => [
                'station_id' => $candidate['station']->id,
                'label' => "{$candidate['station']->network} · {$candidate['station']->name}",
                'address' => $candidate['station']->address,
                'score' => $candidate['score'],
                'match_type' => $candidate['match_type'],
                'distance_m' => $candidate['distance_m'] ?? null,
            ], $candidates),
            'fuels' => $fuelPreview['fuels'],
            'will_create' => $fuelPreview['will_create'],
            'selected' => $fuelPreview['will_create'] && $stationId !== null,
        ];
    }

    /** @param  list<array<string, mixed>>  $sevtechFuels
     * @return array{fuels: list<array<string, mixed>>, will_create: bool}
     */
    private function buildFuelPreview(?int $stationId, array $sevtechFuels): array
    {
        $fuelRows = [];
        $willCreate = false;

        if ($stationId !== null) {
            $station = Station::query()->find($stationId);

            foreach ($sevtechFuels as $fuel) {
                $currentStatus = $this->currentStatus($station, FuelType::from($fuel['fuel_type']));
                $newStatus = $fuel['status'];
                $changed = $currentStatus?->value !== $newStatus;

                if ($changed && ! $this->recentSevtechReportExists($stationId, $fuel['fuel_type'], $newStatus)) {
                    $willCreate = true;
                }

                $fuelRows[] = [
                    'fuel_type' => $fuel['fuel_type'],
                    'fuel_label' => FuelType::from($fuel['fuel_type'])->label(),
                    'current_status' => $currentStatus?->value,
                    'current_status_label' => $currentStatus?->label() ?? '—',
                    'new_status' => $newStatus,
                    'new_status_label' => FuelStatus::from($newStatus)->label(),
                    'sale_types' => $fuel['sale_types'],
                    'changed' => $changed,
                ];
            }
        } else {
            foreach ($sevtechFuels as $fuel) {
                $fuelRows[] = [
                    'fuel_type' => $fuel['fuel_type'],
                    'fuel_label' => FuelType::from($fuel['fuel_type'])->label(),
                    'current_status' => null,
                    'current_status_label' => '—',
                    'new_status' => $fuel['status'],
                    'new_status_label' => FuelStatus::from($fuel['status'])->label(),
                    'sale_types' => $fuel['sale_types'],
                    'changed' => true,
                ];
            }

            $willCreate = $fuelRows !== [];
        }

        return [
            'fuels' => $fuelRows,
            'will_create' => $willCreate,
        ];
    }

    private function currentStatus(?Station $station, FuelType $fuelType): ?FuelStatus
    {
        if ($station === null) {
            return null;
        }

        $latest = Report::query()
            ->visible()
            ->where('station_id', $station->id)
            ->where('fuel_type', $fuelType)
            ->orderByDesc('created_at')
            ->first();

        return $latest?->status;
    }

    private function recentSevtechReportExists(int $stationId, string $fuelType, string $status): bool
    {
        $recent = Report::query()
            ->where('station_id', $stationId)
            ->where('fuel_type', $fuelType)
            ->where('comment', 'like', self::COMMENT_PREFIX.'%')
            ->where('created_at', '>=', now()->subMinutes(config('sevtech.dedup_minutes')))
            ->orderByDesc('created_at')
            ->first();

        return $recent !== null && $recent->status->value === $status;
    }

    private function rawSample(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (! is_string($encoded)) {
            return null;
        }

        return mb_strlen($encoded) > 4000 ? mb_substr($encoded, 0, 4000).'…' : $encoded;
    }
}
