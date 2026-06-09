<?php

namespace App\Services;

use App\Enums\FuelType;
use App\Models\PushSubscription;
use App\Models\PushSubscriptionWatch;
use App\Models\Report;
use App\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FavoriteFuelPushService
{
    public function __construct(
        private StationStatusService $statusService,
        private WebPushService $webPush,
    ) {}

    public function handleReport(Report $report): void
    {
        if ($report->is_confirmation || $report->is_hidden) {
            return;
        }

        $station = $report->station ?? Station::query()->find($report->station_id);

        if ($station === null || ! $station->is_active) {
            return;
        }

        $fuelType = $report->fuel_type;
        $reports = Report::query()
            ->visible()
            ->where('station_id', $station->id)
            ->orderByDesc('created_at')
            ->get();

        $newFuel = $this->statusService->fuelStatus($station, $fuelType, $reports);
        $oldReports = $reports->where('id', '!=', $report->id);
        $oldFuel = $this->statusService->fuelStatus($station, $fuelType, $oldReports);

        $this->updateWatchMarkerColors($station->id, $fuelType, $newFuel['marker_color']);

        if ($oldFuel['marker_color'] === 'green' || $newFuel['marker_color'] !== 'green') {
            return;
        }

        $cooldownMinutes = max(1, (int) config('notifications.fuel_push.cooldown_minutes', 45));
        $cooldownSince = now()->subMinutes($cooldownMinutes);

        $watches = PushSubscriptionWatch::query()
            ->where('station_id', $station->id)
            ->where('fuel_type', $fuelType)
            ->where('notify_available', true)
            ->with('pushSubscription')
            ->get();

        $eligible = $watches->filter(function (PushSubscriptionWatch $watch) use ($cooldownSince) {
            return $watch->last_notified_at === null || $watch->last_notified_at->lt($cooldownSince);
        });

        if ($eligible->isEmpty()) {
            return;
        }

        $subscriptions = $eligible
            ->map(fn (PushSubscriptionWatch $watch) => $watch->pushSubscription)
            ->filter()
            ->unique('id')
            ->values();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $body = $this->notificationBody($station, $fuelType);
        $url = $this->notificationUrl($station, $fuelType);
        $tag = 'sevazs-fuel-'.$station->id;

        $delivered = $this->webPush->sendTo(
            $subscriptions,
            'Севастополь Топливо',
            $body,
            $url,
            $tag,
        );

        if ($delivered === 0) {
            Log::info('Favorite fuel push queued but not delivered', [
                'station_id' => $station->id,
                'fuel_type' => $fuelType->value,
                'report_id' => $report->id,
            ]);

            return;
        }

        $notifiedAt = now();

        PushSubscriptionWatch::query()
            ->whereIn('id', $watches->pluck('id'))
            ->update([
                'last_notified_at' => $notifiedAt,
                'last_marker_color' => 'green',
            ]);
    }

    private function updateWatchMarkerColors(int $stationId, FuelType $fuelType, string $markerColor): void
    {
        PushSubscriptionWatch::query()
            ->where('station_id', $stationId)
            ->where('fuel_type', $fuelType)
            ->update(['last_marker_color' => $markerColor]);
    }

    private function notificationBody(Station $station, FuelType $fuelType): string
    {
        $network = trim($station->network);
        $name = trim($station->name);

        if ($name === '' || strcasecmp($name, $network) === 0) {
            return sprintf('На %s появился %s', $network, $fuelType->label());
        }

        return sprintf('На %s «%s» появился %s', $network, $name, $fuelType->label());
    }

    private function notificationUrl(Station $station, FuelType $fuelType): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/?station='.$station->id.'&fuel='.$fuelType->value;
    }
}
