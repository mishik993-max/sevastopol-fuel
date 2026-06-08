<?php

namespace App\Services;

use App\Enums\Freshness;
use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Enums\QueueSize;
use App\Enums\SaleType;
use App\Models\Report;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StationStatusService
{
    public function __construct(
        private StationClosureService $closureService,
        private StationCorrectionService $correctionService,
        private AppSettingsService $appSettings,
    ) {}

    public function freshnessFor(?Carbon $reportedAt): Freshness
    {
        if ($reportedAt === null) {
            return Freshness::Unknown;
        }

        $minutes = $reportedAt->diffInMinutes(now());

        $fresh = $this->appSettings->freshnessFreshMinutes();
        $stale = $this->appSettings->freshnessStaleMinutes();

        return match (true) {
            $minutes <= $fresh => Freshness::Fresh,
            $minutes <= $stale => Freshness::Stale,
            default => Freshness::Expired,
        };
    }

    public function freshnessLabel(Freshness $freshness, ?Carbon $reportedAt): string
    {
        if ($freshness === Freshness::Unknown || $reportedAt === null) {
            return 'Нет данных';
        }

        $minutes = $reportedAt->diffInMinutes(now());

        return match ($freshness) {
            Freshness::Fresh => $minutes < 1
                ? 'Подтверждено только что'
                : 'Подтверждено '.$this->timeAgoShort($minutes).' назад',
            Freshness::Stale => $minutes < 1
                ? 'Вероятно актуально, только что'
                : 'Вероятно актуально, '.$this->timeAgoShort($minutes).' назад',
            Freshness::Expired => 'Последнее подтверждение '.$this->timeAgoLong($reportedAt).' назад',
            Freshness::Unknown => 'Нет данных',
        };
    }

    public function markerColor(FuelStatus $status, Freshness $freshness): string
    {
        if ($status === FuelStatus::None) {
            return 'red';
        }

        if ($status === FuelStatus::Unknown || $freshness === Freshness::Unknown || $freshness === Freshness::Expired) {
            return 'black';
        }

        if ($status === FuelStatus::Available && $freshness === Freshness::Fresh) {
            return 'green';
        }

        if (in_array($status, [FuelStatus::Available, FuelStatus::Low], true) && $freshness === Freshness::Stale) {
            return 'yellow';
        }

        if ($status === FuelStatus::Low && $freshness === Freshness::Fresh) {
            return 'yellow';
        }

        return 'black';
    }

    /** @return array<string, mixed> */
    public function fuelStatus(Station $station, FuelType $fuelType, Collection $reports): array
    {
        $latest = $reports
            ->where('fuel_type', $fuelType)
            ->sortByDesc('created_at')
            ->first();

        $status = $latest?->status ?? FuelStatus::Unknown;
        $freshness = $this->freshnessFor($latest?->created_at);
        $statusLabels = FuelStatus::labelsFor($latest?->statuses);
        $saleTypeLabels = SaleType::labelsFor($latest?->sale_types);

        return [
            'fuel_type' => $fuelType->value,
            'label' => $fuelType->label(),
            'status' => $status->value,
            'statuses' => $latest?->statuses ?? [],
            'status_label' => $statusLabels ? implode(' · ', $statusLabels) : $status->label(),
            'status_labels' => $statusLabels,
            'freshness' => $freshness->value,
            'freshness_label' => $this->freshnessLabel($freshness, $latest?->created_at),
            'reported_at' => $latest?->created_at?->toIso8601String(),
            'marker_color' => $this->markerColor($status, $freshness),
            'sale_types' => $latest?->sale_types ?? [],
            'sale_type_labels' => $saleTypeLabels,
            'fill_volume' => $latest?->fill_volume?->value,
            'fill_volume_label' => $latest?->fill_volume?->label(),
            'photo_url' => $latest?->photoUrl(),
            'queue_label' => $latest?->queue_size?->label(),
            'comment' => $latest?->comment,
            'is_confirmation' => (bool) ($latest?->is_confirmation ?? false),
        ];
    }

    /** @return array<string, mixed> */
    public function formatStation(Station $station, ?FuelType $filterFuel = null, bool $withHistory = false): array
    {
        $reports = $this->visibleReports(
            $station->relationLoaded('reports')
                ? $station->reports
                : $station->reports()->visible()->orderByDesc('created_at')->get()
        );

        $fuels = collect(FuelType::all())
            ->mapWithKeys(fn (FuelType $type) => [$type->value => $this->fuelStatus($station, $type, $reports)]);

        $latestReport = $reports->sortByDesc('created_at')->first();

        $activeFuel = $filterFuel ?? FuelType::A95;
        $active = $fuels[$activeFuel->value];
        $activeFuelReport = $reports
            ->where('fuel_type', $activeFuel)
            ->sortByDesc('created_at')
            ->first();

        $data = [
            'id' => $station->id,
            'name' => $station->name,
            'network' => $station->network,
            'address' => $station->address,
            'latitude' => $station->latitude,
            'longitude' => $station->longitude,
            'fuels' => $fuels->values()->all(),
            'marker_color' => $active['marker_color'],
            'queue_size' => $latestReport?->queue_size?->value,
            'queue_label' => $latestReport?->queue_size?->label(),
            'sale_types' => $activeFuelReport?->sale_types ?? [],
            'sale_type_labels' => SaleType::labelsFor($activeFuelReport?->sale_types),
            'statuses' => $activeFuelReport?->statuses ?? [],
            'status_labels' => FuelStatus::labelsFor($activeFuelReport?->statuses),
            'fill_volume' => $activeFuelReport?->fill_volume?->value,
            'fill_volume_label' => $activeFuelReport?->fill_volume?->label(),
            'last_comment' => $latestReport?->comment,
            'last_photo_url' => $latestReport?->photoUrl(),
            'distance_m' => $station->distance_m ?? null,
            'closure_reports_count' => $this->closureReportsCount($station),
            'closure_reports_required' => $this->closureService->reportsRequired(),
        ];

        if ($withHistory) {
            $data['pending_corrections'] = $this->correctionService->pendingForStation($station);

            $data['history'] = collect(FuelType::all())
                ->mapWithKeys(function (FuelType $type) use ($reports) {
                    $items = $reports
                        ->where('fuel_type', $type)
                        ->sortByDesc('created_at')
                        ->take(5)
                        ->map(fn (Report $r) => [
                            'time' => $r->created_at->format('H:i'),
                            'status' => $r->status->value,
                            'status_label' => FuelStatus::labelsFor($r->statuses)
                                ? implode(' · ', FuelStatus::labelsFor($r->statuses))
                                : $r->status->label(),
                            'status_labels' => FuelStatus::labelsFor($r->statuses),
                            'sale_type_labels' => SaleType::labelsFor($r->sale_types),
                            'fill_volume_label' => $r->fill_volume?->label(),
                            'queue_label' => $r->queue_size?->label(),
                            'comment' => $r->comment,
                            'photo_url' => $r->photoUrl(),
                            'is_confirmation' => $r->is_confirmation,
                        ])
                        ->values()
                        ->all();

                    return [$type->value => $items];
                })
                ->all();
        }

        return $data;
    }

    /** @return Collection<int, Station> */
    public function nearby(float $lat, float $lng, FuelType $fuel, int $limit = 20): Collection
    {
        $stations = Station::query()
            ->where('is_active', true)
            ->withCount('closureReports')
            ->select('stations.*')
            ->selectRaw(
                'ST_Distance_Sphere(POINT(stations.longitude, stations.latitude), POINT(?, ?)) AS distance_m',
                [$lng, $lat]
            )
            ->orderBy('distance_m')
            ->limit($limit)
            ->get();

        $stationIds = $stations->pluck('id');
        $reports = Report::query()
            ->visible()
            ->whereIn('station_id', $stationIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('station_id');

        return $stations->map(function (Station $station) use ($reports, $fuel) {
            $station->setRelation('reports', $this->visibleReports($reports->get($station->id, collect())));
            $station->distance_m = (int) $station->distance_m;

            return $this->formatStation($station, $fuel);
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function allStations(?FuelType $fuel = null): Collection
    {
        $fuel ??= FuelType::A95;

        $stations = Station::query()
            ->where('is_active', true)
            ->withCount('closureReports')
            ->orderBy('network')
            ->orderBy('name')
            ->get();
        $reports = Report::query()
            ->visible()
            ->whereIn('station_id', $stations->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('station_id');

        return $stations->map(function (Station $station) use ($reports, $fuel) {
            $station->setRelation('reports', $this->visibleReports($reports->get($station->id, collect())));

            return $this->formatStation($station, $fuel);
        });
    }

    /** @param  Collection<int, Report>|iterable<Report>  $reports */
    private function visibleReports(iterable $reports): Collection
    {
        return collect($reports)->filter(fn (Report $r) => ! $r->is_hidden)->values();
    }

    private function closureReportsCount(Station $station): int
    {
        if (isset($station->closure_reports_count)) {
            return (int) $station->closure_reports_count;
        }

        return $this->closureService->reportsCount($station);
    }

    private function timeAgoShort(int $minutes): string
    {
        if ($minutes < 1) {
            return 'только что';
        }

        if ($minutes < 60) {
            return $minutes.' мин';
        }

        return intdiv($minutes, 60).' ч';
    }

    private function timeAgoLong(Carbon $reportedAt): string
    {
        $minutes = $reportedAt->diffInMinutes(now());

        if ($minutes < 60) {
            return $minutes.' мин';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours.' ч';
        }

        return intdiv($hours, 24).' д';
    }
}
