<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuelAssistantConfirmRequest;
use App\Http\Requests\FuelAssistantMessageRequest;
use App\Http\Requests\FuelAssistantRejectRequest;
use App\Http\Requests\FuelAssistantSessionRequest;
use App\Services\FuelAssistantService;
use App\Services\StationStatusService;
use App\Models\Station;
use App\Enums\FuelType;
use Illuminate\Http\JsonResponse;

class FuelAssistantController extends Controller
{
    public function __construct(
        private FuelAssistantService $assistant,
        private StationStatusService $statusService,
    ) {}

    public function session(FuelAssistantSessionRequest $request): JsonResponse
    {
        $data = $this->assistant->activeSessionForClient($request->validated('client_id'));

        return response()->json(['data' => $data]);
    }

    public function message(FuelAssistantMessageRequest $request): JsonResponse
    {
        try {
            $data = $this->assistant->sendMessage(
                $request->validated('client_id'),
                $request->validated('message'),
                isset($request->validated()['latitude']) ? (float) $request->validated('latitude') : null,
                isset($request->validated()['longitude']) ? (float) $request->validated('longitude') : null,
                $request->validated('context_station_id') ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function confirm(FuelAssistantConfirmRequest $request): JsonResponse
    {
        try {
            $result = $this->assistant->confirm(
                $request->validated('client_id'),
                $request->validated('station_id') ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $station = Station::query()->find($result['station_id']);
        $stationPayload = null;

        if ($station !== null) {
            $firstFuel = $result['fuel_type'] ?? FuelType::A95->value;
            $fuelType = FuelType::tryFrom($firstFuel) ?? FuelType::A95;
            $reports = $station->reports()->visible()->orderByDesc('created_at')->get();
            $station->setRelation('reports', $reports);
            $stationPayload = $this->statusService->formatStation($station, $fuelType, withHistory: true);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => [
                ...$result,
                'station' => $stationPayload,
            ],
        ]);
    }

    public function reject(FuelAssistantRejectRequest $request): JsonResponse
    {
        try {
            $data = $this->assistant->reject(
                $request->validated('client_id'),
                $request->validated('message') ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function close(FuelAssistantSessionRequest $request): JsonResponse
    {
        $this->assistant->close($request->validated('client_id'));

        return response()->json(['message' => 'Диалог завершён']);
    }
}
