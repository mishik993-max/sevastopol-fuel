<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CloseStationRequest;
use App\Models\Station;
use App\Services\StationClosureService;
use Illuminate\Http\JsonResponse;

class StationClosureController extends Controller
{
    public function __construct(private StationClosureService $closureService) {}

    public function store(CloseStationRequest $request, Station $station): JsonResponse
    {
        $result = $this->closureService->reportClosure(
            $station,
            $this->closureService->reporterHash($request),
            $request->validated('comment'),
        );

        $message = $result['deactivated']
            ? 'АЗС скрыта с карты- несколько человек сообщили, что она не работает.'
            : 'Спасибо! Ещё '.($result['reports_required'] - $result['reports_count']).' сообщений- и АЗС скроем с карты.';

        return response()->json([
            'message' => $message,
            'data' => $result,
        ]);
    }
}
