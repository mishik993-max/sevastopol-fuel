<?php

namespace App\Http\Controllers\Api;

use App\Enums\FuelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PushWatchSyncRequest;
use App\Services\PushWatchService;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushWatchController extends Controller
{
    public function __construct(
        private PushWatchService $watchService,
        private WebPushService $webPush,
    ) {}

    public function sync(PushWatchSyncRequest $request): JsonResponse
    {
        $subscription = $this->watchService->findSubscription($request->validated('endpoint'));

        if ($subscription === null) {
            return response()->json([
                'message' => 'Подписка на push не найдена. Сначала включите уведомления на сайте.',
            ], 404);
        }

        if ($clientId = $request->validated('client_id')) {
            $this->webPush->attachClientId($subscription, $clientId);
        }

        $fuelType = FuelType::from($request->validated('fuel_type'));
        $watches = $this->watchService->sync(
            $subscription,
            $request->validated('station_ids'),
            $fuelType,
        );

        return response()->json([
            'watches' => $watches,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $subscription = $this->watchService->findSubscription($validated['endpoint']);

        if ($subscription === null) {
            return response()->json(['message' => 'Подписка не найдена'], 404);
        }

        $this->watchService->clear($subscription);

        return response()->json(['message' => 'Отслеживание отключено']);
    }
}
