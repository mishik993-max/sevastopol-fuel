<?php

namespace App\Services;

use App\Enums\FuelType;
use App\Models\PushSubscription;
use App\Models\PushSubscriptionWatch;
use App\Models\Report;
use App\Models\Station;

class PushWatchService
{
    public function __construct(private StationStatusService $statusService) {}

    public function findSubscription(string $endpoint): ?PushSubscription
    {
        return PushSubscription::query()->where('endpoint', $endpoint)->first();
    }

    public function sync(PushSubscription $subscription, array $stationIds, FuelType $fuelType): int
    {
        $stationIds = collect($stationIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->take(7)
            ->values();

        $validIds = Station::query()
            ->where('is_active', true)
            ->whereIn('id', $stationIds)
            ->pluck('id');

        PushSubscriptionWatch::query()
            ->where('push_subscription_id', $subscription->id)
            ->whereNotIn('station_id', $validIds)
            ->delete();

        if ($validIds->isEmpty()) {
            return 0;
        }

        $reportsByStation = Report::query()
            ->visible()
            ->whereIn('station_id', $validIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('station_id');

        foreach ($validIds as $stationId) {
            $station = Station::query()->find($stationId);

            if ($station === null) {
                continue;
            }

            $reports = $reportsByStation->get($stationId, collect());
            $markerColor = $this->statusService->fuelStatus($station, $fuelType, $reports)['marker_color'];

            PushSubscriptionWatch::query()->updateOrCreate(
                [
                    'push_subscription_id' => $subscription->id,
                    'station_id' => $stationId,
                ],
                [
                    'fuel_type' => $fuelType,
                    'notify_available' => true,
                    'last_marker_color' => $markerColor,
                ],
            );
        }

        return PushSubscriptionWatch::query()
            ->where('push_subscription_id', $subscription->id)
            ->count();
    }

    public function clear(PushSubscription $subscription): void
    {
        PushSubscriptionWatch::query()
            ->where('push_subscription_id', $subscription->id)
            ->delete();
    }
}
