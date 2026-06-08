<?php

namespace App\Services;

use App\Enums\FuelType;
use App\Models\Report;
use App\Models\Station;
use App\Models\StationCorrection;
use Illuminate\Support\Collection;

class StationStatsService
{
    public function __construct(private StationStatusService $statusService) {}

    /** @return array<string, mixed> */
    public function summary(?FuelType $fuel = null): array
    {
        $fuel ??= FuelType::A95;

        $stations = Station::query()
            ->where('is_active', true)
            ->orderBy('network')
            ->orderBy('name')
            ->get();

        $reports = Report::query()
            ->visible()
            ->whereIn('station_id', $stations->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('station_id');

        $statusCounts = [
            'available' => 0,
            'low' => 0,
            'none' => 0,
            'unknown' => 0,
        ];

        $markerCounts = [
            'green' => 0,
            'yellow' => 0,
            'red' => 0,
            'black' => 0,
        ];

        foreach ($stations as $station) {
            $stationReports = $reports->get($station->id, collect());
            $fuelData = $this->statusService->fuelStatus($station, $fuel, $stationReports);

            $statusCounts[$fuelData['status']]++;
            $markerCounts[$fuelData['marker_color']]++;
        }

        $networks = $stations
            ->groupBy('network')
            ->map(fn (Collection $group, string $network) => [
                'network' => $network,
                'stations_count' => $group->count(),
            ])
            ->sortByDesc('stations_count')
            ->values()
            ->all();

        $fuelsOverview = collect(FuelType::all())
            ->map(function (FuelType $type) use ($stations, $reports) {
                $counts = ['available' => 0, 'low' => 0, 'none' => 0, 'unknown' => 0];

                foreach ($stations as $station) {
                    $stationReports = $reports->get($station->id, collect());
                    $status = $this->statusService->fuelStatus($station, $type, $stationReports)['status'];
                    $counts[$status]++;
                }

                return [
                    'fuel_type' => $type->value,
                    'label' => $type->label(),
                    'counts' => $counts,
                ];
            })
            ->values()
            ->all();

        return [
            'fuel' => $fuel->value,
            'fuel_label' => $fuel->label(),
            'stations_total' => $stations->count(),
            'reports_24h' => Report::query()->visible()->where('created_at', '>=', now()->subDay())->count(),
            'pending_corrections' => StationCorrection::query()->where('status', 'pending')->count(),
            'status_counts' => $statusCounts,
            'marker_counts' => $markerCounts,
            'networks' => $networks,
            'fuels_overview' => $fuelsOverview,
        ];
    }
}
