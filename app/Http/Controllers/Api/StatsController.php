<?php

namespace App\Http\Controllers\Api;

use App\Enums\FuelType;
use App\Http\Controllers\Controller;
use App\Services\StationStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __construct(private StationStatsService $statsService) {}

    public function index(Request $request): JsonResponse
    {
        $fuel = FuelType::tryFrom($request->query('fuel', 'a95')) ?? FuelType::A95;

        return response()->json([
            'data' => $this->statsService->summary($fuel),
        ]);
    }
}
