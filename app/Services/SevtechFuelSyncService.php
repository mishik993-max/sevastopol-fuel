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
    private const COMMENT_PREFIX = 'Официальная карта ТЭС';

    private const LEGACY_COMMENT_PREFIX = 'Импорт SevTech map';

    public function __construct(
        private SevtechFuelClient $client,
        private StationMatcher $matcher,
    ) {}

    public static function isSevtechComment(?string $comment): bool
    {
        if ($comment === null || trim($comment) === '') {
            return false;
        }

        return str_starts_with($comment, self::COMMENT_PREFIX)
            || str_starts_with($comment, self::LEGACY_COMMENT_PREFIX);
    }

    public static function sourceLabelFor(?string $comment): ?string
    {
        if (! self::isSevtechComment($comment)) {
            return null;
        }

        $marker = self::COMMENT_PREFIX.' · ';

        if (str_starts_with((string) $comment, $marker)) {
            return 'Карта ТЭС · '.mb_substr((string) $comment, mb_strlen($marker));
        }

        return 'Карта ТЭС';
    }

    /** @param  array<string, mixed>  $item */
    public static function reportCommentFor(array $item): string
    {
        $title = trim((string) ($item['name'] ?? ''));

        return $title !== ''
            ? self::COMMENT_PREFIX.' · '.$title
            : self::COMMENT_PREFIX;
    }

    /** @return array<string, mixed> */
    public function preview(): array
    {
        $fetched = $this->client->fetch();

        return $this->buildPreview($fetched['items'], $fetched['fetched_at'], $fetched['raw']);
    }

    /** @param  list<int>  $stationIds
     * @param  list<array<string, mixed>>  $items
     * @return array{created: int, skipped: int, stations: list<string>, updated_stations: list<string>}
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
     * @param  array<string, mixed>  $sevtechItem
     * @return array<string, mixed>
     */
    public function resolveFuels(int $stationId, array $sevtechFuels, array $sevtechItem = []): array
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
        $profileUpdate = $sevtechItem !== []
            ? $this->previewStationProfileUpdate($station, $sevtechItem)
            : null;

        return [
            'station_id' => $stationId,
            'station_label' => "{$station->network} · {$station->name}",
            'station_address' => $station->address,
            'station_profile_update' => $profileUpdate,
            'fuels' => $fuelPreview['fuels'],
            'will_create' => $fuelPreview['will_create'],
            'selected' => ($fuelPreview['will_create'] || $profileUpdate !== null),
        ];
    }

    /** @param  list<array<string, mixed>>  $items
     * @return array{created: int, skipped: int, stations: list<string>, updated_stations: list<string>}
     */
    private function syncExplicitItems(array $items): array
    {
        $created = 0;
        $skipped = 0;
        $stations = [];
        $updatedStations = [];

        DB::transaction(function () use ($items, &$created, &$skipped, &$stations, &$updatedStations) {
            foreach ($items as $item) {
                $stationId = (int) ($item['station_id'] ?? 0);
                $station = Station::query()->find($stationId);

                if ($station === null) {
                    continue;
                }

                $profileUpdate = $this->applySevtechStationProfile($station, $item);

                if ($profileUpdate !== null) {
                    $updatedStations[] = $profileUpdate['label'];
                }

                $comment = self::reportCommentFor($item);
                $createdForStation = false;

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
                        'comment' => $comment,
                        'is_confirmation' => false,
                        'created_at' => now(),
                    ]);

                    $created++;
                    $createdForStation = true;
                }

                if ($createdForStation || $profileUpdate !== null) {
                    $station->refresh();
                    $stations[] = "{$station->network} · {$station->name}";
                }
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'stations' => array_values(array_unique($stations)),
            'updated_stations' => array_values(array_unique($updatedStations)),
        ];
    }

    /** @param  list<array<string, mixed>>  $items
     * @param  array<int, int>|null  $selectedIds
     * @return array{created: int, skipped: int, stations: list<string>, updated_stations: list<string>}
     */
    private function syncPreviewItems(array $items, ?array $selectedIds): array
    {
        $created = 0;
        $skipped = 0;
        $stations = [];
        $updatedStations = [];

        DB::transaction(function () use ($items, $selectedIds, &$created, &$skipped, &$stations, &$updatedStations) {
            foreach ($items as $item) {
                if ($item['station_id'] === null) {
                    continue;
                }

                if ($selectedIds !== null && ! isset($selectedIds[$item['station_id']])) {
                    continue;
                }

                $station = Station::query()->find($item['station_id']);

                if ($station === null) {
                    continue;
                }

                $profileUpdate = $this->applySevtechStationProfile($station, $item);

                if ($profileUpdate !== null) {
                    $updatedStations[] = $profileUpdate['label'];
                }

                if (! $item['will_create'] && $profileUpdate === null) {
                    $skipped++;

                    continue;
                }

                $comment = self::reportCommentFor($item);
                $createdForStation = false;

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
                        'comment' => $comment,
                        'is_confirmation' => false,
                        'created_at' => now(),
                    ]);

                    $created++;
                    $createdForStation = true;
                }

                if ($createdForStation || $profileUpdate !== null) {
                    $station->refresh();
                    $stations[] = "{$station->network} · {$station->name}";
                }
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'stations' => array_values(array_unique($stations)),
            'updated_stations' => array_values(array_unique($updatedStations)),
        ];
    }

    /** @param  array<string, mixed>  $item
     * @return array{label: string, changes: array<string, array{from: string|null, to: string}>}|null
     */
    private function applySevtechStationProfile(Station $station, array $item): ?array
    {
        if (! config('sevtech.update_stations', true)) {
            return null;
        }

        $patch = $this->stationProfilePatch($station, $item);

        if ($patch === []) {
            return null;
        }

        $beforeLabel = "{$station->network} · {$station->name}";
        $station->update($patch);
        $station->refresh();

        return [
            'label' => "{$beforeLabel} → {$station->network} · {$station->name}",
            'changes' => $patch,
        ];
    }

    /** @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function stationProfilePatch(Station $station, array $item): array
    {
        $patch = [];
        $network = trim((string) ($item['network'] ?? config('sevtech.network_hint')));
        $name = trim((string) ($item['name'] ?? ''));
        $address = trim((string) ($item['address'] ?? ''));
        $externalId = trim((string) ($item['external_id'] ?? ''));

        if ($network !== '' && ! $this->textEquals($station->network, $network)) {
            $patch['network'] = $network;
        }

        if ($name !== '' && ! $this->textEquals($station->name, $name)) {
            $patch['name'] = $name;
        }

        if ($externalId !== '' && $station->external_id !== $externalId) {
            $patch['external_id'] = $externalId;
        }

        if ($address !== '' && $this->shouldUpdateAddress($station->address, $address)) {
            $patch['address'] = $address;
        }

        return $patch;
    }

    private function shouldUpdateAddress(?string $current, string $sevtechAddress): bool
    {
        $current = trim((string) $current);

        if ($current === '') {
            return true;
        }

        if ($this->textEquals($current, $sevtechAddress)) {
            return false;
        }

        $normalizedCurrent = $this->normalizeText($current);
        $normalizedSevtech = $this->normalizeText($sevtechAddress);

        return ! str_contains($normalizedCurrent, $normalizedSevtech)
            && ! str_contains($normalizedSevtech, $normalizedCurrent);
    }

    private function textEquals(?string $left, ?string $right): bool
    {
        return $this->normalizeText((string) $left) === $this->normalizeText((string) $right);
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
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
        $willUpdateProfile = 0;

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

            if ($row['station_profile_update'] !== null) {
                $willUpdateProfile++;
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
                'will_update_profile' => $willUpdateProfile,
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
        $match = $this->resolveMatch($item);
        $station = $match['station'] ?? null;
        $stationId = $station?->id;
        $fuelPreview = $this->buildFuelPreview($stationId, $item['fuels']);
        $profileUpdate = $station !== null ? $this->previewStationProfileUpdate($station, $item) : null;

        return [
            'external_id' => $item['external_id'],
            'name' => $item['name'],
            'address' => $item['address'],
            'network' => $item['network'] ?? config('sevtech.network_hint'),
            'latitude' => $item['latitude'],
            'longitude' => $item['longitude'],
            'station_id' => $stationId,
            'station_label' => $station ? "{$station->network} · {$station->name}" : null,
            'station_address' => $station?->address,
            'confidence' => $match['score'] ?? null,
            'match_type' => $match['match_type'] ?? null,
            'match_distance_m' => $match['distance_m'] ?? null,
            'station_profile_update' => $profileUpdate,
            'candidates' => array_map(fn (array $candidate) => [
                'station_id' => $candidate['station']->id,
                'label' => "{$candidate['station']->network} · {$candidate['station']->name}",
                'address' => $candidate['station']->address,
                'score' => $candidate['score'],
                'match_type' => $candidate['match_type'],
                'distance_m' => $candidate['distance_m'] ?? null,
            ], $this->matcher->candidates(
                (string) ($item['network'] ?? config('sevtech.network_hint')),
                (string) $item['name'],
                $item['address'] ?? null,
                12,
                isset($item['latitude']) ? (float) $item['latitude'] : null,
                isset($item['longitude']) ? (float) $item['longitude'] : null,
                restrictNetwork: false,
            )),
            'fuels' => $fuelPreview['fuels'],
            'will_create' => $fuelPreview['will_create'],
            'selected' => ($fuelPreview['will_create'] || $profileUpdate !== null) && $stationId !== null,
        ];
    }

    /** @param  array<string, mixed>  $item
     * @return array{station?: Station, score?: float, match_type?: string, distance_m?: int}
     */
    private function resolveMatch(array $item): array
    {
        $externalId = trim((string) ($item['external_id'] ?? ''));

        if ($externalId !== '') {
            $linked = Station::query()
                ->where('external_id', $externalId)
                ->where('is_active', true)
                ->first();

            if ($linked !== null) {
                return [
                    'station' => $linked,
                    'score' => 100.0,
                    'match_type' => 'external_id',
                ];
            }
        }

        $match = $this->matcher->bestMatch(
            (string) ($item['network'] ?? config('sevtech.network_hint')),
            (string) $item['name'],
            $item['address'] ?? null,
            isset($item['latitude']) ? (float) $item['latitude'] : null,
            isset($item['longitude']) ? (float) $item['longitude'] : null,
            restrictNetwork: false,
        );

        if ($match === null) {
            return [];
        }

        return [
            'station' => $match['station'],
            'score' => $match['score'],
            'match_type' => $match['match_type'],
            'distance_m' => $match['distance_m'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $item
     * @return array{current_label: string, new_label: string, changes: array<string, array{from: string|null, to: string}>}|null
     */
    private function previewStationProfileUpdate(Station $station, array $item): ?array
    {
        if (! config('sevtech.update_stations', true)) {
            return null;
        }

        $patch = $this->stationProfilePatch($station, $item);

        if ($patch === []) {
            return null;
        }

        $nextNetwork = $patch['network'] ?? $station->network;
        $nextName = $patch['name'] ?? $station->name;

        return [
            'current_label' => "{$station->network} · {$station->name}",
            'new_label' => "{$nextNetwork} · {$nextName}",
            'changes' => collect($patch)
                ->mapWithKeys(fn (string $value, string $field) => [$field => [
                    'from' => $station->{$field},
                    'to' => $value,
                ]])
                ->all(),
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
            ->where(function ($query) {
                $query->where('comment', 'like', self::COMMENT_PREFIX.'%')
                    ->orWhere('comment', 'like', self::LEGACY_COMMENT_PREFIX.'%');
            })
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
