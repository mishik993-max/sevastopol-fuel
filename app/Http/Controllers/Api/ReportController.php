<?php

namespace App\Http\Controllers\Api;

use App\Enums\FuelStatus;
use App\Enums\FuelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmReportRequest;
use App\Http\Requests\StoreReportRequest;
use App\Models\Report;
use App\Models\Station;
use App\Services\StationStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(private StationStatusService $statusService) {}

    public function store(StoreReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $photoPath = null;

        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('reports', 'public');
        }

        $statuses = array_values(array_unique($data['statuses']));
        $saleTypes = array_values(array_unique($data['sale_types']));

        $report = Report::query()->create([
            'station_id' => $data['station_id'],
            'fuel_type' => $data['fuel_type'],
            'status' => FuelStatus::primaryFrom($statuses),
            'statuses' => $statuses,
            'queue_size' => $data['queue_size'],
            'sale_types' => $saleTypes,
            'fill_volume' => $data['fill_volume'] ?? null,
            'comment' => $data['comment'] ?? null,
            'photo_path' => $photoPath,
            'is_confirmation' => false,
            'created_at' => now(),
        ]);

        $station = Station::query()->findOrFail($data['station_id']);
        $reports = Report::query()
            ->visible()
            ->where('station_id', $station->id)
            ->orderByDesc('created_at')
            ->get();
        $station->setRelation('reports', $reports);

        return response()->json([
            'message' => 'Отчёт добавлен',
            'data' => $this->statusService->formatStation($station, FuelType::from($data['fuel_type']), withHistory: true),
        ], 201);
    }

    public function confirm(ConfirmReportRequest $request, Station $station): JsonResponse
    {
        $fuelType = FuelType::from($request->validated('fuel_type'));

        $latest = Report::query()
            ->visible()
            ->where('station_id', $station->id)
            ->where('fuel_type', $fuelType)
            ->orderByDesc('created_at')
            ->first();

        if ($latest === null) {
            throw ValidationException::withMessages([
                'fuel_type' => ['Нечего подтверждать для этого вида топлива.'],
            ]);
        }

        Report::query()->create([
            'station_id' => $station->id,
            'fuel_type' => $latest->fuel_type,
            'status' => $latest->status,
            'statuses' => $latest->statuses,
            'queue_size' => $latest->queue_size,
            'sale_types' => $latest->sale_types,
            'fill_volume' => $latest->fill_volume,
            'comment' => null,
            'photo_path' => null,
            'is_confirmation' => true,
            'created_at' => now(),
        ]);

        $reports = Report::query()
            ->visible()
            ->where('station_id', $station->id)
            ->orderByDesc('created_at')
            ->get();
        $station->setRelation('reports', $reports);

        return response()->json([
            'message' => 'Подтверждено',
            'data' => $this->statusService->formatStation($station, $fuelType, withHistory: true),
        ]);
    }
}
