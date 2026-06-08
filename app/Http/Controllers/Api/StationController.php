<?php

namespace App\Http\Controllers\Api;

use App\Enums\FuelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserStationRequest;
use App\Models\Report;
use App\Models\Station;
use App\Services\StationStatusService;
use App\Services\UserStationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function __construct(
        private StationStatusService $statusService,
        private UserStationService $userStationService,
    ) {}

    public function store(StoreUserStationRequest $request): JsonResponse
    {
        $fuel = FuelType::tryFrom($request->query('fuel', 'a95')) ?? FuelType::A95;

        $station = $this->userStationService->create($request->validated());
        $station->setRelation('reports', collect());

        return response()->json([
            'message' => 'АЗС добавлена на карту',
            'data' => $this->statusService->formatStation($station, $fuel),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $fuel = FuelType::tryFrom($request->query('fuel', 'a95')) ?? FuelType::A95;

        return response()->json([
            'data' => $this->statusService->allStations($fuel)->values(),
        ]);
    }

    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'fuel' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $fuel = FuelType::tryFrom($validated['fuel'] ?? 'a95') ?? FuelType::A95;

        return response()->json([
            'data' => $this->statusService->nearby(
                (float) $validated['lat'],
                (float) $validated['lng'],
                $fuel,
                (int) ($validated['limit'] ?? 20),
            )->values(),
        ]);
    }

    public function show(Request $request, Station $station): JsonResponse
    {
        if (! $station->is_active) {
            return response()->json(['message' => 'АЗС закрыта и скрыта с карты'], 404);
        }

        $fuel = FuelType::tryFrom($request->query('fuel', 'a95')) ?? FuelType::A95;

        $station->loadCount('closureReports');

        $reports = Report::query()
            ->visible()
            ->where('station_id', $station->id)
            ->orderByDesc('created_at')
            ->get();

        $station->setRelation('reports', $reports);

        return response()->json([
            'data' => $this->statusService->formatStation($station, $fuel, withHistory: true),
        ]);
    }
}
