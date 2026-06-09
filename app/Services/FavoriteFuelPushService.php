<?php

namespace App\Services;

use App\Enums\FuelType;
use App\Models\PushSubscriptionWatch;
use App\Models\Report;
use App\Models\Station;
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
        $fuelValue = $fuelType->value;
        $reports = Report::query()
            ->visible()
            ->where('station_id', $station->id)
            ->orderByDesc('created_at')
            ->get();

        $newFuel = $this->statusService->fuelStatus($station, $fuelType, $reports);
        $oldReports = $reports->where('id', '!=', $report->id);
        $oldFuel = $this->statusService->fuelStatus($station, $fuelType, $oldReports);

        if ($oldFuel['marker_color'] === 'green' || $newFuel['marker_color'] !== 'green') {
            Log::debug('Fuel push skipped: no green transition', [
                'station_id' => $station->id,
                'fuel_type' => $fuelValue,
                'report_id' => $report->id,
                'old_marker' => $oldFuel['marker_color'],
                'new_marker' => $newFuel['marker_color'],
            ]);
            $this->updateWatchMarkerColors($station->id, $fuelValue, $newFuel['marker_color']);

            return;
        }

        $cooldownMinutes = max(1, (int) config('notifications.fuel_push.cooldown_minutes', 45));
        $cooldownSince = now()->subMinutes($cooldownMinutes);

        $watches = PushSubscriptionWatch::query()
            ->where('station_id', $station->id)
            ->where('fuel_type', $fuelValue)
            ->where('notify_available', true)
            ->with('pushSubscription')
            ->get();

        if ($watches->isEmpty()) {
            Log::info('Fuel push skipped: no watches', [
                'station_id' => $station->id,
                'fuel_type' => $fuelValue,
                'report_id' => $report->id,
            ]);
            $this->updateWatchMarkerColors($station->id, $fuelValue, $newFuel['marker_color']);

            return;
        }

        $eligible = $watches->filter(function (PushSubscriptionWatch $watch) use ($cooldownSince) {
            return $watch->last_notified_at === null || $watch->last_notified_at->lt($cooldownSince);
        });

        if ($eligible->isEmpty()) {
            Log::info('Fuel push skipped: cooldown', [
                'station_id' => $station->id,
                'fuel_type' => $fuelValue,
                'report_id' => $report->id,
            ]);
            $this->updateWatchMarkerColors($station->id, $fuelValue, $newFuel['marker_color']);

            return;
        }

        $subscriptions = $eligible
            ->map(fn (PushSubscriptionWatch $watch) => $watch->pushSubscription)
            ->filter()
            ->unique('id')
            ->values();

        if ($subscriptions->isEmpty()) {
            Log::warning('Fuel push skipped: watches without subscriptions', [
                'station_id' => $station->id,
                'fuel_type' => $fuelValue,
            ]);
            $this->updateWatchMarkerColors($station->id, $fuelValue, $newFuel['marker_color']);

            return;
        }

        $body = $this->notificationBody($station, $fuelType);
        $url = $this->notificationUrl($station, $fuelType);
        $tag = 'sevazs-fuel-'.$station->id;

        Log::info('Fuel push sending', [
            'station_id' => $station->id,
            'fuel_type' => $fuelValue,
            'report_id' => $report->id,
            'subscriptions' => $subscriptions->count(),
        ]);

        $delivered = $this->webPush->sendTo(
            $subscriptions,
            'Севастополь Топливо',
            $body,
            $url,
            $tag,
        );

        if ($delivered === 0) {
            Log::warning('Fuel push not delivered', [
                'station_id' => $station->id,
                'fuel_type' => $fuelValue,
                'report_id' => $report->id,
            ]);

            $this->updateWatchMarkerColors($station->id, $fuelValue, $newFuel['marker_color']);

            return;
        }

        $notifiedAt = now();

        PushSubscriptionWatch::query()
            ->whereIn('id', $watches->pluck('id'))
            ->update([
                'last_notified_at' => $notifiedAt,
                'last_marker_color' => 'green',
            ]);

        Log::info('Fuel push delivered', [
            'station_id' => $station->id,
            'fuel_type' => $fuelValue,
            'delivered' => $delivered,
        ]);
    }

    private function updateWatchMarkerColors(int $stationId, string $fuelType, string $markerColor): void
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
