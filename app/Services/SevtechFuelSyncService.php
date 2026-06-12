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

    /** @param  list<int>  $stationIds */
    public function sync(array $stationIds = []): array
    {
        $preview = $this->preview();
        $selectedIds = $stationIds !== [] ? array_flip($stationIds) : null;
        $created = 0;
        $skipped = 0;
        $stations = [];

        DB::transaction(function () use ($preview, $selectedIds, &$created, &$skipped, &$stations) {
            foreach ($preview['items'] as $item) {
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
            'raw_sample' => $this->rawSample($raw),
        ];
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
            5,
            isset($item['latitude']) ? (float) $item['latitude'] : null,
            isset($item['longitude']) ? (float) $item['longitude'] : null,
        );

        $stationId = $match['station']->id ?? null;
        $fuelRows = [];
        $willCreate = false;

        if ($stationId !== null) {
            $station = Station::query()->find($stationId);

            foreach ($item['fuels'] as $fuel) {
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
            foreach ($item['fuels'] as $fuel) {
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
        }

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
            'fuels' => $fuelRows,
            'will_create' => $willCreate,
            'selected' => $willCreate && $stationId !== null,
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
