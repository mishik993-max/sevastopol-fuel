<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordVisitRequest;
use App\Services\VisitorStatsService;
use Illuminate\Http\JsonResponse;

class VisitController extends Controller
{
    public function __construct(private VisitorStatsService $visitorStats) {}

    public function store(RecordVisitRequest $request): JsonResponse
    {
        $this->visitorStats->record($request->validated('visitor_id'));

        return response()->json(['message' => 'OK']);
    }
}
