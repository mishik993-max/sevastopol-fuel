<?php

namespace App\Services;

use App\Models\Station;
use App\Support\OsmFuelStatus;
use Illuminate\Support\Facades\Http;

class StationOsmSyncService
{
    public function __construct(private StationClosureService $closureService) {}

    /** @return array{checked: int, deactivated: int, reactivated: int, failed: int} */
    public function syncAll(): array
    {
        $stats = [
            'checked' => 0,
            'deactivated' => 0,
            'reactivated' => 0,
            'failed' => 0,
        ];

        $stations = Station::query()
            ->where('source', 'osm')
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->get();

        foreach ($stations as $station) {
            if (! $this->canAutoSync($station)) {
                continue;
            }

            $stats['checked']++;

            $tags = $this->fetchTags($station->external_id);

            if ($tags === null) {
                $stats['failed']++;

                continue;
            }

            $closedReason = OsmFuelStatus::closedReason($tags);

            if ($closedReason !== null && $station->is_active) {
                $this->closureService->deactivate($station, $closedReason);
                $stats['deactivated']++;

                continue;
            }

            if ($closedReason === null && ! $station->is_active && OsmFuelStatus::isOsmClosureReason($station->closed_reason)) {
                $this->closureService->reactivate($station);
                $stats['reactivated']++;
            }

            usleep(1_100_000);
        }

        return $stats;
    }

    /** @return array<string, string>|null */
    public function fetchTags(string $externalId): ?array
    {
        [$type, $id] = array_pad(explode('/', $externalId, 2), 2, null);

        $osmType = match ($type) {
            'node' => 'N',
            'way' => 'W',
            'relation' => 'R',
            default => null,
        };

        if ($osmType === null || $id === null || ! ctype_digit((string) $id)) {
            return null;
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'sevastopol-fuel/1.0 (local fuel map)',
                'Accept-Language' => 'ru',
            ])
            ->get('https://nominatim.openstreetmap.org/details.php', [
                'osmtype' => $osmType,
                'osmid' => $id,
                'format' => 'json',
                'extratags' => 1,
                'addressdetails' => 0,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        /** @var array<string, string> $tags */
        $tags = $payload['extratags'] ?? [];

        if (($payload['type'] ?? null) === 'fuel' || ($payload['category'] ?? null) === 'amenity') {
            $tags['amenity'] = $tags['amenity'] ?? 'fuel';
        }

        if (! empty($payload['localname']) && ! isset($tags['name'])) {
            $tags['name'] = (string) $payload['localname'];
        }

        return $tags;
    }

    public function canAutoSync(Station $station): bool
    {
        if ($station->is_active) {
            return true;
        }

        return OsmFuelStatus::isOsmClosureReason($station->closed_reason);
    }

    public function applyTagsToStation(Station $station, array $tags): void
    {
        if (! $this->canAutoSync($station)) {
            return;
        }

        $closedReason = OsmFuelStatus::closedReason($tags);

        if ($closedReason !== null && $station->is_active) {
            $this->closureService->deactivate($station, $closedReason);
        }
    }
}
