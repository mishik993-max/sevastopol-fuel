<?php

namespace App\Http\Controllers\Api;

use App\Enums\FuelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStationCorrectionRequest;
use App\Models\Station;
use App\Models\StationCorrection;
use App\Services\StationCorrectionService;
use App\Services\StationStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationCorrectionController extends Controller
{
    public function __construct(
        private StationCorrectionService $correctionService,
        private StationStatusService $statusService,
    ) {}

    public function store(StoreStationCorrectionRequest $request, Station $station): JsonResponse
    {
        if (! $station->is_active) {
            return response()->json(['message' => 'АЗС закрыта и скрыта с карты'], 404);
        }

        $fuel = FuelType::tryFrom($request->query('fuel', 'a95')) ?? FuelType::A95;
        $hash = $this->correctionService->reporterHash($request);

        $corrections = $this->correctionService->propose(
            $station,
            $hash,
            $request->validated('corrections'),
        );

        $station->loadCount('closureReports');
        $reports = $station->reports()->visible()->orderByDesc('created_at')->get();
        $station->setRelation('reports', $reports);

        return response()->json([
            'message' => 'Исправление отправлено. Нужны подтверждения других пользователей.',
            'data' => [
                'corrections' => $corrections,
                'station' => $this->statusService->formatStation($station, $fuel, withHistory: true),
            ],
        ], 201);
    }

    public function confirm(Request $request, Station $station, StationCorrection $correction): JsonResponse
    {
        if (! $station->is_active) {
            return response()->json(['message' => 'АЗС закрыта и скрыта с карты'], 404);
        }

        if ($correction->station_id !== $station->id) {
            return response()->json(['message' => 'Исправление не относится к этой АЗС'], 404);
        }

        $fuel = FuelType::tryFrom($request->query('fuel', 'a95')) ?? FuelType::A95;
        $result = $this->correctionService->confirm(
            $correction,
            $this->correctionService->reporterHash($request),
        );

        $station->refresh();
        $station->loadCount('closureReports');
        $reports = $station->reports()->visible()->orderByDesc('created_at')->get();
        $station->setRelation('reports', $reports);

        $message = $result['applied']
            ? 'Исправление применено - достаточно подтверждений.'
            : 'Спасибо! Ещё '.($result['confirmations_required'] - $result['confirmations_count']).' подтверждений.';

        return response()->json([
            'message' => $message,
            'data' => [
                'applied' => $result['applied'],
                'correction' => $result['correction'],
                'station' => $this->statusService->formatStation($station, $fuel, withHistory: true),
            ],
        ]);
    }
}
